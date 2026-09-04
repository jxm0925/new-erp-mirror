<?php

namespace App\Models\Erp;

class InventoryTransaction extends MasterModel
{
    protected $table = 'erp_inventory_transactions';
    protected $casts = [
        'transaction_date' => 'date',
        'posted_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    public function items() { return $this->hasMany(InventoryTransactionItem::class, 'transaction_id'); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function location() { return $this->belongsTo(Location::class); }
}
