<?php

namespace App\Models\Erp;

class AssemblyProductionPlan extends MasterModel
{
    protected $table = 'erp_assembly_production_plans';
    protected $casts = ['plan_snapshot' => 'array', 'prepared_at' => 'datetime', 'cancelled_at' => 'datetime'];
    public function rootWorkOrder() { return $this->belongsTo(WorkOrder::class, 'root_work_order_id'); }
    public function componentDemands() { return $this->hasMany(AssemblyComponentDemand::class, 'assembly_plan_id'); }
}
