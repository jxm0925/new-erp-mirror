<?php

namespace App\Models\Erp;

class Supplier extends MasterModel
{
    protected $table = 'erp_suppliers';

    protected $casts = [
        'is_blacklisted' => 'boolean',
        'purchase_restricted' => 'boolean',
        'quality_frozen_until' => 'datetime',
    ];

    /** Compatibility relation only. Formal capability is stored in itemRelations. */
    public function items() { return $this->hasMany(Item::class, 'default_supplier_id'); }
    public function categoryCapabilities() { return $this->hasMany(SupplierCategoryCapability::class); }
    public function itemRelations() { return $this->hasMany(SupplierItemRelation::class); }
    public function quotations() { return $this->hasMany(ItemSupplierPrice::class); }
    public function itemStats() { return $this->hasMany(SupplierItemStat::class); }
}
