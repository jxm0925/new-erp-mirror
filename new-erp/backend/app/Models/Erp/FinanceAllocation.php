<?php

namespace App\Models\Erp;

class FinanceAllocation extends MasterModel
{
    protected $table = 'erp_finance_allocations';
    protected $casts = [
        'source_amount_snapshot' => 'decimal:4',
        'allocated_amount' => 'decimal:4',
        'cash_allocated_amount' => 'decimal:4',
        'business_allocated_amount' => 'decimal:4',
        'allocation_exchange_rate' => 'decimal:10',
        'base_amount' => 'decimal:4',
        'exchange_rate_date' => 'date:Y-m-d',
        'allocated_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];
    public function cashDocument() { return $this->belongsTo(FinanceCashDocument::class, 'cash_document_id'); }
}
