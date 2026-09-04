<?php

namespace App\Models\Erp;

class ApprovalTask extends MasterModel
{
    protected $table = 'erp_approval_tasks';
    protected $casts = [
        'business_snapshot' => 'array', 'diff_snapshot' => 'array', 'flow_snapshot' => 'array',
        'result_snapshot' => 'array', 'metadata' => 'array', 'submitted_at' => 'datetime', 'completed_at' => 'datetime',
    ];

    public function flowTemplate() { return $this->belongsTo(ApprovalFlowTemplate::class, 'flow_template_id'); }
    public function flowVersion() { return $this->belongsTo(ApprovalFlowVersion::class, 'flow_version_id'); }
    public function nodes() { return $this->hasMany(ApprovalTaskNode::class, 'approval_task_id')->orderBy('node_order'); }
    public function logs() { return $this->hasMany(ApprovalTaskLog::class, 'approval_task_id')->orderByDesc('operated_at'); }
    public function attachments() { return $this->hasMany(ApprovalTaskAttachment::class, 'approval_task_id')->orderByDesc('uploaded_at'); }
    public function assignees() { return $this->hasMany(ApprovalNodeAssignee::class, 'approval_task_id'); }
    public function actionExecutions() { return $this->hasMany(ApprovalActionExecution::class, 'approval_task_id')->orderByDesc('id'); }
}
