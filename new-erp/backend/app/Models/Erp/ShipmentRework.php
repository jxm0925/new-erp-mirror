<?php

namespace App\Models\Erp;

class ShipmentRework extends MasterModel
{
    protected $table = 'erp_shipment_reworks';
    protected $casts = ['round' => 'integer', 'completed_at' => 'datetime', 'returned_at' => 'datetime'];
}
