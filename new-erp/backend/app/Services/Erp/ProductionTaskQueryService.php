<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\ProductionQuantityOperation;
use App\Models\Erp\ProductionTask;
use App\Models\Erp\ProductionUnitOperation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class ProductionTaskQueryService
{
    public function __construct(private readonly ProductionDataScopeResolver $scopeResolver) {}

    public function paginate(array $filters, object $user, array $permissions, bool $superAdmin): LengthAwarePaginator
    {
        $this->permission($permissions, 'production.task.view');
        $query = ProductionTask::query()->with(['workOrder.outputItem', 'targets'])->orderByDesc('id');
        $scope = $this->scopeResolver->resolve($user, 'production.task.view', $permissions, $superAdmin);
        $this->scopeResolver->applyProductionTaskScope($query, $scope, (int) $user->legacy_id);
        if (! empty($filters['status'])) $query->where('status', $filters['status']);
        if (! empty($filters['work_order_id'])) $query->where('work_order_id', (int) $filters['work_order_id']);
        if (($filters['view'] ?? null) === 'pool') $query->where('status', 'WAIT_CLAIM')->whereNull('assignee_user_legacy_id');
        if (($filters['view'] ?? null) === 'mine') {
            $userId = (int) $user->legacy_id;
            $query->where(fn ($q) => $q->where('assignee_user_legacy_id', $userId)
                ->orWhereHas('collaborators', fn ($c) => $c->where('employee_legacy_id', $userId)->whereNull('left_at')));
        }
        if (($filters['view'] ?? null) === 'owned') {
            $query->where('assignee_user_legacy_id', (int) $user->legacy_id);
        }
        if (($filters['view'] ?? null) === 'collaboration') {
            $userId = (int) $user->legacy_id;
            $query->where('assignee_user_legacy_id', '<>', $userId)
                ->whereHas('collaborators', fn ($c) => $c->where('employee_legacy_id', $userId)->whereNull('left_at'));
        }
        $page = $query->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 20))));
        $this->enrichTargets(collect($page->items()));
        return $page;
    }

    public function show(int $id, object $user, array $permissions, bool $superAdmin): ProductionTask
    {
        $this->permission($permissions, 'production.task.view');
        $query = ProductionTask::query()->with(['workOrder.outputItem', 'targets', 'collaborators', 'laborSessions'])->whereKey($id);
        $scope = $this->scopeResolver->resolve($user, 'production.task.view', $permissions, $superAdmin);
        $this->scopeResolver->applyProductionTaskScope($query, $scope, (int) $user->legacy_id);
        $task = $query->first();
        if (! $task) throw new WorkOrderDomainException('task_not_found', '生产任务不存在或不在当前数据范围内。', 404);
        $this->enrichTargets(collect([$task]));
        return $task;
    }

    /**
     * Task targets are deliberately stored as an explicit type/id pair so unit and
     * quantity execution never masquerade as one another.  The mobile execution
     * client nevertheless needs the target version and timestamps in the same
     * response; resolving them here also avoids an N+1 request per production unit.
     */
    private function enrichTargets(Collection $tasks): void
    {
        $links = $tasks->flatMap(fn (ProductionTask $task) => $task->targets);
        $unitIds = $links->where('target_type', 'unit_operation')->pluck('target_id')->map(fn ($id) => (int) $id)->unique()->values();
        $quantityIds = $links->where('target_type', 'quantity_operation')->pluck('target_id')->map(fn ($id) => (int) $id)->unique()->values();

        $units = ProductionUnitOperation::query()->with('productionUnit')
            ->whereIn('id', $unitIds)->get()->keyBy('id');
        $quantities = ProductionQuantityOperation::query()->whereIn('id', $quantityIds)->get()->keyBy('id');

        foreach ($tasks as $task) {
            $details = $task->targets->map(function ($link) use ($units, $quantities): array {
                $target = $link->target_type === 'unit_operation'
                    ? $units->get((int) $link->target_id)
                    : $quantities->get((int) $link->target_id);
                if (! $target) return ['target_type' => $link->target_type, 'target_id' => (int) $link->target_id, 'missing' => true];

                return [
                    'target_type' => $link->target_type,
                    'target_id' => (int) $target->id,
                    'status' => $target->status,
                    'business_version' => (int) $target->business_version,
                    'production_unit_id' => $link->target_type === 'unit_operation' ? (int) $target->production_unit_id : null,
                    'production_unit_no' => $link->target_type === 'unit_operation' ? $target->productionUnit?->unit_no : null,
                    'device_serial_no' => $link->target_type === 'unit_operation' ? $target->productionUnit?->device_no_snapshot : null,
                    'planned_base_qty' => $link->target_type === 'quantity_operation' ? (float) $target->planned_base_qty : 1,
                    'completed_base_qty' => $link->target_type === 'quantity_operation' ? (float) $target->completed_base_qty : ($target->status === 'COMPLETED' ? 1 : 0),
                    'remaining_base_qty' => $link->target_type === 'quantity_operation' ? (float) $target->remaining_base_qty : ($target->status === 'COMPLETED' ? 0 : 1),
                    'actual_labor_minutes' => (float) $target->actual_labor_minutes,
                    'kitting_required' => (bool) $target->kitting_required,
                    'claimed_at' => optional($target->claimed_at)->toISOString(),
                    'kitting_confirmed_at' => optional($target->kitting_confirmed_at)->toISOString(),
                    'started_at' => optional($target->started_at)->toISOString(),
                    'paused_at' => optional($target->paused_at)->toISOString(),
                    'completed_at' => optional($target->completed_at)->toISOString(),
                ];
            })->values()->all();
            $task->setAttribute('target_details', $details);
        }
    }

    private function permission(array $permissions, string $code): void
    {
        if (! in_array($code, $permissions, true)) throw new WorkOrderDomainException('permission_denied', '当前用户没有执行该操作的权限。', 403, ['permission' => $code]);
    }
}
