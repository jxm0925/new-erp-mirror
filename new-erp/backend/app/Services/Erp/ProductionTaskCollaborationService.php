<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\ProductionExecutionCommand;
use App\Models\Erp\ProductionQuantityOperation;
use App\Models\Erp\ProductionTask;
use App\Models\Erp\ProductionUnitOperation;
use Illuminate\Support\Facades\DB;

class ProductionTaskCollaborationService
{
    public function __construct(
        private readonly ProductionLaborSessionService $laborSessions,
        private readonly ProductionOperationWorkModeService $workModes,
    ) {}

    public function join(int $taskId, array $payload, object $user, array $permissions): array
    { $this->permission($permissions); return $this->change($taskId, $payload, $user, true); }
    public function leave(int $taskId, array $payload, object $user, array $permissions): array
    { $this->permission($permissions); return $this->change($taskId, $payload, $user, false); }

    public function startLabor(int $taskId, string $targetType, int $targetId, array $payload, object $user, array $permissions): array
    { $this->permission($permissions); return $this->laborChange($taskId, $targetType, $targetId, $payload, $user, true); }

    public function pauseLabor(int $taskId, string $targetType, int $targetId, array $payload, object $user, array $permissions): array
    { $this->permission($permissions); return $this->laborChange($taskId, $targetType, $targetId, $payload, $user, false); }

    public function add(int $taskId, array $payload, object $user, array $permissions): array
    {
        $this->permission($permissions);
        $employeeIds = array_values(array_unique(array_map('intval', $payload['employee_legacy_ids'] ?? [])));
        sort($employeeIds);
        if ($employeeIds === []) $this->fail('collaborators_required', '请至少选择一位协同人员。');
        $commandId = trim((string) ($payload['client_command_id'] ?? ''));
        $hash = hash('sha256', json_encode([$taskId, (int) ($payload['expected_version'] ?? 0), $employeeIds], JSON_UNESCAPED_UNICODE));

        return DB::transaction(function () use ($taskId, $payload, $user, $employeeIds, $commandId, $hash): array {
            $existing = ProductionExecutionCommand::query()->where('client_command_id', $commandId)->lockForUpdate()->first();
            if ($existing) return $this->replay($existing, 'add_task_collaborators', $hash);
            $ledger = ProductionExecutionCommand::create(['client_command_id' => $commandId, 'command_type' => 'add_task_collaborators',
                'aggregate_type' => 'production_task', 'aggregate_id' => $taskId, 'request_hash' => $hash, 'status' => 'processing',
                'initiated_by_legacy_id' => $this->userId($user), 'processing_started_at' => now()]);
            $task = ProductionTask::query()->with('workOrder')->lockForUpdate()->find($taskId);
            if (! $task) $this->fail('task_not_found', '生产任务不存在。', 404);
            if ((int) $task->business_version !== (int) ($payload['expected_version'] ?? 0)) $this->fail('version_conflict', '任务版本已变化，请刷新后重试。', 409);
            if (! $task->workOrder?->collaboration_enabled) $this->fail('collaboration_not_enabled', '该工单未开启协同生产。', 409);
            if (! $task->assignee_user_legacy_id || $task->status === 'WAIT_CLAIM') $this->fail('task_not_claimed', '生产任务尚未接单，不能添加协同。', 409);
            if ((int) $task->assignee_user_legacy_id !== $this->userId($user)) $this->fail('task_owner_required', '只有任务负责人可以添加协同人员。', 403);
            if (in_array($this->userId($user), $employeeIds, true)) $this->fail('task_owner_not_collaborator', '任务负责人不能重复添加为协同人员。');

            $validIds = DB::table('erp_legacy_admin_users as u')->whereIn('u.legacy_id', $employeeIds)->where('u.status', 'normal')
                ->whereExists(function ($permission): void {
                    $permission->selectRaw('1')->from('erp_rbac_user_roles as ur')
                        ->join('erp_rbac_roles as r', 'r.id', '=', 'ur.role_id')
                        ->join('erp_rbac_role_permissions as rp', 'rp.role_id', '=', 'r.id')
                        ->join('erp_rbac_permissions as p', 'p.id', '=', 'rp.permission_id')
                        ->whereColumn('ur.user_legacy_id', 'u.legacy_id')->where('r.enabled', true)->where('p.enabled', true)
                        ->where('p.code', 'production.task.collaborate');
                })->pluck('u.legacy_id')->map(fn ($id) => (int) $id)->all();
            if (count($validIds) !== count($employeeIds)) $this->fail('collaborator_invalid', '所选人员中存在停用账号或缺少生产协同权限的人员。');

            $activeIds = $task->collaborators()->whereIn('employee_legacy_id', $employeeIds)->whereNull('left_at')
                ->pluck('employee_legacy_id')->map(fn ($id) => (int) $id)->all();
            $addedIds = array_values(array_diff($employeeIds, $activeIds));
            $now = now();
            foreach ($addedIds as $employeeId) {
                $task->collaborators()->create(['employee_legacy_id' => $employeeId, 'role' => 'collaborator',
                    'responsibility_weight' => 0, 'joined_at' => $now, 'business_version' => 1]);
            }
            if ($addedIds !== []) $task->update(['business_version' => (int) $task->business_version + 1]);
            $result = ['task_id' => (int) $task->id, 'added_employee_legacy_ids' => $addedIds,
                'existing_employee_legacy_ids' => $activeIds, 'task_business_version' => (int) $task->business_version,
                'occurred_at' => $now->toISOString()];
            $ledger->update(['result_type' => 'production_task', 'result_id' => $taskId, 'response_snapshot' => $result,
                'status' => 'succeeded', 'processing_finished_at' => now()]);
            return $result;
        }, 5);
    }

