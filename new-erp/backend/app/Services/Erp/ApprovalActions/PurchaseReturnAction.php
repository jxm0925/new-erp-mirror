<?php

namespace App\Services\Erp\ApprovalActions;

use App\Models\Erp\ApprovalBusinessAction;
use App\Models\Erp\ApprovalTask;
use App\Models\Erp\ApprovalTaskNode;
use App\Services\Erp\Contracts\ApprovalBusinessActionHandler;
use App\Services\Erp\PurchaseReturnApplicationService;

class PurchaseReturnAction implements ApprovalBusinessActionHandler
{
    public function execute(ApprovalBusinessAction $action, ApprovalTask $task, ?ApprovalTaskNode $node, array $config, string $operator, ?string $decision = null, ?string $comment = null): array
    {
        $rejected = $action->result_event === 'rejected';
        // 决策行在回调前已写入；使用它的身份快照，避免重试把原审核人覆盖为重试人。
        $approval = $task->latestDecisionForResult($action->result_event);
        $operatorId = $approval?->approver_id;
        $operator = $approval?->approver_name ?: '系统';
        $service = app(PurchaseReturnApplicationService::class);
        $row = $rejected
            ? $service->reject($task->business_id, $operatorId, $operator, $task->id, $approval?->comment ?: $comment)
            : $service->approve($task->business_id, $operatorId, $operator, $task->id);
        return $row->only(['id', 'return_no', 'return_status', 'audit_status']);
    }
}
