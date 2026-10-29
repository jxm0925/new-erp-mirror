<?php

namespace App\Models\Erp;

class SalesInvoiceRequest extends MasterModel
{
    protected $table = 'erp_sales_invoice_requests';

    protected $casts = [
        'amount' => 'decimal:4',
        'tax_rate' => 'decimal:2',
        'invoice_date' => 'date:Y-m-d',
        'handled_at' => 'datetime',
    ];

    public function order() { return $this->belongsTo(SalesOrder::class, 'sales_order_id'); }
    public function financeInvoice() { return $this->belongsTo(FinanceInvoice::class, 'finance_invoice_id'); }
}