    private function change(int $taskId, array $payload, object $user, bool $join): array
    {
        $commandType = $join ? 'join_task_collaboration' : 'leave_task_collaboration';
        $commandId = trim((string) ($payload['client_command_id'] ?? ''));
        $hash = hash('sha256', json_encode([$taskId, (int) ($payload['expected_version'] ?? 0)], JSON_UNESCAPED_UNICODE));
        return DB::transaction(function () use ($taskId, $payload, $user, $join, $commandType, $commandId, $hash): array {
            $existing = ProductionExecutionCommand::query()->where('client_command_id', $commandId)->lockForUpdate()->first();
            if ($existing) return $this->replay($existing, $commandType, $hash);
            $ledger = ProductionExecutionCommand::create(['client_command_id' => $commandId, 'command_type' => $commandType,
                'aggregate_type' => 'production_task', 'aggregate_id' => $taskId, 'request_hash' => $hash, 'status' => 'processing',
                'initiated_by_legacy_id' => $this->userId($user), 'processing_started_at' => now()]);
            $task = ProductionTask::query()->with('workOrder')->lockForUpdate()->find($taskId);
            if (! $task) $this->fail('task_not_found', '生产任务不存在。', 404);
            if ((int) $task->business_version !== (int) ($payload['expected_version'] ?? 0)) $this->fail('version_conflict', '任务版本已变化，请刷新后重试。', 409);
            if (! $task->workOrder?->collaboration_enabled) $this->fail('collaboration_not_enabled', '该工单未开启协同生产。', 409);
            if (! $task->assignee_user_legacy_id || $task->status === 'WAIT_CLAIM') $this->fail('task_not_claimed', '生产任务尚未接单，不能加入协同。', 409);
            $userId = $this->userId($user); $now = now();
            if ((int) $task->assignee_user_legacy_id === $userId) $this->fail('task_owner_cannot_leave', '任务负责人不通过协作者接口加入或退出。', 409);
            $active = $task->collaborators()->where('employee_legacy_id', $userId)->whereNull('left_at')->lockForUpdate()->first();
            if ($join) {
                if ($active) $this->fail('collaborator_already_joined', '当前人员已经在该协同任务中。', 409);
                $task->collaborators()->create(['employee_legacy_id' => $userId, 'role' => 'collaborator',
                    'responsibility_weight' => 0, 'joined_at' => $now, 'business_version' => 1]);
            } else {
                if (! $active) $this->fail('collaborator_not_joined', '当前人员不在该协同任务中。', 409);
                $this->laborSessions->endActiveForTask($task, $userId, 'collaborator_left', $now);
                $active->update(['left_at' => $now, 'business_version' => (int) $active->business_version + 1]);
            }
            $task->update(['business_version' => (int) $task->business_version + 1]);
            $result = ['task_id' => (int) $task->id, 'employee_legacy_id' => $userId,
                'collaboration_status' => $join ? 'JOINED' : 'LEFT', 'task_business_version' => (int) $task->business_version,
                'occurred_at' => $now->toISOString()];
            $ledger->update(['result_type' => 'production_task', 'result_id' => $taskId, 'response_snapshot' => $result,
                'status' => 'succeeded', 'processing_finished_at' => now()]);
            return $result;
        }, 5);
    }

