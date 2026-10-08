<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{Item, Unit, WorkOrder, WorkOrderPlannedOutput, WorkOrderPlannedOutputVersion};
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Planned outputs are declarative WO facts. They do not create reports, stock or costs. */
final class WorkOrderPlannedOutputService
{
    public function __construct(private readonly UnitConversionDomainService $units) {}

    public function validatePayload(array $payload): array
    {
        $unknown = array_diff(array_keys($payload), ['client_command_id', 'expected_version', 'outputs']);
        if ($unknown !== []) $this->fail('unknown_fields', '计划产出包含不允许的字段。', 422, ['fields' => array_values($unknown)]);
        $data = Validator::make($payload, [
            'client_command_id' => 'required|string|max:120', 'expected_version' => 'required|integer|min:1',
            'outputs' => 'present|array|max:100', 'outputs.*' => 'required|array:line_uuid,item_id,planned_base_qty,output_role,remark',
            'outputs.*.line_uuid' => 'required|uuid|distinct', 'outputs.*.item_id' => 'required|integer|min:1',
            'outputs.*.planned_base_qty' => 'required', 'outputs.*.output_role' => 'required|in:product,by_product',
            'outputs.*.remark' => 'nullable|string|max:500',
        ])->validate();
        if (! array_is_list($data['outputs'])) $this->fail('outputs_invalid', '计划产出必须是连续明细列表。');
        foreach ($data['outputs'] as &$row) $row['line_uuid'] = strtolower($row['line_uuid']);
        unset($row);
        if (count(array_unique(array_column($data['outputs'], 'line_uuid'))) !== count($data['outputs'])) {
            $this->fail('output_uuid_duplicate', '同一计划产出标识不能重复。');
        }
        return $data;
    }

    public function projection(WorkOrder $workOrder, bool $editable = false): array
    {
        $rows = $this->rows($workOrder);
        $reference = $rows->first(fn ($row) => $row->is_reference && $row->status === 'ACTIVE');
        $outputs = [];
        if ($reference) $outputs[] = $this->rowProjection($reference);
        else {
            $referenceFacts = $this->referenceFacts($workOrder, false);
            if ($referenceFacts !== null) $outputs[] = $this->referenceProjection($referenceFacts, null);
        }
        foreach ($rows as $row) if (! $row->is_reference && $row->status === 'ACTIVE') $outputs[] = $this->rowProjection($row);
        return [
            'work_order_id' => (int) $workOrder->id, 'business_version' => (int) $workOrder->business_version,
            'editable' => $editable && $workOrder->status === WorkOrderApplicationService::DRAFT,
            'plan_source' => $rows->isEmpty() ? 'legacy_projection' : 'saved_plan',
            'multi_output_execution_available' => false, 'outputs' => $outputs,
        ];
    }

