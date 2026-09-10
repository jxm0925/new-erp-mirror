<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\ProductionQuantityOperation;
use App\Models\Erp\ProductionTask;
use App\Models\Erp\ProductionUnitOperation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ProductionTaskQueryService
{
    public function __construct(
        private readonly ProductionDataScopeResolver $scopeResolver,
        private readonly ErpUserProjectionService $users,
    ) {}

    public function paginate(array $filters, object $user, array $permissions, bool $superAdmin): LengthAwarePaginator
    {
        $query = $this->filteredQuery($filters, $user, $permissions, $superAdmin);
        $page = $query->with(['workOrder.outputItem', 'targets'])->orderByDesc('id')
            ->paginate(min(20, max(1, (int) ($filters['per_page'] ?? 20))));
        $this->enrichTargets(collect($page->items()), $permissions);
        return $page;
    }

    private function filteredQuery(array $filters, object $user, array $permissions, bool $superAdmin): Builder
    {
        $this->permission($permissions, 'production.task.view');
        $query = ProductionTask::query();
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
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) {
            $query->where(fn ($q) => $q->where('task_no', 'like', "%{$keyword}%")
                ->orWhere('operation_name_snapshot', 'like', "%{$keyword}%")
                ->orWhereHas('workOrder', fn ($wo) => $wo->where('work_order_no', 'like', "%{$keyword}%")
                    ->orWhereHas('outputItem', fn ($item) => $item->where('item_code', 'like', "%{$keyword}%")->orWhere('item_name', 'like', "%{$keyword}%"))));
        }
        $this->applyExecutionFilter($query, $filters['execution_filter'] ?? 'all');
        return $query;
    }

    /** 汇总复用列表的数据范围，但在分页前计算；聚合任务不能取第一个目标代表全部单元。 */
    public function summary(array $filters, object $user, array $permissions, bool $superAdmin): array
    {
        unset($filters['execution_filter'], $filters['status'], $filters['keyword']);
        $base = $this->filteredQuery($filters, $user, $permissions, $superAdmin);
        $stats = ['total' => (clone $base)->count()];
        foreach (['running', 'waiting', 'completed', 'kitting'] as $group) {
            $query = clone $base;
            $this->applyExecutionFilter($query, $group);
            $stats[$group] = $query->count();
        }
        $today = clone $base;
        $this->applyExecutionFilter($today, 'completed');
        $this->whereTargetState($today, ['COMPLETED'], false, true);
        $stats['completed_today'] = $today->count();
        $stats['today_total'] = $stats['running'] + $stats['waiting'] + $stats['completed_today'];
        return $stats;
    }

    private function applyExecutionFilter(Builder $query, string $filter): void
    {
        $running = ['IN_PROGRESS', 'PAUSED'];
        $waiting = ['WAIT_PREVIOUS', 'WAIT_PREDECESSOR', 'WAIT_CLAIM', 'CLAIMED', 'WAIT_MATERIAL', 'WAIT_HANDOVER', 'READY', 'WAIT_QUALITY', 'WAIT_WAREHOUSE', 'REWORK'];
        if ($filter === 'running') $this->whereTargetState($query, $running);
        if ($filter === 'waiting') {
            $this->whereTargetState($query, $waiting);
            $this->whereTargetState($query, $running, true);
        }
        if ($filter === 'current') $this->whereTargetState($query, array_merge($running, ['WAIT_MATERIAL', 'READY']));
        if ($filter === 'kitting') $this->whereTargetState($query, ['WAIT_MATERIAL']);
        if ($filter === 'completed') {
            $this->whereTargetState($query, ['COMPLETED']);
            $this->whereTargetState($query, array_merge($running, $waiting), true);
        }
    }

    private function whereTargetState(Builder $query, array $states, bool $negate = false, bool $today = false): void
    {
        $method = $negate ? 'whereDoesntHave' : 'whereHas';
        $query->{$method}('targets', function (Builder $links) use ($states, $today): void {
            $units = ProductionUnitOperation::query()->select('id')->whereIn('status', $states);
            $quantities = ProductionQuantityOperation::query()->select('id')->whereIn('status', $states);
            if ($today) {
                $start = now()->startOfDay();
                $end = $start->copy()->addDay();
                $units->where('completed_at', '>=', $start)->where('completed_at', '<', $end);
                $quantities->where('completed_at', '>=', $start)->where('completed_at', '<', $end);
            }
            $links->where(fn ($q) => $q->where(fn ($unit) => $unit->where('target_type', 'unit_operation')->whereIn('target_id', $units))
                ->orWhere(fn ($quantity) => $quantity->where('target_type', 'quantity_operation')->whereIn('target_id', $quantities)));
        });
    }

    public function show(int $id, object $user, array $permissions, bool $superAdmin): ProductionTask
    {
        $this->permission($permissions, 'production.task.view');
        $query = ProductionTask::query()->with(['workOrder.outputItem', 'targets', 'collaborators', 'laborSessions'])->whereKey($id);
        $scope = $this->scopeResolver->resolve($user, 'production.task.view', $permissions, $superAdmin);
        $this->scopeResolver->applyProductionTaskScope($query, $scope, (int) $user->legacy_id);
        $task = $query->first();
        if (! $task) throw new WorkOrderDomainException('task_not_found', '生产任务不存在或不在当前数据范围内。', 404);
        $this->enrichTargets(collect([$task]), $permissions);
        $this->enrichPeople($task);
        return $task;
    }

    private function enrichPeople(ProductionTask $task): void
    {
        $ids = collect([(int) $task->assignee_user_legacy_id])
            ->merge($task->collaborators->pluck('employee_legacy_id')->map(fn ($id) => (int) $id))
            ->filter()->unique()->values()->all();
        $people = $this->users->many($ids);
        $task->setAttribute('assignee_user', $people[(int) $task->assignee_user_legacy_id] ?? null);
        foreach ($task->collaborators as $collaborator) {
            $collaborator->setAttribute('employee', $people[(int) $collaborator->employee_legacy_id] ?? null);
        }
    }

    /**
     * Task targets are deliberately stored as an explicit type/id pair so unit and
     * quantity execution never masquerade as one another.  The mobile execution
     * client nevertheless needs the target version and timestamps in the same
     * response; resolving them here also avoids an N+1 request per production unit.
     */
    private function enrichTargets(Collection $tasks, array $permissions): void
    {
        $links = $tasks->flatMap(fn (ProductionTask $task) => $task->targets);
        $unitIds = $links->where('target_type', 'unit_operation')->pluck('target_id')->map(fn ($id) => (int) $id)->unique()->values();
        $quantityIds = $links->where('target_type', 'quantity_operation')->pluck('target_id')->map(fn ($id) => (int) $id)->unique()->values();

        $units = ProductionUnitOperation::query()->with('productionUnit')
            ->whereIn('id', $unitIds)->get()->keyBy('id');
        $quantities = ProductionQuantityOperation::query()->whereIn('id', $quantityIds)->get()->keyBy('id');
        $outputs = DB::table('erp_production_output_records')
            ->where(fn ($query) => $query
                ->where(fn ($unit) => $unit->where('source_target_type', 'unit_operation')->whereIn('source_target_id', $unitIds))
                ->orWhere(fn ($quantity) => $quantity->where('source_target_type', 'quantity_operation')->whereIn('source_target_id', $quantityIds)))
            ->orderByDesc('id')->get()->unique(fn ($row) => $row->source_target_type.':'.$row->source_target_id)
            ->keyBy(fn ($row) => $row->source_target_type.':'.$row->source_target_id);

        foreach ($tasks as $task) {
            $details = $task->targets->map(function ($link) use ($units, $quantities, $outputs, $permissions): array {
                $target = $link->target_type === 'unit_operation'
                    ? $units->get((int) $link->target_id)
                    : $quantities->get((int) $link->target_id);
                if (! $target) return ['target_type' => $link->target_type, 'target_id' => (int) $link->target_id, 'missing' => true];

                $output = $outputs->get($link->target_type.':'.$target->id);
                $outputActions = $output ? [
                    'quality_inspect' => $output->status === 'WAIT_QUALITY' && in_array('production.output.quality', $permissions, true),
                    'warehouse' => $output->status === 'WAIT_WAREHOUSE' && in_array('production.output.warehouse', $permissions, true),
                ] : ['quality_inspect' => false, 'warehouse' => false];

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
                    'unqualified_base_qty' => $link->target_type === 'quantity_operation' ? (float) $target->unqualified_base_qty : 0,
                    'scrapped_base_qty' => $link->target_type === 'quantity_operation' ? (float) $target->scrapped_base_qty : 0,
                    'reported_base_qty' => $link->target_type === 'quantity_operation'
                        ? (float) $target->completed_base_qty + (float) $target->unqualified_base_qty + (float) $target->scrapped_base_qty
                        : ($target->status === 'COMPLETED' ? 1 : 0),
                    'remaining_base_qty' => $link->target_type === 'quantity_operation' ? (float) $target->remaining_base_qty : ($target->status === 'COMPLETED' ? 0 : 1),
                    'actual_labor_minutes' => (float) $target->actual_labor_minutes,
                    'kitting_required' => (bool) $target->kitting_required,
                    'output_mode_snapshot' => $target->output_mode_snapshot,
                    'quality_mode_snapshot' => $target->quality_mode_snapshot,
                    'allow_continue_without_warehouse_snapshot' => (bool) $target->allow_continue_without_warehouse_snapshot,
                    'output_record' => $output ? [
                        'id' => (int) $output->id,
                        'output_no' => $output->output_no,
                        'status' => $output->status,
                        'output_base_qty' => (float) $output->output_base_qty,
                        'business_version' => (int) $output->business_version,
                        'disposition' => $output->disposition,
                        'allowed_actions' => $outputActions,
                    ] : null,
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
