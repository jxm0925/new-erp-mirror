<?php

namespace App\Models\Erp;

/** 不良退料处置记录：判定可用释放、报废出库、退供应商。 */
class MfgMaterialReturnDisposition extends MasterModel
{
    protected $table = 'erp_mfg_material_return_dispositions';
    protected $casts = ['qty' => 'decimal:4', 'cost_amount' => 'decimal:4'];

    public function line() { return $this->belongsTo(MfgMaterialReturnLine::class, 'return_line_id'); }
}
