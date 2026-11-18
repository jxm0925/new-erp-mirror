<?php

namespace App\Models\Erp;

use App\Services\Erp\SalesOrderFulfillmentPolicy;

class SalesShipment extends MasterModel
{
    protected $table = 'erp_sales_shipments';

    protected $casts = [
        'receiver_snapshot' => 'array',
        'confirmed_at' => 'datetime',
        'outbound_posted_at' => 'datetime',
        'shipped_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'packing_configured_at' => 'datetime',
        'packing_version' => 'integer',
        'business_version' => 'integer',
        'staged_at' => 'datetime',
    ];

    public function scopeAppendable($query)
    {
        return $query->where(fn ($q) => $q->whereIn('shipment_status', ['draft', 'pending_outbound', 'prepared', 'partially_shipped'])
            ->orWhere(fn ($sent) => $sent->where('shipment_status', 'shipped')->whereNull('cancel_reason')));
    }

    /** 发货进度按当前单对应的整个订单计算，原执行状态仍用于库存与取消命令。 */
    public function scopeWithProgress($query, ?string $filter = null)
    {
        $current = self::query()->from('erp_sales_shipments as candidate')
            ->whereColumn('candidate.sales_order_id', 'erp_sales_shipments.sales_order_id')->appendable()
            ->orderByDesc('candidate.id')->limit(1)->select('candidate.id');
        $currentOrder = SalesOrder::query()->selectRaw('1')->whereColumn('id', 'erp_sales_shipments.sales_order_id')
            ->whereIn('order_status', ['confirmed', 'closed'])->whereNull('short_closed_at')
            ->where(fn ($order) => $order->where('order_source', '!=', 'legacy')->orWhereNull('order_source')
                ->orWhereNull('legacy_order_id')->orWhere('legacy_order_id', '<=', 0));
        $sent = SalesOrderFulfillmentPolicy::shippedLines()->current()->selectRaw('SUM(sales_qty)')
            ->whereColumn('sales_order_line_id', 'erp_sales_order_lines.id');
        $allocated = SalesShipmentLine::query()->current()->selectRaw('1')
            ->whereColumn('sales_order_line_id', 'erp_sales_order_lines.id')
            ->whereHas('shipment', fn ($s) => $s->where('shipment_status', '!=', 'cancelled'));
        // 分配了库存的旧行可能把待发量缓存为已发；只有无执行行时才回退到历史销售行的实发数量。
        $sentQty = 'COALESCE(('.$sent->toSql().'), CASE WHEN EXISTS ('.$allocated->toSql().') THEN 0 ELSE COALESCE(shipped_qty, 0) END)';
        $remaining = SalesOrderLine::query()->selectRaw('1')->whereColumn('sales_order_id', 'erp_sales_shipments.sales_order_id')
            ->whereNotIn('line_status', ['removed', 'cancelled'])
            ->where(fn ($q) => $q->whereNull('line_type')->orWhereNotIn('line_type', SalesOrderFulfillmentPolicy::NON_PHYSICAL_LINE_TYPES))
            ->whereRaw('order_qty - COALESCE(cancelled_qty, 0) > '.$sentQty, [...$sent->getBindings(), ...$allocated->getBindings()]);
        $progress = "CASE WHEN shipment_status IN ('shipped', 'partially_shipped') AND cancel_reason IS NULL
            AND id = (".$current->toSql().') AND EXISTS ('.$currentOrder->toSql().')
            THEN CASE WHEN EXISTS ('.$remaining->toSql().") THEN 'partially_shipped' ELSE 'shipped' END
            ELSE shipment_status END";
        $bindings = [...$current->getBindings(), ...$currentOrder->getBindings(), ...$remaining->getBindings()];
        if ($query->getQuery()->columns === null) $query->select('erp_sales_shipments.*');
        $query->selectRaw($progress.' AS shipment_progress_status', $bindings);
        if ($filter === null) return $query;
        if (in_array($filter, ['shipped', 'partially_shipped'], true)) {
            return $query->whereRaw($progress.' = ?', [...$bindings, $filter]);
        }
        return $query->where('shipment_status', $filter);
    }

    public function order() { return $this->belongsTo(SalesOrder::class, 'sales_order_id'); }
    public function lines() { return $this->hasMany(SalesShipmentLine::class, 'shipment_id'); }
    public function packages() { return $this->hasMany(SalesShipmentPackage::class, 'shipment_id'); }
    public function logs() { return $this->hasMany(SalesShipmentLog::class, 'shipment_id'); }
    public function packingContents() { return $this->hasMany(ShipmentPackingContent::class, 'shipment_id'); }
    public function packingOperations() { return $this->hasMany(ShipmentPackingOperation::class, 'shipment_id'); }
    public function dispatches() { return $this->hasMany(ShipmentDispatch::class, 'shipment_id'); }
}
