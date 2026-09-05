<?php

namespace App\Models\Erp;

class ProductionRoutingOperation extends MasterModel
{
    protected $table = 'erp_production_routing_operations';
    protected $casts = [
        'sequence' => 'integer', 'parameters' => 'array', 'is_key_operation' => 'boolean',
        'standard_minutes' => 'decimal:2', 'setup_standard_minutes' => 'decimal:2',
        'unit_standard_minutes' => 'decimal:2', 'allow_continue_without_warehouse' => 'boolean',
    ];

    public function operation() { return $this->belongsTo(ProductionOperation::class, 'operation_id'); }
    public function routing() { return $this->belongsTo(ProductionRouting::class, 'routing_id'); }
    public function outputItem() { return $this->belongsTo(Item::class, 'output_item_id'); }
    public function materialSupplyRules() { return $this->hasMany(RoutingOperationMaterialSupplyRule::class, 'routing_operation_id'); }
}
