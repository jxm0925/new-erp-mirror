<?php

namespace App\Models\Erp;

class ShipmentDispatch extends MasterModel
{
    protected $table = 'erp_shipment_dispatches';
    protected $casts = ['package_ids' => 'array', 'shipment_line_ids' => 'array', 'dispatched_at' => 'datetime'];
}
