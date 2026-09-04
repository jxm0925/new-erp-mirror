<?php

namespace App\Services\Erp\ApprovalActions;

use App\Models\Erp\ApprovalBusinessAction;
use App\Models\Erp\ApprovalTask;
use App\Models\Erp\ApprovalTaskNode;
use App\Services\Erp\Contracts\ApprovalBusinessActionHandler;
use App\Services\Erp\PurchaseWorkflowApplicationService;

class PurchaseOrderAction implements ApprovalBusinessActionHandler
{
    public function execute(ApprovalBusinessAction $action, ApprovalTask $task, ?ApprovalTaskNode $node, array $config, string $operator, ?string $decision = null, ?string $comment = null): array
    {
        $row = $action->result_event === 'rejected'
            ? app(PurchaseWorkflowApplicationService::class)->rejectOrder($task->business_id, $operator)
            : app(PurchaseWorkflowApplicationService::class)->approveOrder($task->business_id, $operator);
        return $row->only(['id', 'purchase_order_no', 'purchase_status', 'audit_status']);
    }
}
