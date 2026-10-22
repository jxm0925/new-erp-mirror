<?php

namespace App\Models\Erp;

class MfgMaterialDelivery extends MasterModel
{
    protected $table = 'erp_mfg_material_deliveries';
    protected $casts = ['lines' => 'array', 'unit_qty' => 'decimal:4', 'prepared_at' => 'datetime', 'dispatched_at' => 'datetime',
        'signed_at' => 'datetime', 'rejected_at' => 'datetime', 'closed_at' => 'datetime'];

    public function workOrder() { return $this->belongsTo(MfgWorkOrder::class, 'work_order_id'); }
    public function operation() { return $this->belongsTo(MfgWorkOrderOperation::class, 'work_order_operation_id'); }
}
