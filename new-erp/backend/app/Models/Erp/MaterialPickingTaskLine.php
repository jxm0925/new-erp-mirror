<?php

namespace App\Models\Erp;

class MaterialPickingTaskLine extends MasterModel
{
    protected $table = 'erp_material_picking_task_lines';
    protected $casts = [
        'required_qty_snapshot' => 'decimal:8', 'planned_pick_qty' => 'decimal:8',
        'actual_pick_qty' => 'decimal:8', 'delivered_qty' => 'decimal:8',
        'received_qty' => 'decimal:8', 'serial_snapshot' => 'array', 'business_version' => 'integer',
    ];

    public function task() { return $this->belongsTo(MaterialPickingTask::class, 'task_id'); }
    public function requirement() { return $this->belongsTo(WorkOrderMaterialRequirement::class, 'material_requirement_id'); }
    public function componentItem() { return $this->belongsTo(Item::class, 'component_item_id'); }
    public function inventoryBalance() { return $this->belongsTo(InventoryBalance::class, 'inventory_balance_id'); }
}
