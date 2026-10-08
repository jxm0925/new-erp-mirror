<?php
namespace App\Models\Erp;
class ShipmentPackingLaborSession extends MasterModel
{
    protected $table = 'erp_shipment_packing_labor_sessions';
    protected $casts = ['started_at' => 'datetime', 'ended_at' => 'datetime', 'actual_labor_minutes' => 'decimal:2'];
}
