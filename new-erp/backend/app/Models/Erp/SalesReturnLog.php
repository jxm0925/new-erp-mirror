<?php

namespace App\Models\Erp;

class SalesReturnLog extends MasterModel
{
    protected $table = 'erp_sales_return_logs';

    public function salesReturn() { return $this->belongsTo(SalesReturn::class, 'sales_return_id'); }
}
