<?php

namespace App\Models\Erp;

class PurchasePlanSupplierSplit extends PurchaseBaseModel
{
    protected $table = 'erp_purchase_plan_supplier_splits';
    public function plan() { return $this->belongsTo(PurchasePlan::class, 'plan_id'); }
    public function planItem() { return $this->belongsTo(PurchasePlanItem::class, 'plan_item_id'); }
    public function request() { return $this->belongsTo(PurchaseRequest::class, 'request_id'); }
    public function requestItem() { return $this->belongsTo(PurchaseRequestItem::class, 'request_item_id'); }
    public function item() { return $this->belongsTo(Item::class); }
    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function order() { return $this->belongsTo(PurchaseOrder::class, 'order_id'); }
    public function orderItem() { return $this->belongsTo(PurchaseOrderItem::class, 'order_item_id'); }
}
