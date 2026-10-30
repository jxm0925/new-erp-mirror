<?php

namespace App\Models\Erp;

/** 开料批次：随单（work_order）或集中开料（shared，不属于工单，净耗进开料池按定额分给工单）。 */
class MfgCuttingBatch extends MasterModel
{
    protected $table = 'erp_mfg_cutting_batches';

    protected $casts = [
        'business_version' => 'integer', 'unit_qty' => 'integer', 'completed_unit_qty' => 'integer',
        'input_lines' => 'array', 'planned_outputs' => 'array', 'actual_outputs' => 'array', 'remnants' => 'array',
        'bom_snapshot' => 'array', 'input_cost_amount' => 'decimal:4', 'remnant_cost_amount' => 'decimal:4', 'net_cost_amount' => 'decimal:4',
        'pool_measure' => 'decimal:8', 'allocated_measure' => 'decimal:8', 'allocated_cost_amount' => 'decimal:4',
        'issued_at' => 'datetime', 'settled_at' => 'datetime',
    ];
}
