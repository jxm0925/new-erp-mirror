<?php

namespace App\Models\Erp;

class Unit extends MasterModel
{
    protected $table = 'erp_units';
    protected $casts = ['is_base' => 'boolean', 'allow_decimal' => 'boolean', 'is_legacy' => 'boolean'];

    public function items() { return $this->hasMany(Item::class, 'unit_id'); }
    public function skus() { return $this->hasMany(Sku::class, 'sales_unit_id'); }
    public function products() { return $this->hasMany(Product::class, 'unit_id'); }
    public function purchaseConversions() { return $this->hasMany(ItemPurchaseConversion::class, 'purchase_unit_id'); }
    public function standardUnit() { return $this->belongsTo(self::class, 'standard_unit_id'); }
    public function legacyUnits() { return $this->hasMany(self::class, 'standard_unit_id'); }
}
