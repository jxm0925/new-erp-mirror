<?php

namespace App\Models\Erp;

class SalesShipment extends MasterModel
{
    protected $table = 'erp_sales_shipments';

    protected $casts = [
        'receiver_snapshot' => 'array',
        'confirmed_at' => 'datetime',
        'outbound_posted_at' => 'datetime',
        'shipped_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function order() { return $this->belongsTo(SalesOrder::class, 'sales_order_id'); }
    public function lines() { return $this->hasMany(SalesShipmentLine::class, 'shipment_id'); }
    public function packages() { return $this->hasMany(SalesShipmentPackage::class, 'shipment_id'); }
    public function logs() { return $this->hasMany(SalesShipmentLog::class, 'shipment_id'); }
}
