<?php

namespace App\Models\Erp;

/** 集中开料池分给工单的定额用量与成本（工单下料点「已备料」时生成）。 */
class MfgCuttingAllocation extends MasterModel
{
    protected $table = 'erp_mfg_cutting_allocations';

    protected $casts = ['unit_qty' => 'integer', 'qty' => 'decimal:4', 'measure' => 'decimal:8', 'cost_amount' => 'decimal:4'];
}
