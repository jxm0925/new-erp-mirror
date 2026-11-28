<?php

namespace App\Models\Erp;

class ProductionPackagingSchemeItem extends MasterModel
{
    protected $table = 'erp_production_packaging_scheme_items';
    protected $casts = ['qty_per_unit' => 'decimal:8', 'sort_order' => 'integer'];

    public function item() { return $this->belongsTo(Item::class); }
    public function packagingScheme() { return $this->belongsTo(ProductionPackagingScheme::class, 'packaging_scheme_id'); }
}
