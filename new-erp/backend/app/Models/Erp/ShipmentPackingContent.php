<?php

namespace App\Models\Erp;

class ShipmentPackingContent extends MasterModel
{
    protected $table = 'erp_shipment_packing_contents';
    protected $casts = ['base_qty' => 'decimal:8', 'serial_snapshot' => 'array', 'source_snapshot' => 'array', 'routing_snapshot' => 'array'];
    public function shipmentLine() { return $this->belongsTo(SalesShipmentLine::class, 'shipment_line_id'); }
    public function package() { return $this->belongsTo(SalesShipmentPackage::class, 'package_id'); }
}
