<?php

namespace App\Models\Erp;

class SalesReturnItem extends MasterModel
{
    protected $table = 'erp_sales_return_items';

    protected $casts = [
        'requested_sales_qty' => 'decimal:8',
        'requested_base_qty' => 'decimal:8',
        'received_base_qty' => 'decimal:8',
        'restock_base_qty' => 'decimal:8',
        'pending_base_qty' => 'decimal:8',
        'scrap_base_qty' => 'decimal:8',
        'rejected_base_qty' => 'decimal:8',
        'fulfillment_snapshot' => 'array',
    ];

    public function salesReturn() { return $this->belongsTo(SalesReturn::class, 'sales_return_id'); }
    public function orderLine() { return $this->belongsTo(SalesOrderLine::class, 'sales_order_line_id'); }
    public function fulfillment() { return $this->belongsTo(SalesOrderFulfillment::class, 'fulfillment_id'); }
    public function item() { return $this->belongsTo(Item::class); }
    public function baseUnit() { return $this->belongsTo(Unit::class, 'base_unit_id'); }
    public function receiptItems() { return $this->hasMany(SalesReturnReceiptItem::class, 'sales_return_item_id'); }
    public function costAllocations() { return $this->hasMany(SalesReturnCostAllocation::class, 'sales_return_item_id'); }
}