    /** Caller holds the authorized WO lock and writes the parent version in the same transaction. */
    public function replaceLocked(WorkOrder $workOrder, array $payload, object $user, int $beforeVersion, int $afterVersion): void
    {
        if ($workOrder->status !== WorkOrderApplicationService::DRAFT) $this->fail('state_conflict', '只有草稿工单可以维护计划产出。', 409);
        $existing = $this->rows($workOrder, true)->keyBy('line_uuid');
        $before = $this->activeSnapshot($existing->values());
        $reference = $existing->first(fn ($row) => $row->is_reference);
        $referenceFacts = $this->referenceFacts($workOrder, true, $reference);
        $itemIds = array_map(fn ($row) => (int) $row['item_id'], $payload['outputs']);
        if (count(array_unique($itemIds)) !== count($itemIds) || in_array((int) $referenceFacts['item_id'], $itemIds, true)) {
            $this->fail('planned_output_item_duplicate', '同一工单的计划产出物料不能重复；参考产出无需再次添加。');
        }
        $prepared = [];
        // Validate every line before writing even the reference. Unknown quantities are never defaulted.
        foreach ($payload['outputs'] as $index => $row) {
            $other = WorkOrderPlannedOutput::query()->where('line_uuid', $row['line_uuid'])->lockForUpdate()->first();
            if ($other && ((int) $other->work_order_id !== (int) $workOrder->id || $other->is_reference)) {
                $this->fail('output_uuid_scope_mismatch', '产出明细标识不属于当前工单的可编辑明细。');
            }
            $item = Item::query()->with('unit.standardUnit')->lockForUpdate()->find((int) $row['item_id']);
            if ($item) app(ItemManagementScopeService::class)->assertProductionAllowed($item, 'outputs');
            if (! $item || $item->status !== 'enabled' || ! $item->is_stock_item || $item->item_type === 'service'
                || ($row['output_role'] === 'product' && ! $item->is_production_item)) {
                $this->fail('planned_output_item_invalid', '产品须选择已启用、可生产且可库存的物料；副产品须为已启用的真实库存物料。', 422, ['item_id' => (int) $row['item_id']]);
            }
            $unit = $this->baseUnit($item, true);
            $previous = $existing->get($row['line_uuid']);
            $sameItem = $previous && (int) $previous->item_id === (int) $item->id;
            if ($sameItem && (int) $previous->base_unit_id !== (int) $unit->id) {
                $this->fail('planned_output_unit_changed', '已保存产出的库存基本单位已变化，请先核实物料资料，不能直接换单位解释原计划数量。');
            }
            $facts = $this->facts($item, $unit, $this->quantity($row['planned_base_qty'], $unit,
                $sameItem ? (int) $previous->base_unit_decimal_places_snapshot : null), false);
            if ($sameItem) foreach (['item_code_snapshot', 'item_name_snapshot', 'spec_snapshot', 'base_unit_name_snapshot', 'base_unit_decimal_places_snapshot'] as $field) $facts[$field] = $previous->{$field};
            $prepared[$row['line_uuid']] = $facts
                + ['line_no' => $index + 2, 'output_role' => $row['output_role'], 'remark' => $row['remark'] ?? null];
        }

        if (! $reference) $reference = new WorkOrderPlannedOutput(['work_order_id' => $workOrder->id, 'line_uuid' => (string) Str::uuid(), 'business_version' => 1, 'created_by_legacy_id' => $this->actor($user)]);
        $this->writeRow($reference, $referenceFacts + ['line_no' => 1, 'output_role' => 'product', 'remark' => null], $user);
        $retained = [$reference->line_uuid];
        foreach ($prepared as $uuid => $facts) {
            $row = $existing->get($uuid) ?: new WorkOrderPlannedOutput(['work_order_id' => $workOrder->id, 'line_uuid' => $uuid, 'business_version' => 1, 'created_by_legacy_id' => $this->actor($user)]);
            $this->writeRow($row, $facts, $user);
            $retained[] = $uuid;
        }
        foreach ($existing as $row) {
            if (! in_array($row->line_uuid, $retained, true) && $row->status === 'ACTIVE') {
                $row->fill(['status' => 'REMOVED', 'active_reference_work_order_id' => null,
                    'business_version' => (int) $row->business_version + 1, 'updated_by_legacy_id' => $this->actor($user)])->save();
            }
        }
        $workOrder->unsetRelation('plannedOutputs');
        $this->audit($workOrder, $payload['client_command_id'], 'save_planned_outputs', $before, $this->activeSnapshot($this->rows($workOrder)), $beforeVersion, $afterVersion, $user);
    }

    /** An existing saved plan follows authorized changes to the WO's own reference; old WOs stay virtual. */
    public function synchronizeReferenceIfPersisted(WorkOrder $workOrder, object $user, string $commandId, string $command): void
    {
        if ($workOrder->status !== WorkOrderApplicationService::DRAFT || ! $this->tableAvailable()) return;
        $rows = $this->rows($workOrder, true);
        $reference = $rows->first(fn ($row) => $row->is_reference && $row->status === 'ACTIVE');
        if (! $reference) return;
        $itemId = (int) ($workOrder->effective_output_item_id_snapshot ?: $workOrder->output_item_id);
        // Unrelated draft commands do not reinterpret a saved reference after master-data changes.
        if ((int) $reference->item_id === $itemId && bccomp((string) $reference->planned_base_qty, (string) $workOrder->target_base_qty, 8) === 0) return;
        if ($rows->contains(fn ($row) => ! $row->is_reference && $row->status === 'ACTIVE' && (int) $row->item_id === $itemId)) {
            $this->fail('planned_output_item_duplicate', '工单参考产出不能与额外计划产出重复，请先调整计划产出。');
        }
        $facts = $this->referenceFacts($workOrder, true, $reference) + ['line_no' => 1, 'output_role' => 'product', 'remark' => null];
        if (! $this->changed($reference, $facts + ['status' => 'ACTIVE', 'active_reference_work_order_id' => $workOrder->id])) return;
        $before = $this->activeSnapshot($rows);
        $this->writeRow($reference, $facts, $user);
        $workOrder->unsetRelation('plannedOutputs');
        $this->audit($workOrder, $commandId, $command, $before, $this->activeSnapshot($this->rows($workOrder)),
            max(0, (int) $workOrder->business_version - 1), (int) $workOrder->business_version, $user);
    }

