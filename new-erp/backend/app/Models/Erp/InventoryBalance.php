<?php

namespace App\Models\Erp;

class InventoryBalance extends MasterModel
{
    protected $table = 'erp_inventory_balances';
    protected $casts = [
        'last_transaction_at' => 'datetime',
    ];

    public function item() { return $this->belongsTo(Item::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function location() { return $this->belongsTo(Location::class); }
    public function unit() { return $this->belongsTo(Unit::class); }
    public function serials() { return $this->hasMany(InventorySerial::class, 'inventory_balance_id'); }
    public function qualityEvents() { return $this->hasMany(InventoryQualityEvent::class, 'inventory_balance_id'); }
}
