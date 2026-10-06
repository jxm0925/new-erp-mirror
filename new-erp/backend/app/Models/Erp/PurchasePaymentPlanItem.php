<?php

namespace App\Models\Erp;

class PurchasePaymentPlanItem extends PurchaseBaseModel
{
    protected $table = 'erp_purchase_payment_plan_items';
    protected $casts = ['amount' => 'decimal:4', 'due_date' => 'date:Y-m-d', 'sequence_no' => 'integer'];
    public function purchaseOrder() { return $this->belongsTo(PurchaseOrder::class); }
}
