<?php

namespace App\Models\Erp;

class PurchaseExchangeOrder extends PurchaseBaseModel
{
    protected $table = 'erp_purchase_exchange_orders';

    protected $casts = [
        'returned_at' => 'datetime', 'supplier_received_at' => 'datetime',
        'replacement_shipped_date' => 'date:Y-m-d', 'replacement_expected_date' => 'date:Y-m-d',
        'replacement_shipped_at' => 'datetime', 'replacement_accepted_at' => 'datetime', 'completed_at' => 'datetime',
        'exchange_base_qty' => 'decimal:8',
        'replacement_received_base_qty' => 'decimal:8',
        'contract_fulfilled_base_qty' => 'decimal:8',
        'exchange_additional_payable_amount' => 'decimal:4',
        'replacement_payable_amount' => 'decimal:4',
    ];

    public function handling() { return $this->belongsTo(PurchaseDefectHandling::class, 'defect_handling_id'); }
    public function inventoryQualityEvent() { return $this->belongsTo(InventoryQualityEvent::class, 'inventory_quality_event_id'); }
    public function sourceReceipt() { return $this->belongsTo(PurchaseReceipt::class, 'source_receipt_id'); }
    public function sourceReceiptItem() { return $this->belongsTo(PurchaseReceiptItem::class, 'source_receipt_item_id'); }
    public function purchaseOrder() { return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id'); }
    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function item() { return $this->belongsTo(Item::class); }
    public function replacementReceipt() { return $this->belongsTo(PurchaseReceipt::class, 'replacement_receipt_id'); }
    public function serialLinks() { return $this->hasMany(PurchaseExchangeSerialLink::class, 'exchange_order_id'); }
    public function logs() { return $this->hasMany(PurchaseExchangeLog::class, 'exchange_order_id')->latest('id'); }
    public function attachments() { return $this->hasMany(PurchaseAttachment::class, 'document_id')->where('document_type', 'exchange'); }
}
