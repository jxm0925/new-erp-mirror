<?php

namespace App\Models\Erp;

class FinanceCashDocument extends MasterModel
{
    protected $table = 'erp_finance_cash_documents';
    protected $casts = [
        'amount' => 'decimal:4',
        'platform_fee_amount' => 'decimal:4',
        'platform_fee_base_amount' => 'decimal:4',
        'business_exchange_rate' => 'decimal:10',
        'base_amount' => 'decimal:4',
        'business_date' => 'date:Y-m-d',
        'exchange_rate_date' => 'date:Y-m-d',
        'confirmed_at' => 'datetime',
        'voided_at' => 'datetime',
    ];
    public function account() { return $this->belongsTo(FinanceAccount::class, 'finance_account_id'); }
    public function allocations() { return $this->hasMany(FinanceAllocation::class, 'cash_document_id'); }
    public function attachments() { return $this->hasMany(FinanceAttachment::class, 'document_id')->where('document_type', 'cash_document')->where('status', 'active'); }
    public function logs() { return $this->hasMany(FinanceOperationLog::class, 'document_id')->where('document_type', 'cash_document')->latest('id'); }
    public function platformFees() { return $this->hasMany(FinancePlatformFee::class, 'cash_document_id'); }
}
