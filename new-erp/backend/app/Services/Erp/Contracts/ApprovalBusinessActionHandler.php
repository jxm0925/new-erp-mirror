<?php

namespace App\Services\Erp\Contracts;

use App\Models\Erp\ApprovalBusinessAction;
use App\Models\Erp\ApprovalTask;
use App\Models\Erp\ApprovalTaskNode;

interface ApprovalBusinessActionHandler
{
    public function execute(ApprovalBusinessAction $action, ApprovalTask $task, ?ApprovalTaskNode $node, array $config, string $operator, ?string $decision = null, ?string $comment = null): array;
}
