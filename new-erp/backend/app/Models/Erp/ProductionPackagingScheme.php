<?php

namespace App\Models\Erp;

class ProductionPackagingScheme extends MasterModel
{
    protected $table = 'erp_production_packaging_schemes';
    protected $casts = ['sort' => 'integer', 'business_version' => 'integer'];

    public function items() { return $this->hasMany(ProductionPackagingSchemeItem::class, 'packaging_scheme_id')->orderBy('sort_order')->orderBy('id'); }
}
