<?php

namespace App\Models\Erp;

class PurchaseReturnItemPhysical extends PurchaseBaseModel
{
    protected $table = 'erp_purchase_return_item_physicals';

    public function purchaseReturnItem() { return $this->belongsTo(PurchaseReturnItem::class, 'purchase_return_item_id'); }
}
