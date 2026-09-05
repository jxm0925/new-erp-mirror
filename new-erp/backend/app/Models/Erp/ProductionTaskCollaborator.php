<?php

namespace App\Models\Erp;

class ProductionTaskCollaborator extends MasterModel
{
    protected $table = 'erp_production_task_collaborators';
    protected $casts = ['joined_at' => 'datetime', 'left_at' => 'datetime',
        'responsibility_weight' => 'decimal:4', 'business_version' => 'integer'];
    public function task() { return $this->belongsTo(ProductionTask::class, 'task_id'); }
}
