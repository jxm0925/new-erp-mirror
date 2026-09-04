<?php

namespace App\Models\Erp;

class Sku extends MasterModel
{
    protected $table = 'erp_skus';
    protected $hidden = [
        'is_customizable', 'is_sale_item', 'supports_electric', 'electric_required',
        'electric_options', 'supports_need_pump', 'need_pump_required',
    ];
    protected $appends = ['line_type', 'is_sellable', 'spec_model'];
    protected $casts = [
        'is_customizable' => 'boolean', 'allow_customized' => 'boolean',
        'allow_special_customized' => 'boolean',
        'special_custom_drawing_required' => 'boolean',
        'special_custom_agreement_required' => 'boolean',
        'special_custom_description_required' => 'boolean',
        'delivery_inspection_required' => 'boolean',
        'is_need_production' => 'boolean', 'is_need_bom' => 'boolean', 'is_sale_item' => 'boolean',
        'is_custom_sku' => 'boolean', 'supports_electric' => 'boolean',
        'electric_required' => 'boolean', 'electric_options' => 'array',
        'supports_need_pump' => 'boolean', 'need_pump_required' => 'boolean',
        'default_tax_rate' => 'decimal:6',
    ];

    public function getLineTypeAttribute(): string { return $this->order_line_type ?: ($this->fulfillment_type === 'virtual' ? 'no_delivery' : (string) $this->fulfillment_type); }
    public function getIsSellableAttribute(): bool { return (bool) $this->is_sale_item; }
    public function getSpecModelAttribute(): ?string { return $this->spec_text; }
    public function getIsCustomizableAttribute($value): bool { return (bool) ($this->allow_customized || $this->allow_special_customized); }
    public function getSupportsElectricAttribute($value): bool { return $this->electric_mode !== 'hidden'; }
    public function getElectricRequiredAttribute($value): bool { return $this->electric_mode === 'required'; }
    public function getElectricOptionsAttribute($value): ?array { return null; }
    public function getSupportsNeedPumpAttribute($value): bool { return $this->need_pump_mode !== 'hidden'; }
    public function getNeedPumpRequiredAttribute($value): bool { return $this->need_pump_mode === 'required'; }
    public function product() { return $this->belongsTo(Product::class); }
    public function salesUnit() { return $this->belongsTo(Unit::class, 'sales_unit_id'); }
    public function itemRelations() { return $this->hasMany(SkuItemRelation::class); }
    public function baseSku() { return $this->belongsTo(self::class, 'base_sku_id'); }
}
