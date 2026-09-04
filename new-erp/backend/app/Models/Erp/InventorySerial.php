<?php

namespace App\Models\Erp;

class InventorySerial extends MasterModel
{
    protected $table = 'erp_inventory_serials';

    protected $casts = [
        'received_at' => 'datetime',
        'registered_at' => 'datetime',
        'posted_at' => 'datetime',
        'reserved_at' => 'datetime',
        'outbound_at' => 'datetime',
    ];

    public function balance() { return $this->belongsTo(InventoryBalance::class, 'inventory_balance_id'); }
    public function item() { return $this->belongsTo(Item::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function location() { return $this->belongsTo(Location::class); }
    public function receipt() { return $this->belongsTo(PurchaseReceipt::class, 'source_receipt_id'); }
    public function receiptItem() { return $this->belongsTo(PurchaseReceiptItem::class, 'source_receipt_item_id'); }
    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function events() { return $this->hasMany(InventorySerialEvent::class, 'inventory_serial_id')->orderByDesc('occurred_at')->orderByDesc('id'); }
    public function participants() { return $this->hasMany(InventorySerialParticipant::class, 'inventory_serial_id'); }
    public function purchaseReturnLinks() { return $this->hasMany(PurchaseReturnItemSerial::class, 'inventory_serial_id'); }
}
