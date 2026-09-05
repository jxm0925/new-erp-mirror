<?php

namespace App\Models\Erp;

class ProductionQuantityOperation extends MasterModel
{
    protected $table = 'erp_production_quantity_operations';
    protected $casts = [
        'planned_base_qty' => 'decimal:8',
        'completed_base_qty' => 'decimal:8',
        'scrapped_base_qty' => 'decimal:8',
        'remaining_base_qty' => 'decimal:8',
        'sequence_no_snapshot' => 'integer',
        'kitting_required' => 'boolean',
        'allow_continue_without_warehouse_snapshot' => 'boolean',
        'claimed_at' => 'datetime',
        'kitting_confirmed_at' => 'datetime',
        'started_at' => 'datetime',
        'paused_at' => 'datetime',
        'completed_at' => 'datetime',
        'business_version' => 'integer',
        'setup_standard_minutes_snapshot' => 'decimal:2',
        'unit_standard_minutes_snapshot' => 'decimal:2',
        'standard_quantity_snapshot' => 'decimal:8',
    ];

    public function workOrder() { return $this->belongsTo(WorkOrder::class, 'work_order_id'); }
}
