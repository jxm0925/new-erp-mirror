<?php

namespace App\Models\Erp;

/** 新生产工单（第 1 阶段起）。表名用 erp_mfg_ 前缀，与已删除的旧工单表区分。 */
class MfgWorkOrder extends MasterModel
{
    protected $table = 'erp_mfg_work_orders';

    protected $casts = [
        'planned_qty' => 'decimal:4', 'completed_qty' => 'decimal:4', 'stored_qty' => 'decimal:4',
        'bom_snapshot' => 'array', 'routing_snapshot' => 'array', 'configuration_snapshot' => 'array',
        'is_urgent' => 'boolean', 'priority' => 'integer', 'business_version' => 'integer',
        'planned_start_date' => 'date:Y-m-d', 'planned_finish_date' => 'date:Y-m-d', 'required_date' => 'date:Y-m-d',
        'released_at' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime',
        'closed_at' => 'datetime', 'paused_at' => 'datetime', 'cancelled_at' => 'datetime',
    ];

    public function getBomVersionAttribute($value): ?string
    {
        // 旧整数字段曾把 V1.0 存成 0；显示只能恢复本工单快照，不能借当前主档版本。
        $snapshotVersion = data_get($this->bom_snapshot, 'version');
        return $snapshotVersion !== null ? (string) $snapshotVersion : ($value === null ? null : (string) $value);
    }

    public function operations() { return $this->hasMany(MfgWorkOrderOperation::class, 'work_order_id')->orderBy('sequence')->orderBy('id'); }
    public function units() { return $this->hasMany(MfgWorkOrderUnit::class, 'work_order_id')->orderBy('unit_no'); }
    public function materials() { return $this->hasMany(MfgWorkOrderMaterial::class, 'work_order_id')->orderBy('id'); }
    public function logs() { return $this->hasMany(MfgWorkOrderLog::class, 'work_order_id')->orderByDesc('id'); }
    public function salesOrder() { return $this->belongsTo(SalesOrder::class, 'sales_order_id'); }
    public function item() { return $this->belongsTo(Item::class, 'item_id'); }
    public function warehouse() { return $this->belongsTo(Warehouse::class, 'warehouse_id'); }
}
