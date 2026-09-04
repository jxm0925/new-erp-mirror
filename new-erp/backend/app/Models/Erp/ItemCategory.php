<?php

namespace App\Models\Erp;

class ItemCategory extends MasterModel
{
    protected $table = 'erp_item_categories';
    public function parent() { return $this->belongsTo(self::class, 'parent_id'); }
    public function children() { return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('id'); }
    public function items() { return $this->hasMany(Item::class, 'category_id'); }
    public function supplierCapabilities() { return $this->hasMany(SupplierCategoryCapability::class, 'item_category_id'); }
}
