<?php

namespace App\Models\Erp;

class ApprovalNotification extends MasterModel
{
    protected $table = 'erp_approval_notifications';
    protected $casts = ['notified_at' => 'datetime', 'read_at' => 'datetime'];

    public function task() { return $this->belongsTo(ApprovalTask::class, 'approval_task_id'); }
}
