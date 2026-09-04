<?php

namespace App\Models\Erp;

class PurchaseSettlementSource extends PurchaseBaseModel
{
    protected $table = 'erp_purchase_settlement_sources';

    protected $casts = [
        'business_date' => 'date:Y-m-d',
        'original_amount' => 'decimal:4',
        'eligible_amount' => 'decimal:4',
        'frozen_amount' => 'decimal:4',
        'ap_offset_amount' => 'decimal:4',
        'allocated_amount' => 'decimal:4',
        'unallocated_amount' => 'decimal:4',
        'invoice_matched_amount' => 'decimal:4',
        'invoice_unmatched_amount' => 'decimal:4',
        'eligible_at' => 'datetime',
        'frozen_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function receipt() { return $this->belongsTo(PurchaseReceipt::class, 'source_receipt_id'); }
    public function receiptItem() { return $this->belongsTo(PurchaseReceiptItem::class, 'source_line_id'); }
    public function order() { return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id'); }
    public function supplier() { return $this->belongsTo(Supplier::class); }
}
