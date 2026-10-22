<?php

namespace App\Models\Erp;

class MfgPurchaseDemandLink extends MasterModel
{
    protected $table = 'erp_mfg_purchase_demand_links';
    protected $casts = ['qty' => 'decimal:4'];

    public function request() { return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id')->withTrashed(); }
    public function requestItem() { return $this->belongsTo(PurchaseRequestItem::class, 'purchase_request_item_id'); }
    public function item() { return $this->belongsTo(Item::class); }
    public function workOrder() { return $this->belongsTo(MfgWorkOrder::class, 'work_order_id'); }
}
