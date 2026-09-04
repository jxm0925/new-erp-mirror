<?php

namespace App\Models\Erp;

class ProductionRoutingOperation extends MasterModel
{
    protected $table = 'erp_production_routing_operations';
    protected $casts = ['sequence' => 'integer', 'parameters' => 'array', 'is_key_operation' => 'boolean'];

    public function operation() { return $this->belongsTo(ProductionOperation::class, 'operation_id'); }
    public function routing() { return $this->belongsTo(ProductionRouting::class, 'routing_id'); }
}
