<?php

namespace App\Models\Erp;

class SalesReturnSerialIdentity extends MasterModel
{
    protected $table = 'erp_sales_return_serial_identities';
    protected $casts = [
        'unit_cost_snapshot' => 'decimal:8', 'cost_amount_snapshot' => 'decimal:4',
        'received_at' => 'datetime', 'posted_at' => 'datetime',
    ];

    public function receiptItem() { return $this->belongsTo(SalesReturnReceiptItem::class, 'sales_return_receipt_item_id'); }
    public function serial() { return $this->belongsTo(InventorySerial::class, 'inventory_serial_id'); }
    public function costAllocation() { return $this->belongsTo(SalesReturnCostAllocation::class, 'cost_allocation_id'); }
}
