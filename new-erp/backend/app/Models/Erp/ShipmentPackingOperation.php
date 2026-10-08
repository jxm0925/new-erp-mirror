<?php

namespace App\Models\Erp;

class ShipmentPackingOperation extends MasterModel
{
    protected $table = 'erp_shipment_packing_operations';
    protected $casts = ['packing_content_ids' => 'array', 'packaging_materials_snapshot' => 'array', 'is_public_snapshot' => 'boolean',
        'planned_base_qty' => 'decimal:8', 'completed_base_qty' => 'decimal:8', 'performance_rate_snapshot' => 'decimal:8',
        'started_at' => 'datetime', 'completed_at' => 'datetime', 'claimed_at' => 'datetime', 'business_version' => 'integer'];
    public function shipment() { return $this->belongsTo(SalesShipment::class, 'shipment_id'); }
    public function package() { return $this->belongsTo(SalesShipmentPackage::class, 'package_id'); }
    public function participants() { return $this->hasMany(ShipmentPackingParticipant::class, 'operation_id'); }
    public function laborSessions() { return $this->hasMany(ShipmentPackingLaborSession::class, 'operation_id'); }
    public function materials() { return $this->hasMany(ShipmentPackingMaterial::class, 'operation_id'); }
    public function contents() { return ShipmentPackingContent::query()->whereIn('id', $this->packing_content_ids ?? []); }
}
