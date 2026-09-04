<?php

namespace App\Models\Erp;

class SalesShipmentLog extends MasterModel
{
    protected $table = 'erp_sales_shipment_logs';
    protected $casts = ['payload' => 'array'];
    public function shipment() { return $this->belongsTo(SalesShipment::class); }
}
