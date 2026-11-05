<?php

namespace App\Models\Erp;

class AftersalesReshipItem extends MasterModel
{
    protected $table = 'erp_aftersales_reship_items';
    protected $casts = ['reship_id' => 'integer', 'action_item_id' => 'integer', 'sales_order_line_id' => 'integer',
        'item_id' => 'integer', 'unit_id' => 'integer', 'warehouse_id' => 'integer', 'location_id' => 'integer',
        'inventory_balance_id' => 'integer', 'inventory_serial_id' => 'integer', 'transaction_item_id' => 'integer',
        'qty' => 'decimal:8', 'unit_cost' => 'decimal:6', 'cost_amount' => 'decimal:4'];

    public function reship() { return $this->belongsTo(AftersalesReship::class, 'reship_id'); }
    public function actionItem() { return $this->belongsTo(AftersalesActionItem::class, 'action_item_id'); }
    public function orderLine() { return $this->belongsTo(SalesOrderLine::class, 'sales_order_line_id'); }
    public function item() { return $this->belongsTo(Item::class, 'item_id'); }
    public function serial() { return $this->belongsTo(InventorySerial::class, 'inventory_serial_id'); }
}
