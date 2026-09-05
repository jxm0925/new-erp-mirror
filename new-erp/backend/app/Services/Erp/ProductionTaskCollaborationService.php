<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\ProductionExecutionCommand;
use App\Models\Erp\ProductionTask;
use Illuminate\Support\Facades\DB;

class ProductionTaskCollaborationService
{
    public function join(int $taskId, array $payload, object $user, array $permissions): array
    { $this->permission($permissions); return $this->change($taskId, $payload, $user, true); }
    public function leave(int $taskId, array $payload, object $user, array $permissions): array
    { $this->permission($permissions); return $this->change($taskId, $payload, $user, false); }

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
                if ($task->laborSessions()->where('employee_legacy_id', $userId)->where('status', 'ACTIVE')->exists())
                    $this->fail('active_labor_session_exists', '当前人员仍有进行中的加工计时，必须先暂停或完成。', 409);
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
    private function replay(ProductionExecutionCommand $command, string $type, string $hash): array
    { if ($command->command_type !== $type || $command->request_hash !== $hash) $this->fail('command_conflict', '该 client_command_id 已用于不同请求。', 409); if ($command->status !== 'succeeded') $this->fail('command_processing', '相同命令正在处理中，请稍后重试。', 409); return $command->response_snapshot; }
    private function permission(array $permissions): void { if (! in_array('production.task.collaborate', $permissions, true)) $this->fail('permission_denied', '当前用户没有协同生产权限。', 403); }
    private function userId(object $user): int { return (int) ($user->legacy_id ?? $user->id ?? 0); }
    private function fail(string $code, string $message, int $status = 422): never { throw new WorkOrderDomainException($code, $message, $status); }
}
