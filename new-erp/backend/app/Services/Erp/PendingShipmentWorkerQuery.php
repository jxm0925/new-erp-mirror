<?php

namespace App\Services\Erp;

use App\Models\Erp\MfgWorkOrder;
use App\Models\Erp\SalesOrderLine;
use App\Models\Erp\SalesShipment;
use App\Models\Erp\SalesShipmentLine;
use App\Models\Erp\ShipmentPackingContent;
use App\Models\Erp\ShipmentPackingOperation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;

/** 待发清单只提供本人已配置发货工序涉及的产品，不产生任何作业或库存事实。 */
class PendingShipmentWorkerQuery
{
    public function __construct(private readonly PendingShipmentLines $pending, private readonly ProductionMasterDataService $routes) {}

    public function page(int $employeeId, string $keyword, int $page): Paginator
    {
        $workers = $this->assignedOperations($employeeId);
        $items = [];
        if ($workers) {
            $skip = ($page - 1) * 10;
            $defaultRoutes = [];
            $this->shipments()->when($keyword !== '', fn ($query) => $query->where(fn ($match) => $match->where('shipment_no', 'like', '%'.$keyword.'%')
                ->orWhereHas('order', fn ($order) => $order->where('sales_order_no', 'like', '%'.$keyword.'%')
                    ->orWhere('customer_name_snapshot', 'like', '%'.$keyword.'%')->orWhere('customer_name', 'like', '%'.$keyword.'%'))))
                ->chunkByIdDesc(30, function ($shipments) use ($workers, &$defaultRoutes, &$items, &$skip) {
                    $visible = $this->visibleLines($shipments, $workers, $defaultRoutes);
                    foreach ($shipments as $shipment) {
                        if (empty($visible[$shipment->sales_order_id])) continue;
                        if ($skip > 0) { $skip--; continue; }
                        $items[] = $this->header($shipment);
                        if (count($items) === 11) return false;
                    }
                });
        }
        return new Paginator($items, 10, $page, ['path' => Paginator::resolveCurrentPath()]);
    }

    public function detail(int $id, int $employeeId): array
    {
        $workers = $this->assignedOperations($employeeId);
        abort_unless($workers, 404);
        $shipment = $this->shipments()->findOrFail($id);
        $defaultRoutes = [];
        $rows = $this->visibleLines(new Collection([$shipment]), $workers, $defaultRoutes)[$shipment->sales_order_id] ?? [];
        abort_unless($rows, 404);
        return $this->header($shipment) + ['pending_lines' => $rows];
    }

    private function assignedOperations(int $employeeId): array
    {
        return DB::table('erp_production_operation_workers')->where('employee_legacy_id', $employeeId)->pluck('operation_id')->flip()->all();
    }

    private function shipments(): Builder
    {
        $latest = SalesShipment::query()->appendable()->selectRaw('MAX(id)')->groupBy('sales_order_id');
        return SalesShipment::query()->select('id', 'shipment_no', 'sales_order_id', 'shipment_status')->withProgress()->whereIn('id', $latest)
            ->with('order:id,sales_order_no,customer_name_snapshot,customer_name,required_delivery_date,order_status,short_closed_at,order_source,legacy_order_id')
            ->whereHas('order', fn ($order) => $order->where('order_status', 'confirmed')->whereNull('short_closed_at')
                ->where(fn ($current) => $current->where('order_source', '!=', 'legacy')->orWhereNull('order_source')
                    ->orWhereNull('legacy_order_id')->orWhere('legacy_order_id', '<=', 0))
                ->whereHas('lines', fn ($lines) => $lines->where('line_status', '!=', 'cancelled')
                    ->whereNotIn('line_type', ['service', 'no_delivery', 'fee', 'auxiliary', 'deduction'])->whereColumn('order_qty', '>', 'cancelled_qty')));
    }

