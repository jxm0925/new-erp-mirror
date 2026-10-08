<?php

namespace App\Models\Erp;

class SalesShipmentLine extends MasterModel
{
    protected $table = 'erp_sales_shipment_lines';
    // Frozen routes contain private performance settings. Shipment/warehouse projections
    // expose packing requirements through their whitelist rather than serialize these fields.
    protected $hidden = ['packing_routing_snapshot', 'packing_source_snapshot'];
    protected $casts = ['serial_snapshot' => 'array', 'packing_routing_snapshot' => 'array', 'packing_source_snapshot' => 'array'];
    public function shipment() { return $this->belongsTo(SalesShipment::class); }
    public function orderLine() { return $this->belongsTo(SalesOrderLine::class, 'sales_order_line_id'); }
    public function reservation() { return $this->belongsTo(InventoryReservation::class, 'inventory_reservation_id'); }
}
