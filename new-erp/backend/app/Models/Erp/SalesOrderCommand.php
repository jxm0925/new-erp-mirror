<?php

namespace App\Models\Erp;

class SalesOrderCommand extends MasterModel
{
    protected $table = 'erp_sales_order_commands';

    protected $casts = [
        'response_snapshot' => 'array',
        'processing_started_at' => 'datetime',
        'processing_finished_at' => 'datetime',
    ];
}
