<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\Item;
use App\Models\Erp\ItemCategory;
use App\Models\Erp\WorkOrder;
use Illuminate\Database\Eloquent\Builder;

final class WorkOrderPlannedOutputOptionService
{
    public function __construct(
        private readonly ProductionDataScopeResolver $scopeResolver,
        private readonly UnitConversionDomainService $units,
    ) {}

    public function options(int $workOrderId, array $filters, object $user, array $permissions, bool $super = false): array
    {
        $this->assertWorkOrderVisible($workOrderId, $user, $permissions, $super);
        $type = $filters['type'] ?? 'items';
        if ($type === 'categories') return ['data' => $this->categoryTree()];
        if ($type !== 'items') {
            throw new WorkOrderDomainException('planned_output_option_type_invalid', '不支持的计划产出资料类型。');
        }

        // 选料沿用工单权限，不附加主档或采购权限，也不按主产出、联产或副产出角色猜测候选范围。
        // 保存计划时仍须由计划产出服务重新校验物料资格和用途，选择器不能替代正式写入校验。
        $query = app(ItemManagementScopeService::class)->applyScope(Item::query(), 'factory')->where('status', 'enabled')->where('is_stock_item', true)->where('item_type', '!=', 'service')
            // 在分页前按 canonicalUnit 的单层标准映射筛选，确保 total 也是可选物料数。
            ->whereHas('unit', fn (Builder $unit) => $unit->where('status', 'enabled')
                ->where(fn (Builder $canonical) => $canonical->where('is_legacy', false)
                    ->orWhere(fn (Builder $legacy) => $legacy->where('is_legacy', true)
                        ->whereHas('standardUnit', fn (Builder $standard) => $standard->where('status', 'enabled')))))
            ->select(['id', 'item_code', 'item_name', 'spec', 'category_id', 'unit_id'])
            ->with('unit.standardUnit');
        $categoryId = (int) ($filters['category_id'] ?? 0);
        if ($categoryId > 0) $query->whereIn('category_id', $this->categoryIdsWithDescendants($categoryId));
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(fn (Builder $items) => $items->where('item_code', 'like', "%{$keyword}%")
                ->orWhere('item_name', 'like', "%{$keyword}%")->orWhere('spec', 'like', "%{$keyword}%"));
        }
        $perPage = min(50, max(1, (int) ($filters['per_page'] ?? 20)));
        $page = $query->orderBy('item_code')->orderBy('id')->paginate($perPage, ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)));

        return [
            'data' => $page->getCollection()->map(function (Item $item): array {
                $unit = $this->units->canonicalUnit($item->unit);
                return [
                    'id' => (int) $item->id,
                    'item_code' => $item->item_code,
                    'item_name' => $item->item_name,
                    'spec' => $item->spec,
                    'category_id' => $item->category_id === null ? null : (int) $item->category_id,
                    'base_unit_id' => $unit ? (int) $unit->id : null,
                    'base_unit_name' => $unit?->unit_name,
                    'base_unit_decimal_places' => $unit ? (int) $unit->decimal_places : null,
                ];
            })->values()->all(),
            'total' => $page->total(),
            'current_page' => $page->currentPage(),
            'per_page' => $page->perPage(),
            'last_page' => $page->lastPage(),
        ];
    }

    private function assertWorkOrderVisible(int $id, object $user, array $permissions, bool $super): void
    {
        if (! in_array('production.work_order.view', $permissions, true)) {
            throw new WorkOrderDomainException('permission_denied', '当前用户没有工单查看权限。', 403,
                ['permission' => 'production.work_order.view']);
        }
        $workOrder = WorkOrder::query()->find($id, ['id']);
        if (! $workOrder) throw new WorkOrderDomainException('not_found', '工单不存在。', 404);
        $scope = $this->scopeResolver->resolve($user, 'production.work_order.view', $permissions, $super);
        if (! $this->scopeResolver->workOrderVisible($workOrder, $scope)) {
            throw new WorkOrderDomainException('data_scope_denied', '工单不在当前生产数据范围内。', 403);
        }
    }

    private function categoryTree(): array
    {
        // 分类导航沿用物料类目树的真实层级与启停状态；这是整棵只读树，不伪装成第一页分类。
        $categories = app(ItemManagementScopeService::class)->applyScope(ItemCategory::query(), 'factory')->where('category_type', 'item')
            ->select(['id', 'category_code', 'category_name', 'parent_id', 'status', 'sort_order'])
            ->orderBy('sort_order')->orderBy('id')->get();
        $byParent = $categories->groupBy(fn (ItemCategory $category) => (int) ($category->parent_id ?? 0));
        $build = function (int $parentId) use (&$build, $byParent): array {
            return $byParent->get($parentId, collect())->map(function (ItemCategory $category) use (&$build): array {
                return [
                    'id' => (int) $category->id,
                    'category_code' => $category->category_code,
                    'category_name' => $category->category_name,
                    'parent_id' => $category->parent_id === null ? null : (int) $category->parent_id,
                    'status' => $category->status,
                    'children' => $build((int) $category->id),
                ];
            })->values()->all();
        };
        return $build(0);
    }

    private function categoryIdsWithDescendants(int $rootId): array
    {
        $categories = ItemCategory::query()->where('category_type', 'item')->get(['id', 'parent_id']);
        if (! $categories->contains(fn (ItemCategory $category) => (int) $category->id === $rootId)) {
            throw new WorkOrderDomainException('planned_output_category_invalid', '请选择真实的物料类目。');
        }
        $byParent = $categories->groupBy(fn (ItemCategory $category) => (int) ($category->parent_id ?? 0));
        $ids = [$rootId];
        $frontier = [$rootId];
        while ($frontier !== []) {
            $children = collect($frontier)->flatMap(fn (int $id) => $byParent->get($id, collect()))
                ->map(fn (ItemCategory $category) => (int) $category->id)->unique()->diff($ids)->values()->all();
            $ids = array_merge($ids, $children);
            $frontier = $children;
        }
        return $ids;
    }
}
