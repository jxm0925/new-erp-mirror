<?php

namespace App\Models\Erp;

class PurchaseReturnItemSerial extends PurchaseBaseModel
{
    protected $table = 'erp_purchase_return_item_serials';

    public function returnItem() { return $this->belongsTo(PurchaseReturnItem::class, 'purchase_return_item_id'); }
    public function inventorySerial() { return $this->belongsTo(InventorySerial::class, 'inventory_serial_id'); }
}