    public function additionalCount(WorkOrder $workOrder): int
    {
        return $this->rows($workOrder)->filter(fn ($row) => ! $row->is_reference && $row->status === 'ACTIVE')->count();
    }

    private function rows(WorkOrder $workOrder, bool $lock = false): Collection
    {
        // This compatibility branch is for deployment order only; it never invents persisted historical rows.
        if (! $this->tableAvailable()) return collect();
        if (! $lock && $workOrder->relationLoaded('plannedOutputs')) return $workOrder->plannedOutputs;
        $query = WorkOrderPlannedOutput::query()->where('work_order_id', $workOrder->id)->orderBy('line_no')->orderBy('id');
        return ($lock ? $query->lockForUpdate() : $query)->get();
    }

    public function tableAvailable(): bool
    {
        // DTO lists resolve this service repeatedly. Cache deployment compatibility once per request.
        $request = app()->bound('request') ? app('request') : null;
        $key = 'erp_work_order_planned_outputs_table_available:'.config('database.default').':'.config('database.connections.'.config('database.default').'.database');
        if ($request?->attributes->has($key)) return (bool) $request->attributes->get($key);
        $exists = Schema::hasTable('erp_work_order_planned_outputs');
        $request?->attributes->set($key, $exists);
        return $exists;
    }

