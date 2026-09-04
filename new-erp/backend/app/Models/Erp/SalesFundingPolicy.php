<?php

namespace App\Models\Erp;

class SalesFundingPolicy extends MasterModel
{
    protected $table = 'erp_sales_funding_policies';
    protected $casts = ['production_threshold_value' => 'decimal:6', 'shipment_requires_full_payment' => 'boolean'];
}
