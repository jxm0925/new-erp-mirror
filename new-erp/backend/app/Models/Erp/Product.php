<?php

namespace App\Models\Erp;

class Product extends MasterModel
{
    protected $table = 'erp_products';
    public function skus() { return $this->hasMany(Sku::class); }
    public function category() { return $this->belongsTo(ItemCategory::class, 'category_id'); }
    public function unit() { return $this->belongsTo(Unit::class); }
}
