<?php

namespace App\Models\Erp;

class PurchaseReceiptItemAllocation extends PurchaseBaseModel
{
    protected $table = 'erp_purchase_receipt_item_allocations';

    protected $casts = [
        'base_qty' => 'decimal:8',
        'serial_nos' => 'array',
    ];

    public function receiptItem() { return $this->belongsTo(PurchaseReceiptItem::class, 'receipt_item_id'); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function location() { return $this->belongsTo(Location::class); }
}
