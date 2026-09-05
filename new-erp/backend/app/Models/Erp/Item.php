<?php

namespace App\Models\Erp;

class Item extends MasterModel
{
    protected $table = 'erp_items';
    protected $casts = [
        'is_purchase_item' => 'boolean', 'is_stock_item' => 'boolean',
        'is_production_item' => 'boolean', 'is_batch_managed' => 'boolean',
        'is_serial_managed' => 'boolean', 'is_custom_item' => 'boolean',
        'serial_generation_routing_operation_id' => 'integer',
    ];
    public function serialTrackingMode(): string
    {
        return $this->serial_tracking_mode ?: ($this->is_serial_managed ? 'required' : 'none');
    }
    public function category() { return $this->belongsTo(ItemCategory::class, 'category_id'); }
    public function unit() { return $this->belongsTo(Unit::class); }
    public function defaultSupplier() { return $this->belongsTo(Supplier::class, 'default_supplier_id'); }
    public function supplierRelations() { return $this->hasMany(SupplierItemRelation::class); }
    public function activeDefaultSupplierRelation()
    {
        return $this->hasOne(SupplierItemRelation::class)
            ->where('relation_status', 'active')
            ->where('is_default', true)
            ->where(fn ($query) => $query->whereNull('effective_at')->orWhere('effective_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('expired_at')->orWhere('expired_at', '>', now()))
            ->orderByDesc('effective_at')->orderByDesc('id');
    }
    public function defaultWarehouse() { return $this->belongsTo(Warehouse::class, 'default_warehouse_id'); }
    public function skuRelations() { return $this->hasMany(SkuItemRelation::class); }
    public function purchaseConversions() { return $this->hasMany(ItemPurchaseConversion::class); }
    public function defaultPurchaseConversion() { return $this->hasOne(ItemPurchaseConversion::class)->where('status', 'active')->where('is_default', true); }
    public function materialPolicies() { return $this->hasMany(ItemMaterialPolicy::class)->orderByDesc('version_no'); }
    public function activeMaterialPolicy() { return $this->hasOne(ItemMaterialPolicy::class)->where('status', 'active')->latest('effective_at'); }
    public function baseItem() { return $this->belongsTo(self::class, 'base_item_id'); }
}
