<?php

namespace App\Models\Erp;

class ProductionOperation extends MasterModel
{
    protected $table = 'erp_production_operations';
    protected $casts = ['sort' => 'integer', 'business_version' => 'integer'];
}
