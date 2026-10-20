<?php

namespace App\Models\Erp;

class MfgWorkOrderLog extends MasterModel
{
    protected $table = 'erp_mfg_work_order_logs';
    protected $casts = ['payload' => 'array'];
}
