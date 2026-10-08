<?php

namespace App\Models\Erp;

class WorkOrderPlannedOutputVersion extends MasterModel
{
    protected $table = 'erp_work_order_planned_output_versions';

    protected $casts = [
        'before_snapshot' => 'array', 'after_snapshot' => 'array', 'occurred_at' => 'datetime',
        'before_version' => 'integer', 'after_version' => 'integer',
    ];

    public function workOrder() { return $this->belongsTo(WorkOrder::class); }
}
