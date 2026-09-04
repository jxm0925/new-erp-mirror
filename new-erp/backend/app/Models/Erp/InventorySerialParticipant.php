<?php

namespace App\Models\Erp;

class InventorySerialParticipant extends MasterModel
{
    protected $table = 'erp_inventory_serial_participants';

    protected $casts = [
        'effective_at' => 'datetime',
        'expired_at' => 'datetime',
    ];

    public function serial() { return $this->belongsTo(InventorySerial::class, 'inventory_serial_id'); }
}
