<?php

namespace App\Models\Erp;

class MfgWorkOrderMaterial extends MasterModel
{
    protected $table = 'erp_mfg_work_order_materials';
    protected $casts = ['unit_qty' => 'decimal:6', 'loss_rate' => 'decimal:4', 'fixed_qty' => 'decimal:4', 'qty_ratio' => 'decimal:6',
        'required_qty' => 'decimal:4', 'issued_qty' => 'decimal:4', 'participates_in_kitting' => 'boolean'];

    public function workOrder() { return $this->belongsTo(MfgWorkOrder::class, 'work_order_id'); }
    public function operation() { return $this->belongsTo(MfgWorkOrderOperation::class, 'work_order_operation_id'); }
}
