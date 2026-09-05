<?php

namespace App\Models\Erp;

class ProductionQualityInspection extends MasterModel
{
    protected $table = 'erp_production_quality_inspections';
    protected $casts = ['inspection_snapshot' => 'array', 'inspected_at' => 'datetime', 'business_version' => 'integer'];
}
