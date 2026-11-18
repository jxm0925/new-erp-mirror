<?php

namespace App\Services\Erp;

use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesShipment;
use App\Models\Erp\SalesShipmentLine;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class PendingShipmentLines
{
    public function forShipment(SalesShipment $shipment): array
    {
        $currentId = SalesShipment::query()->where('sales_order_id', $shipment->sales_order_id)
            ->appendable()->orderByDesc('id')->value('id');
        if ((int) $currentId !== (int) $shipment->id) return [];

        return $this->forOrder($shipment->order);
    }

    /** 待发是订单需求，已转成发货单明细的数量和仍可分配的订单占用分别统计。数量以销售单位显示。 */
    public function forOrder(SalesOrder $order): array
    {
        return $this->forOrders(new Collection([$order]))[$order->id] ?? [];
    }

    public function forOrders(Collection $orders): array
    {
        $orders = $orders->filter(fn ($order) => $order->order_status === 'confirmed' && !$order->short_closed_at && !$order->isLegacyHistory());
        if ($orders->isEmpty()) return [];
        $orders->loadMissing('lines');
        $lines = $orders->flatMap(fn ($order) => $order->lines->sortBy('line_no'));
        $lineIds = $lines->pluck('id');
        $shipped = SalesOrderFulfillmentPolicy::shippedLines()->current()->whereIn('sales_order_line_id', $lineIds)
            ->selectRaw('sales_order_line_id, SUM(sales_qty) as qty')->groupBy('sales_order_line_id')->pluck('qty', 'sales_order_line_id');
        $allocated = SalesShipmentLine::query()->current()->whereIn('sales_order_line_id', $lineIds)
            ->whereHas('shipment', fn ($q) => $q->where('shipment_status', '!=', 'cancelled'))
            ->selectRaw('sales_order_line_id, SUM(sales_qty) as qty')->groupBy('sales_order_line_id')->pluck('qty', 'sales_order_line_id');
        $reserved = DB::table('erp_inventory_reservations as r')
            ->whereIn('r.source_order_id', $orders->modelKeys())->where('r.source_type', 'sales_order')
            ->where('r.reservation_status', 'active')->where('r.reserved_qty', '>', 0)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('erp_sales_order_fulfillments as f')
                ->whereColumn('f.sales_order_id', 'r.source_order_id')->whereColumn('f.sales_order_line_id', 'r.source_order_line_id')
                ->whereIn('f.fulfillment_type', ['inventory', 'production', 'purchase'])->where('f.demand_status', 'confirmed')
                ->where(fn ($match) => $match->whereColumn('f.id', 'r.sales_order_fulfillment_id')
                    ->orWhere(fn ($old) => $old->whereNull('r.sales_order_fulfillment_id')->whereColumn('f.inventory_balance_id', 'r.inventory_balance_id'))))
            ->selectRaw('r.source_order_line_id, SUM(r.reserved_qty) as qty')->groupBy('r.source_order_line_id')->pluck('qty', 'source_order_line_id');

        $rows = [];
        foreach ($lines as $line) {
            if ($line->line_status === 'cancelled' || ! SalesOrderFulfillmentPolicy::isPhysical((string) $line->line_type)) continue;
            $sent = (string) ($shipped[$line->id] ?? '0');
            $totalAllocated = (string) ($allocated[$line->id] ?? '0');
            // 有历史实发缓存、没有发货明细的旧行也不能重新备货。
            if (! isset($allocated[$line->id])) $sent = (string) max($sent, $line->shipped_qty ?? '0');
            $remaining = bcsub(bcsub((string) $line->order_qty, (string) ($line->cancelled_qty ?? '0'), 8), $sent, 8);
            if (bccomp($remaining, '0', 8) <= 0) continue;
            $executing = (string) min($remaining, max('0', bcsub($totalAllocated, $sent, 8)));
            $unallocated = bcsub($remaining, $executing, 8);
            $factor = (string) ($line->fulfillment_factor_snapshot ?? '0');
            $available = bccomp($factor, '0', 8) > 0 ? bcdiv((string) ($reserved[$line->id] ?? '0'), $factor, 8) : '0';
            $available = (string) min($unallocated, $available);
            $rows[$line->sales_order_id][] = [
                'sales_order_line_id' => (int) $line->id, 'line_no' => $line->line_no,
                'product_name' => $line->product_name, 'sku_name' => $line->sku_name, 'item_name' => $line->item_name,
                'unit_name' => $line->unit_name_snapshot, 'remaining_qty' => $remaining, 'in_progress_qty' => $executing,
                'available_qty' => $available, 'waiting_qty' => bcsub($unallocated, $available, 8),
            ];
        }
        return $rows;
    }
}
