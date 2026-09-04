<?php

namespace App\Models\Erp;

class WorkOrderReleaseGateCheck extends MasterModel
{
    protected $table = 'erp_work_order_release_gate_checks';

    protected $casts = [
        'evidence' => 'array',
        'work_order_version' => 'integer',
        'evaluated_at' => 'datetime',
    ];

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id');
    }
}