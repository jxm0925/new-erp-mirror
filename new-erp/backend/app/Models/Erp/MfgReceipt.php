<?php

namespace App\Models\Erp;

/** 成品入库记录（待试水）。 */
class MfgReceipt extends MasterModel
{
    protected $table = 'erp_mfg_receipts';
    protected $casts = ['qty' => 'decimal:4', 'unit_cost' => 'decimal:4', 'cost_amount' => 'decimal:4', 'serial_nos' => 'array'];
}
