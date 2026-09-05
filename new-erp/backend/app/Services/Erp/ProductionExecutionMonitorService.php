<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\WorkOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ProductionExecutionMonitorService
{
    public function __construct(private readonly ProductionDataScopeResolver $scopeResolver) {}

    public function paginate(array $filters, object $user, array $permissions, bool $superAdmin): array
    {
        if (! in_array('production.unit.view', $permissions, true)) throw new WorkOrderDomainException('permission_denied', '当前用户没有生产执行监管权限。', 403);
        $query = WorkOrder::query()->with('outputItem')->whereIn('status', ['RELEASED', 'IN_PROGRESS', 'COMPLETED']);
        $scope = $this->scopeResolver->resolve($user, 'production.unit.view', $permissions, $superAdmin);
        $this->scopeResolver->applyWorkOrderScope($query, $scope);
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) $query->where(fn ($q) => $q->where('work_order_no', 'like', "%{$keyword}%")->orWhereHas('outputItem', fn ($i) => $i->where('item_code', 'like', "%{$keyword}%")->orWhere('item_name', 'like', "%{$keyword}%")));
        if (! empty($filters['status'])) $query->where('status', $filters['status']);
        if (! empty($filters['planned_date'])) $query->whereDate('planned_date', $filters['planned_date']);
        $statsQuery = clone $query;
        $page = $query->withCount(['productionUnits', 'productionTasks'])->orderByDesc('id')->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 10))));
        $ids = (clone $statsQuery)->pluck('id');
        return ['page' => $page, 'stats' => [
            'work_orders' => $ids->count(),
            'operations' => DB::table('erp_production_unit_operations')->whereIn('work_order_id', $ids)->count() + DB::table('erp_production_quantity_operations')->whereIn('work_order_id', $ids)->count(),
            'units' => DB::table('erp_production_units')->whereIn('work_order_id', $ids)->count(),
            'active_units' => DB::table('erp_production_units')->whereIn('work_order_id', $ids)->whereNotIn('status', ['COMPLETED', 'CANCELLED'])->count(),
        ]];
    }
}
