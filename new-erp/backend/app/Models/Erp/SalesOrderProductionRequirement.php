<?php

namespace App\Models\Erp;

class SalesOrderProductionRequirement extends MasterModel
{
    protected $table = 'erp_sales_order_production_requirements';

    protected $casts = [
        'configuration_snapshot' => 'array',
        'bom_snapshot' => 'array',
        'routing_snapshot' => 'array',
        'drawing_snapshot' => 'array',
        'technical_attachment_snapshot' => 'array',
        'inspection_snapshot' => 'array',
        'required_delivery_date' => 'date',
        'delay_date' => 'date',
        'is_urgent' => 'boolean',
        'is_delay' => 'boolean',
        'is_active' => 'boolean',
        'is_ready_for_work_order' => 'boolean',
        'confirmed_at' => 'datetime',
        'consumed_at' => 'datetime',
        'business_version' => 'integer',
        'allocated_qty' => 'decimal:8',
        'consumed_qty' => 'decimal:8',
        'closed_qty' => 'decimal:8',
        'remaining_qty' => 'decimal:8',
    ];

    public function order() { return $this->belongsTo(SalesOrder::class, 'sales_order_id'); }
    public function line() { return $this->belongsTo(SalesOrderLine::class, 'sales_order_line_id'); }
    public function product() { return $this->belongsTo(Product::class); }
    public function sku() { return $this->belongsTo(Sku::class); }
    public function item() { return $this->belongsTo(Item::class); }

    public function workOrders() { return $this->hasMany(WorkOrder::class, 'production_demand_id'); }
}
