<?php

namespace App\Services\Erp\ApprovalActions;

use App\Models\Erp\{ApprovalBusinessAction, ApprovalTask, ApprovalTaskNode};
use App\Services\Erp\BomApplicationService;
use App\Services\Erp\Contracts\ApprovalBusinessActionHandler;

class BomAction implements ApprovalBusinessActionHandler
{
    public function execute(ApprovalBusinessAction $action, ApprovalTask $task, ?ApprovalTaskNode $node, array $config, string $operator, ?string $decision = null, ?string $comment = null): array
    {
        $bom = app(BomApplicationService::class)->applyApprovalResult($task, $node, $action->result_event);
        return $bom->only(['id', 'bom_no', 'status', 'audit_status', 'is_default']);
    }
}
