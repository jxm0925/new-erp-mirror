<?php

namespace App\Services\Erp\ApprovalActions;

use App\Models\Erp\ApprovalBusinessAction;
use App\Models\Erp\ApprovalFormSubmission;
use App\Models\Erp\ApprovalTask;
use App\Models\Erp\ApprovalTaskNode;
use App\Services\Erp\Contracts\ApprovalBusinessActionHandler;

class CompleteApplicationAction implements ApprovalBusinessActionHandler
{
    public function execute(ApprovalBusinessAction $action, ApprovalTask $task, ?ApprovalTaskNode $node, array $config, string $operator, ?string $decision = null, ?string $comment = null): array
    {
        if ($task->business_object_code !== 'CUSTOM_FORM_SUBMISSION') return ['status' => $action->result_event, 'operated_by' => $operator];
        $submission = ApprovalFormSubmission::query()->findOrFail($task->business_id);
        $status = $action->result_event === 'rejected' ? 'rejected' : 'approved';
        $submission->update(['submission_status' => $status]);
        return ['submission_id' => $submission->id, 'submission_no' => $submission->submission_no, 'submission_status' => $status, 'operated_by' => $operator];
    }
}
