<?php

namespace App\Models\Erp;

class FinanceInvoice extends MasterModel
{
    protected $table = 'erp_finance_invoices';
    protected $casts = ['amount_excl_tax' => 'decimal:4', 'tax_amount' => 'decimal:4', 'amount_incl_tax' => 'decimal:4', 'invoice_date' => 'date:Y-m-d', 'received_date' => 'date:Y-m-d', 'red_date' => 'date:Y-m-d', 'tax_detail' => 'array', 'confirmed_at' => 'datetime'];

    public function allocations() { return $this->hasMany(FinanceInvoiceAllocation::class, 'invoice_id'); }
    public function attachments() { return $this->hasMany(FinanceAttachment::class, 'document_id')->where('document_type', 'finance_invoice'); }
    public function logs() { return $this->hasMany(FinanceOperationLog::class, 'document_id')->where('document_type', 'finance_invoice'); }
    public function originalBlueInvoice() { return $this->belongsTo(self::class, 'red_invoice_of_id'); }
    public function redInvoices() { return $this->hasMany(self::class, 'red_invoice_of_id'); }
}
