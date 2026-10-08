<?php

namespace App\Models\Erp;

class SalesShipmentPackage extends MasterModel
{
    protected $table = 'erp_sales_shipment_packages';
    public function shipment() { return $this->belongsTo(SalesShipment::class); }
    public function packingContents() { return $this->hasMany(ShipmentPackingContent::class, 'package_id'); }
    public function packingOperations() { return $this->hasMany(ShipmentPackingOperation::class, 'package_id'); }
}
