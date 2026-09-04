<?php

namespace App\Models\Erp;

class SalesReturnReceipt extends MasterModel
{
    protected $table = 'erp_sales_return_receipts';

    protected $casts = [
        'receipt_date' => 'date:Y-m-d',
        'confirmed_at' => 'datetime',
        'posted_at' => 'datetime',
    ];

    public function salesReturn() { return $this->belongsTo(SalesReturn::class, 'sales_return_id'); }
    public function items() { return $this->hasMany(SalesReturnReceiptItem::class, 'receipt_id'); }
}
