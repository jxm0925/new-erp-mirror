<?php

namespace App\Models\Erp;

class PurchaseReceiptItem extends PurchaseBaseModel
{
    protected $table = 'erp_purchase_receipt_items';
    protected $casts = [
        'allow_actual_conversion' => 'boolean',
        'is_stock_item_snapshot' => 'boolean',
        'conversion_factor_snapshot' => 'decimal:8',
        'standard_base_qty' => 'decimal:8',
        'actual_base_qty' => 'decimal:8',
        'qualified_base_qty' => 'decimal:8',
        'unqualified_base_qty' => 'decimal:8',
        'original_received_qty' => 'decimal:8',
        'original_qualified_qty' => 'decimal:8',
        'original_unqualified_qty' => 'decimal:8',
        'original_received_base_qty' => 'decimal:8',
        'original_qualified_base_qty' => 'decimal:8',
        'original_unqualified_base_qty' => 'decimal:8',
        'rework_qualified_base_qty' => 'decimal:8',
        'concession_accepted_base_qty' => 'decimal:8',
        'replacement_qualified_base_qty' => 'decimal:8',
        'rejected_base_qty' => 'decimal:8',
        'scrapped_base_qty' => 'decimal:8',
        'final_stockable_base_qty' => 'decimal:8',
        'physical_received_base_qty' => 'decimal:8',
        'contract_fulfilled_base_qty' => 'decimal:8',
        'replacement_received_base_qty' => 'decimal:8',
        'difference_qty' => 'decimal:8',
        'serial_entries' => 'array',
        'material_policy_snapshot' => 'array',
        'tax_rate' => 'decimal:4',
        'receipt_cost' => 'decimal:4',
        'amount_excl_tax' => 'decimal:4',
        'tax_amount_snapshot' => 'decimal:4',
        'amount_incl_tax' => 'decimal:4',
        'qualified_payable_amount' => 'decimal:4',
        'quality_hold_amount' => 'decimal:4',
        'rejected_claim_amount' => 'decimal:4',
        'settlement_amount' => 'decimal:4',
        'inventory_cost_amount' => 'decimal:4',
        'freight_allocated_amount' => 'decimal:4',
        'other_purchase_cost_amount' => 'decimal:4',
        'facts_frozen_at' => 'datetime',
        'expected_arrival_date' => 'date:Y-m-d',
    ];
    public function receipt() { return $this->belongsTo(PurchaseReceipt::class, 'receipt_id'); }
    public function orderItem() { return $this->belongsTo(PurchaseOrderItem::class, 'order_item_id'); }
    public function item() { return $this->belongsTo(Item::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function location() { return $this->belongsTo(Location::class); }
    public function allocations() { return $this->hasMany(PurchaseReceiptItemAllocation::class, 'receipt_item_id')->orderBy('sort_order')->orderBy('id'); }
    public function purchaseUnit() { return $this->belongsTo(Unit::class, 'purchase_unit_id'); }
    public function baseUnit() { return $this->belongsTo(Unit::class, 'base_unit_id'); }
    public function inventoryPostingLog() { return $this->belongsTo(InventoryPostingLog::class, 'inventory_posting_log_id'); }
    public function defectHandlings() { return $this->hasMany(PurchaseDefectHandling::class, 'receipt_item_id'); }
    public function settlementSources() { return $this->hasMany(PurchaseSettlementSource::class, 'source_line_id'); }
}
