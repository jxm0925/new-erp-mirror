<?php

namespace App\Models\Erp;

class InventoryStocktake extends MasterModel
{
    protected $table = 'erp_inventory_stocktakes';
    protected $casts = [
        'stocktake_date' => 'date:Y-m-d',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function lines() { return $this->hasMany(InventoryStocktakeLine::class, 'stocktake_id')->orderBy('id'); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function location() { return $this->belongsTo(Location::class); }
    public function adjustment() { return $this->belongsTo(InventoryAdjustment::class, 'adjustment_id'); }
}
