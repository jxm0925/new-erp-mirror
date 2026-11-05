<?php

namespace App\Models\Erp;

class AftersalesCaseItem extends MasterModel
{
    protected $table = 'erp_aftersales_case_items';
    protected $casts = ['case_id' => 'integer', 'sales_order_line_id' => 'integer', 'item_id' => 'integer',
        'inventory_serial_id' => 'integer', 'qty' => 'decimal:8'];

    public function case() { return $this->belongsTo(AftersalesCase::class, 'case_id'); }
    public function orderLine() { return $this->belongsTo(SalesOrderLine::class, 'sales_order_line_id'); }
    public function serial() { return $this->belongsTo(InventorySerial::class, 'inventory_serial_id'); }
}
