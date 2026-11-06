<?php

namespace App\Models\Erp;

class ShipmentOutboundApplication extends MasterModel
{
    protected $table = 'erp_shipment_outbound_applications';

    public const OPEN = ['pending', 'approved'];

    protected $casts = [
        'package_ids' => 'array', 'package_nos' => 'array', 'warehouse_ids' => 'array', 'lines_snapshot' => 'array',
        'reviewed_at' => 'datetime', 'posted_at' => 'datetime', 'withdrawn_at' => 'datetime',
    ];

    public function scopeOpen($query)
    {
        return $query->whereIn('status', self::OPEN);
    }
}
