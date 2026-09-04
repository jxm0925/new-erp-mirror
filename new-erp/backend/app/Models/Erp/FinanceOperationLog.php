<?php

namespace App\Models\Erp;

class FinanceOperationLog extends MasterModel
{
    protected $table = 'erp_finance_operation_logs';
    protected $casts = ['fact_snapshot' => 'array'];
}
