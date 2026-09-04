<?php

namespace App\Models\Erp;

class InventoryQualityEvent extends MasterModel
{
    protected $table = 'erp_inventory_quality_events';

    protected $casts = [
        'issue_qty' => 'decimal:8',
        'attachments' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function balance() { return $this->belongsTo(InventoryBalance::class, 'inventory_balance_id'); }
    public function serial() { return $this->belongsTo(InventorySerial::class, 'inventory_serial_id'); }
    public function item() { return $this->belongsTo(Item::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function location() { return $this->belongsTo(Location::class); }
    public function unit() { return $this->belongsTo(Unit::class); }
    public function receipt() { return $this->belongsTo(PurchaseReceipt::class, 'source_receipt_id'); }
    public function receiptItem() { return $this->belongsTo(PurchaseReceiptItem::class, 'source_receipt_item_id'); }
    public function purchaseReturnItems() { return $this->hasMany(PurchaseReturnItem::class, 'source_inventory_quality_event_id'); }
    public function exchangeOrder() { return $this->hasOne(PurchaseExchangeOrder::class, 'inventory_quality_event_id'); }
    public function order() { return $this->belongsTo(PurchaseOrder::class, 'source_order_id'); }
    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function logs() { return $this->hasMany(InventoryQualityEventLog::class, 'quality_event_id'); }
}
