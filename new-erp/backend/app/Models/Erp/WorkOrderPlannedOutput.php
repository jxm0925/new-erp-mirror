<?php

namespace App\Models\Erp;

class WorkOrderPlannedOutput extends MasterModel
{
    protected $table = 'erp_work_order_planned_outputs';

    protected $casts = [
        'is_reference' => 'boolean', 'planned_base_qty' => 'decimal:8', 'business_version' => 'integer',
        'base_unit_decimal_places_snapshot' => 'integer',
    ];

    public function workOrder() { return $this->belongsTo(WorkOrder::class); }
    public function item() { return $this->belongsTo(Item::class); }
    public function baseUnit() { return $this->belongsTo(Unit::class, 'base_unit_id'); }
}
