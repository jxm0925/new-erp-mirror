<?php

namespace App\Models\Erp;

class ApprovalNodeAssignee extends MasterModel
{
    protected $table = 'erp_approval_node_assignees';
    protected $casts = ['assigned_at' => 'datetime', 'completed_at' => 'datetime'];
    public function task() { return $this->belongsTo(ApprovalTask::class, 'approval_task_id'); }
    public function node() { return $this->belongsTo(ApprovalTaskNode::class, 'approval_task_node_id'); }
}
