<?php

namespace App\Models\Erp;

class InventoryTransferLine extends MasterModel
{
    protected $table = 'erp_inventory_transfer_lines';
    protected $casts = ['serial_ids' => 'array'];

    public function transfer() { return $this->belongsTo(InventoryTransfer::class, 'transfer_id'); }
    public function item() { return $this->belongsTo(Item::class); }
    public function sourceLocation() { return $this->belongsTo(Location::class, 'source_location_id'); }
    public function targetLocation() { return $this->belongsTo(Location::class, 'target_location_id'); }
    public function unit() { return $this->belongsTo(Unit::class); }
}
