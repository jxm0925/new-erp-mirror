<?php

namespace App\Models\Erp;

class ShipmentSerialResult extends MasterModel
{
    protected $table = 'erp_shipment_serial_results';
    protected $casts = ['round' => 'integer', 'completed_at' => 'datetime', 'inspected_at' => 'datetime'];
}
