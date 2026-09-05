<?php

namespace App\Models\Erp;

class ProductionOutputRecord extends MasterModel
{
    protected $table = 'erp_production_output_records';
    protected $casts = ['output_base_qty' => 'decimal:8', 'produced_at' => 'datetime', 'business_version' => 'integer'];
}
