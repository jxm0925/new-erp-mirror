<?php

namespace App\Models\Erp;

class ProductionMasterOrder extends MasterModel
{
    protected $table = 'erp_production_master_orders';

    protected $casts = [
        'customer_snapshot' => 'array',
        'required_delivery_date_snapshot' => 'date:Y-m-d',
        'production_progress' => 'decimal:4',
        'completed_unit_qty' => 'decimal:8',
        'total_unit_qty' => 'decimal:8',
        'in_progress_unit_qty' => 'decimal:8',
        'exception_unit_qty' => 'decimal:8',
        'business_version' => 'integer',
    ];

    public function salesOrder() { return $this->belongsTo(SalesOrder::class, 'sales_order_id'); }
    public function workOrders() { return $this->hasMany(WorkOrder::class, 'production_master_order_id'); }
}
