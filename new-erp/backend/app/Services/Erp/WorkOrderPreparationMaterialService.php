<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{Bom, Item, WorkOrder, WorkOrderMaterialRequirement, WorkOrderPreparationMaterial};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Versioned buying authority before release, with no execution or inventory side effects. */
final class WorkOrderPreparationMaterialService
{
    public function schemaReady(): bool
    {
        return Schema::hasColumns('erp_work_order_material_preparations', ['id', 'work_order_id', 'version', 'work_order_version', 'status', 'input_hash', 'input_snapshot', 'prepared_by_legacy_id', 'published_at', 'created_at', 'updated_at'])
            && Schema::hasColumns('erp_work_order_material_preparation_versions', ['id', 'preparation_id', 'version', 'input_hash', 'input_snapshot', 'prepared_by_legacy_id', 'created_at', 'updated_at'])
            && Schema::hasColumns('erp_work_order_preparation_materials', ['id', 'work_order_id', 'preparation_id', 'preparation_version', 'identity_key', 'bom_item_id', 'component_item_id', 'base_unit_id', 'required_base_qty', 'material_snapshot', 'status', 'material_requirement_id', 'created_at', 'updated_at'])
            && Schema::hasColumns('erp_material_procurement_sources', ['preparation_material_requirement_id', 'preparation_version']);
    }

