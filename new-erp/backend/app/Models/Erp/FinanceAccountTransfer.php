<?php

namespace App\Models\Erp;

use Illuminate\Database\Eloquent\Relations\HasMany;

class FinanceAccountTransfer extends MasterModel
{
    protected $table = 'erp_finance_account_transfers';

    protected $casts = [
        'actual_exchange_rate' => 'decimal:10', 'reference_exchange_rate' => 'decimal:10', 'source_amount' => 'decimal:4', 'exchange_source_amount' => 'decimal:4', 'target_amount' => 'decimal:4',
        'gross_target_amount' => 'decimal:4', 'net_target_amount' => 'decimal:4', 'reference_difference_amount' => 'decimal:4',
        'source_base_amount' => 'decimal:4', 'target_base_amount' => 'decimal:4', 'realized_fx_gain_loss' => 'decimal:4',
        'fee_amount' => 'decimal:4', 'fee_base_amount' => 'decimal:4', 'business_date' => 'date:Y-m-d', 'confirmed_at' => 'datetime',
    ];

    public function sourceAccount() { return $this->belongsTo(FinanceAccount::class, 'source_account_id'); }
    public function targetAccount() { return $this->belongsTo(FinanceAccount::class, 'target_account_id'); }
    public function feeAccount() { return $this->belongsTo(FinanceAccount::class, 'fee_account_id'); }
    public function attachments(): HasMany { return $this->hasMany(FinanceAttachment::class, 'document_id')->where('document_type', 'account_transfer')->where('status', 'active'); }
    public function logs(): HasMany { return $this->hasMany(FinanceOperationLog::class, 'document_id')->where('document_type', 'account_transfer')->latest('id'); }
}
