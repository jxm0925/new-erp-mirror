<?php

namespace App\Models\Erp;

class ApprovalTaskAttachment extends MasterModel
{
    protected $table = 'erp_approval_task_attachments';
    protected $casts = ['uploaded_at' => 'datetime'];
    public function task() { return $this->belongsTo(ApprovalTask::class, 'approval_task_id'); }
    public function node() { return $this->belongsTo(ApprovalTaskNode::class, 'approval_task_node_id'); }
}
