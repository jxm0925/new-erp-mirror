<?php

namespace App\Models\Erp;

class ProductionJobBundleLine extends MasterModel
{
    protected $table = 'erp_production_job_bundle_lines';
    protected $casts = ['source_snapshot' => 'array', 'material_snapshot' => 'array', 'started_material_snapshot' => 'array',
        'standard_weight_minutes_snapshot' => 'decimal:8', 'allocated_labor_minutes' => 'decimal:2', 'execution_completed_at' => 'datetime'];
    public function bundle() { return $this->belongsTo(ProductionJobBundle::class, 'job_bundle_id'); }
    public function task() { return $this->belongsTo(ProductionTask::class, 'task_id'); }
    public function workOrder() { return $this->belongsTo(WorkOrder::class, 'work_order_id'); }
    public function laborAllocations() { return $this->hasMany(ProductionJobBundleLaborAllocation::class, 'job_bundle_line_id'); }
}
