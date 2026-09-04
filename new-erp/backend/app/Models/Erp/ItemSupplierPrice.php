<?php

namespace App\Models\Erp;

class ItemSupplierPrice extends PurchaseBaseModel
{
    protected $table = 'erp_item_supplier_prices';
    protected $casts = [
        'tier_prices' => 'array',
        'valid_from' => 'date',
        'valid_until' => 'date',
        'approved_at' => 'datetime',
        'standard_conversion_factor' => 'decimal:8',
        'final_conversion_factor' => 'decimal:8',
        'base_unit_price' => 'decimal:8',
    ];
    public function item() { return $this->belongsTo(Item::class); }
    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function unit() { return $this->belongsTo(Unit::class); }
}
