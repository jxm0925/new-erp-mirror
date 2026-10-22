<?php

namespace App\Models\Erp;

/** 装配记录：每台设备装的带编号部件，工序收料时按台登记（UnitComponentService）。 */
class MfgUnitComponent extends MasterModel
{
    protected $table = 'erp_mfg_unit_components';
    protected $casts = ['installed_at' => 'datetime'];
}
