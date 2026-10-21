<?php

namespace App\Models\Erp;

/** 定制工单的技术资料状态与定制 BOM。 */
class MfgWorkOrderTech extends MasterModel
{
    protected $table = 'erp_mfg_work_order_techs';
    protected $casts = ['custom_bom' => 'array', 'bom_confirmed' => 'boolean', 'released_at' => 'datetime', 'drawings_updated_at' => 'datetime'];

    public function workOrder() { return $this->belongsTo(MfgWorkOrder::class, 'work_order_id'); }
}
