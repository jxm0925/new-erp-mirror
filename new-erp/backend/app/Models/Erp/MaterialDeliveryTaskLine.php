<?php

namespace App\Models\Erp;

class MaterialDeliveryTaskLine extends MasterModel
{
    protected $table = 'erp_material_delivery_task_lines';
    protected $casts = ['allocated_qty' => 'decimal:8', 'serial_snapshot' => 'array', 'business_version' => 'integer'];

    public function task() { return $this->belongsTo(MaterialDeliveryTask::class, 'delivery_task_id'); }
    public function delivery() { return $this->belongsTo(MaterialDelivery::class, 'material_delivery_id'); }
    public function deliveryLine() { return $this->belongsTo(MaterialDeliveryLine::class, 'material_delivery_line_id'); }
}
