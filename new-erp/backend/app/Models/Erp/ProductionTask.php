<?php

namespace App\Models\Erp;

class ProductionTask extends MasterModel
{
    protected $table = 'erp_production_tasks';
    protected $casts = [
        'sequence_no_snapshot' => 'integer',
        'assignment_score_snapshot' => 'array',
        'claimed_at' => 'datetime',
        'business_version' => 'integer', 'labor_allocation_rule_version' => 'integer',
        'labor_allocation_rule_snapshot' => 'array',
    ];

    public function workOrder() { return $this->belongsTo(WorkOrder::class, 'work_order_id'); }
    public function targets() { return $this->hasMany(ProductionTaskTarget::class, 'task_id'); }
    public function collaborators() { return $this->hasMany(ProductionTaskCollaborator::class, 'task_id'); }
    public function laborSessions() { return $this->hasMany(ProductionLaborSession::class, 'task_id'); }
}
