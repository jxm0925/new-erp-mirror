<?php

namespace App\Models\Erp;

/** 生产退料明细：按原收料出库流水的批次和单价冲回。 */
class MfgMaterialReturnLine extends MasterModel
{
    protected $table = 'erp_mfg_material_return_lines';
    protected $casts = ['qty' => 'decimal:4', 'unit_cost' => 'decimal:4', 'cost_amount' => 'decimal:4'];

    public function materialReturn() { return $this->belongsTo(MfgMaterialReturn::class, 'return_id'); }
}
