<?php

namespace App\Models\Erp;

/** 报工计时段：开始 → 暂停/完成。 */
class MfgLaborSession extends MasterModel
{
    protected $table = 'erp_mfg_labor_sessions';
    protected $casts = ['is_rework' => 'boolean', 'started_at' => 'datetime', 'ended_at' => 'datetime', 'labor_minutes' => 'decimal:2'];
}
