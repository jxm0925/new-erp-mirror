<?php

namespace App\Models\Erp;

class WorkOrderMaterialRequirement extends MasterModel
{
    protected $table = 'erp_work_order_material_requirements';

    protected $casts = [
        'per_output_qty' => 'decimal:8',
        'loss_rate' => 'decimal:4',
        'fixed_qty' => 'decimal:8',
        'required_qty' => 'decimal:8',
        'base_required_qty' => 'decimal:8',
        'issued_qty' => 'decimal:8',
        'returned_qty' => 'decimal:8',
        'remaining_qty' => 'decimal:8',
        'business_version' => 'integer',
    ];

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id');
    }

    public function componentItem()
    {
        return $this->belongsTo(Item::class, 'component_item_id');
    }
}