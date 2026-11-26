<?php

namespace App\Services\Erp\ApprovalActions;

use App\Models\Erp\ApprovalBusinessAction;
use App\Models\Erp\ApprovalTask;
use App\Models\Erp\ApprovalTaskNode;
use App\Services\Erp\Contracts\ApprovalBusinessActionHandler;
use App\Services\Erp\PurchaseWorkflowApplicationService;

class PurchasePlanAction implements ApprovalBusinessActionHandler
{
    public function execute(ApprovalBusinessAction $action, ApprovalTask $task, ?ApprovalTaskNode $node, array $config, string $operator, ?string $decision = null, ?string $comment = null): array
    {
        $rejected = $action->result_event === 'rejected';
        // 完成动作可能由抄送节点或重试触发，业务操作人取真实决定，不能记成系统或重试人。
        $approval = $task->latestDecisionForResult($action->result_event);
        $operator = $approval?->approver_name ?: '系统';
        $service = app(PurchaseWorkflowApplicationService::class);
        $row = $rejected
            ? $service->rejectPlan($task->business_id, $approval?->comment ?: $comment ?: '审核不通过', $operator, $task->id)
            : $service->approvePlan($task->business_id, $operator, $task->id);
        return $row->only(['id', 'plan_no', 'plan_status', 'audit_status']);
    }
}
