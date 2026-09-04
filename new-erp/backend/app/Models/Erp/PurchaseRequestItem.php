<?php

namespace App\Models\Erp;

class PurchaseRequestItem extends PurchaseBaseModel
{
    protected $table = 'erp_purchase_request_items';
    protected $casts = ['material_policy_snapshot' => 'array'];
    public function request() { return $this->belongsTo(PurchaseRequest::class, 'request_id'); }
    public function item() { return $this->belongsTo(Item::class); }
    public function unit() { return $this->belongsTo(Unit::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
}
