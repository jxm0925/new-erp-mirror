<?php

namespace App\Models\Erp;

class ProductionUnit extends MasterModel
{
    protected $table = 'erp_production_units';
    protected $casts = [
        'sequence_no' => 'integer',
        'routing_version_snapshot' => 'integer',
        'routing_snapshot' => 'array',
        'business_version' => 'integer',
    ];

    public function workOrder() { return $this->belongsTo(WorkOrder::class, 'work_order_id'); }
    public function operations() { return $this->hasMany(ProductionUnitOperation::class, 'production_unit_id')->orderBy('sequence_no_snapshot'); }
    public function deviceSerial() { return $this->belongsTo(ProductionSerial::class, 'device_serial_id'); }
    public function outputItem() { return $this->belongsTo(Item::class, 'output_item_id'); }
}
