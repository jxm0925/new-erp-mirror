<?php

namespace App\Models\Erp;

class ApprovalTaskLog extends MasterModel
{
    protected $table = 'erp_approval_task_logs';
    protected $casts = ['payload' => 'array', 'operated_at' => 'datetime'];

    public function task() { return $this->belongsTo(ApprovalTask::class, 'approval_task_id'); }
    public function node() { return $this->belongsTo(ApprovalTaskNode::class, 'approval_task_node_id'); }
}
