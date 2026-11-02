<?php

namespace App\Models\Erp;

/** 生产入库申请及仓库确认事实。 */
class MfgReceipt extends MasterModel
{
    protected $table = 'erp_mfg_receipts';
    protected $casts = ['qty' => 'decimal:4', 'unit_cost' => 'decimal:4', 'cost_amount' => 'decimal:4', 'serial_nos' => 'array',
        'requested_at' => 'datetime', 'confirmed_at' => 'datetime', 'rejected_at' => 'datetime', 'cancelled_at' => 'datetime',
        'business_version' => 'integer'];

    public function workOrder() { return $this->belongsTo(MfgWorkOrder::class, 'work_order_id'); }
    public function warehouse() { return $this->belongsTo(Warehouse::class, 'warehouse_id'); }
    public function location() { return $this->belongsTo(Location::class, 'location_id'); }
    public function requestedWarehouse() { return $this->belongsTo(Warehouse::class, 'requested_warehouse_id'); }
    public function requestedLocation() { return $this->belongsTo(Location::class, 'requested_location_id'); }
    public function inventoryTransaction() { return $this->belongsTo(InventoryTransaction::class, 'inventory_transaction_id'); }
}
