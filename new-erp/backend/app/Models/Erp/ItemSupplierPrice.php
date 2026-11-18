<?php

namespace App\Models\Erp;

use App\Domain\Finance\Money;

class ItemSupplierPrice extends PurchaseBaseModel
{
    protected $table = 'erp_item_supplier_prices';
    protected $hidden = ['latestPriceHistory'];
    protected $casts = [
        'tier_prices' => 'array',
        'valid_from' => 'date',
        'valid_until' => 'date',
        'approved_at' => 'datetime',
        'standard_conversion_factor' => 'decimal:8',
        'final_conversion_factor' => 'decimal:8',
        'base_unit_price' => 'decimal:8',
    ];
    public function latestPriceHistory()
    {
        return $this->hasOne(SupplierQuotationHistory::class, 'quotation_id')->ofMany(['id' => 'max'],
            fn ($query) => $query->whereIn('action', ['create', 'update']));
    }

    public function getPriceAttribute($value)
    {
        if ($value === null || !$this->exists) return $value;
        $snapshot = $this->relationLoaded('latestPriceHistory') ? $this->getRelation('latestPriceHistory')?->quotation_snapshot : null;
        $price = $snapshot['price'] ?? null;
        if (is_string($price) && preg_match('/^\d{1,10}(?:\.\d{1,8})?$/D', $price)
            && (int) ($snapshot['unit_id'] ?? 0) === (int) $this->unit_id
            && bccomp(Money::normalize($price, 4), (string) $value, 8) === 0
            && strlen(explode('.', $price, 2)[1] ?? '') > 4) return $price;

        // 旧历史可能仅留下四位采购单价。同一单位且换算为一，基本单价可直接复用。
        $item = $this->relationLoaded('item') ? $this->getRelation('item') : null;
        $base = $this->base_unit_price;
        if ($base !== null && $item && $item->unit_id && (int) $item->unit_id === (int) $this->unit_id
            && bccomp((string) ($this->final_conversion_factor ?? 0), '1', 8) === 0
            && bccomp(Money::normalize($base, 4), (string) $value, 8) === 0) return $base;
        return $value;
    }

    public function item() { return $this->belongsTo(Item::class); }
    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function unit() { return $this->belongsTo(Unit::class); }
}
