<?php

namespace App\Models\Erp;

class PurchasePriceHistory extends PurchaseBaseModel
{
    protected $table = 'erp_purchase_price_histories';
    protected $casts = [
        'effective_date' => 'date',
        'unit_price' => 'decimal:8',
        'base_unit_price' => 'decimal:8',
        'conversion_factor_snapshot' => 'decimal:8',
        'tax_rate' => 'decimal:4',
    ];
    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function item() { return $this->belongsTo(Item::class); }
    public function unit() { return $this->belongsTo(Unit::class); }
    public function baseUnit() { return $this->belongsTo(Unit::class, 'base_unit_id'); }
}
