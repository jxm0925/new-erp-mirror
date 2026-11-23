<?php

namespace App\Models\Erp;

class InventoryStocktakeLine extends MasterModel
{
    protected $table = 'erp_inventory_stocktake_lines';
    protected $casts = ['counted_at' => 'datetime'];
    public const ITEM_RELATION = 'item:id,item_code,item_name,spec,cutting_mode,is_length_cut_material';

    public function presentForCount(?array $entryStats): void
    {
        $this->item?->setAttribute('cutting_mode', $this->item->cuttingMode());
        $this->setAttribute('entry_stats', $entryStats);
        $this->setAttribute('unit_cost_editable', app(\App\Services\Erp\Inventory\InventoryStocktakeService::class)->canEditUnitCost($this));
    }

    public function scopeWithDifferences($query)
    {
        return $query->whereNotNull('counted_qty')->where(fn ($lines) => $lines->where('difference_qty', '<>', 0)
            ->orWhereHas('entries', fn ($entries) => $entries->changed()));
    }

    public function stocktake() { return $this->belongsTo(InventoryStocktake::class, 'stocktake_id'); }
    public function item() { return $this->belongsTo(Item::class); }
    public function location() { return $this->belongsTo(Location::class); }
    public function unit() { return $this->belongsTo(Unit::class); }
    public function entries() { return $this->hasMany(InventoryStocktakeEntry::class, 'line_id'); }
}
