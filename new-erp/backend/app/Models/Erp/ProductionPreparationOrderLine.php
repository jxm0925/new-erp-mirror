<?php

namespace App\Models\Erp;

class ProductionPreparationOrderLine extends MasterModel
{
    protected $table = 'erp_production_preparation_order_lines';
    protected $appends = ['delivery_trigger_status_label'];

    protected $casts = [
        'required_base_qty' => 'decimal:8',
        'prepared_base_qty' => 'decimal:8',
        'delivered_base_qty' => 'decimal:8',
        'received_base_qty' => 'decimal:8',
        'business_version' => 'integer',
        'planned_start_at' => 'datetime', 'delivery_released_at' => 'datetime', 'delivery_lead_minutes' => 'integer',
    ];

    public function preparationOrder() { return $this->belongsTo(ProductionPreparationOrder::class, 'preparation_order_id'); }
    public function workOrder() { return $this->belongsTo(WorkOrder::class, 'work_order_id'); }
    public function materialRequirement() { return $this->belongsTo(WorkOrderMaterialRequirement::class, 'material_requirement_id'); }
    public function componentItem() { return $this->belongsTo(Item::class, 'component_item_id'); }

    /** The frontend displays this server-owned meaning instead of inventing a 60-minute default. */
    public function getDeliveryTriggerStatusLabelAttribute(): string
    {
        return match ($this->delivery_trigger_status) {
            'WAIT_CONFIGURATION' => '待配置配送触发条件',
            'WAIT_SCHEDULE' => '待配送触发时间',
            'READY_TO_RELEASE' => '可释放配送',
            'RELEASED' => '已释放配送',
            default => '配送触发状态异常',
        };
    }
}
