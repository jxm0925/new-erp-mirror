<?php

namespace App\Models\Erp;

class InventoryTransfer extends MasterModel
{
    protected $table = 'erp_inventory_transfers';
    protected $casts = [
        'transfer_date' => 'date:Y-m-d',
        'posted_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'dispatched_at' => 'datetime', 'confirmed_at' => 'datetime', 'exception_at' => 'datetime', 'business_version' => 'integer',
    ];

    public function lines() { return $this->hasMany(InventoryTransferLine::class, 'transfer_id')->orderBy('id'); }
    public function sourceWarehouse() { return $this->belongsTo(Warehouse::class, 'source_warehouse_id'); }
    public function targetWarehouse() { return $this->belongsTo(Warehouse::class, 'target_warehouse_id'); }
}
