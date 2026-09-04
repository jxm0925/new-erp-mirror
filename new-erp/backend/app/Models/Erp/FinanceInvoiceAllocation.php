<?php

namespace App\Models\Erp;

class FinanceInvoiceAllocation extends MasterModel
{
    protected $table = 'erp_finance_invoice_allocations';
    protected $casts = ['source_amount_snapshot' => 'decimal:4', 'allocated_amount' => 'decimal:4'];

    public function invoice() { return $this->belongsTo(FinanceInvoice::class, 'invoice_id'); }
}
