<?php

namespace App\Models\Erp;

class PurchaseReturnLog extends PurchaseBaseModel
{
    protected $table = 'erp_purchase_return_logs';

    public function purchaseReturn() { return $this->belongsTo(PurchaseReturn::class, 'return_id'); }
}
