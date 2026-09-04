<?php

namespace App\Models\Erp;

class SalesChannel extends MasterModel
{
    protected $table = 'erp_sales_channels';
    protected $casts = ['requires_external_order_no' => 'boolean', 'is_default' => 'boolean'];
}
