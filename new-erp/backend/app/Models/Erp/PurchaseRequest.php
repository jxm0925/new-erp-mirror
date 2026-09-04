<?php

namespace App\Models\Erp;

class PurchaseRequest extends PurchaseBaseModel
{
    protected $table = 'erp_purchase_requests';
    public function item() { return $this->belongsTo(Item::class); }
    public function items() { return $this->hasMany(PurchaseRequestItem::class, 'request_id'); }
}
