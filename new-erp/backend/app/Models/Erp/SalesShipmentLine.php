<?php

namespace App\Models\Erp;

class SalesShipmentLine extends MasterModel
{
    protected $table = 'erp_sales_shipment_lines';
    protected $casts = ['serial_snapshot' => 'array'];
    public function shipment() { return $this->belongsTo(SalesShipment::class); }
    public function orderLine() { return $this->belongsTo(SalesOrderLine::class, 'sales_order_line_id'); }
    public function reservation() { return $this->belongsTo(InventoryReservation::class, 'inventory_reservation_id'); }
}
