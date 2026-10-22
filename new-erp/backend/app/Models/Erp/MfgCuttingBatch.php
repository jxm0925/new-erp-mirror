<?php

namespace App\Models\Erp;

/** 集中开料批次：原料来源、板件分配、余料确认与净材料成本的冻结事实。 */
class MfgCuttingBatch extends MasterModel
{
    protected $table = 'erp_mfg_cutting_batches';

    protected $casts = [
        'business_version' => 'integer', 'unit_qty' => 'integer', 'completed_unit_qty' => 'integer',
        'input_lines' => 'array', 'planned_outputs' => 'array', 'actual_outputs' => 'array', 'remnants' => 'array',
        'bom_snapshot' => 'array', 'input_cost_amount' => 'decimal:4', 'remnant_cost_amount' => 'decimal:4', 'net_cost_amount' => 'decimal:4',
        'issued_at' => 'datetime', 'settled_at' => 'datetime',
    ];
}
