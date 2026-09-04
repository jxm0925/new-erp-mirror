<?php

namespace App\Models\Erp;

class InventoryQualityEventLog extends MasterModel
{
    protected $table = 'erp_inventory_quality_event_logs';

    public function event() { return $this->belongsTo(InventoryQualityEvent::class, 'quality_event_id'); }
}
