<?php

namespace App\Models\Erp;

class SalesOrderLog extends MasterModel
{
    protected $table = 'erp_sales_order_logs';

    protected $casts = [
        'payload' => 'array',
    ];

    public function order() { return $this->belongsTo(SalesOrder::class, 'sales_order_id'); }
    public function line() { return $this->belongsTo(SalesOrderLine::class, 'sales_order_line_id'); }
}

