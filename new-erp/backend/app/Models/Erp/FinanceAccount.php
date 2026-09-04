<?php

namespace App\Models\Erp;

use Illuminate\Database\Eloquent\Relations\HasMany;

class FinanceAccount extends MasterModel
{
    protected $table = 'erp_finance_accounts';

    public function movements(): HasMany { return $this->hasMany(FinanceAccountMovement::class, 'finance_account_id'); }
}
