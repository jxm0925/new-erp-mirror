<?php

namespace App\Models\Erp;

class SalesOrderFulfillment extends MasterModel
{
    protected $table = 'erp_sales_order_fulfillments';

    protected $casts = [
        'match_snapshot' => 'array',
        'sales_qty' => 'decimal:8',
        'fulfillment_factor_snapshot' => 'decimal:8',
        'item_base_qty' => 'decimal:8',
    ];

    public function order() { return $this->belongsTo(SalesOrder::class, 'sales_order_id'); }
    public function line() { return $this->belongsTo(SalesOrderLine::class, 'sales_order_line_id'); }
    public function item() { return $this->belongsTo(Item::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function location() { return $this->belongsTo(Location::class); }
}
