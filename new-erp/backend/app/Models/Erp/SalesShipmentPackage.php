<?php

namespace App\Models\Erp;

class SalesShipmentPackage extends MasterModel
{
    protected $table = 'erp_sales_shipment_packages';
    public function shipment() { return $this->belongsTo(SalesShipment::class); }
}
