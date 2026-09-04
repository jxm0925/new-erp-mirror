<?php

namespace App\Models\Erp;

class PurchasePlan extends PurchaseBaseModel
{
    protected $table = 'erp_purchase_plans';
    public function items() { return $this->hasMany(PurchasePlanItem::class, 'plan_id'); }
    public function splits() { return $this->hasMany(PurchasePlanSupplierSplit::class, 'plan_id'); }
    public function orders() { return $this->hasMany(PurchaseOrder::class, 'plan_id'); }
}
