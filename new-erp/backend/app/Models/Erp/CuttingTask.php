<?php

namespace App\Models\Erp;

class CuttingTask extends MasterModel
{
    protected $table = 'erp_cutting_tasks';

    protected $casts = [
        'claimed_at' => 'datetime',
        'started_at' => 'datetime',
        'paused_at' => 'datetime',
        'completed_at' => 'datetime',
        'actual_labor_minutes' => 'decimal:2',
        'business_version' => 'integer',
    ];

    public function participants() { return $this->hasMany(CuttingTaskParticipant::class, 'cutting_task_id'); }
    public function laborSessions() { return $this->hasMany(ProductionLaborSession::class, 'cutting_task_id'); }
}
