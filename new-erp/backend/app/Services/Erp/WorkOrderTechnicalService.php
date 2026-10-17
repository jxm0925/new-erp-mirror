<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\Bom;
use App\Models\Erp\Item;
use App\Models\Erp\ProductionRouting;
use App\Models\Erp\WorkOrder;
use Illuminate\Support\Facades\DB;

/** 技术确认由工单命令事务调用；版本只追加，不能覆盖已发布执行事实。 */
final class WorkOrderTechnicalService
{
    public function preview(WorkOrder $workOrder, ?int $bomId = null): array
    {
        if ($bomId === null && $workOrder->technical_snapshot) return $workOrder->technical_snapshot;
        if ($bomId === null && ! in_array($workOrder->status, ['DRAFT', 'WAIT_RELEASE'], true)) {
            // 旧工单没有技术版本记录时仍读取正式需求快照，禁止用当前主数据伪造历史。
            return [
                'bom_id' => $workOrder->bom_id, 'bom_snapshot' => $workOrder->bom_snapshot,
                'production_routing_id' => $workOrder->production_routing_id, 'routing_snapshot' => $workOrder->routing_snapshot,
                'output_configuration' => null, 'output_is_custom' => false, 'drawing_reference' => null,
                'materials' => $workOrder->materialRequirements->map(fn ($row) => [
                    'bom_item_id' => (int) $row->bom_item_id, 'component_item_id' => (int) $row->component_item_id,
                    'component_item_code' => $row->component_item_code_snapshot,
                    'component_item_name' => $row->component_item_name_snapshot,
                    'spec' => $row->component_spec_snapshot, 'per_output_qty' => (string) $row->per_output_qty,
                    'unit_name' => $row->unit_name_snapshot, 'is_custom_item' => (bool) $row->configuration_id,
                    'configuration' => $row->configuration_snapshot,
                    'cutting_mode' => $row->cutting_requirement_snapshot['quantity_calculation']['mode'] ?? 'none',
                    'cut_dimensions' => $row->cutting_requirement_snapshot['quantity_calculation']['dimensions'] ?? $row->cutting_requirement_snapshot['dimensions'] ?? null,
                    'cutting_quantity_snapshot' => $row->cutting_requirement_snapshot['quantity_calculation'] ?? null,
                ])->all(),
            ];
        }
        $bom = Bom::with(['items.componentItem', 'items.unit'])->find($bomId ?: $workOrder->bom_id);
        $sourceLine = $workOrder->demand?->line;
        if ($bom && ((int) $bom->output_item_id !== (int) $workOrder->output_item_id
            || ($bom->product_id && (int) $bom->product_id !== (int) $sourceLine?->product_id)
            || ($bom->sku_id && (int) $bom->sku_id !== (int) $sourceLine?->sku_id))) {
            $this->fail('technical_bom_invalid', 'BOM 产出与本工单不一致。');
        }
        return [
            'bom_id' => $bom?->id,
            'bom_snapshot' => $bom ? ['bom_no' => $bom->bom_no, 'bom_name' => $bom->bom_name, 'version' => $bom->version] : null,
            'production_routing_id' => $workOrder->production_routing_id,
            'routing_snapshot' => $workOrder->routing_snapshot,
            'output_configuration' => null,
            'output_is_custom' => (bool) $workOrder->outputItem?->is_custom_item,
            'materials' => $bom?->items->map(fn ($row) => [
                'bom_item_id' => (int) $row->id, 'component_item_id' => (int) $row->component_item_id,
                'component_item_code' => $row->componentItem?->item_code,
                'component_item_name' => $row->componentItem?->item_name,
                'spec' => $row->componentItem?->spec, 'is_custom_item' => (bool) $row->componentItem?->is_custom_item,
                'per_output_qty' => (string) $row->qty, 'unit_name' => $row->unit?->unit_name, 'configuration' => null,
                'cutting_mode' => $row->componentItem?->cuttingMode() ?? 'none',
                'cut_dimensions' => $this->bomCutDimensions($row),
                'cutting_quantity_snapshot' => $row->cutting_quantity_snapshot,
                'unit' => $row->unit?->toArray(),
            ])->all() ?? [],
            'drawing_reference' => null,
        ];
    }

