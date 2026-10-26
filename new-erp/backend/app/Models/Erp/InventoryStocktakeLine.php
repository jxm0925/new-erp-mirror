<?php

namespace App\Models\Erp;

class InventoryStocktakeLine extends MasterModel
{
    protected $table = 'erp_inventory_stocktake_lines';
    protected $casts = ['counted_at' => 'datetime'];

    public function stocktake() { return $this->belongsTo(InventoryStocktake::class, 'stocktake_id'); }
    public function item() { return $this->belongsTo(Item::class); }
    public function location() { return $this->belongsTo(Location::class); }
    public function unit() { return $this->belongsTo(Unit::class); }
}
