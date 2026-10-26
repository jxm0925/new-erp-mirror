<?php

namespace App\Models\Erp;

class SalesReturnTerm extends MasterModel
{
    protected $table = 'erp_sales_return_terms';

    protected $casts = ['credit_amount' => 'decimal:4'];

    public function salesReturn() { return $this->belongsTo(SalesReturn::class, 'sales_return_id'); }
}
