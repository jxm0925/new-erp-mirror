<?php
namespace App\Models\Erp;
class ShipmentPackingMaterial extends MasterModel
{
    protected $table = 'erp_shipment_packing_materials';
    protected $casts = ['base_qty' => 'decimal:8', 'serial_snapshot' => 'array', 'cost_amount_snapshot' => 'decimal:4', 'posted_at' => 'datetime'];
    public function item() { return $this->belongsTo(Item::class, 'item_id'); }
    public function balance() { return $this->belongsTo(InventoryBalance::class, 'inventory_balance_id'); }
}
