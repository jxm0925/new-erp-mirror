<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{WorkOrder, WorkOrderPreparationMaterial};
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** The execution query remains the sole source for pickers; this is explicitly procurement-only. */
final class PublicMaterialPreparationQueryService
{
    public function paginate(array $filters, object $user, array $permissions, bool $admin): LengthAwarePaginator
    {
        if (! in_array('production.material_requirement.view', $permissions, true)) throw new WorkOrderDomainException('permission_denied', '当前用户没有查看物料需求的权限。', 403);
        $materials = app(WorkOrderPreparationMaterialService::class);
        if (! $materials->schemaReady()) throw new WorkOrderDomainException('schema_not_ready', '发布前物料准备结构尚未就绪。', 409);
        $scope = app(ProductionDataScopeResolver::class);
        $visible = WorkOrder::query()->select('id')->whereNull('released_at')->whereIn('status', ['DRAFT', 'WAIT_RELEASE']);
        $scope->applyWorkOrderScope($visible, $scope->resolve($user, 'production.material_requirement.view', $permissions, $admin));
        $query = WorkOrderPreparationMaterial::with(['workOrder', 'componentItem'])->whereIn('work_order_id', $visible)->where('status', 'ACTIVE');
        if (! empty($filters['work_order_id'])) $query->where('work_order_id', (int) $filters['work_order_id']);
        if (! empty($filters['category_id'])) $query->whereHas('componentItem', fn ($q) => $q->where('category_id', (int) $filters['category_id']));
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) $query->where(fn ($q) => $q
            ->whereHas('workOrder', fn ($w) => $w->where('work_order_no', 'like', '%'.$keyword.'%'))
            ->orWhereHas('componentItem', fn ($i) => $i->where('item_code', 'like', '%'.$keyword.'%')->orWhere('item_name', 'like', '%'.$keyword.'%')->orWhere('spec', 'like', '%'.$keyword.'%')));
        $page = $query->orderBy('id')->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 20))));
        $projections = [];
        $page->getCollection()->transform(function ($row) use ($materials, &$projections): array {
            $projection = $projections[$row->work_order_id] ??= $materials->projection($row->workOrder);
            return [...$materials->rowProjection($row, $row->workOrder, $projection['ready']), 'preparation_issues' => $projection['issues']];
        });
        return $page;
    }
}
