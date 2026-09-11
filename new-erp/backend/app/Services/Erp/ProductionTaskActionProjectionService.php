<?php

namespace App\Services\Erp;

use Illuminate\Support\Collection;

class ProductionTaskActionProjectionService
{
    public function project(object $task, object $target, object $user, array $permissions, Collection $activeLabor, array $readiness): array
    {
        $userId = (int) ($user->legacy_id ?? $user->id ?? 0);
        $status = (string) $target->status;
        $isOwner = (int) $task->assignee_user_legacy_id === $userId;
        $isCollaborator = (bool) $task->workOrder?->collaboration_enabled
            && $task->collaborators->contains(fn ($row) => $row->role === 'collaborator'
                && (int) $row->employee_legacy_id === $userId && $row->left_at === null);
        $myActive = $activeLabor->contains(fn ($session) => (int) $session->employee_legacy_id === $userId);
        $has = fn (string $permission): bool => in_array($permission, $permissions, true);

        $allowed = [
            'confirm_kitting' => $isOwner && ! $target->started_at && (bool) ($readiness['confirm_kitting_allowed'] ?? false)
                && $has('production.kitting.confirm'),
            'start' => $isOwner && ! $target->started_at && ! (bool) $target->kitting_required && $status === 'READY'
                && $has('production.task.start'),
            'start_rework' => $isOwner && $status === 'REWORK' && $has('production.task.start'),
            'pause' => $isOwner && $status === 'IN_PROGRESS' && $myActive && $has('production.task.pause'),
            'resume' => $isOwner && ! $myActive && $target->started_at
                && in_array($status, ['IN_PROGRESS', 'PAUSED'], true) && $has('production.task.resume'),
            'complete' => $isOwner && in_array($status, ['IN_PROGRESS', 'PAUSED'], true)
                && $has('production.task.complete'),
            'accept_handover' => $isOwner && $status === 'WAIT_HANDOVER'
                && $has('production.handover.receive'),
            'start_collaborator_labor' => $isCollaborator && ! $myActive
                && in_array($status, ['IN_PROGRESS', 'PAUSED'], true) && $has('production.task.collaborate'),
            'pause_collaborator_labor' => $isCollaborator && $myActive
                && in_array($status, ['IN_PROGRESS', 'PAUSED'], true) && $has('production.task.collaborate'),
        ];
        $labels = [
            'confirm_kitting' => ['code' => 'confirm_kitting_and_start', 'label' => '确认齐套并开工'],
            'start' => ['code' => 'start', 'label' => '开始加工'],
            'start_rework' => ['code' => 'start_rework', 'label' => '开始返工'],
            'pause' => ['code' => 'pause_my_work', 'label' => '暂停我的作业'],
            'resume' => ['code' => 'resume_my_work', 'label' => '恢复我的作业'],
            'accept_handover' => ['code' => 'accept_handover', 'label' => '接收交接'],
            'start_collaborator_labor' => ['code' => 'start_collaborator_labor', 'label' => '开始协同计时'],
            'pause_collaborator_labor' => ['code' => 'pause_collaborator_labor', 'label' => '暂停协同计时'],
            'complete' => ['code' => 'complete', 'label' => '完成本工序'],
        ];
        $ordered = ['confirm_kitting', 'start', 'start_rework', 'pause', 'resume', 'accept_handover', 'start_collaborator_labor', 'pause_collaborator_labor', 'complete'];
        $actions = collect($ordered)->filter(fn (string $key): bool => $allowed[$key])->map(fn (string $key): array => $labels[$key])->values()->all();

        return [
            'my_role' => $isOwner ? 'owner' : ($isCollaborator ? 'collaborator' : 'viewer'),
            'allowed_actions' => $allowed,
            'primary_action' => $actions[0] ?? null,
            'secondary_actions' => array_slice($actions, 1),
        ];
    }
}
