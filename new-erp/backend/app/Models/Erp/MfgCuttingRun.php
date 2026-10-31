<?php

namespace App\Models\Erp;

/** 集中开料记录：一次只开一个 BOM 的料，按实际计时，结束时登记每种料切了多少件；工单「已备料」按件数领走工时。 */
class MfgCuttingRun extends MasterModel
{
    protected $table = 'erp_mfg_cutting_runs';

    protected $casts = [
        'business_version' => 'integer', 'set_qty' => 'integer', 'lines' => 'array', 'total_pieces' => 'integer', 'allocated_pieces' => 'integer',
        'labor_minutes' => 'decimal:2', 'allocated_minutes' => 'decimal:4', 'started_at' => 'datetime', 'finished_at' => 'datetime',
    ];
}
