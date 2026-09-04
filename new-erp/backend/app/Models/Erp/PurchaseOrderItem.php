<?php

namespace App\Models\Erp;

class PurchaseOrderItem extends PurchaseBaseModel
{
    protected $table = 'erp_purchase_order_items';
    protected $casts = [
        'allow_actual_conversion_snapshot' => 'boolean',
        'conversion_factor_snapshot' => 'decimal:8',
        'purchase_qty' => 'decimal:8',
        'planned_base_qty' => 'decimal:8',
        'tax_rate' => 'decimal:4',
        'amount' => 'decimal:4',
        'amount_excl_tax' => 'decimal:4',
        'tax_amount_snapshot' => 'decimal:4',
        'amount_incl_tax' => 'decimal:4',
        'freight_allocated_amount' => 'decimal:4',
        'other_purchase_cost_amount' => 'decimal:4',
        'contract_amount_snapshot' => 'decimal:4',
        'commercial_snapshot_at' => 'datetime',
        'material_policy_snapshot' => 'array',
    ];
    public function order() { return $this->belongsTo(PurchaseOrder::class, 'order_id'); }
    public function plan() { return $this->belongsTo(PurchasePlan::class, 'plan_id'); }
    public function planItem() { return $this->belongsTo(PurchasePlanItem::class, 'plan_item_id'); }
    public function planSplit() { return $this->belongsTo(PurchasePlanSupplierSplit::class, 'plan_split_id'); }
    public function supplierSplit() { return $this->belongsTo(PurchasePlanSupplierSplit::class, 'supplier_split_id'); }
    public function request() { return $this->belongsTo(PurchaseRequest::class, 'request_id'); }
    public function requestItem() { return $this->belongsTo(PurchaseRequestItem::class, 'request_item_id'); }
    public function item() { return $this->belongsTo(Item::class); }
    public function purchaseUnit() { return $this->belongsTo(Unit::class, 'purchase_unit_id'); }
    public function baseUnit() { return $this->belongsTo(Unit::class, 'base_unit_id'); }
}
