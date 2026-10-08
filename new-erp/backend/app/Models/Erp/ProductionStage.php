<?php

namespace App\Models\Erp;

class ProductionStage extends MasterModel
{
    protected $table = 'erp_production_stages';
    protected $casts = ['sort' => 'integer', 'business_version' => 'integer'];
}
