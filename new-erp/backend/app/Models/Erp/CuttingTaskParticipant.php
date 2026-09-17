<?php

namespace App\Models\Erp;

class CuttingTaskParticipant extends MasterModel
{
    protected $table = 'erp_cutting_task_participants';

    protected $casts = [
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
        'responsibility_weight' => 'decimal:4',
        'business_version' => 'integer',
    ];

    public function task() { return $this->belongsTo(CuttingTask::class, 'cutting_task_id'); }
}
