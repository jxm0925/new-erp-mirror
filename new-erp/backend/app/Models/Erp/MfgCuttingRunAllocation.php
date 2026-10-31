<?php

namespace App\Models\Erp;

/** 工单从集中开料记录领走的件数和对应工时。 */
class MfgCuttingRunAllocation extends MasterModel
{
    protected $table = 'erp_mfg_cutting_run_allocations';

    protected $casts = ['unit_qty' => 'integer', 'pieces' => 'integer', 'labor_minutes' => 'decimal:4'];
}
