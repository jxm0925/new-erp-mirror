<?php

namespace App\Models\Erp;

class InventoryAdjustmentSerial extends MasterModel
{
    protected $table = 'erp_inventory_adjustment_serials';

    public function item() { return $this->belongsTo(InventoryAdjustmentItem::class, 'adjustment_item_id'); }
    public function inventorySerial() { return $this->belongsTo(InventorySerial::class, 'inventory_serial_id'); }
}
