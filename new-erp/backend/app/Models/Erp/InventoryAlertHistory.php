<?php

namespace App\Models\Erp;

class InventoryAlertHistory extends MasterModel
{
    protected $table = 'erp_inventory_alert_histories';
    protected $casts = ['quantity_snapshot' => 'array', 'threshold_snapshot' => 'array', 'occurred_at' => 'datetime'];
    public function alert() { return $this->belongsTo(InventoryAlert::class, 'alert_id'); }
}
