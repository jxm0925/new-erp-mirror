<?php

namespace App\Models\Erp;

class ProductionTaskAssignment extends MasterModel
{
    protected $table = 'erp_production_task_assignments';
    protected $casts = [
        'score_snapshot' => 'array', 'offered_at' => 'datetime', 'decided_at' => 'datetime',
        'business_version' => 'integer', 'offered_to_legacy_id' => 'integer',
    ];

    public function task() { return $this->belongsTo(ProductionTask::class, 'task_id'); }
}
