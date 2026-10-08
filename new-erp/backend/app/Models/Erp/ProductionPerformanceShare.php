<?php

namespace App\Models\Erp;

class ProductionPerformanceShare extends MasterModel
{
    protected $table = 'erp_production_performance_shares';
    protected $casts = ['eligible' => 'boolean', 'share_ratio' => 'decimal:8'];
}
