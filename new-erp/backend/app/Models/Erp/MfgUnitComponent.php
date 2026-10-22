<?php

namespace App\Models\Erp;

/** 装配记录（装上）：每台设备装的带编号部件，只追加不修改；有拆下记录的为历史记录。 */
class MfgUnitComponent extends MasterModel
{
    protected $table = 'erp_mfg_unit_components';
    protected $casts = ['installed_at' => 'datetime'];

    public function removal() { return $this->hasOne(MfgUnitComponentRemoval::class, 'component_id'); }

    /** 当前装在台上的（没有拆下记录）。 */
    public function scopeInstalled($query)
    {
        return $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from('erp_mfg_unit_component_removals as r')
            ->whereColumn('r.component_id', 'erp_mfg_unit_components.id'));
    }
}
