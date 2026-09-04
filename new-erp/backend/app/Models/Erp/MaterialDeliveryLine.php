<?php

namespace App\Models\Erp;

class MaterialDeliveryLine extends MasterModel
{
    protected $table = 'erp_material_delivery_lines';
    protected $casts = [
        'delivery_qty' => 'decimal:8', 'received_qty' => 'decimal:8',
        'rejected_qty' => 'decimal:8', 'serial_snapshot' => 'array',
    ];

    public function delivery() { return $this->belongsTo(MaterialDelivery::class, 'delivery_id'); }
    public function pickingTaskLine() { return $this->belongsTo(MaterialPickingTaskLine::class, 'picking_task_line_id'); }
    public function requirement() { return $this->belongsTo(WorkOrderMaterialRequirement::class, 'material_requirement_id'); }
}
