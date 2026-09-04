<?php

namespace App\Models\Erp;

class InventoryTransactionItem extends MasterModel
{
    protected $table = 'erp_inventory_transaction_items';

    protected $casts = [
        'quantity_change' => 'decimal:8',
        'unit_cost' => 'decimal:8',
        'cost_amount' => 'decimal:4',
        'purchase_amount_snapshot' => 'decimal:4',
        'freight_amount_snapshot' => 'decimal:4',
        'other_purchase_cost_amount_snapshot' => 'decimal:4',
    ];

    public function transaction() { return $this->belongsTo(InventoryTransaction::class, 'transaction_id'); }
    public function item() { return $this->belongsTo(Item::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function location() { return $this->belongsTo(Location::class); }
    public function unit() { return $this->belongsTo(Unit::class); }
    public function purchaseReceiptItem() { return $this->belongsTo(PurchaseReceiptItem::class, 'source_item_id'); }
}
