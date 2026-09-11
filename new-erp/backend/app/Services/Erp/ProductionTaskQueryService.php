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
        private readonly ProductionTargetReadinessService $readiness,
        private readonly ProductionTaskActionProjectionService $actions,
    ) {}

    public function paginate(array $filters, object $user, array $permissions, bool $superAdmin): LengthAwarePaginator
    {
        $query = $this->filteredQuery($filters, $user, $permissions, $superAdmin);
        $page = $query->with(['workOrder.outputItem', 'workOrder.productionMasterOrder', 'targets', 'collaborators', 'laborSessions'])->orderByDesc('id')
            ->paginate(min(20, max(1, (int) ($filters['per_page'] ?? 20))));
        $this->enrichTargets(collect($page->items()), $permissions, $user);
        collect($page->items())->each(fn (ProductionTask $task) => $this->enrichPeople($task));
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
        $query = ProductionTask::query()->with(['workOrder.outputItem', 'workOrder.productionMasterOrder', 'targets', 'collaborators', 'laborSessions'])->whereKey($id);
        $scope = $this->scopeResolver->resolve($user, 'production.task.view', $permissions, $superAdmin);
        $this->scopeResolver->applyProductionTaskScope($query, $scope, (int) $user->legacy_id);
        $task = $query->first();
        if (! $task) throw new WorkOrderDomainException('task_not_found', '生产任务不存在或不在当前数据范围内。', 404);
        $this->enrichTargets(collect([$task]), $permissions, $user);
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
    private function enrichTargets(Collection $tasks, array $permissions, object $user): void
    {
        $serverNow = now();
        $links = $tasks->flatMap(fn (ProductionTask $task) => $task->targets);
        $unitIds = $links->where('target_type', 'unit_operation')->pluck('target_id')->map(fn ($id) => (int) $id)->unique()->values();
        $quantityIds = $links->where('target_type', 'quantity_operation')->pluck('target_id')->map(fn ($id) => (int) $id)->unique()->values();

        $units = ProductionUnitOperation::query()->with('productionUnit.deviceSerial')
            ->whereIn('id', $unitIds)->get()->keyBy('id');
        $quantities = ProductionQuantityOperation::query()->whereIn('id', $quantityIds)->get()->keyBy('id');
        $outputs = DB::table('erp_production_output_records')
            ->where(fn ($query) => $query
                ->where(fn ($unit) => $unit->where('source_target_type', 'unit_operation')->whereIn('source_target_id', $unitIds))
                ->orWhere(fn ($quantity) => $quantity->where('source_target_type', 'quantity_operation')->whereIn('source_target_id', $quantityIds)))
            ->orderByDesc('id')->get()->unique(fn ($row) => $row->source_target_type.':'.$row->source_target_id)
            ->keyBy(fn ($row) => $row->source_target_type.':'.$row->source_target_id);

        foreach ($tasks as $task) {
            $details = $task->targets->map(function ($link) use ($task, $units, $quantities, $outputs, $permissions, $user, $serverNow): array {
                $target = $link->target_type === 'unit_operation'
                    ? $units->get((int) $link->target_id)
                    : $quantities->get((int) $link->target_id);
                if (! $target) return ['target_type' => $link->target_type, 'target_id' => (int) $link->target_id, 'missing' => true];

                $output = $outputs->get($link->target_type.':'.$target->id);
                $outputActions = $output ? [
                    'quality_inspect' => $output->status === 'WAIT_QUALITY' && in_array('production.output.quality', $permissions, true),
                    'warehouse' => $output->status === 'WAIT_WAREHOUSE' && in_array('production.output.warehouse', $permissions, true),
                ] : ['quality_inspect' => false, 'warehouse' => false];

                $status = (string) $target->status;
                $activeLabor = $task->laborSessions->filter(fn ($session) => $session->status === 'ACTIVE'
                    && $session->target_type === $link->target_type && (int) $session->target_id === (int) $target->id);
                $workMode = $target->work_mode_snapshot ?: 'manual';
                $executionIntegrity = $status === 'IN_PROGRESS'
                    ? ((int) $task->assignee_user_legacy_id > 0 && ($workMode === 'automatic' || $activeLabor->isNotEmpty())
                        ? ['valid' => true, 'reason_code' => null, 'message' => null]
                        : ['valid' => false, 'reason_code' => 'in_progress_labor_missing', 'message' => '人工工序处于加工中，但没有任何负责人或协作者的进行中工时。'])
                    : ['valid' => true, 'reason_code' => null, 'message' => null];
                $readiness = $this->readiness->project($link->target_type, $target);
                $actionProjection = $this->actions->project($task, $target, $user, $permissions, $activeLabor, $readiness);
                $userId = (int) ($user->legacy_id ?? $user->id ?? 0);
                $mySessions = $task->laborSessions->filter(fn ($session) => $session->target_type === $link->target_type
                    && (int) $session->target_id === (int) $target->id && (int) $session->employee_legacy_id === $userId);
                $myActiveSession = $mySessions->firstWhere('status', 'ACTIVE');
                $myAccumulatedSeconds = (int) round($mySessions->sum(fn ($session) => (float) $session->actual_labor_minutes * 60));
                if ($myActiveSession) $myAccumulatedSeconds += max(0, $myActiveSession->started_at->diffInSeconds($serverNow));

                return [
                    'target_type' => $link->target_type,
                    'target_id' => (int) $target->id,
                    'status' => $target->status,
                    'status_label' => $this->statusLabel($status),
                    'reason_code' => $this->reasonCode($status),
                    'reason_message' => $this->reasonMessage($status),
                    'my_role' => $actionProjection['my_role'],
                    'allowed_actions' => $actionProjection['allowed_actions'],
                    'primary_action' => $actionProjection['primary_action'],
                    'secondary_actions' => $actionProjection['secondary_actions'],
                    'business_version' => (int) $target->business_version,
                    'production_unit_id' => $link->target_type === 'unit_operation' ? (int) $target->production_unit_id : null,
                    'production_unit_no' => $link->target_type === 'unit_operation' ? $target->productionUnit?->unit_no : null,
                    'serial_no' => $link->target_type === 'unit_operation' ? $target->productionUnit?->deviceSerial?->serial_no : null,
                    'execution_integrity' => $executionIntegrity,
                    'planned_base_qty' => $link->target_type === 'quantity_operation' ? (float) $target->planned_base_qty : 1,
                    'completed_base_qty' => $link->target_type === 'quantity_operation' ? (float) $target->completed_base_qty : ($target->status === 'COMPLETED' ? 1 : 0),
                    'unqualified_base_qty' => $link->target_type === 'quantity_operation' ? (float) $target->unqualified_base_qty : 0,
                    'scrapped_base_qty' => $link->target_type === 'quantity_operation' ? (float) $target->scrapped_base_qty : 0,
                    'reported_base_qty' => $link->target_type === 'quantity_operation'
                        ? (float) $target->completed_base_qty + (float) $target->unqualified_base_qty + (float) $target->scrapped_base_qty
                        : ($target->status === 'COMPLETED' ? 1 : 0),
                    'remaining_base_qty' => $link->target_type === 'quantity_operation' ? (float) $target->remaining_base_qty : ($target->status === 'COMPLETED' ? 0 : 1),
                    'actual_labor_minutes' => (float) $target->actual_labor_minutes,
                    'work_mode_snapshot' => $workMode,
                    'active_labor_count' => $activeLabor->count(),
                    'operation_runtime' => [
                        'work_mode' => $workMode,
                        'status' => $status,
                        'started_at' => optional($target->started_at)->toISOString(),
                        'active_labor_count' => $activeLabor->count(),
                    ],
                    'my_labor' => [
                        'session_id' => $myActiveSession?->id ? (int) $myActiveSession->id : null,
                        'status' => $myActiveSession ? 'ACTIVE' : 'INACTIVE',
                        'accumulated_seconds' => $myAccumulatedSeconds,
                        'started_at' => optional($myActiveSession?->started_at)->toISOString(),
                    ],
                    'readiness' => $readiness,
                    'server_now' => $serverNow->toISOString(),
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
            $task->setAttribute('production_context', $this->productionContext($task));
            $roles = collect($details)->pluck('my_role')->unique()->values();
            $task->setAttribute('my_role', $roles->contains('owner') ? 'owner' : ($roles->contains('collaborator') ? 'collaborator' : 'viewer'));
            $task->setAttribute('server_now', $serverNow->toISOString());
            $task->setAttribute('allowed_actions', [
                'claim' => (int) ($task->assignee_user_legacy_id ?? 0) === 0
                    && $task->status === 'WAIT_CLAIM'
                    && in_array('production.task.claim', $permissions, true),
            ]);
        }
    }

    /** One service-side vocabulary prevents wxapp and PC from inventing divergent interpretations of a PT state. */
    private function statusLabel(string $status): string
    {
        return match ($status) {
            'WAIT_PREVIOUS', 'WAIT_PREDECESSOR' => '待前工序可交接',
            'WAIT_CLAIM' => '待接单', 'CLAIMED' => '已接单',
            'WAIT_MATERIAL' => '待齐套', 'WAIT_HANDOVER' => '待交接确认',
            'READY' => '待开工', 'IN_PROGRESS' => '进行中', 'PAUSED' => '已暂停',
            'WAIT_QUALITY' => '待质检', 'WAIT_WAREHOUSE' => '待入库',
            'REWORK' => '返工', 'COMPLETED' => '已完成', 'CANCELLED' => '已取消',
            default => '状态异常',
        };
    }

    private function reasonCode(string $status): ?string
    {
        return match ($status) {
            'WAIT_PREVIOUS', 'WAIT_PREDECESSOR' => 'predecessor_not_handover_ready',
            'WAIT_MATERIAL' => 'kitting_conditions_unmet',
            'WAIT_HANDOVER' => 'handover_confirmation_required',
            default => null,
        };
    }

    private function reasonMessage(string $status): ?string
    {
        return match ($status) {
            'WAIT_PREVIOUS', 'WAIT_PREDECESSOR' => '上一工序尚未形成正式可交接事实。',
            'WAIT_MATERIAL' => '物料条件待满足；可能来自配送、交接、内部领用或工位常备料。',
            'WAIT_HANDOVER' => '已接单，等待负责人确认上一工序交接。',
            default => null,
        };
    }

    private function productionContext(ProductionTask $task): array
    {
        $workOrder = $task->workOrder;
        $master = $workOrder?->productionMasterOrder;
        return [
            'master_order_id' => $master?->id ? (int) $master->id : null,
            'master_order_no' => $master?->master_order_no,
            'sales_order_id' => $master?->sales_order_id ? (int) $master->sales_order_id : null,
            'sales_order_no' => $master?->sales_order_no_snapshot,
            'work_order_id' => $workOrder?->id ? (int) $workOrder->id : null,
            'work_order_no' => $workOrder?->work_order_no,
            'task_id' => (int) $task->id,
            'task_no' => $task->task_no,
            'product' => $workOrder?->outputItem ? [
                'item_id' => (int) $workOrder->outputItem->id,
                'item_code' => $workOrder->outputItem->item_code,
                'item_name' => $workOrder->outputItem->item_name,
                'spec' => $workOrder->outputItem->spec,
            ] : null,
        ];
    }

    private function permission(array $permissions, string $code): void
    {
        if (! in_array($code, $permissions, true)) throw new WorkOrderDomainException('permission_denied', '当前用户没有执行该操作的权限。', 403, ['permission' => $code]);
    }
}
