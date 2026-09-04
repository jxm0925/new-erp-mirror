<?php

namespace App\Services\Erp;

use App\Models\Erp\ApprovalBusinessAction;
use App\Models\Erp\ApprovalTask;
use App\Models\Erp\ApprovalTaskNode;

/**
 * Compatibility facade retained for the existing task service.
 * Concrete business behavior is resolved from the registered action handler,
 * never from a business-type branch in the approval core.
 */
class ApprovalBusinessAdapterRegistry
{
    public function __construct(private readonly BusinessActionRegistry $actions) {}

    public function decide(ApprovalTask $task, ApprovalTaskNode $node, string $decision, string $operator, ?string $comment): array
    {
        $nodeAction = ApprovalBusinessAction::query()->where('enabled', true)->where('result_event', 'node_decision')
            ->whereHas('businessObject', fn ($q) => $q->where('object_code', $task->business_object_code))->first();
        if ($nodeAction) {
            return $this->actions->execute($nodeAction->action_code, $task, $node, [], $operator, $decision, $comment);
        }

        $hasNext = ApprovalTaskNode::query()->where('approval_task_id', $task->id)
            ->where('node_order', '>', $node->node_order)->where('node_status', 'WAITING')->exists();
        $event = $decision === 'reject' ? 'rejected' : 'approved';
        $definition = (array) data_get($task->flow_snapshot, 'definition', []);
        $configured = collect((array) ($definition['completion_actions'] ?? []))->firstWhere('event', $event);
        $actionCode = (string) ($configured['action_key'] ?? '');
        $actionResult = null;
        if (($decision === 'reject' || !$hasNext) && $actionCode !== '') {
            $actionResult = $this->actions->execute($actionCode, $task, $node, (array) ($configured['config'] ?? []), $operator, $decision, $comment);
        }
        return [
            'candidate_status' => $decision === 'reject' ? 'REJECTED' : ($hasNext ? 'PENDING' : 'APPROVED'),
            'business_type' => $task->business_type, 'business_id' => $task->business_id,
            'action_key' => $actionCode ?: null, 'action_result' => $actionResult,
        ];
    }
}
