<?php

namespace App\Services\Erp\ApprovalActions;

use App\Models\Erp\ApprovalBusinessAction;
use App\Models\Erp\ApprovalTask;
use App\Models\Erp\ApprovalTaskNode;
use App\Models\Erp\SalesOrderChangeCandidate;
use App\Services\Erp\Contracts\ApprovalBusinessActionHandler;
use App\Services\Erp\SalesOrderEditImpactService;

class SalesOrderChangeAction implements ApprovalBusinessActionHandler
{
    public function execute(ApprovalBusinessAction $action, ApprovalTask $task, ?ApprovalTaskNode $node, array $config, string $operator, ?string $decision = null, ?string $comment = null): array
    {
        $candidate = SalesOrderChangeCandidate::query()->findOrFail($task->business_id);
        $result = app(SalesOrderEditImpactService::class)->decide($candidate->id, (string) $node?->approval_type, $decision === 'approve', $operator, $comment);
        return ['candidate_id' => $result->id, 'candidate_status' => $result->candidate_status,
            'activated_at' => optional($result->activated_at)->toDateTimeString(), 'conflict_reason' => $result->conflict_reason];
    }
}