    public function synchronizeLocked(WorkOrder $workOrder, object $user): array
    {
        $this->transaction();
        $workOrder = WorkOrder::whereKey($workOrder->id)->lockForUpdate()->firstOrFail();
        if (! $this->schemaReady()) return $this->unavailable('schema_not_ready', '发布前物料准备结构尚未就绪。');
        $this->editable($workOrder);
        $input = $this->calculate($workOrder);
        $hash = $this->hash($input);
        $header = DB::table('erp_work_order_material_preparations')->where('work_order_id', $workOrder->id)->lockForUpdate()->first();
        if ($header && $header->input_hash === $hash && $header->status === 'ACTIVE') return $this->projection($workOrder);
        // A changed BOM/configuration must not detach already ordered material. The operator
        // must reverse the old procurement explicitly before a new version can become usable.
        $existing = WorkOrderPreparationMaterial::where('work_order_id', $workOrder->id)->orderBy('id')->lockForUpdate()->get();
        foreach ($existing as $row) {
            if (bccomp($this->pendingForPreparation((int) $row->id), '0', 8) > 0) {
                $this->fail('preparation_procurement_conflict', '物料准备依据已变化，但旧版本仍有未撤销申购；请先处理原采购需求再重新准备。');
            }
        }
        $version = (int) ($header->version ?? 0) + 1;
        $actor = (int) ($user->legacy_id ?? $user->id ?? 0);
        $values = ['version' => $version, 'work_order_version' => $workOrder->business_version, 'status' => 'ACTIVE',
            'input_hash' => $hash, 'input_snapshot' => $this->json($input), 'prepared_by_legacy_id' => $actor, 'updated_at' => now()];
        $headerId = $header?->id;
        if ($headerId) DB::table('erp_work_order_material_preparations')->where('id', $headerId)->update($values);
        else $headerId = DB::table('erp_work_order_material_preparations')->insertGetId([...$values, 'work_order_id' => $workOrder->id, 'created_at' => now()]);
        DB::table('erp_work_order_material_preparation_versions')->insert(['preparation_id' => $headerId, 'version' => $version,
            'input_hash' => $hash, 'input_snapshot' => $this->json($input), 'prepared_by_legacy_id' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        WorkOrderPreparationMaterial::where('work_order_id', $workOrder->id)->update(['status' => 'SUPERSEDED']);
        foreach ($input['rows'] as $row) {
            WorkOrderPreparationMaterial::updateOrCreate(['work_order_id' => $workOrder->id, 'identity_key' => $this->materialIdentity($row)], [
                'bom_item_id' => $row['bom_item_id'],
                'preparation_id' => $headerId, 'preparation_version' => $version, 'component_item_id' => $row['component_item_id'],
                'base_unit_id' => $row['base_unit_id'], 'required_base_qty' => $row['base_required_qty'],
                'material_snapshot' => $row, 'status' => 'ACTIVE', 'material_requirement_id' => null,
            ]);
        }
        return $this->projection($workOrder);
    }

    /** Called inside publication, after the ordinary frozen requirements have been created. */
    public function bindPublishedLocked(WorkOrder $workOrder): void
    {
        $this->transaction();
        if (! $this->schemaReady()) return; // Old installations have no preparation authority to bind.
        $header = DB::table('erp_work_order_material_preparations')->where('work_order_id', $workOrder->id)->lockForUpdate()->first();
        if (! $header) return; // Historical work orders retain the original release contract.
        $input = $this->calculate($workOrder);
        if (! hash_equals($header->input_hash, $this->hash($input))) $this->fail('preparation_stale', '物料准备依据已变化，请重新准备后发布。');
        $rows = WorkOrderPreparationMaterial::where('work_order_id', $workOrder->id)->whereIn('status', ['ACTIVE', 'PUBLISHED'])->orderBy('id')->lockForUpdate()->get();
        $formal = WorkOrderMaterialRequirement::where('work_order_id', $workOrder->id)->orderBy('id')->lockForUpdate()->get();
        if ($rows->count() !== $formal->count() || $rows->count() !== count($input['rows'])) $this->fail('preparation_binding_mismatch', '准备需求与正式物料需求行数不一致。');
        foreach ($rows as $row) {
            $matches = $formal->filter(fn ($candidate) => $this->materialIdentity($candidate->getAttributes()) === $row->identity_key);
            $match = $matches->first();
            if ($matches->count() !== 1 || ! $match || $this->hash($this->materialFacts($match->getAttributes())) !== $this->hash($row->material_snapshot)) {
                $this->fail('preparation_binding_mismatch', '准备需求与发布冻结用料不一致，不能发布。');
            }
            if ($row->material_requirement_id && (int) $row->material_requirement_id !== (int) $match->id) $this->fail('preparation_binding_mismatch', '准备需求已绑定另一条正式需求。');
            $row->update(['material_requirement_id' => $match->id, 'status' => 'PUBLISHED']);
        }
        DB::table('erp_work_order_material_preparations')->where('id', $header->id)->update(['status' => 'PUBLISHED', 'published_at' => now(), 'updated_at' => now()]);
    }

    /** Gate uses this without mutating or silently regenerating a preparation version. */
    public function releaseCheck(WorkOrder $workOrder): ?array
    {
        if (! $this->schemaReady()) return null;
        if (! DB::table('erp_work_order_material_preparations')->where('work_order_id', $workOrder->id)->exists()) return null;
        $result = $this->projection($workOrder);
        return ['valid' => $result['ready'], 'code' => $result['issues'][0]['code'] ?? '',
            'message' => $result['issues'][0]['message'] ?? '通过', 'version' => $result['version']];
    }

    public function projection(WorkOrder $workOrder): array
    {
        if (! $this->schemaReady()) return $this->unavailable('schema_not_ready', '发布前物料准备结构尚未就绪。');
        $header = DB::table('erp_work_order_material_preparations')->where('work_order_id', $workOrder->id)->first();
        if (! $header) return $this->unavailable('not_prepared', '尚未完成物料准备。');
        $issues = [];
        if ($header->status !== 'PUBLISHED') {
            try {
                $this->editable($workOrder);
                if (! hash_equals($header->input_hash, $this->hash($this->calculate($workOrder)))) $this->fail('preparation_stale', '物料准备依据已变化，请重新准备。');
            } catch (WorkOrderDomainException $e) { $issues[] = ['code' => $e->errorCode, 'message' => $e->getMessage()]; }
            catch (ValidationException $e) { $issues[] = ['code' => 'preparation_material_invalid', 'message' => collect($e->errors())->flatten()->first() ?: '物料准备参数无效。']; }
        }
        $rows = WorkOrderPreparationMaterial::with('componentItem')->where('preparation_id', $header->id)
            ->where('preparation_version', $header->version)->whereIn('status', ['ACTIVE', 'PUBLISHED'])->orderBy('id')->get();
        return ['status' => $issues ? 'blocked' : strtolower($header->status), 'ready' => $issues === [], 'issues' => $issues,
            'version' => (int) $header->version, 'rows' => $rows->map(fn ($row) => $this->rowProjection($row, $workOrder, $issues === []))->all()];
    }

    public function rowProjection(WorkOrderPreparationMaterial $row, WorkOrder $wo, bool $valid = true): array
    {
        $item = $row->componentItem;
        $snapshot = $row->material_snapshot;
        $secured = '0';
        if (Schema::hasTable('erp_assembly_inventory_reservations')) {
            $secured = (string) DB::table('erp_assembly_inventory_reservations as r')->join('erp_assembly_component_demands as d', 'd.id', '=', 'r.component_demand_id')
                ->where('r.work_order_id', $wo->id)->where('d.bom_item_id', $row->bom_item_id)->where('r.status', 'ACTIVE')
                ->selectRaw('COALESCE(SUM(r.reserved_base_qty-r.consumed_base_qty),0) as qty')->value('qty');
        }
        $commitments = $this->procurementTotals($this->activeProcurement()->where('source.preparation_material_requirement_id', $row->id));
        $pending = $commitments['pending'];
        $purchasable = $valid && $row->status === 'ACTIVE' && $item?->status === 'enabled' && $item?->is_purchase_item
            && $item->manufacturing_strategy !== 'make' && ($snapshot['requirement_kind'] ?? 'standard') !== 'stock_continuation' && ! $row->material_requirement_id;
        // Shared free stock is informative only. It is not an ownership fact and cannot
        // be counted as guaranteed coverage for several concurrent preparation rows.
        $available = app(InventoryAvailabilityService::class)->availableBaseQuantities([(int) $row->component_item_id]);
        // Multiple resolved lines of the same Item share a single stock pool. Allocate only
        // a read-only estimate in stable line order; no reservation or execution target is made.
        $earlierRequired = (string) WorkOrderPreparationMaterial::where('preparation_id', $row->preparation_id)
            ->where('preparation_version', $row->preparation_version)->where('status', $row->status)
            ->where('component_item_id', $row->component_item_id)->where('id', '<', $row->id)->sum('required_base_qty');
        $availableForRow = $this->positive(bcsub(number_format((float) ($available[$row->component_item_id] ?? 0), 8, '.', ''), $earlierRequired, 8));
        $quantities = $this->netQuantities((string) $row->required_base_qty, $secured, $availableForRow, $commitments['committed'], $commitments['received']);
        $shortage = $quantities['shortage_qty'];
        return ['id' => (int) $row->id, 'preparation_material_requirement_id' => (int) $row->id,
            'preparation_version' => (int) $row->preparation_version, 'work_order_id' => (int) $wo->id,
            'work_order_no' => $wo->work_order_no, 'work_order_version' => (int) $wo->business_version,
            'demand_stage' => 'preparation', 'fulfillment_mode' => 'procurement_only', 'can_pick' => false,
            'can_procure' => (bool) $purchasable, 'production_target_type' => null, 'production_target_id' => null,
            'target_material_requirement_id' => null, 'material_requirement_id' => $row->material_requirement_id,
            'component_item_id' => (int) $row->component_item_id, 'item_code' => $snapshot['component_item_code_snapshot'],
            'item_name' => $snapshot['component_item_name_snapshot'], 'spec' => $snapshot['component_spec_snapshot'],
            'unit_name' => $snapshot['base_unit_name_snapshot'], 'configuration_id' => $snapshot['configuration_id'],
            'cut_length_mm_snapshot' => $snapshot['cut_length_mm_snapshot'], 'required_piece_qty_snapshot' => $snapshot['required_piece_qty'],
            'required_qty' => (string) $row->required_base_qty, 'secured_qty' => $secured,
            'available_stock_qty' => $availableForRow, 'unsecured_qty' => $quantities['unsecured_qty'],
            'committed_procurement_qty' => $commitments['committed'], 'received_procurement_qty' => $commitments['received'],
            'pending_procurement_qty' => $pending, 'shortage_qty' => $shortage, 'remaining_to_prepare' => $shortage,
            'procureable_qty' => $purchasable ? $quantities['procureable_qty'] : '0.00000000',
            'status' => $valid ? $row->status : 'BLOCKED', 'business_version' => (int) $row->preparation_version];
    }

    public function assertProcurementLocked(WorkOrderPreparationMaterial $row, WorkOrder $wo, array $line): array
    {
        $this->transaction();
        $this->editable($wo);
        if ((int) ($line['work_order_version'] ?? 0) !== (int) $wo->business_version
            || (int) ($line['preparation_version'] ?? 0) !== (int) $row->preparation_version) $this->fail('version_conflict', '工单或物料准备版本已变化，请刷新后重新申购。');
        $result = $this->projection($wo);
        if (! $result['ready'] || $row->status !== 'ACTIVE' || (int) $result['version'] !== (int) $row->preparation_version) $this->fail('preparation_stale', '物料准备已失效，请重新准备后申购。');
        $projection = $this->rowProjection($row, $wo);
        if (! $projection['can_procure']) $this->fail('preparation_not_purchasable', '该准备需求当前不能申购。');
        if (bccomp((string) $line['request_qty'], (string) $projection['procureable_qty'], 8) > 0) $this->fail('procurement_quantity_exceeded', '申购数量超过当前准备需求的未申购缺口。');
        return $projection;
    }

    public function pendingForPreparation(int $id): string
    {
        if (! $this->schemaReady()) return '0.00000000';
        return $this->procurementTotals($this->activeProcurement()->where('source.preparation_material_requirement_id', $id))['committed'];
    }

    /** Count all targets sharing the same frozen requirement together with its pre-release source. */
    public function pendingForFormal(int $id): string
    {
        $query = $this->activeProcurement()->where(function ($q) use ($id): void {
            $q->whereIn('source.target_material_requirement_id', DB::table('erp_production_target_material_requirements')->where('material_requirement_id', $id)->select('id'));
            if ($this->schemaReady()) $q->orWhereIn('source.preparation_material_requirement_id', WorkOrderPreparationMaterial::where('material_requirement_id', $id)->select('id'));
        });
        return $this->procurementTotals($query)['committed'];
    }

    public function formalProcurementBalance(object $source): array
    {
        $pending = $this->pendingForFormal((int) $source->material_requirement_id);
        $targets = DB::table('erp_production_target_material_requirements')->where('material_requirement_id', $source->material_requirement_id)
            ->selectRaw('SUM(required_base_qty) as required, SUM(GREATEST(satisfied_base_qty-returned_base_qty,0)) as satisfied')->first();
        $allocated = DB::table('erp_material_picking_task_lines as line')->join('erp_material_picking_tasks as task', 'task.id', '=', 'line.task_id')
            ->where('line.material_requirement_id', $source->material_requirement_id)->where('task.status', '<>', 'CANCELLED')
            ->sum(DB::raw("CASE WHEN task.status IN ('WAIT_PICK','PICKING') THEN line.planned_pick_qty ELSE GREATEST(line.actual_pick_qty-line.received_qty,0) END"));
        $owned = app(AssemblyProductionInventoryService::class)->totalOwnedQuantity((int) $source->material_requirement_id);
        $remaining = max(0, (float) ($targets->required ?? 0) - (float) ($targets->satisfied ?? 0) - (float) $allocated - (float) $owned - (float) $pending);
        return ['pending_procurement_qty' => $pending, 'procureable_qty' => number_format($remaining, 8, '.', '')];
    }

    private function activeProcurement()
    {
        // Closing releases only the unconverted remainder. Converted procurement remains
        // an obligation; receipt posting changes its in-transit part, not its provenance.
        return DB::table('erp_material_procurement_sources as source')->join('erp_purchase_requests as request', 'request.id', '=', 'source.request_id')
            ->leftJoin('erp_purchase_request_items as request_line', 'request_line.id', '=', 'source.request_item_id')
            ->where('request.request_status', '<>', 'cancelled');
    }

    private function procurementTotals($query): array
    {
        $committed = '0.00000000'; $received = '0.00000000';
        foreach ($query->get(['source.*', 'request.request_status', 'request_line.request_qty as current_qty', 'request_line.converted_qty']) as $source) {
            $quantity = $source->request_status === 'closed' ? (string) ($source->converted_qty ?? $source->requested_base_qty)
                : (string) ($source->current_qty ?? $source->requested_base_qty);
            $committed = bcadd($committed, $quantity, 8);
            if (! $source->request_item_id) continue;
            $posted = (string) DB::table('erp_inventory_transaction_items as movement')
                ->join('erp_inventory_transactions as posting', 'posting.id', '=', 'movement.transaction_id')
                ->join('erp_purchase_receipt_items as receipt', 'receipt.id', '=', 'movement.source_item_id')
                ->join('erp_purchase_order_items as ordered', 'ordered.id', '=', 'receipt.order_item_id')
                ->where('ordered.request_item_id', $source->request_item_id)->where('movement.item_id', $source->component_item_id)
                ->where('posting.source_type', 'purchase_receipt')->where('posting.transaction_type', 'purchase_receipt_posting')
                ->where('posting.posting_status', 'posted')->sum('movement.quantity_change');
            $received = bcadd($received, bccomp($posted, $quantity, 8) < 0 ? $posted : $quantity, 8);
        }
        return ['committed' => $committed, 'received' => $received, 'pending' => $this->positive(bcsub($committed, $received, 8))];
    }

    /** Pure decimal planning arithmetic; public for regression coverage without a database. */
    public function netQuantities(string $required, string $secured, string $available, string $committed, string $received): array
    {
        $unsecured = $this->positive(bcsub($required, $secured, 8));
        $shortage = $this->positive(bcsub($unsecured, $available, 8));
        $pending = $this->positive(bcsub($committed, $received, 8));
        $net = $this->positive(bcsub($shortage, $pending, 8));
        $uncommitted = $this->positive(bcsub($required, $committed, 8));
        return ['unsecured_qty' => $unsecured, 'shortage_qty' => $shortage,
            'procureable_qty' => bccomp($net, $uncommitted, 8) < 0 ? $net : $uncommitted];
    }

    public function materialIdentity(array $row): string
    {
        // The same BOM template may resolve to several cut dimensions. line_no is the
        // resolver's stable occurrence identity; quantity/spec changes belong to a version.
        return $this->hash(['bom_item_id' => isset($row['bom_item_id']) ? (int) $row['bom_item_id'] : null,
            'line_no' => (int) $row['line_no'], 'component_item_id' => (int) $row['component_item_id']]);
    }

    private function calculate(WorkOrder $wo): array
    {
        $wo->loadMissing('demand.line', 'demand.order');
        $demand = $wo->demand;
        $source = $demand;
        if ($wo->assembly_root_work_order_id) $source = WorkOrder::with('demand')->find($wo->assembly_root_work_order_id)?->demand;
        if (($wo->source_type === 'sales_order' || $wo->assembly_root_work_order_id) && (! $source || ! $source->is_active || in_array($source->requirement_status, ['cancelled', 'closed', 'superseded'], true))) $this->fail('demand_inactive', '来源生产需求已失效，不能继续物料准备。');
        $configuration = app(WorkOrderTechnicalService::class)->effectiveConfiguration($wo);
        $match = app(BomMatcher::class)->match((int) ($demand?->product_id ?: $demand?->line?->product_id) ?: null,
            (int) ($demand?->sku_id ?: $demand?->line?->sku_id) ?: null, (int) $wo->output_item_id ?: null,
            $configuration, (int) ($wo->bom_id ?: $demand?->bom_id) ?: null);
        if (($match['status'] ?? null) !== 'matched') $this->fail('bom_not_matched', (string) ($match['block_reason'] ?? '未匹配到唯一有效 BOM。'));
        $bom = Bom::with(['items.componentItem.unit', 'items.unit', 'outputItem.unit'])->find($match['bom_id']);
        if (! $bom || $bom->audit_status !== 'approved' || ! in_array($bom->status, ['active', 'enabled', 'published'], true)
            || ($bom->effective_date && $bom->effective_date->toDateString() > now()->toDateString())
            || ($bom->expire_date && $bom->expire_date->toDateString() < now()->toDateString())) $this->fail('bom_not_effective', '物料准备必须使用当前有效的已审核 BOM。');
        if (app(WorkOrderTechnicalService::class)->requiresConfirmation($wo, $bom) && ($wo->technical_version < 1
            || (int) data_get($wo->technical_snapshot, 'bom_id') !== (int) $wo->bom_id
            || (int) data_get($wo->technical_snapshot, 'production_routing_id') !== (int) $wo->production_routing_id)) $this->fail('technical_confirmation_required', '定制用料必须先完成工单技术确认。');
        $rows = app(ReleaseGateApplicationService::class)->buildMaterialRows($wo, $bom, $configuration);
        $rows = app(ProductionInventoryContinuationService::class)->materialRows($wo, $rows);
        if ($rows === []) $this->fail('bom_incomplete', '物料准备不能使用空 BOM。');
        foreach ($rows as $row) {
            $item = Item::find($row['component_item_id']);
            if (! $item || $item->managementScope() !== 'factory' || $item->status !== 'enabled' || ! $row['base_unit_id'] || (float) $row['base_required_qty'] <= 0) $this->fail('preparation_material_invalid', '物料准备包含无效物料、单位或需求数量。');
        }
        return ['bom_id' => (int) $bom->id, 'bom_version' => $bom->version,
            'technical_version' => (int) $wo->technical_version, 'technical_snapshot' => $wo->technical_snapshot,
            'routing_snapshot' => $wo->routing_snapshot, 'output_item_id' => (int) $wo->output_item_id,
            'inventory_continuation_plan' => $wo->inventory_continuation_plan,
            'target_base_qty' => number_format((float) $wo->target_base_qty, 8, '.', ''),
            'rows' => array_map(fn ($row) => $this->materialFacts($row), $rows)];
    }

    private function materialFacts(array $row): array
    {
        $fields = ['line_no', 'bom_id', 'bom_item_id', 'component_item_id', 'configuration_id', 'configuration_snapshot',
            'component_item_code_snapshot', 'component_item_name_snapshot', 'component_spec_snapshot', 'cut_length_mm_snapshot',
            'cutting_requirement_snapshot', 'per_output_piece_qty', 'required_piece_qty', 'unit_id', 'unit_name_snapshot',
            'per_output_qty', 'loss_rate', 'fixed_qty', 'required_qty', 'base_unit_id', 'base_unit_name_snapshot', 'base_required_qty',
            'requirement_kind', 'remaining_supply_snapshot'];
        $facts = [];
        foreach ($fields as $key) {
            $value = $row[$key] ?? ($key === 'requirement_kind' ? 'standard' : null);
            if (in_array($key, ['configuration_snapshot', 'cutting_requirement_snapshot', 'remaining_supply_snapshot'], true) && is_string($value)) $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            if ($value !== null && (str_ends_with($key, '_qty') || in_array($key, ['loss_rate', 'cut_length_mm_snapshot'], true))) $value = is_float($value) ? number_format($value, 8, '.', '') : bcadd((string) $value, '0', 8);
            if ($value !== null && (str_ends_with($key, '_id') || $key === 'line_no')) $value = (int) $value;
            $facts[$key] = $value;
        }
        return $facts;
    }

    private function editable(WorkOrder $wo): void
    {
        if ($wo->released_at || ! in_array($wo->status, ['DRAFT', 'WAIT_RELEASE'], true)) $this->fail('invalid_state', '只有未发布的有效工单可进行物料准备或申购。');
    }
    private function transaction(): void { if (DB::transactionLevel() < 1) $this->fail('transaction_required', '物料准备必须在锁定工单的事务内执行。'); }
    private function positive(string $qty): string { return bccomp($qty, '0', 8) > 0 ? $qty : '0.00000000'; }
    private function json(array $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE); }
    private function hash(array $value): string { return hash('sha256', $this->json($value)); }
    private function unavailable(string $code, string $message): array { return ['status' => $code, 'ready' => false, 'issues' => [['code' => $code, 'message' => $message]], 'version' => null, 'rows' => []]; }
    private function fail(string $code, string $message): never { throw new WorkOrderDomainException($code, $message, 409); }
}
