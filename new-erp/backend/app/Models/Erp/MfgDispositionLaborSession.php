<?php

namespace App\Models\Erp;

/** 订单变更后退料、拆解、报废的实际作业时段，与原生产计时分开。 */
class MfgDispositionLaborSession extends MasterModel
{
    protected $table = 'erp_mfg_disposition_labor_sessions';
    protected $casts = ['started_at' => 'datetime', 'ended_at' => 'datetime', 'labor_minutes' => 'decimal:2'];
}
