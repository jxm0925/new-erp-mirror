<?php

namespace App\Models\Erp;

/** 半成品工单与总装工单的关联（总装缺半成品时开的单）。 */
class MfgWorkOrderLink extends MasterModel
{
    protected $table = 'erp_mfg_work_order_links';
    protected $casts = ['qty' => 'decimal:4'];
}
