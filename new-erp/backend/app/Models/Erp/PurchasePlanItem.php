<?php

namespace App\Models\Erp;

class PurchasePlanItem extends PurchaseBaseModel
{
    protected $table = 'erp_purchase_plan_items';
    protected $casts = ['material_policy_snapshot' => 'array'];
    public function plan() { return $this->belongsTo(PurchasePlan::class, 'plan_id'); }
    public function request() { return $this->belongsTo(PurchaseRequest::class, 'request_id'); }
    public function requestItem() { return $this->belongsTo(PurchaseRequestItem::class, 'request_item_id'); }
    public function item() { return $this->belongsTo(Item::class); }
    public function unit() { return $this->belongsTo(Unit::class); }
    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function splits() { return $this->hasMany(PurchasePlanSupplierSplit::class, 'plan_item_id'); }
}
