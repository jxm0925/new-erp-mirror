<?php

namespace App\Models\Erp;

class MfgWorkOrderOperation extends MasterModel
{
    protected $table = 'erp_mfg_work_order_operations';
    protected $casts = ['is_key_operation' => 'boolean', 'completed_qty' => 'decimal:4', 'setup_standard_minutes' => 'decimal:4', 'unit_standard_minutes' => 'decimal:4'];

    public function workOrder() { return $this->belongsTo(MfgWorkOrder::class, 'work_order_id'); }
    public function materials() { return $this->hasMany(MfgWorkOrderMaterial::class, 'work_order_operation_id'); }
}
