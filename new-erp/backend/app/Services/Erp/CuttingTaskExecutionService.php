<?php

namespace App\Services\Erp;

use App\Models\Erp\CuttingTask;
use App\Models\Erp\ProductionLaborSession;
use Illuminate\Support\Facades\DB;

final class CuttingTaskExecutionService
{
    public function __construct(
        private readonly CuttingCommandService $commands,
        private readonly ProductionLaborSessionService $laborSessions,
    ) {}

    public function claim(int $taskId, array $payload, object $user, array $permissions, bool $super = false): array
    {
        $this->authorize($taskId, $user, $permissions, $super, 'production.cutting.task.claim');
        return $this->commands->run('claim_cutting_task', $taskId, $payload, $user, function () use ($taskId, $payload, $user, $permissions, $super): array {
            $task = $this->lock($taskId, $user, $permissions, $super, 'production.cutting.task.claim');
            $this->version($task, $payload);
            if ($task->status !== 'WAIT_CLAIM' || $task->assignee_user_legacy_id) {
                $this->commands->fail('cutting_task_already_claimed', '该下料任务已经被领取。', 409);
            }
            $before = clone $task; $now = now(); $actor = $this->commands->actor($user);
            $task->fill(['status' => 'READY', 'assignee_user_legacy_id' => $actor, 'claimed_at' => $now,
                'business_version' => (int) $task->business_version + 1])->save();
            $task->participants()->create(['employee_legacy_id' => $actor, 'role' => 'owner', 'responsibility_weight' => 1,
                'joined_at' => $now, 'active_participant_key' => $task->id.':'.$actor, 'business_version' => 1]);
            return $this->record($task, 'claim', $user, $before);
        });
    }

    public function start(int $taskId, array $payload, object $user, array $permissions, bool $super = false): array
    {
        return $this->ownerLifecycle('start_cutting_task', 'production.cutting.task.start', $taskId, $payload, $user, $permissions, $super,
            function (CuttingTask $task, int $actor) use ($payload): void {
                if ($task->status !== 'READY') $this->commands->fail('cutting_task_not_ready', '只有已领取且待开工的下料任务可以开工。', 409);
                if (! DB::table('erp_cutting_settlement_batches')->where('cutting_task_id', $task->id)->whereNotNull('issue_transaction_id')->exists()) {
                    $this->commands->fail('cutting_input_not_issued', '下料任务尚无正式领出的用料，不能开工。', 409);
                }
                $now = now();
                $task->fill(['status' => 'IN_PROGRESS', 'started_at' => $task->started_at ?: $now, 'paused_at' => null,
                    'business_version' => (int) $task->business_version + 1])->save();
                $this->laborSessions->startCutting($task, $actor, 'owner', 1, $now, $payload);
            });
    }

    public function pause(int $taskId, array $payload, object $user, array $permissions, bool $super = false): array
    {
        return $this->ownerLifecycle('pause_cutting_task', 'production.cutting.task.pause', $taskId, $payload, $user, $permissions, $super,
            function (CuttingTask $task, int $actor): void {
                if ($task->status !== 'IN_PROGRESS') $this->commands->fail('cutting_task_not_in_progress', '只有进行中的下料任务可以暂停本人计时。', 409);
                $this->laborSessions->endCutting($task, $actor, 'owner_paused', now());
            });
    }

    public function resume(int $taskId, array $payload, object $user, array $permissions, bool $super = false): array
    {
        return $this->ownerLifecycle('resume_cutting_task', 'production.cutting.task.resume', $taskId, $payload, $user, $permissions, $super,
            function (CuttingTask $task, int $actor) use ($payload): void {
                if (! in_array($task->status, ['IN_PROGRESS', 'PAUSED'], true) || ! $task->started_at) {
                    $this->commands->fail('cutting_task_not_resumable', '只有已经开工且本人当前未计时的下料任务可以恢复。', 409);
                }
                $now = now();
                $this->laborSessions->startCutting($task, $actor, 'owner', 1, $now, $payload);
                $this->laborSessions->recalculateCuttingTask($task, $now);
            });
    }

    public function finish(int $taskId, array $payload, object $user, array $permissions, bool $super = false): array
    {
        return $this->ownerLifecycle('finish_cutting_task', 'production.cutting.task.finish', $taskId, $payload, $user, $permissions, $super,
            function (CuttingTask $task, int $actor): void {
                if (! in_array($task->status, ['IN_PROGRESS', 'PAUSED'], true)) {
                    $this->commands->fail('cutting_task_not_finishable', '只有已开工的下料任务可以完成。', 409);
                }
                $now = now();
                $this->laborSessions->endCutting($task, $actor, 'task_finished', $now, false, false);
                if (ProductionLaborSession::query()->where('execution_task_type', 'CUTTING_TASK')
                    ->where('cutting_task_id', $task->id)->where('status', 'ACTIVE')->exists()) {
                    $this->commands->fail('cutting_collaborator_labor_active', '仍有协作者正在下料计时，必须先结束全部协同计时。', 409);
                }
                $task->fill(['status' => 'FINISHED', 'completed_at' => $now, 'paused_at' => null,
                    'business_version' => (int) $task->business_version + 1])->save();
            });
    }

