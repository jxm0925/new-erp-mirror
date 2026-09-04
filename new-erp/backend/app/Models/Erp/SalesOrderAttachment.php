<?php

namespace App\Models\Erp;

class SalesOrderAttachment extends MasterModel
{
    protected $table = 'erp_sales_order_attachments';

    protected $casts = [
        'uploaded_at' => 'datetime',
        'deleted_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function order() { return $this->belongsTo(SalesOrder::class, 'sales_order_id'); }
    public function line() { return $this->belongsTo(SalesOrderLine::class, 'sales_order_line_id'); }
}