    public function options(WorkOrder $workOrder, string $type, array $filters): array
    {
        $workOrder->loadMissing('demand.line');
        $line = $workOrder->demand?->line;
        $itemId = (int) ($filters['item_id'] ?? $workOrder->output_item_id);
        if ($type === 'configurations') {
            $bomId = (int) ($filters['bom_id'] ?? $workOrder->bom_id);
            $allowed = $itemId === (int) $workOrder->output_item_id || Bom::whereKey($bomId)
                ->where('output_item_id', $workOrder->output_item_id)
                ->whereHas('items', fn ($q) => $q->where('component_item_id', $itemId))->exists();
            if (! $allowed) $this->fail('technical_item_scope', '只能选择本工单产出或 BOM 用料的配置。');
            $query = DB::table('erp_custom_configurations as c')->join('erp_items as i', 'i.id', '=', 'c.item_id')
                ->where('c.item_id', $itemId)->where('c.status', 'PUBLISHED')
                ->where(fn ($q) => $q->where('c.scope_mode', 'PUBLIC')->orWhere(fn ($restricted) => $restricted->where('c.scope_mode', 'RESTRICTED')->whereExists(fn ($scope) => $scope->selectRaw('1')
                    ->from('erp_custom_configuration_scopes as s')->whereColumn('s.configuration_id', 'c.id')
                    ->where('s.source_type', 'work_order')->where('s.source_id', $workOrder->id))))
                ->select('c.*', 'i.item_name', 'i.item_code', 'i.spec', 'i.category_id');
            $number = 'c.configuration_no'; $name = 'c.drawing_reference'; $order = 'c.id';
        } else {
            $query = match ($type) {
                'boms' => Bom::query()->where('audit_status', 'approved')->whereIn('status', ['active', 'enabled', 'published'])
                    ->where(fn ($q) => $q->whereNull('effective_date')->orWhere('effective_date', '<=', now()->toDateString()))
                    ->where(fn ($q) => $q->whereNull('expire_date')->orWhere('expire_date', '>=', now()->toDateString())),
                'routings' => ProductionRouting::query()->with(['operations.operation', 'operations.stage', 'operations.packagingScheme'])->where('status', 'active'),
                default => $this->fail('technical_option_type', '不支持的生产资料类型。'),
            };
            $query->where('output_item_id', $workOrder->output_item_id)
                ->where(fn ($q) => $q->whereNull('product_id')->when($line?->product_id, fn ($q, $id) => $q->orWhere('product_id', $id)))
                ->where(fn ($q) => $q->whereNull('sku_id')->when($line?->sku_id, fn ($q, $id) => $q->orWhere('sku_id', $id)));
            $number = $type === 'boms' ? 'bom_no' : 'routing_no';
            $name = $type === 'boms' ? 'bom_name' : 'routing_name'; $order = 'id';
        }
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) {
            $query->where(function ($q) use ($number, $name, $keyword, $type): void {
                $q->where($number, 'like', "%{$keyword}%")->orWhere($name, 'like', "%{$keyword}%");
                if ($type === 'configurations') $q->orWhere('i.item_code', 'like', "%{$keyword}%")
                    ->orWhere('i.item_name', 'like', "%{$keyword}%")->orWhere('i.spec', 'like', "%{$keyword}%")
                    ->orWhere('c.dimensions', 'like', "%{$keyword}%");
                else $q->orWhere('version', 'like', "%{$keyword}%");
            });
        }
        if (! empty($filters['category_id'])) {
            if ($type === 'configurations') $query->where('i.category_id', (int) $filters['category_id']);
            else $query->whereHas('outputItem', fn ($q) => $q->where('category_id', (int) $filters['category_id']));
        }
        $rows = $query->orderByDesc($order)->paginate(max(1, min(100, (int) ($filters['per_page'] ?? 10))));
        $rows->getCollection()->transform(function ($row) use ($type) {
            $value = (array) ($row instanceof \Illuminate\Database\Eloquent\Model ? $row->toArray() : $row);
            if ($type === 'configurations') $value['dimensions'] = json_decode($value['dimensions'], true, 512, JSON_THROW_ON_ERROR);
            if ($type === 'routings') {
                // 候选只展示可选择的真实工序，不泄露技术选择权限之外的绩效比例和包材成本。
                // 完整执行事实仍在确认时由权威路线服务重新校验并冻结。
                $value['operations'] = $row->operations->sortBy('sequence')->map(fn ($node) => [
                    'routing_operation_id' => (int) $node->id,
                    'operation_id' => (int) $node->operation_id,
                    'operation_no' => $node->operation?->operation_no,
                    'operation_name' => $node->operation?->operation_name,
                    'sequence' => (int) $node->sequence,
                    'execution_context' => $node->execution_context ?: 'production',
                    'stage_name' => $node->stage?->name,
                    'packaging_scheme_name' => $node->packagingScheme?->name,
                ])->values()->all();
            }
            return $value;
        });
        return $rows->toArray();
    }

    public function prepare(WorkOrder $workOrder, array $payload): array
    {
        $workOrder->loadMissing(['demand.line', 'outputItem']);
        if (! $workOrder->outputItem || $workOrder->outputItem->status !== 'enabled') {
            $this->fail('technical_output_disabled', '工单产出物料不存在或已停用。');
        }
        $line = $workOrder->demand?->line;
        $bom = Bom::with(['items.componentItem', 'items.unit'])->lockForUpdate()->find((int) ($payload['bom_id'] ?? 0));
        $match = app(BomMatcher::class)->match(
            $line?->product_id, $line?->sku_id, (int) $workOrder->output_item_id, null, $bom?->id ?? 0,
        );
        if (! $bom || $match['status'] !== 'matched' || $bom->items->isEmpty()) {
            $this->fail('technical_bom_invalid', '请选择适用于本工单产出的已审核、生效 BOM。');
        }
        $routing = ProductionRouting::query()->lockForUpdate()->find((int) ($payload['production_routing_id'] ?? 0));
        if (! $routing || $routing->status !== 'active' || (int) $routing->output_item_id !== (int) $workOrder->output_item_id
            || ($routing->product_id && (int) $routing->product_id !== (int) $line?->product_id)
            || ($routing->sku_id && (int) $routing->sku_id !== (int) $line?->sku_id)) {
            $this->fail('technical_routing_invalid', '请选择适用于本工单产出的已启用工艺路线。');
        }
        // 备货工序终点是创建时的用途契约，技术修订不得借换路线改变其产出。
        if ($workOrder->source_type === 'stock_prebuild' && (int) $routing->id !== (int) $workOrder->production_routing_id) {
            $this->fail('technical_prebuild_routing_frozen', '备货工单不能更换已经确定目标工序的路线。');
        }
        $routingSnapshot = app(ProductionMasterDataService::class)->snapshot($routing);
        if (empty($routingSnapshot['operations'])) $this->fail('technical_routing_empty', '工艺路线必须包含工序。');
        $output = $this->configuration($workOrder, $workOrder->outputItem, $payload['output_configuration_id'] ?? null);
        $rows = collect($payload['materials'] ?? []);
        if ($rows->count() !== $rows->pluck('bom_item_id')->unique()->count()
            || $rows->pluck('bom_item_id')->diff($bom->items->pluck('id'))->isNotEmpty()) {
            $this->fail('technical_material_invalid', '用料配置必须引用本 BOM 的唯一物料行。');
        }
        $materials = $bom->items->map(function ($item) use ($rows, $workOrder): array {
            $row = $rows->firstWhere('bom_item_id', $item->id) ?? [];
            if (! $item->componentItem || $item->componentItem->status !== 'enabled') {
                $this->fail('technical_material_disabled', 'BOM 用料包含已停用或不存在的物料。');
            }
            return [
                'bom_item_id' => (int) $item->id,
                'component_item_id' => (int) $item->component_item_id,
                'component_item_code' => $item->componentItem->item_code,
                'component_item_name' => $item->componentItem->item_name,
                'spec' => $item->componentItem->spec,
                'is_custom_item' => (bool) $item->componentItem->is_custom_item,
                'per_output_qty' => (string) $item->qty,
                'unit_name' => $item->unit?->unit_name,
                'configuration' => $this->configuration($workOrder, $item->componentItem, $row['configuration_id'] ?? null),
                'cutting_mode' => $item->componentItem->cuttingMode(),
                'cut_dimensions' => $row['cut_dimensions'] ?? $this->bomCutDimensions($item),
                'unit' => $item->unit?->toArray(),
            ];
        })->all();
        $drawing = trim((string) ($payload['drawing_reference'] ?? $output['drawing_reference'] ?? ''));
        if (($line?->is_special_customized || $workOrder->outputItem?->is_custom_item) && $drawing === '') {
            $this->fail('technical_drawing_required', '定制生产必须由技术岗位确认图纸版本。');
        }
        $configurationSnapshot = $this->cuttingConfiguration($bom, collect($materials));
        $configurationSnapshot['stock_bom_lines'] = collect($materials)->filter(fn ($row) => $row['configuration'] !== null)
            ->map(fn ($row) => ['bom_item_id' => $row['bom_item_id'], 'configuration' => $row['configuration']])->values()->all();
        $resolvedBom = app(BomMatcher::class)->match(
            $line?->product_id, $line?->sku_id, (int) $workOrder->output_item_id, $configurationSnapshot, $bom->id,
        );
        $resolvedLines = collect($resolvedBom['bom_snapshot']['items'] ?? [])->keyBy('id');
        $materials = collect($materials)->map(function (array $row) use ($resolvedLines): array {
            $resolved = $resolvedLines->get($row['bom_item_id']);
            $row['per_output_qty'] = $resolved['qty'] ?? $row['per_output_qty'];
            $row['cutting_quantity_snapshot'] = $resolved['cutting_quantity_snapshot'] ?? null;
            $row['cut_dimensions'] = $resolved['cutting_quantity_snapshot']['dimensions'] ?? null;
            if (FrozenCuttingStockPolicy::isTotalLength($row['cutting_quantity_snapshot'] ?? [])) {
                $row['cut_dimensions'] = ['quantity_basis' => 'total_length', 'total_length_mm' => $row['cutting_quantity_snapshot']['total_length_mm']];
            }
            return $row;
        })->all();
        return [
            'bom_id' => (int) $bom->id, 'bom_snapshot' => $resolvedBom['bom_snapshot'],
            'production_routing_id' => (int) $routing->id, 'routing_snapshot' => $routingSnapshot,
            'output_configuration' => $output, 'materials' => $materials,
            'output_is_custom' => (bool) $workOrder->outputItem?->is_custom_item,
            'drawing_reference' => $drawing ?: null,
            'configuration_snapshot' => $configurationSnapshot,
        ];
    }

    private function cuttingConfiguration(Bom $bom, \Illuminate\Support\Collection $materials): array
    {
        $lines = [];
        foreach ($bom->items as $row) {
            $mode = $row->componentItem?->cuttingMode();
            $material = $materials->firstWhere('bom_item_id', (int) $row->id);
            $dimensions = $material['cut_dimensions'] ?? null;
            if (! in_array($mode, ['length', 'sheet'], true)) {
                if ($dimensions !== null) $this->fail('technical_cutting_material_invalid', '只有下料原材料可以维护加工尺寸。');
                continue;
            }
            if (is_array($dimensions) && ($dimensions['quantity_basis'] ?? null) === 'total_length') {
                if ($mode !== 'length' || ! FrozenCuttingStockPolicy::positiveDecimal($dimensions['total_length_mm'] ?? null)
                    || isset($dimensions['length_mm']) || isset($dimensions['piece_qty']) || isset($dimensions['width_mm'])
                    || isset($dimensions['thickness_mm']) || ! empty($dimensions['allow_rotation'])) {
                    $this->fail('technical_cutting_total_length_invalid', '管材总长度必须明确，不能同时维护单段尺寸或件数。');
                }
                $lines[] = ['bom_item_id' => (int) $row->id, 'cutting_quantity_basis' => 'total_length',
                    'total_length_mm' => $dimensions['total_length_mm'], 'cut_length_mm' => null, 'piece_qty' => null,
                    'cut_width_mm' => null, 'cut_thickness_mm' => null, 'allow_cut_rotation' => false];
                continue;
            }
            if (! is_array($dimensions) || ! isset($dimensions['length_mm'], $dimensions['piece_qty'])) {
                $this->fail('technical_cutting_dimensions_required', '每条下料明细必须填写加工长度和件数。');
            }
            // Finished product dimensions never substitute a component's own dimensions.
            $lines[] = ['bom_item_id' => (int) $row->id,
                'cutting_quantity_basis' => 'dimensions_pieces', 'total_length_mm' => null,
                'cut_length_mm' => $dimensions['length_mm'],
                'cut_width_mm' => $mode === 'sheet' ? ($dimensions['width_mm'] ?? null) : null,
                'cut_thickness_mm' => $mode === 'sheet' ? ($dimensions['thickness_mm'] ?? null) : null,
                'piece_qty' => $dimensions['piece_qty'],
                'allow_cut_rotation' => $mode === 'sheet' && (bool) ($dimensions['allow_rotation'] ?? false),
            ];
        }
        return $lines === [] ? [] : ['technical_bom_lines' => $lines];
    }

    private function bomCutDimensions(object $row): ?array
    {
        if (! in_array($row->componentItem?->cuttingMode(), ['length', 'sheet'], true)) return null;
        if (FrozenCuttingStockPolicy::isTotalLength($row->cutting_quantity_snapshot ?? [])) {
            return ['quantity_basis' => 'total_length', 'total_length_mm' => $row->cutting_quantity_snapshot['total_length_mm']];
        }
        return ['length_mm' => $row->cut_length_mm, 'width_mm' => $row->cut_width_mm,
            'thickness_mm' => $row->cut_thickness_mm, 'piece_qty' => $row->piece_qty,
            'allow_rotation' => (bool) $row->allow_cut_rotation];
    }

    public function requiresConfirmation(WorkOrder $workOrder, ?Bom $bom): bool
    {
        return (bool) ($workOrder->outputItem?->is_custom_item || $workOrder->demand?->line?->is_special_customized
            || $bom?->items->contains(fn ($row) => (bool) $row->componentItem?->is_custom_item));
    }

    public function confirmationCurrent(WorkOrder $workOrder, ?Bom $bom): bool
    {
        // 配置解析可能拆行或只取部分模板；无明细快照的新备货和关联子工单保留原确认规则。
        $frozenItemIds = collect(data_get($workOrder->bom_snapshot, 'items', []))->pluck('id')
            ->map(fn ($id) => (int) $id)->unique();
        // shortcut: 依赖 BOM 编辑整体替换明细 ID；若改为原行更新，需增加明细修订标识。
        if ($frozenItemIds->isNotEmpty() && $frozenItemIds->diff($bom?->items->pluck('id') ?? [])->isNotEmpty()) return false;

        return ! $this->requiresConfirmation($workOrder, $bom) || (
            $workOrder->technical_version > 0
            && (int) data_get($workOrder->technical_snapshot, 'bom_id') === (int) $workOrder->bom_id
            && (int) data_get($workOrder->technical_snapshot, 'production_routing_id') === (int) $workOrder->production_routing_id
        );
    }

    public function effectiveConfiguration(WorkOrder $workOrder): ?array
    {
        // 技术岗位确认后仅使用该不可变版本，禁止旧销售下料参数覆盖生产规格。
        if ($workOrder->technical_version > 0) {
            $configuration = data_get($workOrder->technical_snapshot, 'configuration_snapshot', []);
            // 原料配置与产出配置各自冻结，原料长宽不能从产出裁切尺寸推断。
            $configuration['stock_bom_lines'] = collect(data_get($workOrder->technical_snapshot, 'materials', []))
                ->filter(fn ($row) => ! empty($row['configuration']))
                ->map(fn ($row) => ['bom_item_id' => $row['bom_item_id'], 'configuration' => $row['configuration']])->values()->all();
            return $configuration;
        }
        return $workOrder->demand?->configuration_snapshot ?: $workOrder->demand?->line?->configuration_snapshot;
    }

    private function configuration(WorkOrder $workOrder, ?Item $item, mixed $id): ?array
    {
        if (! $item) $this->fail('technical_item_missing', '生产资料引用的物料不存在。');
        app(ItemManagementScopeService::class)->assertProductionAllowed($item, 'materials');
        if (! $item->is_custom_item) {
            if ($id) $this->fail('technical_standard_configuration', '标准物料使用固定规格，不能指定定制配置。');
            return null;
        }
        $row = DB::table('erp_custom_configurations')->where('id', (int) $id)->lockForUpdate()->first();
        if (! $row || $row->status !== 'PUBLISHED' || (int) $row->item_id !== (int) $item->id) {
            $this->fail('technical_configuration_required', "{$item->item_name} 必须选择该物料已发布的配置版本。");
        }
        if ($row->scope_mode !== 'PUBLIC' && ($row->scope_mode !== 'RESTRICTED' || ! DB::table('erp_custom_configuration_scopes')
            ->where('configuration_id', $row->id)->where('source_type', 'work_order')->where('source_id', $workOrder->id)->exists())) {
            $this->fail('technical_configuration_scope', '所选配置版本不适用于本工单。');
        }
        return [
            'id' => (int) $row->id, 'configuration_no' => $row->configuration_no,
            'version_no' => (int) $row->version_no, 'item_id' => (int) $row->item_id,
            'dimensions' => json_decode($row->dimensions, true, 512, JSON_THROW_ON_ERROR),
            'drawing_reference' => $row->drawing_reference,
        ];
    }

    private function fail(string $code, string $message): never
    {
        throw new WorkOrderDomainException($code, $message, 422);
    }
}
