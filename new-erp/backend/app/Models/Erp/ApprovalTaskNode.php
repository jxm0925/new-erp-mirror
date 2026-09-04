<?php

namespace App\Models\Erp;

class ApprovalTaskNode extends MasterModel
{
    protected $table = 'erp_approval_task_nodes';
    protected $casts = [
        'approver_rule' => 'array', 'action_config' => 'array', 'condition_snapshot' => 'array',
        'reject_on_any' => 'boolean', 'required_approver_ratio' => 'decimal:4',
        'current_round' => 'integer',
        'started_at' => 'datetime', 'due_at' => 'datetime', 'decided_at' => 'datetime',
    ];

    public function task() { return $this->belongsTo(ApprovalTask::class, 'approval_task_id'); }
    public function decisions() { return $this->hasMany(ApprovalNodeDecision::class, 'approval_task_node_id')->orderBy('decided_at'); }
    public function assignees() { return $this->hasMany(ApprovalNodeAssignee::class, 'approval_task_node_id')->orderBy('id'); }
}
