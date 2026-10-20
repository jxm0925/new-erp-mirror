<?php

namespace App\Models\Erp;

/** 关键部件追溯（预留）：第 2 阶段总装报工时登记。 */
class MfgUnitComponent extends MasterModel
{
    protected $table = 'erp_mfg_unit_components';
    protected $casts = ['installed_at' => 'datetime'];
}
