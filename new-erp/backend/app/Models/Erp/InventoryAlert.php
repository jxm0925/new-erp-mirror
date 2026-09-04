<?php

namespace App\Models\Erp;

class InventoryAlert extends MasterModel
{
    protected $table = 'erp_inventory_alerts';
    protected $casts = [
        'is_active' => 'boolean', 'is_read' => 'boolean', 'first_triggered_at' => 'datetime',
        'last_changed_at' => 'datetime', 'resolved_at' => 'datetime', 'read_at' => 'datetime',
    ];
    public function item() { return $this->belongsTo(Item::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function policy() { return $this->belongsTo(InventoryAlertPolicy::class, 'policy_id'); }
    public function purchaseRequest() { return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id'); }
    public function histories() { return $this->hasMany(InventoryAlertHistory::class, 'alert_id')->latest('occurred_at'); }
}