    public function addCollaborators(int $taskId, array $payload, object $user, array $permissions, bool $super = false): array
    {
        $permission = 'production.cutting.task.collaborate';
        $this->authorize($taskId, $user, $permissions, $super, $permission);
        return $this->commands->run('add_cutting_task_collaborators', $taskId, $payload, $user, function () use ($taskId, $payload, $user, $permissions, $super, $permission): array {
            $task = $this->lock($taskId, $user, $permissions, $super, $permission); $this->version($task, $payload); $this->owner($task, $user);
            if (in_array($task->status, ['WAIT_CLAIM', 'FINISHED', 'CANCELLED'], true)) {
                $this->commands->fail('cutting_task_collaboration_not_allowed', '当前下料任务不能添加协作者。', 409);
            }
            $ids = array_values(array_unique(array_map('intval', $payload['employee_legacy_ids'] ?? []))); sort($ids);
            if ($ids === []) $this->commands->fail('collaborators_required', '请至少选择一位协作者。');
            if (in_array((int) $task->assignee_user_legacy_id, $ids, true)) $this->commands->fail('task_owner_not_collaborator', '负责人不能重复添加为协作者。');
            $this->assertEligibleCollaborators($ids);
            $active = $task->participants()->whereIn('employee_legacy_id', $ids)->whereNull('left_at')->pluck('employee_legacy_id')
                ->map(fn ($id) => (int) $id)->all();
            $added = array_values(array_diff($ids, $active)); $before = clone $task; $now = now();
            foreach ($added as $id) $task->participants()->create(['employee_legacy_id' => $id, 'role' => 'collaborator',
                'responsibility_weight' => 0, 'joined_at' => $now, 'active_participant_key' => $task->id.':'.$id, 'business_version' => 1]);
            if ($added !== []) $task->update(['business_version' => (int) $task->business_version + 1]);
            $result = $this->record($task, 'add_collaborators', $user, $before);
            $result['added_employee_legacy_ids'] = $added; $result['existing_employee_legacy_ids'] = $active;
            return $result;
        });
    }

    public function leaveCollaboration(int $taskId, array $payload, object $user, array $permissions, bool $super = false): array
    {
        $permission = 'production.cutting.task.collaborate';
        $this->authorize($taskId, $user, $permissions, $super, $permission);
        return $this->commands->run('leave_cutting_task_collaboration', $taskId, $payload, $user, function () use ($taskId, $payload, $user, $permissions, $super, $permission): array {
            $task = $this->lock($taskId, $user, $permissions, $super, $permission); $this->version($task, $payload);
            $actor = $this->commands->actor($user);
            if ((int) $task->assignee_user_legacy_id === $actor) $this->commands->fail('task_owner_cannot_leave', '任务负责人不能通过协作者接口退出。', 409);
            $participant = $task->participants()->where('employee_legacy_id', $actor)->where('role', 'collaborator')->whereNull('left_at')->lockForUpdate()->first();
            if (! $participant) $this->commands->fail('collaborator_not_joined', '当前人员不在该下料任务协作组中。', 409);
            $before = clone $task; $now = now();
            $this->laborSessions->endCutting($task, $actor, 'collaborator_left', $now, false);
            $participant->update(['left_at' => $now, 'active_participant_key' => null,
                'business_version' => (int) $participant->business_version + 1]);
            $task->update(['business_version' => (int) $task->business_version + 1]);
            return $this->record($task, 'leave_collaboration', $user, $before);
        });
    }

    public function startCollaboratorLabor(int $taskId, array $payload, object $user, array $permissions, bool $super = false): array
    {
        return $this->collaboratorLabor(true, $taskId, $payload, $user, $permissions, $super);
    }

    public function pauseCollaboratorLabor(int $taskId, array $payload, object $user, array $permissions, bool $super = false): array
    {
        return $this->collaboratorLabor(false, $taskId, $payload, $user, $permissions, $super);
    }

