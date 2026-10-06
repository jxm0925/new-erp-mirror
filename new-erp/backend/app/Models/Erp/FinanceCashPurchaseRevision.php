<?php

namespace App\Models\Erp;

class FinanceCashPurchaseRevision extends MasterModel
{
    protected $table = 'erp_finance_cash_purchase_revisions';
    protected $casts = ['before_snapshot' => 'array', 'after_snapshot' => 'array', 'version' => 'integer'];
}
