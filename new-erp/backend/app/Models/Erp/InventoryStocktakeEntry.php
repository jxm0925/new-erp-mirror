<?php

namespace App\Models\Erp;

class InventoryStocktakeEntry extends MasterModel
{
    protected $table = 'erp_inventory_stocktake_entries';
    protected $casts = [
        'book_present' => 'boolean',
        'counted_present' => 'boolean',
        'is_new' => 'boolean',
        'is_locked' => 'boolean',
        'dimensions' => 'array',
        'counted_at' => 'datetime',
    ];

    public function line() { return $this->belongsTo(InventoryStocktakeLine::class, 'line_id'); }
}
