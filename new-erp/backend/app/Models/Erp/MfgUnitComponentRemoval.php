<?php

namespace App\Models\Erp;

/** 装配谱系的拆下记录：互换、换号、退料、报废时登记，原装配记录保持不变。 */
class MfgUnitComponentRemoval extends MasterModel
{
    protected $table = 'erp_mfg_unit_component_removals';
    protected $casts = ['removed_at' => 'datetime'];
}
