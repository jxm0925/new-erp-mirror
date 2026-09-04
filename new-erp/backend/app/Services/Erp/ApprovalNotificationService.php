<?php

namespace App\Services\Erp;

use App\Events\ApprovalTaskChanged;
use App\Models\Erp\ApprovalNotification;
use App\Models\Erp\ApprovalTask;
use App\Models\Erp\ApprovalTaskNode;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Auth\Access\AuthorizationException;

class ApprovalNotificationService
{
    public function paginate(int $userId, array $filters = []): LengthAwarePaginator
    {
        return ApprovalNotification::query()->where('user_id', $userId)->with('task:id,task_no,subject,task_status')
            ->when(($filters['status'] ?? null), fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('notified_at')->paginate(min(max((int) ($filters['per_page'] ?? 20), 1), 100));
    }

    public function unreadCount(int $userId): int
    {
        return ApprovalNotification::query()->where('user_id', $userId)->where('status', 'UNREAD')->count();
    }

    public function markRead(int $id, int $userId): ApprovalNotification
    {
        $notification = ApprovalNotification::query()->findOrFail($id);
        if ((int) $notification->user_id !== $userId) throw new AuthorizationException('无权操作该审批通知。');
        $notification->update(['status' => 'READ', 'read_at' => now()]);
        return $notification;
    }

    public function notifyNode(ApprovalTask $task, ApprovalTaskNode $node, string $type = 'ASSIGNED'): void
    {
        if ((bool) data_get($task->flow_snapshot, 'definition.notifications.in_app', true)) {
            foreach ($node->assignees()->where('status', 'PENDING')->get() as $assignee) {
                ApprovalNotification::query()->firstOrCreate(['dedup_key' => implode(':', [$type, $task->id, $node->id, $assignee->user_id])], [
                    'approval_task_id' => $task->id, 'approval_task_node_id' => $node->id, 'user_id' => $assignee->user_id,
                    'notification_type' => $type, 'title' => $type === 'REMINDER' ? '审核任务催办' : ($type === 'CC' ? '审批抄送通知' : '新的待审核任务'),
                    'content' => $task->subject.' · '.$node->node_name, 'status' => 'UNREAD', 'notified_at' => now(),
                ]);
            }
        }
        event(new ApprovalTaskChanged($task));
    }

    public function sendDueReminders(): int
    {
        $count = 0;
        ApprovalTaskNode::query()->where('node_status', 'PENDING')->whereNotNull('due_at')->where('due_at', '<=', now())
            ->with('task')->chunkById(100, function ($nodes) use (&$count) {
                foreach ($nodes as $node) {
                    $definition = collect(data_get($node->task->flow_snapshot, 'definition.nodes', []))->firstWhere('key', $node->node_key) ?: [];
                    if (!($definition['reminder_enabled'] ?? false)) continue;
                    $interval = max(1, (int) ($definition['reminder_hours'] ?? 1));
                    $bucket = now()->floorHours($interval)->format('YmdH');
                    foreach ($node->assignees()->where('status', 'PENDING')->get() as $assignee) {
                        $created = ApprovalNotification::query()->firstOrCreate(['dedup_key' => "REMINDER:{$node->task->id}:{$node->id}:{$assignee->user_id}:{$bucket}"], [
                            'approval_task_id' => $node->task->id, 'approval_task_node_id' => $node->id, 'user_id' => $assignee->user_id,
                            'notification_type' => 'REMINDER', 'title' => '审核任务已超时', 'content' => $node->task->subject.' · '.$node->node_name,
                            'status' => 'UNREAD', 'notified_at' => now(),
                        ]);
                        if ($created->wasRecentlyCreated) $count++;
                    }
                    event(new ApprovalTaskChanged($node->task));
                }
            });
        return $count;
    }
}
