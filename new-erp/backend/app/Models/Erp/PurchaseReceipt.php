<?php

namespace App\Models\Erp;

class PurchaseReceipt extends PurchaseBaseModel
{
    protected $table = 'erp_purchase_receipts';
    protected $casts = [
        'receipt_date' => 'date:Y-m-d',
        'has_stock_items' => 'boolean',
        'physical_received_base_qty' => 'decimal:8',
        'contract_fulfilled_base_qty' => 'decimal:8',
        'replacement_received_base_qty' => 'decimal:8',
        'amount_excl_tax' => 'decimal:4',
        'tax_amount_snapshot' => 'decimal:4',
        'amount_incl_tax' => 'decimal:4',
        'qualified_payable_amount' => 'decimal:4',
        'quality_hold_amount' => 'decimal:4',
        'rejected_claim_amount' => 'decimal:4',
        'settlement_amount' => 'decimal:4',
        'inventory_cost_amount' => 'decimal:4',
        'freight_amount_snapshot' => 'decimal:4',
        'other_purchase_cost_amount' => 'decimal:4',
    ];
    public function order() { return $this->belongsTo(PurchaseOrder::class, 'order_id'); }
    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function items() { return $this->hasMany(PurchaseReceiptItem::class, 'receipt_id'); }
    public function purchaseReturns() { return $this->hasMany(PurchaseReturn::class, 'source_receipt_id'); }
    public function settlementSources() { return $this->hasMany(PurchaseSettlementSource::class, 'source_receipt_id'); }
    public function attachments() { return $this->hasMany(PurchaseAttachment::class, 'document_id')->where('document_type', 'receipt'); }
    public function logs() { return $this->hasMany(PurchaseLog::class, 'target_id')->where('target_type', 'purchase_receipt')->latest('id'); }
}
