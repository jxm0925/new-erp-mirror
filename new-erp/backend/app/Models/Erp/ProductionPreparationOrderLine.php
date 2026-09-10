<?php

namespace App\Models\Erp;

class ProductionPreparationOrderLine extends MasterModel
{
    protected $table = 'erp_production_preparation_order_lines';

    protected $casts = [
        'required_base_qty' => 'decimal:8',
        'prepared_base_qty' => 'decimal:8',
        'delivered_base_qty' => 'decimal:8',
        'received_base_qty' => 'decimal:8',
        'business_version' => 'integer',
    ];

    public function preparationOrder() { return $this->belongsTo(ProductionPreparationOrder::class, 'preparation_order_id'); }
    public function workOrder() { return $this->belongsTo(WorkOrder::class, 'work_order_id'); }
    public function materialRequirement() { return $this->belongsTo(WorkOrderMaterialRequirement::class, 'material_requirement_id'); }
    public function componentItem() { return $this->belongsTo(Item::class, 'component_item_id'); }
}
