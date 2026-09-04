<?php

namespace App\Models\Erp;

class ApprovalActionExecution extends MasterModel
{
    protected $table = 'erp_approval_action_executions';
    protected $casts = [
        'config_snapshot' => 'array', 'result_snapshot' => 'array',
        'started_at' => 'datetime', 'finished_at' => 'datetime',
    ];
}
