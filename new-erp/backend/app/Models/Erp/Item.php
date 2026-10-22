<?php

namespace App\Models\Erp;

class Item extends MasterModel
{
    protected $table = 'erp_items';
    protected $casts = [
        'is_purchase_item' => 'boolean', 'is_stock_item' => 'boolean',
        'is_production_item' => 'boolean', 'is_batch_managed' => 'boolean',
        'is_serial_managed' => 'boolean', 'is_custom_item' => 'boolean',
        'is_length_cut_material' => 'boolean',
        'standard_stock_length_mm' => 'decimal:2',
        'serial_generation_routing_operation_id' => 'integer',
    ];
    /**
     * 启用序列号追溯的物料：序列号前缀必填、1～10 位英文字母、各追溯物料不重复，
     * 这样看到号就知道是什么物料（统一规则见 SerialNumberService）。只在追溯方式或前缀变化时校验。
     */
    protected static function booted(): void
    {
        static::saving(function (Item $item): void {
            $prefix = strtoupper(trim((string) $item->serial_number_prefix));
            $item->serial_number_prefix = $prefix !== '' ? $prefix : null;
            if ($item->serialTrackingMode() === 'none' || ! $item->isDirty(['serial_tracking_mode', 'is_serial_managed', 'serial_number_prefix'])) return;
            if (! preg_match(\App\Services\Erp\SerialNumberService::PREFIX_PATTERN, $prefix))
                throw \Illuminate\Validation\ValidationException::withMessages(['serial_number_prefix' => '启用序列号追溯的物料必须填写序列号前缀（1～10 位英文字母），例如一体机 DF、架子 JZ。']);
            $used = static::query()->where('serial_number_prefix', $prefix)->where('serial_tracking_mode', '!=', 'none')
                ->when($item->exists, fn ($q) => $q->whereKeyNot($item->getKey()))->value('item_code');
            if ($used) throw \Illuminate\Validation\ValidationException::withMessages(['serial_number_prefix' => "序列号前缀 {$prefix} 已被物料 {$used} 使用，请换一个。"]);
        });
    }

    public function serialTrackingMode(): string
    {
        return $this->serial_tracking_mode ?: ($this->is_serial_managed ? 'required' : 'none');
    }
    public function materialManagementMode(): string { return $this->material_management_mode ?: 'quantity'; }
    public function cuttingMode(): string { return $this->cutting_mode ?: ($this->is_length_cut_material ? 'length' : 'none'); }
    public function isPurchaseFulfilledItem(): bool
    {
        // A purchased assembly may retain a BOM or production-use flag. Its explicit
        // supply strategy wins; flags are a fallback only for unspecified legacy items.
        if ($this->item_type === 'service' || ! $this->is_stock_item || $this->managementScope() !== 'factory') return false;
        if ($this->manufacturing_strategy === 'purchase') return true;
        if ($this->manufacturing_strategy === 'make') return false;
        return in_array($this->manufacturing_strategy, [null, '', 'unspecified'], true)
            && $this->is_purchase_item && ! $this->is_production_item;
    }
    public function managementScope(): string
    {
        return $this->management_scope ?: ($this->item_type === 'office_consumable' ? 'office' : 'factory');
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
