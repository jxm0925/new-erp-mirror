<?php

namespace App\Models\Erp;

class AssemblyComponentDemand extends MasterModel
{
    protected $table = 'erp_assembly_component_demands';
    protected $casts = ['demand_snapshot' => 'array', 'required_base_qty' => 'decimal:8', 'inventory_reserved_base_qty' => 'decimal:8', 'production_base_qty' => 'decimal:8'];
    public function parentWorkOrder() { return $this->belongsTo(WorkOrder::class, 'parent_work_order_id'); }
    public function childWorkOrder() { return $this->belongsTo(WorkOrder::class, 'child_work_order_id'); }
    public function plan() { return $this->belongsTo(AssemblyProductionPlan::class, 'assembly_plan_id'); }
}
