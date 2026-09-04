<?php

namespace App\Models\Erp;

class SupplierItemStat extends PurchaseBaseModel
{
    protected $table = 'erp_supplier_item_stats';
    protected $casts = [
        'last_receipt_at' => 'datetime',
        'last_return_at' => 'datetime',
        'on_time_rate' => 'float',
        'qualified_rate' => 'float',
        'return_rate' => 'float',
    ];
    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function item() { return $this->belongsTo(Item::class); }
}
