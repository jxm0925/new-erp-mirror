<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\Item;
use App\Models\Erp\ItemCategory;
use App\Models\Erp\SalesOrderFulfillment;
use App\Models\Erp\SalesOrderLine;
use App\Models\Erp\Unit;
use App\Services\Erp\Manufacturing\WorkOrderPlanBuilder;
use App\Services\Erp\Manufacturing\WorkOrderService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final class SalesProductionBomService
{
    public function __construct(
        private readonly BomMatcher $matcher,
        private readonly WorkOrderPlanBuilder $plans,
        private readonly UnitConversionDomainService $units,
        private readonly ItemCategoryApplicationService $categories,
    ) {}

    public function preview(SalesOrderLine $line, float $baseQty): array
    {
        try {
            $resolved = $this->resolve($line, $baseQty);
            return ['detail' => $this->detail($resolved['snapshot'], $baseQty), 'block_reason' => $resolved['block_reason']];
        } catch (ValidationException|WorkOrderDomainException $error) {
            return ['detail' => null, 'block_reason' => $error->getMessage()];
        }
    }

    public function confirm(SalesOrderLine $line, float $baseQty, array $decision): array
    {
        $replacements = $decision['bom_replacements'] ?? [];
        $reason = trim((string) ($decision['bom_adjustment_reason'] ?? ''));
        if (! $replacements) return $this->resolve($line, $baseQty, true);
        if ($baseQty <= 0) $this->invalid('有替换物料时，本次工单生产数量必须大于零。');
        if ($reason === '') $this->invalid('请填写本单 BOM 的替换原因。');

        $resolved = $this->resolve($line, $baseQty, true);
        $this->assertRevision($resolved['snapshot'], $decision['bom_revision'] ?? null);
        $snapshot = $resolved['snapshot'];
        $positions = collect($snapshot['items'])->keyBy('sales_position_key');
        $selected = [];
        foreach ($replacements as $replacement) {
            $key = $replacement['position_key'];
            if (isset($selected[$key])) $this->invalid('同一 BOM 位置只能选择一项替换物料。');
            $position = $positions->get($key);
            if (! $position) $this->invalid('所选位置不在当前销售行的标准 BOM 中，请重新选择。');
            $this->assertPosition($position);
            $selected[$key] = (int) $replacement['replacement_item_id'];
        }

        // 多个位置按统一物料顺序加锁，避免同批验证和随后建工单读到不同物料状态。
        $items = $this->materialQuery()->with(['unit' => fn ($q) => $q->lockForUpdate(), 'unit.standardUnit' => fn ($q) => $q->lockForUpdate()])
            ->whereKey(array_values($selected))->orderBy('erp_items.id')->lockForUpdate()->get()->keyBy('id');
        foreach ($snapshot['items'] as &$position) {
            $id = $selected[$position['sales_position_key']] ?? null;
            if ($id === null) continue;
            $item = $items->get($id);
            $unit = $this->units->canonicalUnit($item?->unit);
            if (! $item || $id === (int) $line->item_id || $id === (int) $position['component_item_id']
                || (int) $unit?->id !== (int) $position['sales_unit_id']) {
                $this->invalid('所选物料已不可替换或库存基本单位与标准物料不一致，请重新选择。');
            }
            $position['original_component'] = $position['component_snapshot'];
            $position['component_snapshot'] = $this->component($item);
            $position['component_item_id'] = $id;
            $position['component_item_code'] = $item->item_code;
            $position['component_item_name'] = $item->item_name;
            $position['unit_id'] = $item->unit_id;
        }
        unset($position);
        $snapshot['sales_confirmation']['adjustment_reason'] = $reason;
        $snapshot['sales_confirmation']['has_replacements'] = true;
        return ['snapshot' => $snapshot, 'plan' => $this->plans->applyConfirmedMaterials($resolved['plan'], $snapshot, $baseQty), 'block_reason' => null];
    }

    public function candidates(SalesOrderLine $line, array $input): array
    {
        $allocated = $line->fulfillments()->where('demand_status', 'confirmed')->get()
            ->sum(fn (SalesOrderFulfillment $row) => $row->sales_qty !== null ? (float) $row->sales_qty
                : (float) $row->fulfillment_qty / ((float) $line->fulfillment_factor_snapshot ?: 1));
        if (! SalesOrderFulfillmentPolicy::isPhysical($line->line_type) || SalesOrderFulfillmentPolicy::isPurchaseLine($line)
            || $allocated >= (float) $line->order_qty - (float) $line->cancelled_qty - 0.00000001) {
            $this->invalid('此销售行没有待确认的生产数量。');
        }
        $resolved = $this->resolve($line, 0);
        $this->assertRevision($resolved['snapshot'], $input['bom_revision']);
        $position = collect($resolved['snapshot']['items'])->firstWhere('sales_position_key', $input['position_key']);
        if (! $position) $this->invalid('所选位置不在当前销售行的标准 BOM 中。');
        $this->assertPosition($position);

        $query = $this->materialQuery()->whereKeyNot((int) $position['component_item_id'])->whereKeyNot((int) $line->item_id)
            ->whereHas('unit', fn ($units) => $units->where(fn ($unit) => $unit->whereKey((int) $position['sales_unit_id'])
                ->orWhere(fn ($legacy) => $legacy->where('is_legacy', true)->where('standard_unit_id', (int) $position['sales_unit_id']))));
        $categoryId = (int) ($input['category_id'] ?? 0);
        if ($categoryId) $query->whereIn('category_id', [$categoryId, ...$this->categories->descendantIds($categoryId)]);
        $keyword = trim((string) ($input['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(fn ($items) => $items->where('item_code', 'like', "%{$keyword}%")->orWhere('item_name', 'like', "%{$keyword}%")
                ->orWhere('spec', 'like', "%{$keyword}%")->orWhere('model', 'like', "%{$keyword}%"));
        }
        $page = $query->orderBy('item_code')->orderBy('id')->paginate((int) ($input['per_page'] ?? 10), ['*'], 'page', (int) ($input['page'] ?? 1));
        $categories = ItemCategory::query()->where('management_scope', 'factory')->where('status', 'enabled')
            ->orderBy('sort_order')->orderBy('id')->get(['id', 'parent_id', 'category_name']);
        $tree = $categories->groupBy(fn (ItemCategory $category) => $category->parent_id ?: 0);
        $treeNodes = function (int $parent) use (&$treeNodes, $tree): array {
            return $tree->get($parent, collect())->map(fn (ItemCategory $category) => [
                'id' => (int) $category->id, 'name' => $category->category_name,
                'parent_id' => $category->parent_id ? (int) $category->parent_id : null, 'children' => $treeNodes((int) $category->id),
            ])->values()->all();
        };

        return [
            'data' => $page->getCollection()->map(fn (Item $item) => $this->component($item) + ['category_id' => $item->category_id])->all(),
            'total' => $page->total(), 'current_page' => $page->currentPage(), 'per_page' => $page->perPage(),
            'categories' => $treeNodes(0),
        ];
    }

    public function history(SalesOrderFulfillment $fulfillment): array
    {
        $snapshot = data_get($fulfillment->match_snapshot, 'production_bom');
        $baseQty = (float) data_get($fulfillment->match_snapshot, 'item_base_qty', $fulfillment->item_base_qty ?? $fulfillment->fulfillment_qty);
        return [
            'fulfillment_id' => (int) $fulfillment->id,
            'demand_status' => $fulfillment->demand_status,
            'production_qty' => (float) data_get($fulfillment->match_snapshot, 'sales_qty', $fulfillment->sales_qty),
            'production_base_qty' => $baseQty,
            'sales_unit' => $fulfillment->sales_unit_name_snapshot,
            'base_unit' => $fulfillment->base_unit_name_snapshot,
            'confirmed_at' => data_get($fulfillment->match_snapshot, 'planning.confirmed_at') ?? $fulfillment->created_at?->toDateTimeString(),
            'confirmed_by' => data_get($fulfillment->match_snapshot, 'planning.confirmed_by'),
            'bom_detail' => $this->detail($snapshot, $baseQty, true),
        ];
    }

    private function resolve(SalesOrderLine $line, float $baseQty, bool $lock = false): array
    {
        $match = $this->matcher->match($line->product_id, $line->sku_id, $line->item_id, $line->configuration_snapshot, null, $lock);
        if (! $line->item || $match['status'] !== 'matched') return ['snapshot' => null, 'plan' => null, 'block_reason' => $match['block_reason']];

        $snapshot = $this->plans->freezeBom($match['bom_snapshot'], (int) $line->item_id, $lock);
        $plan = $this->plans->build($line->item, $line->product_id, $line->sku_id, $line->configuration_snapshot, $baseQty, null, null, $snapshot);
        $operationNames = collect($plan['operations'])->pluck('operation_name', 'key');
        $materialOperations = collect($plan['materials'])->groupBy('bom_item_id');
        $items = Item::query()->with('unit.standardUnit')->whereKey(array_values(array_unique(array_column($snapshot['items'], 'component_item_id'))))
            ->when($lock, fn ($q) => $q->with(['unit' => fn ($unit) => $unit->lockForUpdate(), 'unit.standardUnit' => fn ($unit) => $unit->lockForUpdate()]))
            ->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get()->keyBy('id');
        $bomUnits = Unit::query()->with('standardUnit')->whereKey(array_values(array_unique(array_filter(array_column($snapshot['items'], 'unit_id')))))
            ->when($lock, fn ($q) => $q->lockForUpdate()->with(['standardUnit' => fn ($unit) => $unit->lockForUpdate()]))->get()->keyBy('id');

        $routingIdentity = collect($plan['operations'])->map(fn (array $op) => array_intersect_key($op, array_flip([
            'routing_operation_id', 'operation_id', 'sequence', 'execution_context', 'operation_name', 'material_supply_rules',
        ])))->all();
        foreach ($snapshot['items'] as &$position) {
            $item = $items->get((int) $position['component_item_id']);
            $unit = $this->units->canonicalUnit($item?->unit);
            $bomUnit = $this->units->canonicalUnit($bomUnits->get($position['unit_id'] ?? 0)) ?? $unit;
            // 配置下料可重复引用原行，保留配置序号以区分这些用料位置。
            $position['sales_position_key'] = ($position['position_path'] ?? $position['position_uid'] ?? $position['id'])
                .':'.$position['id'].(isset($position['configuration_requirement_index']) ? ':'.$position['configuration_requirement_index'] : '');
            $position['component_snapshot'] = $item ? $this->component($item) : [
                'id' => (int) $position['component_item_id'], 'item_code' => $position['component_item_code'],
                'item_name' => $position['component_item_name'], 'spec' => null, 'unit_name' => $bomUnit?->unit_name,
            ];
            $position['sales_unit_id'] = $unit?->id;
            $position['sales_operation_name'] = $materialOperations->get($position['id'], collect())
                ->map(fn (array $row) => $operationNames->get($row['operation_key']))->filter()->unique()->implode('、') ?: null;
            $position['replacement_block_reason'] = match (true) {
                ! $snapshot['phantom_expansion_complete'] => 'BOM 还有虚拟件未完整展开，请先补齐生产资料。',
                ! $item || ! $unit || $bomUnit?->id !== $unit->id => '原物料的库存基本单位和 BOM 用量单位不一致。',
                $item->isPhantom(), ! empty($position['child_bom_id']), WorkOrderService::isChildOrderItem($item) => '此位置涉及子 BOM，请由技术处理。',
                $item->cuttingMode() !== 'none', $item->materialManagementMode() !== 'quantity', ! empty($position['cutting_quantity_snapshot']) => '此位置涉及尺寸下料或按实物管理，请由技术处理。',
                default => null,
            };
        }
        unset($position);

        $snapshot['sales_confirmation'] = ['revision' => hash('sha256', json_encode([$snapshot, $routingIdentity], JSON_THROW_ON_ERROR)), 'has_replacements' => false];
        $plan['bom'] = $snapshot;
        return ['snapshot' => $snapshot, 'plan' => $plan, 'block_reason' => implode('；', $snapshot['phantom_expansion_block_reasons'] ?? []) ?: null];
    }

    private function detail(?array $snapshot, float $baseQty, bool $historical = false): ?array
    {
        if ($snapshot === null) return null;
        return [
            'id' => (int) $snapshot['id'], 'bom_no' => $snapshot['bom_no'], 'version' => $snapshot['version'],
            'revision' => data_get($snapshot, 'sales_confirmation.revision'),
            'production_base_qty' => $baseQty,
            'adjustment_reason' => data_get($snapshot, 'sales_confirmation.adjustment_reason'),
            'block_reason' => implode('；', $snapshot['phantom_expansion_block_reasons'] ?? []) ?: null,
            'items' => array_map(fn (array $position) => [
                'position_key' => $position['sales_position_key'],
                'component_item_id' => (int) $position['component_item_id'],
                'component_item_code' => $position['component_item_code'], 'component_item_name' => $position['component_item_name'],
                'spec' => $position['component_snapshot']['spec'], 'unit_name' => $position['component_snapshot']['unit_name'],
                'qty' => (float) (data_get($position, 'cutting_quantity_snapshot.theoretical_qty') ?? $position['qty']),
                'loss_rate' => (float) ($position['loss_rate'] ?? 0), 'fixed_qty' => (float) ($position['fixed_qty'] ?? 0),
                'total_required_qty' => $baseQty > 0 ? WorkOrderPlanBuilder::requiredQuantity($position, $baseQty) : 0,
                'operation_name' => $position['sales_operation_name'],
                'can_replace' => ! $historical && $position['replacement_block_reason'] === null,
                'replacement_block_reason' => $position['replacement_block_reason'],
                'original_component' => $position['original_component'] ?? null,
            ], $snapshot['items']),
        ];
    }

    private function component(Item $item): array
    {
        return [
            'id' => (int) $item->id, 'item_code' => $item->item_code, 'item_name' => $item->item_name,
            'spec' => $item->spec ?: $item->model, 'model' => $item->model, 'material_grade' => $item->material_grade, 'unit_name' => $item->unit?->unit_name,
        ];
    }

    private function materialQuery(): Builder
    {
        return Item::query()->with('unit.standardUnit')->where('management_scope', 'factory')->where('status', 'enabled')
            ->where('is_stock_item', true)->whereNotIn('item_type', ['service', 'office_consumable'])
            ->where('material_management_mode', 'quantity')->where('is_length_cut_material', false)
            ->where(fn ($q) => $q->whereNull('cutting_mode')->orWhereIn('cutting_mode', ['none', '']))
            ->where('manufacturing_strategy', '!=', 'phantom')
            ->where(fn ($q) => $q->whereNotIn('item_type', ['semi_finished', 'finished_product'])->orWhere('manufacturing_strategy', 'purchase')
                ->orWhere(fn ($legacy) => $legacy->whereIn('manufacturing_strategy', ['', 'unspecified'])
                    ->where('is_purchase_item', true)->where('is_production_item', false)));
    }

    private function assertPosition(array $position): void
    {
        if ($position['replacement_block_reason']) $this->invalid($position['replacement_block_reason']);
    }

    private function assertRevision(?array $snapshot, ?string $revision): void
    {
        if (! $snapshot || ! $revision || ! hash_equals($snapshot['sales_confirmation']['revision'], $revision)) {
            throw new WorkOrderDomainException('bom_revision_conflict', '标准 BOM 或用料工序已变化，请重新计算并核对本单替换。', 409);
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['bom_replacements' => $message]);
    }
}
