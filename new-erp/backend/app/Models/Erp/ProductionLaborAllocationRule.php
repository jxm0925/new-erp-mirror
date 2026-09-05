<?php

namespace App\Models\Erp;

class ProductionLaborAllocationRule extends MasterModel
{
    protected $table = 'erp_production_labor_allocation_rules';
    protected $casts = [
        'owner_ratio' => 'decimal:4',
        'collaborator_total_ratio' => 'decimal:4',
        'effective_at' => 'datetime',
        'retired_at' => 'datetime',
        'business_version' => 'integer',
    ];
}
