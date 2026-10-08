<?php

namespace App\Models\Erp;

class ProductionJobBundleLaborAllocation extends MasterModel
{
    protected $table = 'erp_production_job_bundle_labor_allocations';
    protected $casts = ['allocated_labor_minutes' => 'decimal:2', 'credited_labor_minutes' => 'decimal:2', 'standard_weight_minutes_snapshot' => 'decimal:8'];
}