    private function collaboratorLabor(bool $start, int $taskId, array $payload, object $user, array $permissions, bool $super): array
    {
        $permission = 'production.cutting.task.collaborate';
        $this->authorize($taskId, $user, $permissions, $super, $permission);
        $command = $start ? 'start_cutting_collaborator_labor' : 'pause_cutting_collaborator_labor';
        return $this->commands->run($command, $taskId, $payload, $user, function () use ($start, $taskId, $payload, $user, $permissions, $super, $permission): array {
            $task = $this->lock($taskId, $user, $permissions, $super, $permission); $this->version($task, $payload);
            $actor = $this->commands->actor($user);
            $participant = $task->participants()->where('employee_legacy_id', $actor)->where('role', 'collaborator')->whereNull('left_at')->lockForUpdate()->first();
            if (! $participant) $this->commands->fail('collaborator_not_joined', '当前人员不在该下料任务协作组中。', 403);
            if (! in_array($task->status, ['IN_PROGRESS', 'PAUSED'], true) || ! $task->started_at) {
                $this->commands->fail('cutting_task_not_started', '负责人尚未正式开工，协作者不能启动或暂停计时。', 409);
            }
            $before = clone $task; $now = now();
            if ($start) {
                $this->laborSessions->startCutting($task, $actor, 'collaborator', (float) $participant->responsibility_weight, $now, $payload);
                $this->laborSessions->recalculateCuttingTask($task, $now);
            } else {
                $this->laborSessions->endCutting($task, $actor, 'collaborator_paused', $now);
            }
            return $this->record($task, $start ? 'start_collaborator_labor' : 'pause_collaborator_labor', $user, $before);
        });
    }

    private function ownerLifecycle(string $command, string $permission, int $taskId, array $payload, object $user, array $permissions, bool $super, callable $action): array
    {
        $this->authorize($taskId, $user, $permissions, $super, $permission);
        return $this->commands->run($command, $taskId, $payload, $user, function () use ($taskId, $payload, $user, $permissions, $super, $permission, $action, $command): array {
            $task = $this->lock($taskId, $user, $permissions, $super, $permission); $this->version($task, $payload); $this->owner($task, $user);
            $before = clone $task; $action($task, $this->commands->actor($user));
            return $this->record($task, $command, $user, $before);
        });
    }

    private function authorize(int $taskId, object $user, array $permissions, bool $super, string $permission): void
    {
        $this->commands->cuttingTask($taskId, $user, $permissions, $super, $permission);
    }

    private function lock(int $taskId, object $user, array $permissions, bool $super, string $permission): CuttingTask
    {
        return $this->commands->cuttingTask($taskId, $user, $permissions, $super, $permission, true);
    }

    private function version(CuttingTask $task, array $payload): void { $this->commands->version($task, $payload); }

    private function owner(CuttingTask $task, object $user): void
    {
        if ((int) $task->assignee_user_legacy_id !== $this->commands->actor($user)) {
            $this->commands->fail('cutting_task_owner_required', '只有下料任务负责人可以执行该操作。', 403);
        }
    }

    private function assertEligibleCollaborators(array $ids): void
    {
        $valid = DB::table('erp_legacy_admin_users as u')->whereIn('u.legacy_id', $ids)->where('u.status', 'normal')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')->from('erp_rbac_user_roles as ur')->join('erp_rbac_roles as r', 'r.id', '=', 'ur.role_id')
                    ->join('erp_rbac_role_permissions as rp', 'rp.role_id', '=', 'r.id')->join('erp_rbac_permissions as p', 'p.id', '=', 'rp.permission_id')
                    ->whereColumn('ur.user_legacy_id', 'u.legacy_id')->where('r.enabled', true)->where('p.enabled', true)
                    ->where('p.code', 'production.cutting.task.collaborate');
            })->pluck('u.legacy_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        if ($valid !== $ids) $this->commands->fail('collaborator_invalid', '所选人员中存在停用账号或缺少下料协作权限的人员。');
    }

    private function record(CuttingTask $task, string $action, object $user, CuttingTask $before): array
    {
        $task->refresh(); $result = $this->projection($task);
        $this->commands->event('cutting_task', (int) $task->id, $action, $user, $before->toArray(), $result);
        return $result;
    }

    private function projection(CuttingTask $task): array
    {
        $active = ProductionLaborSession::query()->where('execution_task_type', 'CUTTING_TASK')->where('cutting_task_id', $task->id)
            ->where('status', 'ACTIVE')->orderBy('id')->get(['id', 'employee_legacy_id', 'role', 'started_at']);
        return ['id' => (int) $task->id, 'cutting_order_id' => (int) $task->cutting_order_id, 'task_no' => $task->task_no,
            'status' => $task->status, 'assignee_user_legacy_id' => $task->assignee_user_legacy_id ? (int) $task->assignee_user_legacy_id : null,
            'claimed_at' => optional($task->claimed_at)->toISOString(), 'started_at' => optional($task->started_at)->toISOString(),
            'paused_at' => optional($task->paused_at)->toISOString(), 'completed_at' => optional($task->completed_at)->toISOString(),
            'actual_labor_minutes' => (string) $task->actual_labor_minutes, 'business_version' => (int) $task->business_version,
            'active_labor_sessions' => $active->map(fn ($row) => ['id' => (int) $row->id,
                'employee_legacy_id' => (int) $row->employee_legacy_id, 'role' => $row->role,
                'started_at' => optional($row->started_at)->toISOString()])->all(),
            'settlement_statuses' => DB::table('erp_cutting_settlement_batches')->where('cutting_task_id', $task->id)
                ->selectRaw('status, COUNT(*) AS count')->groupBy('status')->orderBy('status')->pluck('count', 'status')->map(fn ($v) => (int) $v)->all()];
    }
}
