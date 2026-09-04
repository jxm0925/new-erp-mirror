<?php

namespace App\Models\Erp;

class WorkOrderStatusLog extends MasterModel
{
    public $timestamps = false;

    protected $table = 'erp_work_order_status_logs';

    protected $casts = [
        'before_version' => 'integer',
        'after_version' => 'integer',
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id');
    }
}
