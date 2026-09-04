<?php

namespace App\Models\Erp;

class SalesReturnReceiptItem extends MasterModel
{
    protected $table = 'erp_sales_return_receipt_items';

    protected $casts = [
        'received_base_qty' => 'decimal:8',
        'restock_base_qty' => 'decimal:8',
        'pending_base_qty' => 'decimal:8',
        'scrap_base_qty' => 'decimal:8',
        'rejected_base_qty' => 'decimal:8',
    ];

    public function receipt() { return $this->belongsTo(SalesReturnReceipt::class, 'receipt_id'); }
    public function salesReturnItem() { return $this->belongsTo(SalesReturnItem::class, 'sales_return_item_id'); }
    public function item() { return $this->belongsTo(Item::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function location() { return $this->belongsTo(Location::class); }
    public function baseUnit() { return $this->belongsTo(Unit::class, 'base_unit_id'); }
}
