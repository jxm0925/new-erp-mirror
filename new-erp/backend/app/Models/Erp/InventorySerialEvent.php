<?php

namespace App\Models\Erp;

class InventorySerialEvent extends MasterModel
{
    protected $table = 'erp_inventory_serial_events';

    protected $casts = [
        'event_payload' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function serial() { return $this->belongsTo(InventorySerial::class, 'inventory_serial_id'); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function location() { return $this->belongsTo(Location::class); }
}
