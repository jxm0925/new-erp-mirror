<?php

namespace App\Models\Erp;

class MaterialDelivery extends MasterModel
{
    protected $table = 'erp_material_deliveries';
    protected $casts = ['departed_at' => 'datetime', 'delivered_at' => 'datetime', 'business_version' => 'integer'];

    public function workOrder() { return $this->belongsTo(WorkOrder::class, 'work_order_id'); }
    public function pickingTask() { return $this->belongsTo(MaterialPickingTask::class, 'picking_task_id'); }
    public function lines() { return $this->hasMany(MaterialDeliveryLine::class, 'delivery_id'); }
    public function receipts() { return $this->hasMany(MaterialReceipt::class, 'delivery_id'); }
}
