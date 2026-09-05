<?php

namespace App\Models\Erp;

class ProductionTaskTarget extends MasterModel
{
    protected $table = 'erp_production_task_targets';
    public function task() { return $this->belongsTo(ProductionTask::class, 'task_id'); }
}
