<?php

namespace App\Models\Erp;

class PurchaseOrder extends PurchaseBaseModel
{
    protected $table = 'erp_purchase_orders';

    protected $casts = [
        'amount_excl_tax' => 'decimal:4',
        'amount_incl_tax' => 'decimal:4',
        'freight_amount' => 'decimal:4',
        'other_purchase_cost_amount' => 'decimal:4',
    ];
    public function plan() { return $this->belongsTo(PurchasePlan::class, 'plan_id'); }
    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function items() { return $this->hasMany(PurchaseOrderItem::class, 'order_id'); }
    public function receipts() { return $this->hasMany(PurchaseReceipt::class, 'order_id'); }
    public function attachments() { return $this->hasMany(PurchaseAttachment::class, 'document_id')->where('document_type', 'order'); }
    public function logs() { return $this->hasMany(PurchaseLog::class, 'target_id')->where('target_type', 'purchase_order')->latest('id'); }
}
