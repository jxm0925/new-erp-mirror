<?php

namespace App\Models\Erp;

class PurchaseReturnItem extends PurchaseBaseModel
{
    protected $table = 'erp_purchase_return_items';

    protected $casts = [
        'requested_return_qty' => 'decimal:8',
        'return_conversion_factor_snapshot' => 'decimal:8',
        'requested_base_qty' => 'decimal:8',
        'approved_base_qty' => 'decimal:8',
        'posted_base_qty' => 'decimal:8',
        'unit_cost_snapshot' => 'decimal:8',
        'tax_rate_snapshot' => 'decimal:4',
        'return_unit_price' => 'decimal:8',
        'return_amount_excl_tax' => 'decimal:4',
        'return_tax_amount' => 'decimal:4',
        'return_amount_incl_tax' => 'decimal:4',
        'settlement_amount' => 'decimal:4',
        'inventory_cost_amount' => 'decimal:4',
    ];

    public function purchaseReturn() { return $this->belongsTo(PurchaseReturn::class, 'return_id'); }
    public function sourceReceiptItem() { return $this->belongsTo(PurchaseReceiptItem::class, 'source_receipt_item_id'); }
    public function sourceDefectHandling() { return $this->belongsTo(PurchaseDefectHandling::class, 'source_defect_handling_id'); }
    public function sourceInventoryQualityEvent() { return $this->belongsTo(InventoryQualityEvent::class, 'source_inventory_quality_event_id'); }
    public function item() { return $this->belongsTo(Item::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function location() { return $this->belongsTo(Location::class); }
    public function baseUnit() { return $this->belongsTo(Unit::class, 'base_unit_id'); }
    public function returnUnit() { return $this->belongsTo(Unit::class, 'return_unit_id'); }
    public function serialLinks() { return $this->hasMany(PurchaseReturnItemSerial::class, 'purchase_return_item_id'); }
}
