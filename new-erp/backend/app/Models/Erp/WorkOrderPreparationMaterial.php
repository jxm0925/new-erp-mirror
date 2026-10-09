<?php

namespace App\Models\Erp;

/** A procurement source only; never an execution target or an issuing authority. */
class WorkOrderPreparationMaterial extends MasterModel
{
    protected $table = 'erp_work_order_preparation_materials';
    protected $casts = ['material_snapshot' => 'array', 'required_base_qty' => 'decimal:8', 'preparation_version' => 'integer'];
    public function workOrder() { return $this->belongsTo(WorkOrder::class, 'work_order_id'); }
    public function componentItem() { return $this->belongsTo(Item::class, 'component_item_id'); }
}
