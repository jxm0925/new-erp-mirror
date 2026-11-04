<?php

namespace App\Models\Erp;

class ProductionRoutingOperation extends MasterModel
{
    protected $table = 'erp_production_routing_operations';
    protected $casts = [
        'sequence' => 'integer', 'parameters' => 'array', 'is_key_operation' => 'boolean',
        'allow_continue_without_warehouse' => 'boolean', 'packaging_materials' => 'array',
    ];

    public function operation() { return $this->belongsTo(ProductionOperation::class, 'operation_id'); }
    public function routing() { return $this->belongsTo(ProductionRouting::class, 'routing_id'); }
    public function materialSupplyRules() { return $this->hasMany(RoutingOperationMaterialSupplyRule::class, 'routing_operation_id'); }
    public function stage() { return $this->belongsTo(ProductionStage::class, 'production_stage_id'); }
    public function packagingScheme() { return $this->belongsTo(ProductionPackagingScheme::class, 'packaging_scheme_id'); }
}