    private function visibleLines(Collection $shipments, array $workers, array &$defaultRoutes): array
    {
        $orders = new Collection($shipments->pluck('order')->all());
        $orders->load('lines:id,sales_order_id,line_no,line_type,line_status,order_qty,shipped_qty,cancelled_qty,fulfillment_factor_snapshot,product_name,sku_name,item_name,unit_name_snapshot,item_id,product_id,sku_id,routing_snapshot');
        $pending = $this->pending->forOrders($orders);
        if (!$pending) return [];
        $orderLines = $orders->flatMap(fn ($order) => $order->lines)->keyBy('id');
        $lines = SalesShipmentLine::query()->current()->whereIn('shipment_id', $shipments->modelKeys())
            ->whereNotIn('id', SalesOrderFulfillmentPolicy::shippedLines()->select('id'))
            ->get()->groupBy('sales_order_line_id');
        // 一个包可能含多条路线，工序只适用于 packing_content_ids 点名的发货明细。
        $contents = ShipmentPackingContent::query()->whereIn('shipment_id', $shipments->modelKeys())
            ->whereHas('package', fn ($q) => $q->whereNotIn('package_status', ['shipped', 'cancelled']))->pluck('shipment_line_id', 'id');
        $actual = [];
        foreach (ShipmentPackingOperation::query()->whereIn('shipment_id', $shipments->modelKeys())->where('status', '!=', 'CANCELLED')
            ->get(['packing_content_ids', 'operation_id']) as $op) {
            foreach ($op->packing_content_ids ?? [] as $contentId) {
                if ($lineId = $contents->get($contentId)) $actual[$lineId] = ($actual[$lineId] ?? false) || isset($workers[$op->operation_id]);
            }
        }
        $workOrders = MfgWorkOrder::query()->whereIn('sales_order_id', $orders->modelKeys())->where('source_type', 'sales_order')
            ->where('status', '!=', 'cancelled')->get(['sales_order_id', 'sales_order_line_id', 'item_id', 'routing_snapshot'])->groupBy('sales_order_line_id');
        $visible = [];
        foreach ($pending as $orderId => $rows) {
            foreach ($rows as $row) {
                $line = $orderLines[$row['sales_order_line_id']];
                $preparedMatches = $lines->get($line->id)?->contains(fn ($prepared) => $actual[$prepared->id] ?? $this->matchesPreparedLine($prepared, $workers));
                // 同一订单行可以部分备货。只有未加入发货单的余量才继续按订单路线判断。
                $hasUnallocated = bccomp($row['remaining_qty'], $row['in_progress_qty'], 8) > 0;
                if ($preparedMatches || ($hasUnallocated && $this->matchesOrderLine($line, $workOrders->get($line->id, collect()), $workers, $defaultRoutes))) {
                    $visible[$orderId][] = $row;
                }
            }
        }
        return $visible;
    }

    private function matchesPreparedLine(SalesShipmentLine $line, array $workers): bool
    {
        $route = $line->packing_routing_snapshot;
        if ($line->packing_source_snapshot === null && $route === null) {
            $context = app(ShipmentSourceResolver::class)->contextForShipmentLine($line);
            $route = $context['production_ready'] ? $context['routing_snapshot'] : null;
        }
        return $this->matchesRoute($route, $workers);
    }

    private function matchesOrderLine(SalesOrderLine $line, \Illuminate\Support\Collection $workOrders, array $workers, array &$defaultRoutes): bool
    {
        $work = $workOrders->where('sales_order_id', $line->sales_order_id)->where('item_id', $line->item_id);
        if ($work->isNotEmpty()) return $work->contains(fn ($wo) => $this->matchesRoute($wo->routing_snapshot, $workers));
        if ($line->routing_snapshot !== null) return $this->matchesRoute($line->routing_snapshot, $workers);
        if (!$line->item_id) return false;
        $key = $line->item_id.':'.$line->product_id.':'.$line->sku_id;
        if (!array_key_exists($key, $defaultRoutes)) {
            $matches = $this->routes->defaultRoutingMatches((int) $line->item_id, $line->product_id, $line->sku_id, false);
            $defaultRoutes[$key] = $matches->count() === 1 && $matches->first()->operations->contains(
                fn ($op) => $op->execution_context === 'shipment' && isset($workers[$op->operation_id]));
        }
        return $defaultRoutes[$key];
    }

    private function matchesRoute(?array $route, array $workers): bool
    {
        return collect($route['operations'] ?? [])->contains(fn ($node) => ($node['execution_context'] ?? '') === 'shipment'
            && isset($workers[$node['operation_id'] ?? 0]));
    }

    private function header(SalesShipment $shipment): array
    {
        return ['id' => (int) $shipment->id, 'shipment_id' => (int) $shipment->id, 'shipment_no' => $shipment->shipment_no,
            'shipment_status' => $shipment->shipment_status, 'sales_order_id' => (int) $shipment->sales_order_id,
            'shipment_progress_status' => $shipment->shipment_progress_status,
            'sales_order_no' => $shipment->order->sales_order_no,
            'customer_name' => $shipment->order->customer_name_snapshot ?: $shipment->order->customer_name,
            'expected_delivery_date' => $shipment->order->required_delivery_date?->toDateString()];
    }
}
