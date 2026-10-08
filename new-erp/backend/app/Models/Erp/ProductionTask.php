<?php

namespace App\Models\Erp;

class ProductionTask extends MasterModel
{
    protected $table = 'erp_production_tasks';
    protected $casts = [
        'sequence_no_snapshot' => 'integer',
        'assignment_score_snapshot' => 'array',
        'claimed_at' => 'datetime',
        'auto_assignment_enabled_snapshot' => 'boolean',
        'is_public_snapshot' => 'boolean',
        'business_version' => 'integer', 'labor_allocation_rule_version' => 'integer',
        'labor_allocation_rule_snapshot' => 'array',
        'active_job_bundle_id' => 'integer',
    ];

    public function workOrder() { return $this->belongsTo(WorkOrder::class, 'work_order_id'); }
    public function productionUnit() { return $this->belongsTo(ProductionUnit::class, 'production_unit_id'); }
    public function productionUnitOperation() { return $this->belongsTo(ProductionUnitOperation::class, 'production_unit_operation_id'); }
    public function productionQuantityOperation() { return $this->belongsTo(ProductionQuantityOperation::class, 'production_quantity_operation_id'); }
    public function targets() { return $this->hasMany(ProductionTaskTarget::class, 'task_id'); }
    public function collaborators() { return $this->hasMany(ProductionTaskCollaborator::class, 'task_id'); }
    public function laborSessions() { return $this->hasMany(ProductionLaborSession::class, 'task_id'); }
    public function assignments() { return $this->hasMany(ProductionTaskAssignment::class, 'task_id'); }
    public function pendingAssignment() { return $this->hasOne(ProductionTaskAssignment::class, 'active_task_id')->where('status', 'PENDING'); }
    public function activeJobBundle() { return $this->belongsTo(ProductionJobBundle::class, 'active_job_bundle_id'); }
}
