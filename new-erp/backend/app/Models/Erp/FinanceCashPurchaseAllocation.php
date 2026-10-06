<?php

namespace App\Models\Erp;

class FinanceCashPurchaseAllocation extends MasterModel
{
    protected $table = 'erp_finance_cash_purchase_allocations';
    protected $casts = ['amount' => 'decimal:4', 'allocation_version' => 'integer', 'sequence_no_snapshot' => 'integer'];
    public function cashDocument() { return $this->belongsTo(FinanceCashDocument::class); }
    public function purchaseOrder() { return $this->belongsTo(PurchaseOrder::class); }
    public function paymentPlan() { return $this->belongsTo(PurchasePaymentPlanItem::class, 'payment_plan_id'); }
}
