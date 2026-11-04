<?php

namespace App\Models\Erp;

/** BOM 每道路线节点的工时与提成设置；工时 NULL=未设，提成 inherit/override/zero。 */
class BomOperationStandard extends MasterModel
{
    public const MODES = ['inherit', 'override', 'zero'];

    protected $table = 'erp_bom_operation_standards';
    protected $casts = ['bom_id' => 'integer', 'routing_operation_id' => 'integer'];

    public function bom() { return $this->belongsTo(Bom::class, 'bom_id')->withTrashed(); }
    public function routingOperation() { return $this->belongsTo(ProductionRoutingOperation::class, 'routing_operation_id'); }
}
