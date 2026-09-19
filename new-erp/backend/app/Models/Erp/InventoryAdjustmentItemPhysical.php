<?php

namespace App\Models\Erp;

class InventoryAdjustmentItemPhysical extends MasterModel
{
    protected $table = 'erp_inventory_adjustment_item_physicals';

    protected $casts = [
        'dimensions' => 'array',
        'total_cost' => 'decimal:4',
    ];

    public function adjustmentItem() { return $this->belongsTo(InventoryAdjustmentItem::class, 'adjustment_item_id'); }
}
