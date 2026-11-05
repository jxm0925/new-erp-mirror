<?php

namespace App\Models\Erp;

class CancellationProductionDisposition extends MasterModel
{
    protected $table = 'erp_cancellation_production_dispositions';
    protected $hidden = ['history', 'material_allocation_snapshot', 'reserved_material_cost', 'loss_material_cost'];
    protected $casts = ['history' => 'array', 'effective_at' => 'datetime', 'due_at' => 'datetime', 'deadline_at' => 'datetime', 'resolved_at' => 'datetime', 'business_version' => 'integer',
        'sales_order_id' => 'integer', 'sales_order_line_id' => 'integer', 'sales_order_change_id' => 'integer', 'work_order_id' => 'integer',
        'work_order_unit_id' => 'integer', 'parent_unit_id' => 'integer', 'material_allocation_snapshot' => 'array',
        'reserved_material_cost' => 'decimal:4', 'loss_material_cost' => 'decimal:4'];
}
