<?php

namespace App\Models\Erp;

class MaterialReceipt extends MasterModel
{
    protected $table = 'erp_material_receipts';
    protected $casts = ['received_at' => 'datetime', 'business_version' => 'integer'];

    public function delivery() { return $this->belongsTo(MaterialDelivery::class, 'delivery_id'); }
    public function workOrder() { return $this->belongsTo(WorkOrder::class, 'work_order_id'); }
    public function lines() { return $this->hasMany(MaterialReceiptLine::class, 'receipt_id'); }
}
