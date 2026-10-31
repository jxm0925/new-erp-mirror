<?php

namespace App\Models\Erp;

/** 集中开料计时段。 */
class MfgCuttingRunSession extends MasterModel
{
    protected $table = 'erp_mfg_cutting_run_sessions';

    protected $casts = ['started_at' => 'datetime', 'ended_at' => 'datetime', 'labor_minutes' => 'decimal:2'];
}
