<?php

namespace App\Models\Erp;

class InventoryAdjustmentItem extends MasterModel
{
    protected $table = 'erp_inventory_adjustment_items';

    public function adjustment() { return $this->belongsTo(InventoryAdjustment::class, 'adjustment_id'); }
    public function item() { return $this->belongsTo(Item::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function location() { return $this->belongsTo(Location::class); }
    public function unit() { return $this->belongsTo(Unit::class); }
    public function serials() { return $this->hasMany(InventoryAdjustmentSerial::class, 'adjustment_item_id'); }
}
