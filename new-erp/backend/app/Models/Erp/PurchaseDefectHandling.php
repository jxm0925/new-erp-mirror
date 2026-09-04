<?php

namespace App\Models\Erp;

class PurchaseDefectHandling extends PurchaseBaseModel
{
    protected $table = 'erp_purchase_defect_handlings';

    protected $casts = [
        'handled_at' => 'datetime',
        'started_at' => 'datetime',
        'approved_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function receipt() { return $this->belongsTo(PurchaseReceipt::class, 'receipt_id'); }
    public function receiptItem() { return $this->belongsTo(PurchaseReceiptItem::class, 'receipt_item_id'); }
    public function item() { return $this->belongsTo(Item::class); }
    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function replacementReceipt() { return $this->belongsTo(PurchaseReceipt::class, 'replacement_receipt_id'); }
    public function exchangeOrder() { return $this->hasOne(PurchaseExchangeOrder::class, 'defect_handling_id'); }
    public function logs() { return $this->hasMany(PurchaseDefectHandlingLog::class, 'handling_id')->latest('id'); }
}
