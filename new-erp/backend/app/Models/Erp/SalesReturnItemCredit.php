<?php

namespace App\Models\Erp;

class SalesReturnItemCredit extends MasterModel
{
    protected $table = 'erp_sales_return_item_credits';

    protected $casts = [
        'unit_price_snapshot' => 'decimal:4',
        'credited_sales_qty' => 'decimal:8',
        'credit_amount' => 'decimal:4',
    ];

    public function item() { return $this->belongsTo(SalesReturnItem::class, 'sales_return_item_id'); }
}
