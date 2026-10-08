<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\MaterialPickingTask;
use App\Models\Erp\PublicMaterialPreparationTask;
use App\Models\Erp\WorkOrder;
use Illuminate\Support\Facades\DB;

final class PublicMaterialPreparationApplicationService
{
    public function __construct(private readonly ProductionMaterialExecutionService $materials,
        private readonly ProductionMaterialCommandService $commands, private readonly ProductionDataScopeResolver $scope) {}

    public function paginate(array $filters, object $user, array $permissions, bool $admin)
    {
        $this->permission($permissions, 'view');
        $query = $this->visibleQuery($user, $permissions, $admin)->with('warehouse')->withCount('tasks')->orderByDesc('id');
        if (! empty($filters['status'])) $query->where('status', $filters['status']);
        if (! empty($filters['keyword'])) $query->where('task_no', 'like', '%'.$filters['keyword'].'%');
        return $query->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 20))));
    }

    public function show(int $id, array $filters, object $user, array $permissions, bool $admin): array
    {
        $this->permission($permissions, 'view');
        $job = $this->visibleQuery($user, $permissions, $admin)->with('warehouse')->find($id);
        if (! $job) throw new WorkOrderDomainException('not_found', '公共配料任务不存在或不在当前数据范围内。', 404);
        $tasks = $job->tasks()->orderBy('id')->get(['id', 'task_no', 'work_order_id', 'status', 'business_version']);
        $lines = DB::table('erp_material_picking_task_lines as line')->join('erp_material_picking_tasks as task', 'task.id', '=', 'line.task_id')
            ->join('erp_work_orders as wo', 'wo.id', '=', 'task.work_order_id')->where('task.public_preparation_task_id', $job->id)
            ->join('erp_items as item', 'item.id', '=', 'line.component_item_id')
            ->orderBy('line.id')->select('line.*', 'wo.work_order_no', 'task.task_no', 'item.item_code', 'item.item_name', 'item.spec', 'item.material_management_mode')
            ->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 20))));
        $picker = $job->assigned_picker_legacy_id ? DB::table('erp_legacy_admin_users')->where('legacy_id', $job->assigned_picker_legacy_id)->first(['nickname', 'username']) : null;
        return [...$job->toArray(), 'assigned_picker_name' => $picker?->nickname ?: $picker?->username, 'children' => $tasks->toArray(), 'lines' => $lines->toArray()];
    }

    public function create(array $payload, object $user, array $permissions, bool $admin): array
    {
        $this->permission($permissions, 'create');
        return $this->commands->run('public_preparation_create', $payload, $user, function () use ($payload, $user, $permissions, $admin) {
            $rows = collect($payload['lines']);
            $demands = DB::table('erp_production_target_material_requirements')->whereIn('id', $rows->pluck('target_material_requirement_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($rows->contains(fn ($row) => ! $demands->has($row['target_material_requirement_id']))) throw new WorkOrderDomainException('preparation_demand_invalid', '部分待配需求已失效。', 422);
            $groups = $rows->groupBy(fn ($row) => $demands[$row['target_material_requirement_id']]->work_order_id)->sortKeys();
            if ($groups->count() > 20) throw new WorkOrderDomainException('validation_error', '单次公共配料最多合并20张工单。', 422);
            $job = PublicMaterialPreparationTask::create(['task_no' => 'TMP-'.bin2hex(random_bytes(12)), 'warehouse_id' => $payload['warehouse_id'],
                'created_by_legacy_id' => (int) ($user->legacy_id ?? $user->id), 'remark' => $payload['remark'] ?? null]);
            $job->update(['task_no' => 'PMP'.now()->format('Ymd').str_pad((string) $job->id, 6, '0', STR_PAD_LEFT)]);
            foreach ($groups as $workOrderId => $lines) {
                $workOrder = WorkOrder::lockForUpdate()->findOrFail($workOrderId);
                $expected = $payload['work_order_versions'][$workOrderId] ?? null;
                if ((int) $expected !== (int) $workOrder->business_version) throw new WorkOrderDomainException('version_conflict', '来源工单版本已变化，请刷新需求后重试。', 409);
                $task = $this->materials->createPickingTask(['client_command_id' => $this->childKey($payload, 'create', $workOrderId),
                    'work_order_id' => $workOrderId, 'expected_version' => $expected, 'warehouse_id' => $payload['warehouse_id'],
                    'remark' => $payload['remark'] ?? null, 'lines' => $lines->values()->all()], $user, $permissions, $admin);
                $task->update(['public_preparation_task_id' => $job->id]);
            }
            return $this->show($job->id, [], $user, $permissions, $admin);
        }, fn ($id) => $this->show($id, [], $user, $permissions, $admin));
    }

    public function transition(int $id, string $action, array $payload, object $user, array $permissions, bool $admin): array
    {
        $this->permission($permissions, ['assign' => 'assign', 'claim' => 'pick', 'start' => 'pick', 'confirm' => 'pick', 'cancel' => 'cancel'][$action]);
        return $this->commands->run('public_preparation_'.$action, $payload, $user, function () use ($id, $action, $payload, $user, $permissions, $admin) {
            $job = $this->visibleQuery($user, $permissions, $admin)->lockForUpdate()->findOrFail($id);
            if ((int) $payload['expected_version'] !== (int) $job->business_version) throw new WorkOrderDomainException('version_conflict', '公共配料任务已变化，请刷新后重试。', 409);
            $tasks = $job->tasks()->orderBy('id')->lockForUpdate()->get();
            $expectedState = $action === 'confirm' ? 'PICKING' : 'WAIT_PICK';
            if (($action === 'cancel' && ! in_array($job->status, ['WAIT_PICK', 'PICKING'], true)) || ($action !== 'cancel' && $job->status !== $expectedState))
                throw new WorkOrderDomainException('invalid_state', '当前公共配料任务状态不允许此操作。', 409);
            $actorId = (int) ($user->legacy_id ?? $user->id);
            if (in_array($action, ['claim', 'start', 'confirm'], true) && $job->assigned_picker_legacy_id && (int) $job->assigned_picker_legacy_id !== $actorId)
                throw new WorkOrderDomainException('picker_mismatch', '该公共配料任务已由其他人领取。', 403);
            if ($action === 'claim') $payload['assigned_picker_legacy_id'] = $actorId;
            if ($action === 'confirm') {
                $lineIds = DB::table('erp_material_picking_task_lines')->whereIn('task_id', $tasks->pluck('id'))->pluck('id');
                $rows = collect($payload['lines']);
                if ($rows->count() !== $lineIds->count() || $rows->pluck('picking_task_line_id')->unique()->count() !== $rows->count() || $rows->pluck('picking_task_line_id')->diff($lineIds)->isNotEmpty())
                    throw new WorkOrderDomainException('picking_line_invalid', '实拣明细重复或不属于当前公共任务。', 422);
                $knownLines = \App\Models\Erp\MaterialPickingTaskLine::with('componentItem')->whereIn('id', $lineIds)->get()->keyBy('id');
                foreach ($rows as $row) {
                    $qty = (string) $row['actual_pick_qty'];
                    $line = $knownLines[$row['picking_task_line_id']];
                    if (! preg_match('/^\d+(?:\.\d{1,8})?$/', $qty) || bccomp($qty, (string) $line->planned_pick_qty, 8) > 0)
                        throw new WorkOrderDomainException('pick_quantity_exceeded', '每条实拣数量必须有效且不能超过计划，未拣填写0。', 422);
                    if ($line->componentItem->materialManagementMode() === 'physical' && (float) $qty > 0
                        && bccomp((string) count(array_unique($row['physical_material_ids'] ?? [])), $qty, 8) !== 0)
                        throw new WorkOrderDomainException('physical_quantity_mismatch', '请逐张选择实拣板材，数量需与所选实物一致。', 422);
                }
                if (! $rows->contains(fn ($row) => (float) $row['actual_pick_qty'] > 0)) throw new WorkOrderDomainException('validation_error', '至少登记一条大于0的实拣数量；全部未拣请取消任务。', 422);
            }
            foreach ($tasks as $task) {
                if ((int) ($payload['child_versions'][$task->id] ?? 0) !== (int) $task->business_version)
                    throw new WorkOrderDomainException('version_conflict', '来源配料单已变化，请刷新后重试。', 409);
                $body = [...$payload, 'expected_version' => $task->business_version, 'client_command_id' => $this->childKey($payload, $action, $task->id)];
                if ($action === 'confirm') {
                    $ownedIds = $task->lines()->pluck('id');
                    $body['lines'] = collect($payload['lines'])->filter(fn ($row) => $ownedIds->contains($row['picking_task_line_id']))->values()->all();
                }
                $method = ['assign' => 'assignPickingTask', 'claim' => 'claimPickingTask', 'start' => 'startPickingTask', 'confirm' => 'confirmPickingTask', 'cancel' => 'cancelPickingTask'][$action];
                if ($action === 'confirm' && ! collect($body['lines'])->contains(fn ($row) => (float) $row['actual_pick_qty'] > 0)) {
                    $method = 'releaseUnpickedPublicTask';
                    $body['reason'] = '公共配料实拣为0，释放本次预留';
                }
                $this->materials->{$method}($task->id, $body, $user, $permissions, $admin, true);
            }
            $partial = $action === 'confirm' && DB::table('erp_material_picking_task_lines')->whereIn('task_id', $tasks->pluck('id'))->whereColumn('actual_pick_qty', '<', 'planned_pick_qty')->exists();
            $job->update(['status' => ['assign' => 'WAIT_PICK', 'claim' => 'WAIT_PICK', 'start' => 'PICKING', 'confirm' => $partial ? 'PARTIALLY_PREPARED' : 'PREPARED', 'cancel' => 'CANCELLED'][$action],
                'assigned_picker_legacy_id' => $payload['assigned_picker_legacy_id'] ?? $job->assigned_picker_legacy_id, 'business_version' => $job->business_version + 1]);
            return $this->show($job->id, [], $user, $permissions, $admin);
        }, fn ($resultId) => $this->show($resultId, [], $user, $permissions, $admin));
    }

    private function visibleQuery(object $user, array $permissions, bool $admin)
    {
        $scope = $this->scope->resolve($user, 'production.material_picking.view', $permissions, $admin);
        $visible = WorkOrder::query()->select('id');
        $this->scope->applyWorkOrderScope($visible, $scope);
        return PublicMaterialPreparationTask::query()->whereHas('tasks')->whereDoesntHave('tasks', fn ($q) => $q->whereNotIn('work_order_id', $visible));
    }

    private function permission(array $permissions, string $action): void
    {
        if (! in_array('production.material_picking.'.$action, $permissions, true)) throw new WorkOrderDomainException('permission_denied', '当前用户没有公共配料操作权限。', 403);
    }
    private function childKey(array $payload, string $action, int $id): string { return 'public-'.hash('sha256', $payload['client_command_id'].':'.$action.':'.$id); }
}
