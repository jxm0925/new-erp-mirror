<?php

namespace App\Events;

use App\Models\Erp\ApprovalTask;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ApprovalTaskChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public ApprovalTask $task) {}
    public function broadcastOn(): array
    {
        $task = $this->task->fresh(['assignees']);
        $userIds = $task->assignees->pluck('user_id')->push($task->initiator_id)->filter()->unique();
        return $userIds->map(fn ($userId) => new PrivateChannel('approval-user.'.(int) $userId))->values()->all();
    }
    public function broadcastAs(): string { return 'approval.task.changed'; }
    public function broadcastWhen(): bool { return (bool) data_get($this->task->flow_snapshot, 'definition.notifications.websocket', true); }
    public function broadcastWith(): array
    {
        $task = $this->task->fresh(['nodes']);
        return [
            'task_id' => $task->id, 'task_no' => $task->task_no, 'business_type' => $task->business_type,
            'business_id' => $task->business_id, 'business_no' => $task->business_no, 'subject' => $task->subject,
            'risk_level' => $task->risk_level, 'task_status' => $task->task_status,
            'current_node' => optional($task->nodes->firstWhere('node_status', 'PENDING'))->node_name,
            'submitted_at' => optional($task->submitted_at)->toDateTimeString(),
        ];
    }
}
