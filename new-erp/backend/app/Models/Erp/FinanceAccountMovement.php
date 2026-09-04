<?php

namespace App\Models\Erp;

class FinanceAccountMovement extends MasterModel
{
    protected $table = 'erp_finance_account_movements';

    protected $casts = [
        'original_amount' => 'decimal:4',
        'base_amount' => 'decimal:4',
        'business_date' => 'date',
    ];
}
