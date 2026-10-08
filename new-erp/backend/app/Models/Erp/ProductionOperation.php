<?php

namespace App\Models\Erp;

class ProductionOperation extends MasterModel
{
    protected $table = 'erp_production_operations';
    protected $casts = ['sort' => 'integer', 'business_version' => 'integer', 'is_public' => 'boolean', 'auto_assignment_enabled' => 'boolean'];
}
