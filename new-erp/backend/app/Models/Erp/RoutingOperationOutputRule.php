<?php

namespace App\Models\Erp;

class RoutingOperationOutputRule extends MasterModel
{
    protected $table = 'erp_routing_operation_output_rules';

    protected $casts = [
        'routing_id' => 'integer', 'routing_operation_id' => 'integer', 'item_id' => 'integer', 'base_unit_id' => 'integer',
        'reference_item_id' => 'integer', 'reference_base_unit_id' => 'integer',
        'line_no' => 'integer', 'base_qty_per_reference_unit' => 'decimal:8',
        'base_unit_decimal_places_snapshot' => 'integer', 'reference_base_unit_decimal_places_snapshot' => 'integer',
        'allow_continue_without_warehouse' => 'boolean', 'business_version' => 'integer',
    ];

    public function routing() { return $this->belongsTo(ProductionRouting::class, 'routing_id'); }
    public function routingOperation() { return $this->belongsTo(ProductionRoutingOperation::class, 'routing_operation_id'); }
    public function item() { return $this->belongsTo(Item::class); }
    public function baseUnit() { return $this->belongsTo(Unit::class, 'base_unit_id'); }
    public function referenceItem() { return $this->belongsTo(Item::class, 'reference_item_id'); }
    public function referenceBaseUnit() { return $this->belongsTo(Unit::class, 'reference_base_unit_id'); }
}
