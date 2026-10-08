<?php

namespace App\Models\Erp;

class ProductionPackagingScheme extends MasterModel
{
    protected $table = 'erp_production_packaging_schemes';
    protected $casts = ['sort' => 'integer', 'business_version' => 'integer'];
}
