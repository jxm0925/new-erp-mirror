<?php

namespace App\Models\Erp;

/** 生产退料单：车间申请、库管审核通过时冲回库存。 */
class MfgMaterialReturn extends MasterModel
{
    protected $table = 'erp_mfg_material_returns';
    protected $casts = ['cost_amount' => 'decimal:4', 'requested_at' => 'datetime', 'reviewed_at' => 'datetime'];

    public function lines() { return $this->hasMany(MfgMaterialReturnLine::class, 'return_id')->orderBy('id'); }
    public function workOrder() { return $this->belongsTo(MfgWorkOrder::class, 'work_order_id'); }
    public function operation() { return $this->belongsTo(MfgWorkOrderOperation::class, 'work_order_operation_id'); }
}
