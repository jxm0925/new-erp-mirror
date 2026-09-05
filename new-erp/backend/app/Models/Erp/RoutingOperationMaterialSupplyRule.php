<?php

namespace App\Models\Erp;

class RoutingOperationMaterialSupplyRule extends MasterModel
{
    protected $table = 'erp_routing_operation_material_supply_rules';
    protected $casts = [
        'required_qty_ratio' => 'decimal:6', 'requires_delivery' => 'boolean',
        'participates_in_kitting' => 'boolean', 'allow_partial_delivery' => 'boolean',
        'business_version' => 'integer',
    ];

    public function routingOperation() { return $this->belongsTo(ProductionRoutingOperation::class, 'routing_operation_id'); }
    public function targetRoutingOperation() { return $this->belongsTo(ProductionRoutingOperation::class, 'target_routing_operation_id'); }
    public function componentItem() { return $this->belongsTo(Item::class, 'component_item_id'); }
}
