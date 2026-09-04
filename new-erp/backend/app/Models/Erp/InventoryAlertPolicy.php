<?php

namespace App\Models\Erp;

class InventoryAlertPolicy extends MasterModel
{
    protected $table = 'erp_inventory_alert_policies';
    protected $casts = ['is_enabled' => 'boolean', 'effective_at' => 'datetime'];
    protected $fillable = [
        'item_id', 'warehouse_id', 'scope_key', 'status', 'is_enabled', 'min_stock', 'safety_stock',
        'max_stock', 'suggested_replenishment_qty', 'created_by_legacy_id', 'enabled_by_legacy_id', 'effective_at',
    ];
    public function item() { return $this->belongsTo(Item::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
}
