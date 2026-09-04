<?php

namespace App\Models\Erp;

class ItemPurchaseConversion extends MasterModel
{
    protected $table = 'erp_item_purchase_conversions';

    protected $casts = [
        'factor' => 'decimal:8',
        'is_default' => 'boolean',
        'allow_actual_conversion' => 'boolean',
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
    ];

    public function item() { return $this->belongsTo(Item::class); }
    public function purchaseUnit() { return $this->belongsTo(Unit::class, 'purchase_unit_id'); }
    public function baseUnit() { return $this->belongsTo(Unit::class, 'base_unit_id'); }
}
