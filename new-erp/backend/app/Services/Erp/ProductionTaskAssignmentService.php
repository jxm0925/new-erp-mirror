<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\ProductionExecutionCommand;
use App\Models\Erp\ProductionQuantityOperation;
use App\Models\Erp\ProductionTask;
use App\Models\Erp\ProductionUnitOperation;
use App\Models\Erp\WorkOrder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ProductionTaskAssignmentService
{
    public function claim(int $taskId, array $payload, object $user, array $permissions): array
    {
        $this->permission($permissions, 'production.task.claim');
        foreach (['user_id', 'assignee_user_id', 'assignee_user_legacy_id'] as $forbidden) {
            if (array_key_exists($forbidden, $payload)) {
                $this->fail('assignee_spoofing_forbidden', '接单人只能取当前登录用户，前端不得指定其他人员。');
            }
        }

        return $this->command('claim_task', 'production_task', $taskId, $payload, $user, function () use ($taskId, $payload, $user): array {
            $task = ProductionTask::query()->with('targets')->lockForUpdate()->find($taskId);
            if (! $task) $this->fail('task_not_found', '生产任务不存在。', 404);
            $userId = $this->userId($user);
            if ($task->assignee_user_legacy_id && (int) $task->assignee_user_legacy_id !== $userId) {
                $this->fail('task_already_claimed', '该生产任务已被其他员工接单。', 409);
            }
            if ((int) $task->business_version !== (int) $payload['expected_version']) {
                $this->fail('version_conflict', '任务版本已变化，请刷新任务池后重试。', 409, ['current_version' => (int) $task->business_version]);
            }
            if ($task->status !== 'WAIT_CLAIM' || $task->assignee_user_legacy_id) {
                $this->fail('task_already_claimed', '该生产任务已经完成接单，不能重复领取。', 409);
            }

            $version = (int) $task->business_version;
            $claimedAt = now();
            $task->fill([
                'assignee_user_legacy_id' => $userId,
                'claimed_at' => $claimedAt,
                'status' => 'CLAIMED',
                'assignment_mode' => 'manual_claim',
                'assignment_score_snapshot' => null,
                'business_version' => $version + 1,
            ])->save();

            foreach ($task->targets as $link) {
                $target = $this->lockTarget($link->target_type, (int) $link->target_id);
                if ($target->status !== 'WAIT_CLAIM') $this->fail('target_state_conflict', '任务包含的生产目标已被推进，请刷新后重试。', 409);
                $nextStatus = $this->statusAfterClaim($link->target_type, $target);
                $target->fill([
                    'responsible_user_legacy_id' => $userId,
                    'claimed_at' => $claimedAt,
                    'status' => $nextStatus,
                    'business_version' => (int) $target->business_version + 1,
                ])->save();
                $link->update(['status_snapshot' => $nextStatus]);
            }

            $workOrder = WorkOrder::query()->lockForUpdate()->findOrFail($task->work_order_id);
            if (! $workOrder->responsible_user_legacy_id) {
                $workOrder->responsible_user_legacy_id = $userId;
                $workOrder->business_version = (int) $workOrder->business_version + 1;
                $workOrder->save();
            }
            $task->collaborators()->create([
                'employee_legacy_id' => $userId,
                'role' => 'owner',
                'responsibility_weight' => 1,
                'joined_at' => $claimedAt,
                'business_version' => 1,
            ]);
            $this->event('production_task', $task->id, 'claim', 'WAIT_CLAIM', 'CLAIMED', $version, $version + 1, [
                'assignment_mode' => 'manual_claim', 'assignment_score_snapshot' => null,
            ], $user);

            return $this->taskProjection($task->fresh(['targets', 'workOrder']));
        });
    }

    public function recommend(ProductionTask $task): never { $this->notEnabled(); }
    public function autoAssign(ProductionTask $task): never { $this->notEnabled(); }
    public function reassign(ProductionTask $task): never { $this->notEnabled(); }
    public function getCandidateScores(ProductionTask $task): never { $this->notEnabled(); }

    private function statusAfterClaim(string $type, object $target): string
    {
        if ((bool) $target->kitting_required && ! $target->kitting_confirmed_at) return 'WAIT_MATERIAL';
        $handoverPending = DB::table('erp_production_operation_handovers')
            ->where('target_target_type', $type)->where('target_target_id', $target->id)
            ->where('status', 'WAIT_RECEIVE')->exists();
        $internalIssuePending = DB::table('erp_production_internal_issue_tasks')
            ->where('target_type', $type)->where('target_id', $target->id)
            ->whereIn('status', ['WAIT_ISSUE', 'ISSUED'])->exists();
        if ($internalIssuePending) return 'WAIT_MATERIAL';
        return $handoverPending ? 'WAIT_HANDOVER' : 'READY';
    }

    private function lockTarget(string $type, int $id): object
    {
        $model = match ($type) {
            'unit_operation' => ProductionUnitOperation::class,
            'quantity_operation' => ProductionQuantityOperation::class,
            default => $this->fail('task_target_invalid', '生产任务包含无法识别的执行目标。', 409),
        };
        return $model::query()->lockForUpdate()->findOrFail($id);
    }

    private function command(string $type, string $aggregateType, int $aggregateId, array $payload, object $user, callable $action): array
    {
        $commandId = trim((string) ($payload['client_command_id'] ?? ''));
        if ($commandId === '') $this->fail('client_command_id_required', '写操作必须提供 client_command_id。');
        $hashPayload = $payload;
        ksort($hashPayload);
        $hash = hash('sha256', json_encode($hashPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        try {
            return DB::transaction(function () use ($commandId, $type, $aggregateType, $aggregateId, $hash, $user, $action): array {
                $existing = ProductionExecutionCommand::query()->where('client_command_id', $commandId)->lockForUpdate()->first();
                if ($existing) return $this->replay($existing, $type, $hash);

                $ledger = ProductionExecutionCommand::create([
                    'client_command_id' => $commandId,
                    'command_type' => $type,
                    'aggregate_type' => $aggregateType,
                    'aggregate_id' => $aggregateId,
                    'request_hash' => $hash,
                    'status' => 'processing',
                    'initiated_by_legacy_id' => $this->userId($user),
                    'processing_started_at' => now(),
                ]);
                $result = $action();
                $ledger->update([
                    'result_type' => $aggregateType,
                    'result_id' => $aggregateId,
                    'response_snapshot' => $result,
                    'status' => 'succeeded',
                    'processing_finished_at' => now(),
                ]);
                return $result;
            }, 5);
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) throw $e;
            $existing = ProductionExecutionCommand::query()->where('client_command_id', $commandId)->first();
            if ($existing) return $this->replay($existing, $type, $hash);
            throw $e;
        }
    }

    private function replay(ProductionExecutionCommand $command, string $type, string $hash): array
    {
        if ($command->command_type !== $type || $command->request_hash !== $hash) {
            $this->fail('command_conflict', '该 client_command_id 已用于不同请求。', 409);
        }
        if ($command->status !== 'succeeded' || ! is_array($command->response_snapshot)) {
            $this->fail('command_processing', '相同命令正在处理中，请稍后重试。', 409);
        }
        return $command->response_snapshot;
    }

    private function taskProjection(ProductionTask $task): array
    {
        return [
            'id' => (int) $task->id,
            'task_no' => $task->task_no,
            'work_order_id' => (int) $task->work_order_id,
            'status' => $task->status,
            'assignee_user_legacy_id' => (int) $task->assignee_user_legacy_id,
            'assignment_mode' => $task->assignment_mode,
            'assignment_score_snapshot' => $task->assignment_score_snapshot,
            'claimed_at' => optional($task->claimed_at)->toISOString(),
            'business_version' => (int) $task->business_version,
            'targets' => $task->targets->map(fn ($target) => [
                'id' => (int) $target->id,
                'target_type' => $target->target_type,
                'target_id' => (int) $target->target_id,
                'status' => $target->status_snapshot,
            ])->values()->all(),
        ];
    }

    private function event(string $aggregateType, int $aggregateId, string $action, ?string $before, ?string $after, int $beforeVersion, int $afterVersion, array $snapshot, object $user): void
    {
        DB::table('erp_production_execution_events')->insert([
            'aggregate_type' => $aggregateType, 'aggregate_id' => $aggregateId, 'action' => $action,
            'before_status' => $before, 'after_status' => $after, 'before_version' => $beforeVersion,
            'after_version' => $afterVersion, 'fact_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'operator_legacy_id' => $this->userId($user), 'operator_name' => $user->nickname ?? $user->username ?? null,
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function notEnabled(): never { $this->fail('not_enabled', '自动派单当前未启用。', 422); }
    private function permission(array $permissions, string $code): void { if (! in_array($code, $permissions, true)) $this->fail('permission_denied', '当前用户没有执行该操作的权限。', 403, ['permission' => $code]); }
    private function userId(object $user): int { return (int) ($user->legacy_id ?? $user->id ?? 0); }
    private function fail(string $code, string $message, int $status = 422, array $details = []): never { throw new WorkOrderDomainException($code, $message, $status, $details); }
}
