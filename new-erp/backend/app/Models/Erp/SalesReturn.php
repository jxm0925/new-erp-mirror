<?php

namespace App\Models\Erp;

class SalesReturn extends MasterModel
{
    protected $table = 'erp_sales_returns';

    protected $casts = [
        'return_date' => 'date:Y-m-d',
        'confirmed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function order() { return $this->belongsTo(SalesOrder::class, 'sales_order_id'); }
    public function customer() { return $this->belongsTo(SalesCustomer::class, 'customer_id'); }
    public function items() { return $this->hasMany(SalesReturnItem::class, 'sales_return_id'); }
    public function receipts() { return $this->hasMany(SalesReturnReceipt::class, 'sales_return_id'); }
    public function logs() { return $this->hasMany(SalesReturnLog::class, 'sales_return_id'); }
}
