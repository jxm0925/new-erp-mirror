<?php

namespace App\Models\Erp;

class MfgWorkOrderUnit extends MasterModel
{
    protected $table = 'erp_mfg_work_order_units';
    protected $casts = ['rework_count' => 'integer', 'unit_no' => 'integer', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'stored_at' => 'datetime'];

    public function workOrder() { return $this->belongsTo(MfgWorkOrder::class, 'work_order_id'); }
    public function components() { return $this->hasMany(MfgUnitComponent::class, 'unit_id')->orderBy('id'); }
}
