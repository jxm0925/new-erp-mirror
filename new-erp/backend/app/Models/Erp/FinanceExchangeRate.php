<?php

namespace App\Models\Erp;

class FinanceExchangeRate extends MasterModel
{
    protected $table = 'erp_finance_exchange_rates';

    protected $casts = [
        'rate' => 'decimal:10',
        'effective_at' => 'datetime',
        'disabled_at' => 'datetime',
    ];
}
