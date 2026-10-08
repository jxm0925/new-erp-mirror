<?php
namespace App\Models\Erp;
class ShipmentPackingParticipant extends MasterModel
{
    protected $table = 'erp_shipment_packing_participants';
    protected $casts = ['is_active' => 'boolean'];
}
