<?php

namespace App\Models\Erp;

class InventoryAdjustment extends MasterModel
{
    protected $table = 'erp_inventory_adjustments';
    protected $casts = [
        'adjustment_date' => 'date',
        'submitted_at' => 'datetime',
        'posted_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function items() { return $this->hasMany(InventoryAdjustmentItem::class, 'adjustment_id'); }
}
