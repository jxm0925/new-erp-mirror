<?php

namespace App\Models\Erp;

class InventoryReservation extends MasterModel
{
    public const SOURCE_SALES_ORDER = 'sales_order';
    public const SOURCE_WORK_ORDER = 'work_order';
    public const SOURCE_PURCHASE_RETURN = 'purchase_return';
    public const SOURCE_QUALITY_HOLD = 'quality_hold';
    public const SOURCE_OTHER = 'other';

    protected $table = 'erp_inventory_reservations';

    protected $casts = [
        'reserved_at' => 'datetime',
        'released_at' => 'datetime',
        'reservation_snapshot' => 'array',
        'reserved_qty' => 'decimal:8',
    ];

    public function item() { return $this->belongsTo(Item::class); }
    public function balance() { return $this->belongsTo(InventoryBalance::class, 'inventory_balance_id'); }
}