    private function laborChange(int $taskId, string $targetType, int $targetId, array $payload, object $user, bool $start): array
    {
        $commandType = $start ? 'start_collaborator_labor' : 'pause_collaborator_labor';
        $commandId = trim((string) ($payload['client_command_id'] ?? ''));
        $hash = hash('sha256', json_encode([$taskId, $targetType, $targetId, (int) ($payload['expected_version'] ?? 0)], JSON_UNESCAPED_UNICODE));
        return DB::transaction(function () use ($taskId, $targetType, $targetId, $payload, $user, $start, $commandType, $commandId, $hash): array {
            $existing = ProductionExecutionCommand::query()->where('client_command_id', $commandId)->lockForUpdate()->first();
            if ($existing) return $this->replay($existing, $commandType, $hash);
            $ledger = ProductionExecutionCommand::create(['client_command_id' => $commandId, 'command_type' => $commandType,
                'aggregate_type' => $targetType, 'aggregate_id' => $targetId, 'request_hash' => $hash, 'status' => 'processing',
                'initiated_by_legacy_id' => $this->userId($user), 'processing_started_at' => now()]);
            $task = ProductionTask::query()->with(['workOrder', 'targets'])->lockForUpdate()->find($taskId);
            if (! $task || ! $task->targets->contains(fn ($row) => $row->target_type === $targetType && (int) $row->target_id === $targetId)) $this->fail('task_target_not_found', '任务中不存在该生产执行目标。', 404);
            if (! $task->workOrder?->collaboration_enabled) $this->fail('collaboration_not_enabled', '该工单未开启协同生产。', 409);
            $userId = $this->userId($user);
            $collaborator = $task->collaborators()->where('employee_legacy_id', $userId)->where('role', 'collaborator')->whereNull('left_at')->lockForUpdate()->first();
            if (! $collaborator) $this->fail('collaborator_not_joined', '当前人员不在该协同任务中。', 403);
            $target = $this->target($targetType, $targetId);
            if ((int) $target->business_version !== (int) ($payload['expected_version'] ?? 0)) $this->fail('version_conflict', '生产目标版本已变化，请刷新后重试。', 409);
            $now = now();
            if ($start) {
                if (! in_array($target->status, ['IN_PROGRESS', 'PAUSED'], true)) $this->fail('target_not_started', '负责人尚未正式开工，协作者不能启动计时。', 409);
                $this->laborSessions->start($task, $target, $targetType, $userId, 'collaborator', (float) $collaborator->responsibility_weight, $now, $payload);
                $this->workModes->recalculate($task, $target, $targetType, $now);
            } else {
                if (! in_array($target->status, ['IN_PROGRESS', 'PAUSED'], true)) $this->fail('target_not_in_progress', '当前生产目标不在可暂停协同计时的状态。', 409);
                $this->laborSessions->end($task, $target, $targetType, $userId, 'collaborator_paused', $now);
            }
            $result = ['task_id' => (int) $task->id, 'target_type' => $targetType, 'target_id' => (int) $target->id,
                'target_status' => $target->status, 'target_business_version' => (int) $target->business_version,
                'labor_status' => $start ? 'ACTIVE' : 'ENDED', 'occurred_at' => $now->toISOString()];
            $ledger->update(['result_type' => $targetType, 'result_id' => $targetId, 'response_snapshot' => $result,
                'status' => 'succeeded', 'processing_finished_at' => now()]);
            return $result;
        }, 5);
    }

    private function target(string $type, int $id): object
    {
        $model = $type === 'unit_operation' ? ProductionUnitOperation::class : ($type === 'quantity_operation' ? ProductionQuantityOperation::class : null);
        if (! $model) $this->fail('task_target_invalid', '生产执行目标类型无效。');
        $target = $model::query()->lockForUpdate()->find($id);
        if (! $target) $this->fail('task_target_not_found', '生产执行目标不存在。', 404);
        return $target;
    }
    private function replay(ProductionExecutionCommand $command, string $type, string $hash): array
    { if ($command->command_type !== $type || $command->request_hash !== $hash) $this->fail('command_conflict', '该 client_command_id 已用于不同请求。', 409); if ($command->status !== 'succeeded') $this->fail('command_processing', '相同命令正在处理中，请稍后重试。', 409); return $command->response_snapshot; }
    private function permission(array $permissions): void { if (! in_array('production.task.collaborate', $permissions, true)) $this->fail('permission_denied', '当前用户没有协同生产权限。', 403); }
    private function userId(object $user): int { return (int) ($user->legacy_id ?? $user->id ?? 0); }
    private function fail(string $code, string $message, int $status = 422): never { throw new WorkOrderDomainException($code, $message, $status); }
}