    private function referenceFacts(WorkOrder $workOrder, bool $strict, ?WorkOrderPlannedOutput $saved = null): ?array
    {
        // A prebuild ending at a semi-finished node retains its final product header. Never rewrite that header.
        $itemId = (int) ($workOrder->effective_output_item_id_snapshot ?: $workOrder->output_item_id);
        $item = $itemId === (int) $workOrder->output_item_id && $workOrder->relationLoaded('outputItem')
            ? $workOrder->outputItem
            : ($itemId === (int) $workOrder->effective_output_item_id_snapshot && $workOrder->relationLoaded('effectiveOutputItem')
                ? $workOrder->effectiveOutputItem : ($itemId > 0 ? Item::query()->with('unit.standardUnit')->find($itemId) : null));
        if (! $item) {
            if ($strict) $this->fail('reference_output_missing', '工单尚未确认真实的计划产出物料，请先补齐工单资料。');
            return null;
        }
        $unit = $this->baseUnit($item, $strict);
        $sameSavedItem = $saved && (int) $saved->item_id === $itemId;
        if ($strict && $sameSavedItem && (int) $saved->base_unit_id !== (int) $unit?->id) {
            $this->fail('reference_output_unit_changed', '已保存参考产出的库存基本单位已变化，不能通过标准单位映射重新解释原计划数量。');
        }
        $frozenUnitId = $sameSavedItem ? (int) $saved->base_unit_id
            : ($itemId === (int) $workOrder->output_item_id ? (int) $workOrder->base_unit_id : 0);
        if ($frozenUnitId > 0) {
            $frozenUnit = ! $sameSavedItem && $workOrder->relationLoaded('baseUnit')
                ? $workOrder->baseUnit : Unit::query()->with('standardUnit')->find($frozenUnitId);
            $canonicalFrozen = $this->units->canonicalUnit($frozenUnit);
            $mappedMissing = $frozenUnit?->is_legacy && $frozenUnit?->standard_unit_id && ! $frozenUnit?->standardUnit;
            if ($strict && (! $canonicalFrozen || $mappedMissing || $frozenUnit?->status !== 'enabled' || $canonicalFrozen->status !== 'enabled'
                || (int) $canonicalFrozen->id !== (int) $unit?->id)) {
                $this->fail('reference_output_unit_changed', '工单冻结的库存基本单位与当前物料单位不一致或已停用，请先核实单位资料，不能直接改写数量。');
            }
            if ($canonicalFrozen && ! $mappedMissing) $unit = $canonicalFrozen;
        }
        if ($strict) {
            if ($item->status !== 'enabled' || ! $item->is_stock_item || $item->item_type === 'service') $this->fail('reference_output_invalid', '工单参考产出物料须启用且可库存。');
            $quantity = $this->quantity($workOrder->target_base_qty, $unit, $sameSavedItem ? (int) $saved->base_unit_decimal_places_snapshot : null);
        } else {
            $quantity = $workOrder->target_base_qty === null ? null : (string) $workOrder->target_base_qty;
        }
        $facts = $this->facts($item, $unit, $quantity, true);
        if ($sameSavedItem) {
            foreach (['item_code_snapshot', 'item_name_snapshot', 'spec_snapshot', 'base_unit_name_snapshot', 'base_unit_decimal_places_snapshot'] as $field) {
                $facts[$field] = $saved->{$field};
            }
            return $facts;
        }
        // Historical labels come from the actual target node first, then the frozen route/sales facts.
        $route = (array) ($workOrder->routing_snapshot ?? []);
        $node = collect($route['operations'] ?? [])->firstWhere('routing_operation_id', (int) $workOrder->target_routing_operation_id);
        $frozenItem = [];
        if ((int) ($node['output_item_id'] ?? 0) === $itemId) $frozenItem = (array) $node;
        elseif ((int) ($route['output_item_id'] ?? 0) === $itemId) $frozenItem = $route;
        foreach (['output_item_code' => 'item_code_snapshot', 'output_item_name' => 'item_name_snapshot'] as $source => $target) {
            if (isset($frozenItem[$source])) $facts[$target] = $frozenItem[$source];
        }
        if ($itemId === (int) $workOrder->output_item_id) {
            $line = $workOrder->demand?->line;
            $salesItem = (array) ($line?->item_snapshot ?? []);
            if ((int) ($salesItem['id'] ?? $salesItem['item_id'] ?? $line?->item_id ?? 0) === $itemId) {
                foreach (['item_code' => 'item_code_snapshot', 'item_name' => 'item_name_snapshot'] as $source => $target) {
                    if (! isset($frozenItem['output_'.$source]) && isset($salesItem[$source])) $facts[$target] = $salesItem[$source];
                }
                if (array_key_exists('spec', $salesItem)) $facts['spec_snapshot'] = $salesItem['spec'];
            }
            if ($workOrder->base_unit_name_snapshot !== null && $frozenUnitId > 0) $facts['base_unit_name_snapshot'] = $workOrder->base_unit_name_snapshot;
        }
        return $facts;
    }

    private function baseUnit(Item $item, bool $strict): ?Unit
    {
        $unit = $this->units->canonicalUnit($item->unit);
        $mappedMissing = $item->unit?->is_legacy && (! $item->unit?->standard_unit_id || ! $item->unit?->standardUnit);
        if ($strict && (! $unit || $mappedMissing || $item->unit?->status !== 'enabled' || $unit->status !== 'enabled')) {
            $this->fail('planned_output_unit_invalid', '产出物料缺少有效的库存基本单位，不能保存计划数量。', 422, ['item_id' => (int) $item->id]);
        }
        return $mappedMissing ? null : $unit;
    }

    private function quantity(mixed $value, Unit $unit, ?int $frozenPrecision = null): string
    {
        if ((! is_string($value) && ! is_int($value)) || ! preg_match('/^\d{1,20}(?:\.\d+)?$/D', (string) $value)) {
            $this->fail('planned_output_quantity_invalid', '计划数量必须填写明确的正十进制数，不能使用浮点数或科学计数法。');
        }
        $fraction = explode('.', (string) $value, 2)[1] ?? '';
        $scale = min(8, max(0, $frozenPrecision ?? (int) $unit->decimal_places));
        if (trim(substr($fraction, $scale), '0') !== '') $this->fail('planned_output_quantity_precision', "库存单位 {$unit->unit_name} 最多允许 {$scale} 位小数。", 422, ['base_unit_id' => (int) $unit->id]);
        $quantity = bcadd((string) $value, '0', 8);
        if (bccomp($quantity, '0', 8) <= 0) $this->fail('planned_output_quantity_invalid', '计划数量必须大于零，未确认的数量不能默认填写。');
        return $quantity;
    }

