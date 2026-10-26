<?php

namespace App\Models\Erp;

class SalesReturnDisposition extends MasterModel
{
    protected $table = 'erp_sales_return_dispositions';

    protected $casts = ['base_qty' => 'decimal:8'];

    public function salesReturn() { return $this->belongsTo(SalesReturn::class, 'sales_return_id'); }
    public function item() { return $this->belongsTo(SalesReturnItem::class, 'sales_return_item_id'); }
    public function sourceReceiptItem() { return $this->belongsTo(SalesReturnReceiptItem::class, 'source_receipt_item_id'); }
    public function targetReceiptItem() { return $this->belongsTo(SalesReturnReceiptItem::class, 'target_receipt_item_id'); }
}
