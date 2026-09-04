<?php

namespace App\Models\Erp;

class MaterialPickingTask extends MasterModel
{
    protected $table = 'erp_material_picking_tasks';
    protected $casts = ['planned_delivery_at' => 'datetime', 'business_version' => 'integer'];

    public function workOrder() { return $this->belongsTo(WorkOrder::class, 'work_order_id'); }
    public function warehouse() { return $this->belongsTo(Warehouse::class, 'warehouse_id'); }
    public function inventoryTransaction() { return $this->belongsTo(InventoryTransaction::class, 'inventory_transaction_id'); }
    public function lines() { return $this->hasMany(MaterialPickingTaskLine::class, 'task_id'); }
    public function deliveries() { return $this->hasMany(MaterialDelivery::class, 'picking_task_id'); }
}
