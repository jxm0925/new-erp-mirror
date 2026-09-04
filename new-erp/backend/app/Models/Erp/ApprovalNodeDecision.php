<?php

namespace App\Models\Erp;

class ApprovalNodeDecision extends MasterModel
{
    protected $table = 'erp_approval_node_decisions';
    protected $casts = ['round_no' => 'integer', 'decided_at' => 'datetime'];

    public function task() { return $this->belongsTo(ApprovalTask::class, 'approval_task_id'); }
    public function node() { return $this->belongsTo(ApprovalTaskNode::class, 'approval_task_node_id'); }
}
