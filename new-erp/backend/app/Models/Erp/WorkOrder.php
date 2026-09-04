<?php

namespace App\Models\Erp;

class WorkOrder extends MasterModel
{
    protected $table = 'erp_work_orders';

    protected $casts = [
        'target_qty' => 'decimal:8',
        'target_base_qty' => 'decimal:8',
        'planned_date' => 'date:Y-m-d',
        'business_version' => 'integer',
        'bom_snapshot' => 'array',
        'routing_snapshot' => 'array',
        'routing_version_snapshot' => 'integer',
        'release_gate_checked_at' => 'datetime',
        'released_at' => 'datetime',
        'submitted_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function demand()
    {
        return $this->belongsTo(ProductionDemand::class, 'production_demand_id');
    }

    public function outputItem() { return $this->belongsTo(Item::class, 'output_item_id'); }
    public function routing() { return $this->belongsTo(ProductionRouting::class, 'production_routing_id'); }
    public function targetOperation() { return $this->belongsTo(ProductionOperation::class, 'target_operation_id'); }
    public function targetRoutingOperation() { return $this->belongsTo(ProductionRoutingOperation::class, 'target_routing_operation_id'); }

    public function statusLogs()
    {
        return $this->hasMany(WorkOrderStatusLog::class, 'work_order_id');
    }

    public function releaseGateChecks()
    {
        return $this->hasMany(WorkOrderReleaseGateCheck::class, 'work_order_id');
    }

    public function materialRequirements()
    {
        return $this->hasMany(WorkOrderMaterialRequirement::class, 'work_order_id')->orderBy('line_no');
    }

    public function materialPickingTasks() { return $this->hasMany(MaterialPickingTask::class, 'work_order_id'); }
    public function materialDeliveries() { return $this->hasMany(MaterialDelivery::class, 'work_order_id'); }
    public function materialReceipts() { return $this->hasMany(MaterialReceipt::class, 'work_order_id'); }

    public function commands()
    {
        return $this->hasMany(WorkOrderCommandLedger::class, 'aggregate_id')
            ->where('aggregate_type', 'work_order');
    }
}
