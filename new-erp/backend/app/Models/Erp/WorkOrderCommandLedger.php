<?php

namespace App\Models\Erp;

class WorkOrderCommandLedger extends MasterModel
{
    protected $table = 'erp_work_order_command_ledgers';

    protected $casts = [
        'response_snapshot' => 'array',
        'processing_started_at' => 'datetime',
        'processing_finished_at' => 'datetime',
    ];

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class, 'aggregate_id');
    }
}
