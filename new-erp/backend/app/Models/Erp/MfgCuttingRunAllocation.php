<?php

namespace App\Models\Erp;

/** 工单从集中开料记录领走的件数和对应工时，以及按件数带走的料和成本。 */
class MfgCuttingRunAllocation extends MasterModel
{
    protected $table = 'erp_mfg_cutting_run_allocations';

    protected $casts = ['unit_ids' => 'array', 'unit_qty' => 'integer', 'pieces' => 'integer', 'labor_minutes' => 'decimal:4', 'material_qty' => 'decimal:4', 'cost_amount' => 'decimal:4'];
}
