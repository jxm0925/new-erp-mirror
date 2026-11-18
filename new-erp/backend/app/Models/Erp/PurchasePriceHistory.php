<?php

namespace App\Models\Erp;

use App\Domain\Finance\Money;

class PurchasePriceHistory extends PurchaseBaseModel
{
    protected $table = 'erp_purchase_price_histories';
    protected $casts = [
        'effective_date' => 'date',
        'base_unit_price' => 'decimal:8',
        'conversion_factor_snapshot' => 'decimal:8',
        'tax_rate' => 'decimal:4',
    ];
    public function getPriceAttribute($value)
    {
        $base = $this->base_unit_price;
        if ($value !== null && $base !== null && $this->unit_id && (int) $this->unit_id === (int) $this->base_unit_id
            && bccomp((string) ($this->conversion_factor_snapshot ?? 0), '1', 8) === 0
            && bccomp(Money::normalize($base, 4), (string) $value, 8) === 0) return $base;
        return $value;
    }

    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function item() { return $this->belongsTo(Item::class); }
    public function unit() { return $this->belongsTo(Unit::class); }
    public function baseUnit() { return $this->belongsTo(Unit::class, 'base_unit_id'); }
}
