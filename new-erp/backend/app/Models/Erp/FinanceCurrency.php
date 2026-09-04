<?php

namespace App\Models\Erp;

class FinanceCurrency extends MasterModel
{
    protected $table = 'erp_finance_currencies';

    protected $casts = [
        'is_base' => 'boolean',
    ];
}
