<?php

namespace App\Models\Erp;

class FinancePlatformFee extends MasterModel
{
    protected $table = 'erp_finance_platform_fees';

    protected $casts = [
        'amount' => 'decimal:4',
        'exchange_rate' => 'decimal:10',
        'base_amount' => 'decimal:4',
        'business_date' => 'date',
    ];
}
