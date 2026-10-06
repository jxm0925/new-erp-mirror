<?php

namespace App\Models\Erp;

use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseRequest extends PurchaseBaseModel
{
    use SoftDeletes;

    protected $table = 'erp_purchase_requests';
    public function item() { return $this->belongsTo(Item::class); }
    public function items() { return $this->hasMany(PurchaseRequestItem::class, 'request_id'); }
    public function planItems() { return $this->hasMany(PurchasePlanItem::class, 'request_id'); }
    public function planItemsViaLines() { return $this->hasManyThrough(PurchasePlanItem::class, PurchaseRequestItem::class, 'request_id', 'request_item_id'); }
    public function orderItems() { return $this->hasMany(PurchaseOrderItem::class, 'request_id'); }
    public function orderItemsViaLines() { return $this->hasManyThrough(PurchaseOrderItem::class, PurchaseRequestItem::class, 'request_id', 'request_item_id'); }
}