    private function facts(Item $item, ?Unit $unit, ?string $quantity, bool $reference): array
    {
        return ['is_reference' => $reference, 'item_id' => (int) $item->id, 'base_unit_id' => $unit?->id,
            'item_code_snapshot' => (string) $item->item_code, 'item_name_snapshot' => (string) $item->item_name,
            'spec_snapshot' => $item->spec, 'base_unit_name_snapshot' => $unit?->unit_name,
            'base_unit_decimal_places_snapshot' => $unit === null ? null : min(8, max(0, (int) $unit->decimal_places)), 'planned_base_qty' => $quantity];
    }

    private function writeRow(WorkOrderPlannedOutput $row, array $facts, object $user): void
    {
        $facts += ['status' => 'ACTIVE', 'active_reference_work_order_id' => $facts['is_reference'] ? (int) $row->work_order_id : null];
        if ($row->exists && $this->changed($row, $facts)) $row->business_version = (int) $row->business_version + 1;
        $row->fill($facts + ['updated_by_legacy_id' => $this->actor($user)])->save();
    }

    private function changed(WorkOrderPlannedOutput $row, array $facts): bool
    {
        foreach ($facts as $key => $value) {
            if ($key === 'planned_base_qty') {
                if (bccomp((string) $row->{$key}, (string) $value, 8) !== 0) return true;
            } elseif ((string) $row->{$key} !== (string) $value) return true;
        }
        return false;
    }

    private function referenceProjection(array $facts, ?WorkOrderPlannedOutput $row): array
    {
        return ['line_uuid' => $row?->line_uuid, 'is_reference' => true, 'output_role' => 'product',
            'item_id' => $facts['item_id'], 'item_code' => $facts['item_code_snapshot'], 'item_name' => $facts['item_name_snapshot'],
            'spec' => $facts['spec_snapshot'], 'base_unit_id' => $facts['base_unit_id'], 'base_unit_name' => $facts['base_unit_name_snapshot'],
            'base_unit_decimal_places' => $facts['base_unit_decimal_places_snapshot'],
            'planned_base_qty' => $facts['planned_base_qty'], 'remark' => null, 'business_version' => $row?->business_version];
    }

    private function rowProjection(WorkOrderPlannedOutput $row): array
    {
        return ['line_uuid' => $row->line_uuid, 'is_reference' => (bool) $row->is_reference, 'output_role' => $row->output_role,
            'item_id' => (int) $row->item_id, 'item_code' => $row->item_code_snapshot, 'item_name' => $row->item_name_snapshot,
            'spec' => $row->spec_snapshot, 'base_unit_id' => (int) $row->base_unit_id, 'base_unit_name' => $row->base_unit_name_snapshot,
            'base_unit_decimal_places' => (int) $row->base_unit_decimal_places_snapshot,
            'planned_base_qty' => (string) $row->planned_base_qty, 'remark' => $row->remark, 'business_version' => (int) $row->business_version];
    }

    private function activeSnapshot(Collection $rows): array
    {
        return $rows->filter(fn ($row) => $row->status === 'ACTIVE')->sortBy('line_no')->map(fn ($row) => $this->rowProjection($row))->values()->all();
    }

    private function audit(WorkOrder $workOrder, string $commandId, string $command, array $before, array $after, int $beforeVersion, int $afterVersion, object $user): void
    {
        WorkOrderPlannedOutputVersion::create(['work_order_id' => $workOrder->id, 'client_command_id' => $commandId,
            'command_type' => $command, 'before_version' => $beforeVersion, 'after_version' => $afterVersion,
            'before_snapshot' => $before, 'after_snapshot' => $after, 'operator_legacy_id' => $this->actor($user),
            'operator_name' => $user->nickname ?? $user->username ?? null, 'organization_code' => $workOrder->organization_code, 'occurred_at' => now()]);
    }

    private function actor(object $user): int { return (int) ($user->legacy_id ?? $user->id ?? 0); }
    private function fail(string $code, string $message, int $status = 422, array $details = []): never
    {
        throw new WorkOrderDomainException($code, $message, $status, $details);
    }
}
