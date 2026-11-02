<?php

namespace App\Models\Erp;

class SalesCancellation extends MasterModel
{
    protected $table = 'erp_sales_cancellations';
    protected $casts = ['history' => 'array', 'finance_confirmed_at' => 'datetime', 'business_version' => 'integer'];
}
