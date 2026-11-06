<?php

namespace App\Services\Erp\Sales;

use App\Models\Erp\{InventorySerial, Item, MfgCuttingRun, MfgCuttingRunAllocation, MfgMaterialIssue, MfgReceipt, MfgTaskParticipant, MfgUnitComponent, MfgUnitReport, MfgWorkOrder, MfgWorkOrderLog, MfgWorkOrderOperation, MfgWorkOrderUnit, SalesOrder, SalesOrderLine, SalesShipmentLine, ShipmentDispatch, ShipmentPackingContent, ShipmentPackingOperation, ShipmentRework, ShipmentSerialResult};
use App\Services\Erp\Manufacturing\TaskCompletionService;
use App\Services\Erp\Manufacturing\CancellationProductionService;
use App\Services\Erp\Manufacturing\OrderChangeLaborService;
use App\Models\Erp\CancellationProductionDisposition;
use App\Services\Erp\SalesOrderInventoryLockService;
use App\Services\Erp\SalesOrderFulfillmentPolicy;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** 只读订单生产与发货进度；不调用会补台件、结束计时或刷新状态的执行服务。 */
final class SalesOrderProgressQueryService
{
    public function tree(SalesOrder $order, bool $workOrderLinks): array
    {
        $lines = SalesOrderLine::query()->where('sales_order_id', $order->id)->where('line_status', '!=', 'removed')->orderBy('line_no')->orderBy('id')->get();
        $graph = $this->graph($order);
        $orders = $graph['orders'];
        $disposed = CancellationProductionDisposition::where('sales_order_id', $order->id)->whereNotNull('work_order_unit_id')->pluck('work_order_unit_id')->all();
        $allCounts = DB::table('erp_mfg_work_order_units')->whereIn('work_order_id', $orders->keys())->selectRaw('work_order_id, COUNT(*) AS n')->groupBy('work_order_id')->pluck('n', 'work_order_id');
        $currentCounts = DB::table('erp_mfg_work_order_units')->whereIn('work_order_id', $orders->keys())->whereNotIn('id', $disposed)->whereNotIn('status', ['cancelled', 'scrapped'])
            ->selectRaw("work_order_id, COUNT(*) AS qty, SUM(status = 'stored') AS stored_qty, SUM(status IN ('completed','stored')) AS done_qty, SUM(started_at IS NOT NULL) AS started_qty")
            ->groupBy('work_order_id')->get()->keyBy('work_order_id');
        $historyCounts = CancellationProductionDisposition::where('sales_order_id', $order->id)->whereNotNull('work_order_unit_id')
            ->selectRaw('work_order_id, COUNT(*) AS qty')->groupBy('work_order_id')->pluck('qty', 'work_order_id');
        $doneByOperation = MfgUnitReport::whereIn('work_order_id', $orders->keys())->where('result', 'passed')->whereNotIn('unit_id', $disposed)
            ->whereIn('unit_id', MfgWorkOrderUnit::whereNotIn('status', ['cancelled', 'scrapped'])->select('id'))
            ->selectRaw('work_order_operation_id, COUNT(DISTINCT unit_id) AS qty')->groupBy('work_order_operation_id')->pluck('qty', 'work_order_operation_id');
        $operations = MfgWorkOrderOperation::query()->whereIn('work_order_id', $orders->keys())->where('execution_context', 'production')->orderBy('sequence')->orderBy('id')->get()->groupBy('work_order_id');
        $installed = MfgUnitComponent::query()->installed()->whereIn('work_order_id', $orders->keys())
            ->whereNotIn('unit_id', $disposed)
            ->selectRaw('work_order_id, component_item_id, COUNT(DISTINCT unit_id) AS installed_qty')->groupBy('work_order_id', 'component_item_id')->get()
            ->keyBy(fn ($row) => $row->work_order_id.':'.$row->component_item_id);
        $reserved = DB::table('erp_inventory_reservations')->where('source_type', 'sales_order')->where('source_order_id', $order->id)
            ->whereExists(fn (Builder $current) => $current->selectRaw('1')->from('erp_sales_order_lines as current_line')->whereColumn('current_line.id', 'erp_inventory_reservations.source_order_line_id')->whereColumn('current_line.item_id', 'erp_inventory_reservations.item_id'))
            ->whereIn('reservation_status', ['active', 'converted_to_shipment'])->selectRaw('source_order_line_id, SUM(reserved_qty) AS qty')->groupBy('source_order_line_id')->pluck('qty', 'source_order_line_id');
        $shipped = SalesShipmentLine::query()->whereIn('shipment_id', DB::table('erp_sales_shipments')->where('sales_order_id', $order->id)->where('shipment_status', '!=', 'cancelled')->select('id'))
            ->whereExists(fn (Builder $current) => $current->selectRaw('1')->from('erp_sales_order_lines as current_line')->whereColumn('current_line.id', 'erp_sales_shipment_lines.sales_order_line_id')->whereColumn('current_line.item_id', 'erp_sales_shipment_lines.item_id'))
            ->where('line_status', 'shipped')->selectRaw('sales_order_line_id, SUM(sales_qty) AS qty')->groupBy('sales_order_line_id')->pluck('qty', 'sales_order_line_id');
        $shippingStates = $this->treeShippingStates($order, $lines);
        $children = $graph['links']->groupBy('parent_work_order_id');
        $build = function (MfgWorkOrder $wo, int $lineId, array $path = [], bool $historical = false) use (&$build, $graph, $orders, $children, $operations, $installed, $workOrderLinks, $allCounts, $currentCounts, $doneByOperation): array {
            $path[] = (int) $wo->id;
            $links = $children->get($wo->id, collect());
            $nested = [];
            foreach ($links as $link) {
                $child = $orders->get($link->child_work_order_id);
                if ($child && $child->status !== 'cancelled' && (!$allCounts->get($child->id) || $currentCounts->get($child->id)?->qty)
                    && ! in_array((int) $child->id, $path, true)) $nested[] = $build($child, $lineId, $path, $historical);
            }
            $qty = $allCounts->get($wo->id) ? (float) ($currentCounts->get($wo->id)?->qty ?? 0) : (float) $wo->planned_qty;
            $done = (float) ($currentCounts->get($wo->id)?->done_qty ?? 0);
            $pending = $operations->get($wo->id, collect())->first(fn ($op) => (float) ($doneByOperation[$op->id] ?? 0) + 0.00001 < $qty);
            $status = $wo->status === 'cancelled' || $historical ? 'cancelled' : ((float) ($currentCounts->get($wo->id)?->stored_qty ?? 0) + 0.00001 >= $qty ? 'completed' : (($currentCounts->get($wo->id)?->started_qty ?? 0) ? 'in_progress' : 'pending'));
            $parent = $graph['parents']->get($wo->id);
            $assembled = $parent ? (int) ($installed->get($parent->parent_work_order_id.':'.$wo->item_id)?->installed_qty ?? 0) : 0;
            return ['key' => 'work_order:'.$wo->id, 'id' => (int) $wo->id, 'type' => 'work_order', 'line_id' => $lineId, 'work_order_id' => (int) $wo->id,
                'name' => $wo->item_name ?: $wo->product_name, 'subtitle' => ($parent ? '子工单' : '主工单').($assembled > 0 ? ' · 已装 '.$assembled.' 台' : ''),
                'document_no' => $workOrderLinks ? $wo->work_order_no : null, 'work_order_no' => $workOrderLinks ? $wo->work_order_no : null,
                'url' => $workOrderLinks ? '/production/work-orders/'.$wo->id : null, 'quantity' => $qty, 'unit_name' => $wo->unit_name,
                'completed_qty' => $done, 'planned_qty' => $qty, 'status' => $status,
                'status_label' => $historical ? '历史工单' : ($status === 'cancelled' ? '已取消' : $this->number($done).'/'.$this->number($qty)), 'is_historical' => $historical,
                'current_node_label' => $pending?->operation_name, 'children' => $nested];
        };
        $nodes = [];
        foreach ($lines as $line) {
            $active = $orders->filter(fn ($wo) => $graph['line_ids']->get($wo->id) === (int) $line->id && ! $graph['parents']->has($wo->id)
                && $wo->status !== 'cancelled' && (!$allCounts->get($wo->id) || $currentCounts->get($wo->id)?->qty)
                && ! $graph['historical']->get($wo->id) && ! $this->historical($wo, $line));
            $nested = $active->map(fn ($wo) => $build($wo, (int) $line->id))->values()->all();
            $currentDone = collect($nested)->sum('completed_qty');
            foreach ($orders->filter(fn ($wo) => $graph['line_ids']->get($wo->id) === (int) $line->id
                && (($historyCounts[$wo->id] ?? 0) > 0 || $wo->status === 'cancelled' || $graph['historical']->get($wo->id) || $this->historical($wo, $line))) as $historyWo) {
                $historyQty = (float) ($historyCounts[$historyWo->id] ?? $allCounts[$historyWo->id] ?? $historyWo->planned_qty);
                $nested[] = ['key' => 'history-work_order:'.$historyWo->id, 'id' => (int) $historyWo->id, 'type' => 'work_order',
                    'line_id' => (int) $line->id, 'work_order_id' => (int) $historyWo->id, 'name' => $historyWo->item_name ?: $historyWo->product_name,
                    'subtitle' => '历史台件', 'work_order_no' => $workOrderLinks ? $historyWo->work_order_no : null,
                    'document_no' => $workOrderLinks ? $historyWo->work_order_no : null, 'quantity' => $historyQty, 'planned_qty' => $historyQty,
                    'completed_qty' => 0, 'status' => 'cancelled', 'status_label' => '历史 '.$this->number($historyQty).' '.($historyWo->unit_name ?: '件'),
                    'unit_name' => $historyWo->unit_name, 'is_historical' => true, 'history_only' => true, 'children' => []];
            }
            $planned = max(0, (float) $line->order_qty - (float) $line->cancelled_qty);
            $shippedQty = (float) ($shipped[$line->id] ?? 0);
            $factor = max(0.00000001, (float) ($line->fulfillment_factor_snapshot ?: 1));
            $ready = min($planned, (float) ($reserved[$line->id] ?? 0) / $factor + $shippedQty);
            $unfinished = collect($nested)->first(fn ($node) => $node['status'] !== 'cancelled' && $node['status'] !== 'completed');
            $cancelled = $line->line_status === 'removed' || $planned <= 0;
            $label = $cancelled ? '已取消' : ($shippedQty + 0.00001 >= $planned ? '已发货'
                : ($unfinished ? ($unfinished['current_node_label'] ?: '待生产') : ($shippingStates[$line->id] ?? ($active->isNotEmpty() ? '已入库' : ($ready > 0 ? '备货 '.$this->number($ready).'/'.$this->number($planned) : '待备货')))));
            if (in_array($line->line_type, ['service', 'deduction', 'no_delivery'], true) || in_array($line->fulfillment_type, ['service', 'no_delivery'], true)) $label = '无需发货';
            $nodes[] = ['key' => 'line:'.$line->id, 'id' => (int) $line->id, 'type' => 'line', 'line_id' => (int) $line->id, 'work_order_id' => null,
                'name' => $line->sku_name ?: $line->product_name ?: $line->item_name, 'subtitle' => '第 '.$line->line_no.' 行 · '.$this->number($line->order_qty).($line->unit_name_snapshot ?: ''),
                'image_url' => $line->image ?: null, 'document_no' => null, 'quantity' => (float) $line->order_qty, 'unit_name' => $line->unit_name_snapshot,
                'completed_qty' => $currentDone, 'planned_qty' => $planned,
                'status' => $cancelled ? 'cancelled' : ($label === '已发货' || $label === '无需发货' ? 'completed' : ($ready > 0 || collect($nested)->contains('status', 'in_progress') ? 'in_progress' : 'pending')),
                'status_label' => $label, 'children' => $nested];
        }
        return ['data' => $nodes, 'meta' => ['order_id' => (int) $order->id, 'order_no' => $order->sales_order_no, 'permissions' => ['work_order_links' => $workOrderLinks]]];
    }

    /** 树仅批量读取数量和最慢阶段，不调用逐台详情，也不装载报工和影像。 */
    private function treeShippingStates(SalesOrder $order, Collection $orderLines): array
    {
        $lines = SalesShipmentLine::query()->with('shipment')->whereIn('shipment_id', DB::table('erp_sales_shipments')->where('sales_order_id', $order->id)->select('id'))
            ->whereExists(fn (Builder $current) => $current->selectRaw('1')->from('erp_sales_order_lines as current_line')->whereColumn('current_line.id', 'erp_sales_shipment_lines.sales_order_line_id')->whereColumn('current_line.item_id', 'erp_sales_shipment_lines.item_id'))
            ->whereNotIn('line_status', ['cancelled', 'replaced'])->orderBy('id')->get()->filter(fn ($line) => $line->shipment?->shipment_status !== 'cancelled');
        $contents = ShipmentPackingContent::query()->whereIn('shipment_line_id', $lines->pluck('id'))->get(['id', 'shipment_line_id']);
        $operations = ShipmentPackingOperation::query()->whereIn('shipment_id', $lines->pluck('shipment_id')->unique())->where('status', '!=', 'CANCELLED')->orderBy('sequence')->orderBy('id')
            ->get(['id', 'sequence', 'status', 'operation_name_snapshot', 'packing_content_ids']);
        $results = ShipmentSerialResult::query()->whereIn('shipment_line_id', $lines->pluck('id'))->get(['operation_id', 'shipment_line_id', 'round', 'status']);
        $labels = [];
        foreach ($lines->groupBy('sales_order_line_id') as $lineId => $shipments) {
            $states = [];
            $orderLine = $orderLines->firstWhere('id', $lineId);
            $expected = (float) ($orderLine?->item_base_required_qty ?: max(0, (float) $orderLine?->order_qty - (float) $orderLine?->cancelled_qty));
            if ((float) $shipments->sum('base_qty') + 0.00001 < $expected) $states[] = ['rank' => 0, 'label' => '待发准备'];
            foreach ($shipments as $line) {
                if ($line->line_status === 'shipped') { $states[] = ['rank' => 100000, 'label' => '已发货']; continue; }
                if ($line->line_status === 'repair') { $states[] = ['rank' => -1, 'label' => '返工']; continue; }
                if (! $line->prepared_at) { $states[] = ['rank' => 0, 'label' => '待发准备']; continue; }
                $ids = $contents->where('shipment_line_id', $line->id)->pluck('id')->all();
                $pending = null;
                $related = $operations->filter(fn ($op) => array_intersect($op->packing_content_ids ?: [], $ids) !== []);
                foreach ($related->values() as $index => $op) {
                    $round = $results->first(fn ($r) => (int) $r->operation_id === (int) $op->id && (int) $r->shipment_line_id === (int) $line->id && (int) $r->round === (int) $line->quality_round);
                    if ($round ? $round->status === 'completed' : $op->status === 'COMPLETED') continue;
                    $pending = ['rank' => 10 + $index, 'label' => $op->operation_name_snapshot];
                    break;
                }
                $states[] = $pending ?: ['rank' => $related->isEmpty() ? 0 : 10000, 'label' => $related->isEmpty() ? '待发准备' : '待发货'];
            }
            $labels[$lineId] = collect($states)->sortBy('rank')->first()['label'] ?? '待发准备';
        }
        return $labels;
    }

    public function units(SalesOrder $order, int $lineId, array $filters, bool $workOrderLinks = true): array
    {
        $line = $this->line($order, $lineId);
        $workOrderId = ! empty($filters['work_order_id']) ? (int) $filters['work_order_id'] : null;
        $historical = false;
        if ($workOrderId) [, , $historical] = $this->workOrder($order, $workOrderId, $lineId);
        $query = $this->unitQuery($order, $line, $workOrderId, $historical || !empty($filters['history']), $historical);
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) $query->where('device.serial_no', 'like', '%'.$keyword.'%');
        $page = $query->orderBy('device.serial_no')->orderBy('device.key')->paginate((int) ($filters['per_page'] ?? 10), ['device.*'], 'page', (int) ($filters['page'] ?? 1));
        return ['data' => $this->deviceRows($order, $line, $page->getCollection(), $workOrderLinks, $historical || !empty($filters['history'])), 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage()]];
    }

    public function unit(SalesOrder $order, int $lineId, string $unitKey, bool $workOrderLinks): array
    {
        $line = $this->line($order, $lineId);
        $quantities = app(SalesOrderInventoryLockService::class)->projection($order, true);
        $quantitySummary = collect($quantities['lines'])->firstWhere('sales_order_line_id', (int) $line->id);
        $requiresDelivery = $quantitySummary['requires_delivery'] ?? (SalesOrderFulfillmentPolicy::isPhysical((string) $line->line_type)
            && !in_array($line->fulfillment_type, ['service', 'no_delivery'], true));
        if (!$requiresDelivery) {
            abort_unless($unitKey === 'quantity', 404, '该订单行没有逐台设备。');
            return ['view' => 'line', 'subject' => $this->subject($order, $line, null, $workOrderLinks), 'units_mode' => 'quantity', 'selected_unit' => null,
                'unit_options' => [], 'unit_total' => 0, 'production_nodes' => [], 'shipment_nodes' => [], 'production_records' => [], 'shipment_records' => [],
                'timeline' => [], 'progress' => null,
                'quantity_summary' => $quantitySummary,
                'notice' => '本订单行无需生产及实物发货。', 'permissions' => ['work_order_links' => $workOrderLinks]];
        }
        $query = $this->unitQuery($order, $line);
        $total = (clone $query)->count();
        $unit = null;
        if ($unitKey === 'quantity') abort_if($total > 0, 422, '该订单行已有逐台设备，请选择具体设备。');
        else {
            $unit = (clone $query)->where('device.key', $unitKey)->first();
            abort_unless($unit, 404, '该设备不属于本订单行。');
        }
        $wo = $unit?->work_order_id ? MfgWorkOrder::query()->findOrFail($unit->work_order_id) : null;
        $production = $wo ? $this->production($wo, $unit->unit_id ? (int) $unit->unit_id : null, $workOrderLinks)
            : $this->sourceProduction($order, $line, $unit?->inventory_serial_id ? (int) $unit->inventory_serial_id : null, $workOrderLinks);
        $subParents = $wo ? collect([$wo]) : collect();
        if ($line->line_status === 'removed' || ($wo && ($this->historical($wo, $line) || $this->transferred($wo, $order, $line)))) {
            $production = $this->historicalProduction($production);
            $subParents = collect();
        }
        if (! $unit && ! $wo) {
            $direct = MfgWorkOrder::query()->where('sales_order_id', $order->id)->where('sales_order_line_id', $line->id)->where('status', '!=', 'cancelled')->orderBy('id')->get()
                ->reject(fn ($item) => $this->historical($item, $line) || ($item->units()->exists() && !CancellationProductionService::salesDemandUnitIds((int) $item->id)));
            if ($direct->isNotEmpty()) {
                $production = ['nodes' => [], 'records' => [], 'destination_label' => null, 'materials' => []];
                foreach ($direct as $item) {
                    $part = $this->production($item, null, $workOrderLinks, $item->units()->exists() ? CancellationProductionService::salesDemandUnitIds((int) $item->id) : null);
                    $production['nodes'] = array_merge($production['nodes'], $part['nodes']);
                    $production['records'] = array_merge($production['records'], $part['records']);
                    $production['materials'] = array_merge($production['materials'], $part['materials'] ?? []);
                    $production['destination_label'] = collect([$production['destination_label'], $part['destination_label']])->filter()->unique()->implode(' / ');
                }
                $wo = $direct->first();
                $subParents = $direct;
            }
        }
        $serialId = $unit?->inventory_serial_id ? (int) $unit->inventory_serial_id : null;
        $anonymous = ! $unit || (! $serialId && $wo && $this->untracked($wo));
        $shipment = $this->shipment($this->shipmentData($line, $serialId ? [$serialId] : ($anonymous ? null : [])), $line, $serialId, $anonymous, $wo);
        [$production, $shipment] = $this->mergeShipmentHandoff($production, $shipment, $anonymous);
        if ($line->line_status === 'removed') {
            $shipment['nodes'] = array_map(fn ($node) => array_replace($node, ['status' => 'cancelled']), $shipment['nodes']);
            $shipment['records'] = array_map(fn ($record) => array_replace($record, ['is_cancelled' => true]), $shipment['records']);
        }
        $options = $total <= 8 ? $this->deviceRows($order, $line, (clone $query)->orderBy('device.serial_no')->get(), $workOrderLinks) : [];
        $payload = ['view' => 'line', 'subject' => $this->subject($order, $line, $wo, $workOrderLinks, $production['destination_label'] ?? null) + ['shipment_no' => $shipment['shipment_no']], 'units_mode' => $unit ? 'unit' : 'quantity',
            'selected_unit' => $unit ? $this->deviceRows($order, $line, collect([$unit]), $workOrderLinks)[0] : null, 'unit_options' => $options, 'unit_total' => $total,
            'production_nodes' => $production['nodes'], 'shipment_nodes' => $shipment['nodes'], 'production_records' => $production['records'], 'shipment_records' => $shipment['records'],
            'quantity_summary' => $quantitySummary,
            'materials' => $production['materials'] ?? [],
            'notice' => $anonymous ? (($quantitySummary['fulfillment_strategy'] ?? null) === 'purchase'
                ? '外购商品按订单行数量显示备货和发货进度。' : '工厂产品按订单行数量显示备货、生产和发货进度。') : null,
            'permissions' => ['work_order_links' => $workOrderLinks]];
        return $payload + app(OrderProgressTimeline::class)->attach($payload, $this->subGroups($subParents, $workOrderLinks));
    }

    public function workOrderView(SalesOrder $order, int $workOrderId, ?string $unitKey, bool $workOrderLinks, bool $historyOnly = false): array
    {
        [$wo, $line, $wholeHistorical] = $this->workOrder($order, $workOrderId);
        $unitId = $unitKey ? (int) preg_replace('/^unit:/', '', $unitKey) : null;
        $historical = $wholeHistorical || $historyOnly || ($unitId && CancellationProductionDisposition::where('sales_order_id', $order->id)->where('work_order_unit_id', $unitId)->exists());
        if ($unitId) abort_unless(MfgWorkOrderUnit::query()->where('work_order_id', $wo->id)->whereKey($unitId)->exists(), 404, '该台件不属于本工单。');
        $scopeIds = $wholeHistorical ? null : ($historical ? CancellationProductionDisposition::where('sales_order_id', $order->id)->where('work_order_id', $wo->id)->whereNotNull('work_order_unit_id')->pluck('work_order_unit_id')->all()
            : CancellationProductionService::salesDemandUnitIds((int) $wo->id));
        if (!$historical && !$wo->units()->exists()) $scopeIds = null;
        $production = $this->production($wo, $unitId, $workOrderLinks, $scopeIds);
        if ($historical) $production = $this->historicalProduction($production);
        $query = $this->unitQuery($order, $line, (int) $wo->id, $historical, $wholeHistorical);
        $total = (clone $query)->count();
        $selected = $unitId ? (clone $query)->where('device.unit_id', $unitId)->first() : null;
        $payload = ['view' => 'work_order', 'subject' => $this->subject($order, $line, $wo, $workOrderLinks, $production['destination_label']), 'units_mode' => $unitId ? 'unit' : ($total ? 'all' : 'quantity'),
            'selected_unit' => $selected ? $this->deviceRows($order, $line, collect([$selected]), $workOrderLinks, $historical)[0] : null,
            'unit_options' => $total <= 8 ? $this->deviceRows($order, $line, (clone $query)->orderBy('device.serial_no')->get(), $workOrderLinks, $historical) : [], 'unit_total' => $total,
            'production_nodes' => $production['nodes'], 'shipment_nodes' => [], 'production_records' => $production['records'], 'shipment_records' => [],
            'materials' => $production['materials'] ?? [],
            'notice' => $total ? null : '本工单没有逐台设备记录，按工序数量显示。', 'permissions' => ['work_order_links' => $workOrderLinks]];
        if ($scopeIds !== null) $payload['subject']['quantity'] = $total;
        return $payload + app(OrderProgressTimeline::class)->attach($payload, $historical ? [] : $this->subGroups(collect([$wo]), $workOrderLinks));
    }

    /**
     * 子工单（自制半成品）各自的工序记录，随主工单一起展示；只读、递归、防环。
     * 子工单与整机序列号目前没有逐台绑定，所以只能按“本工单批次”挂在主工单级别，不能对应到某一台。
     */
    private function subGroups(Collection $parents, bool $links, int $depth = 0, array $seen = []): array
    {
        $groups = [];
        foreach ($parents as $parent) {
            $childIds = DB::table('erp_mfg_work_order_links')->where('parent_work_order_id', $parent->id)->orderBy('id')->pluck('child_work_order_id');
            $children = MfgWorkOrder::query()->whereIn('id', $childIds)->where('status', '!=', 'cancelled')->orderBy('id')->get();
            foreach ($children as $child) {
                if (in_array((int) $child->id, $seen, true)) continue;
                $currentIds = CancellationProductionService::salesDemandUnitIds((int) $child->id);
                if (!$currentIds && $child->units()->exists()) continue;
                $production = $this->production($child, null, $links, $currentIds ?: null);
                $planned = $child->units()->exists() ? (float) count($currentIds) : (float) $child->planned_qty;
                $currentUnits = $child->units()->whereIn('id', $currentIds)->get(['id', 'status']);
                $stored = (float) $currentUnits->where('status', 'stored')->count();
                $completed = (float) $currentUnits->whereIn('status', ['stored', 'completed'])->count();
                $done = $stored + 0.00001 >= $planned;
                $current = $child->operations()->where('execution_context', 'production')->get()->first(fn ($op) => MfgUnitReport::where('work_order_operation_id', $op->id)
                    ->whereIn('unit_id', $currentIds)->where('result', 'passed')->distinct()->count('unit_id') + 0.00001 < $planned)?->operation_name;
                $progress = $this->number($done ? $stored : $completed).'/'.$this->number($planned);
                $name = $child->item_name ?: $child->product_name;
                $groups[] = ['work_order_id' => (int) $child->id, 'work_order_no' => $links ? $child->work_order_no : null, 'name' => $name, 'depth' => $depth,
                    'status' => $done ? 'completed' : ($child->started_at ? 'in_progress' : 'pending'), 'progress_label' => $progress,
                    'destination_label' => $production['destination_label'] ?? null, 'current_label' => $done ? null : $name.($current ? '（'.$current.'）' : ''),
                    'summary' => $done ? $progress.' 已完成'.($production['destination_label'] ? ' · '.$production['destination_label'] : '')
                        : $progress.' 进行中'.($current ? ' · '.$current : ''),
                    'nodes' => $production['nodes'], 'records' => $production['records']];
                $groups = array_merge($groups, $this->subGroups(collect([$child]), $links, $depth + 1, array_merge($seen, [(int) $parent->id, (int) $child->id])));
            }
        }
        return $groups;
    }

    private function line(SalesOrder $order, int $lineId): SalesOrderLine
    {
        return SalesOrderLine::query()->where('sales_order_id', $order->id)->findOrFail($lineId);
    }

    /** 递归只查本订单工单闭包，路径防环；不加载几百台设备来构造产品树。 */
    private function graph(SalesOrder $order): array
    {
        $rows = DB::select("WITH RECURSIVE wo_tree AS (
            SELECT wo.id, wo.id AS root_id, COALESCE(wo.sales_order_line_id, original_line.id) AS line_id,
                CASE WHEN wo.sales_order_id IS NULL THEN 1 ELSE 0 END AS historical,
                CAST(CONCAT(',', wo.id, ',') AS CHAR(20000)) AS path FROM erp_mfg_work_orders wo
            LEFT JOIN erp_sales_order_lines original_line ON original_line.sales_order_id = ? AND original_line.line_no = wo.sales_order_line_no
            WHERE ((wo.sales_order_id = ? AND wo.sales_order_line_id IS NOT NULL)
                OR (wo.sales_order_id IS NULL AND wo.source_type = 'stock' AND wo.stock_reason = 'order_released' AND wo.sales_order_no = ? AND original_line.id IS NOT NULL))
                AND NOT EXISTS (SELECT 1 FROM erp_mfg_work_order_links parent_link WHERE parent_link.child_work_order_id = wo.id)
            UNION ALL SELECT child.id, tree.root_id, tree.line_id, tree.historical, CONCAT(tree.path, child.id, ',') FROM wo_tree tree
            JOIN erp_mfg_work_order_links link ON link.parent_work_order_id = tree.id JOIN erp_mfg_work_orders child ON child.id = link.child_work_order_id
            WHERE LOCATE(CONCAT(',', child.id, ','), tree.path) = 0)
            SELECT DISTINCT id, root_id, line_id, historical FROM wo_tree", [$order->id, $order->id, $order->sales_order_no]);
        $orders = MfgWorkOrder::query()->whereIn('id', array_column($rows, 'id'))->orderBy('id')->get()->keyBy('id');
        $links = DB::table('erp_mfg_work_order_links')->whereIn('parent_work_order_id', $orders->keys())->whereIn('child_work_order_id', $orders->keys())->orderBy('id')->get();
        return ['orders' => $orders, 'links' => $links, 'parents' => $links->keyBy('child_work_order_id'), 'line_ids' => collect($rows)->keyBy('id')->map(fn ($r) => (int) $r->line_id),
            'root_ids' => collect($rows)->keyBy('id')->map(fn ($r) => (int) $r->root_id), 'historical' => collect($rows)->keyBy('id')->map(fn ($r) => (bool) $r->historical)];
    }

    private function workOrder(SalesOrder $order, int $id, ?int $lineId = null): array
    {
        $graph = $this->graph($order);
        $wo = $graph['orders']->get($id);
        abort_unless($wo && ($lineId === null || $graph['line_ids']->get($id) === $lineId), 404, '该工单不属于本订单行。');
        $line = $this->line($order, $graph['line_ids']->get($id));
        return [$wo, $line, $wo->status === 'cancelled' || $graph['historical']->get($id) || $this->historical($graph['orders']->get($graph['root_ids']->get($id)), $line)];
    }

    private function unitQuery(SalesOrder $order, SalesOrderLine $line, ?int $workOrderId = null, bool $historyOnly = false, bool $wholeHistorical = false): Builder
    {
        $assigned = DB::table('erp_sales_shipment_lines as sl')->join('erp_sales_shipments as sh', 'sh.id', '=', 'sl.shipment_id')
            ->join('erp_inventory_serials as s', fn ($join) => $join->whereRaw("JSON_CONTAINS(sl.serial_snapshot, JSON_ARRAY(s.id), '$.inventory_serial_ids')"))
            ->where('sh.sales_order_id', $order->id)->where('sl.sales_order_line_id', $line->id)
            ->where('sh.shipment_status', '!=', 'cancelled')->whereNotIn('sl.line_status', ['cancelled', 'replaced'])
            ->when($line->item_id, fn ($q) => $q->where('sl.item_id', $line->item_id)->where('s.item_id', $line->item_id))->select('s.id');
        $units = DB::table('erp_mfg_work_order_units as u')->join('erp_mfg_work_orders as w', 'w.id', '=', 'u.work_order_id')
            ->leftJoin('erp_mfg_work_order_operations as o', 'o.id', '=', 'u.current_operation_id')
            // 根节点只选当前商品的有效设备；指定工单仍可独立追溯取消、转备货和换机历史。
            ->when($workOrderId, function ($q) use ($workOrderId, $historyOnly, $wholeHistorical, $order) {
                $q->where('w.id', $workOrderId);
                if (!$wholeHistorical) {
                    $disposed = CancellationProductionDisposition::where('sales_order_id', $order->id)->whereNotNull('work_order_unit_id')->select('work_order_unit_id');
                    if ($historyOnly) $q->whereIn('u.id', $disposed); else $q->whereNotIn('u.id', $disposed);
                }
            }, fn ($q) => $q
                ->where('w.status', '!=', 'cancelled')->where('u.status', '!=', 'cancelled')
                ->when($line->item_id, fn ($item) => $item->where('w.item_id', $line->item_id))
                ->when($line->sku_id, fn ($sku) => $sku->where(fn ($match) => $match->whereNull('w.sku_id')->orWhere('w.sku_id', $line->sku_id)))
                ->where(fn ($scope) => $scope
                    ->where(fn ($direct) => $direct->where('w.sales_order_id', $order->id)->where('w.sales_order_line_id', $line->id)
                        ->whereNotIn('u.id', CancellationProductionDisposition::where('sales_order_id', $order->id)->whereNotNull('work_order_unit_id')->select('work_order_unit_id')))
                    ->orWhereIn('u.inventory_serial_id', clone $assigned)))
            ->selectRaw("CONCAT('unit:', u.id) AS `key`, u.id AS unit_id, u.inventory_serial_id, u.work_order_id, w.work_order_no, w.item_id AS source_item_id, w.sku_id AS source_sku_id,
                w.sales_order_id AS source_order_id, w.sales_order_line_id AS source_line_id, w.sales_order_no AS source_order_no, w.sales_order_line_no AS source_line_no, w.source_type, w.stock_reason,
                u.unit_no, u.serial_no, u.status, u.current_operation_id, o.operation_name, u.started_at, u.completed_at, u.stored_at, u.updated_at");
        if (! $workOrderId) {
            $serials = DB::table('erp_inventory_serials as s')->whereIn('s.id', $assigned)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('erp_mfg_work_order_units as u')->whereColumn('u.inventory_serial_id', 's.id'))
                ->selectRaw("CONCAT('serial:', s.id) AS `key`, NULL AS unit_id, s.id AS inventory_serial_id, NULL AS work_order_id, NULL AS work_order_no, s.item_id AS source_item_id, NULL AS source_sku_id,
                    NULL AS source_order_id, NULL AS source_line_id, NULL AS source_order_no, NULL AS source_line_no, NULL AS source_type, NULL AS stock_reason,
                    NULL AS unit_no, s.serial_no, 'stored' AS status, NULL AS current_operation_id, NULL AS operation_name, NULL AS started_at, NULL AS completed_at, s.posted_at AS stored_at, s.updated_at");
            $units->unionAll($serials);
        }
        return DB::query()->fromSub($units, 'device');
    }

    private function subject(SalesOrder $order, SalesOrderLine $line, ?MfgWorkOrder $wo, bool $links, ?string $destinationLabel = null): array
    {
        $parent = $wo ? DB::table('erp_mfg_work_order_links as l')->join('erp_mfg_work_orders as p', 'p.id', '=', 'l.parent_work_order_id')->where('l.child_work_order_id', $wo->id)->first(['p.item_name', 'p.product_name']) : null;
        return ['id' => $wo ? (int) $wo->id : (int) $line->id, 'name' => $wo ? ($wo->item_name ?: $wo->product_name) : ($line->sku_name ?: $line->product_name ?: $line->item_name),
            'image_url' => $parent ? null : ($line->image ?: null), 'quantity' => $wo ? (float) $wo->planned_qty : (float) $line->order_qty,
            'unit_name' => $wo?->unit_name ?: $line->unit_name_snapshot, 'line_no' => (int) $line->line_no, 'line_id' => (int) $line->id,
            'work_order_id' => $wo ? (int) $wo->id : null, 'routing_name' => $wo?->routing_name,
            'routing_version' => $wo?->routing_version, 'document_no' => $wo && $links ? $wo->work_order_no : null,
            'parent_name' => $parent ? ($parent->item_name ?: $parent->product_name) : null,
            'destination_label' => $destinationLabel, 'planned_finish_date' => $wo?->planned_finish_date?->toDateString(), 'order_no' => $order->sales_order_no];
    }

    private function untracked(MfgWorkOrder $wo): bool
    {
        return Item::query()->find($wo->item_id)?->serialTrackingMode() === 'none';
    }

    private function production(MfgWorkOrder $wo, ?int $unitId, bool $links, ?array $scopeIds = null): array
    {
        $operations = $wo->operations()->where('execution_context', 'production')->get();
        $units = $wo->units()->when($unitId, fn ($q) => $q->whereKey($unitId))->when($scopeIds !== null, fn ($q) => $q->whereIn('id', $scopeIds))->get(['id', 'unit_no', 'serial_no', 'status', 'current_operation_id', 'rework_count', 'started_at', 'completed_at', 'stored_at']);
        $reports = MfgUnitReport::query()->where('work_order_id', $wo->id)->when($unitId, fn ($q) => $q->where('unit_id', $unitId))
            ->when($scopeIds !== null, fn ($q) => $q->whereIn('unit_id', $scopeIds))->orderBy('id')->get();
        $currentParticipants = MfgTaskParticipant::query()->where('work_order_id', $wo->id)->where('is_active', true)->get();
        $owners = $currentParticipants->where('role', 'owner')->keyBy(fn ($p) => $p->work_order_operation_id.':'.$p->lane);
        $sessions = DB::table('erp_mfg_labor_sessions')->where('work_order_id', $wo->id)->orderBy('started_at')->get();
        $shipmentRepairs = ShipmentRework::query()->where('work_order_id', $wo->id)->when($unitId, fn ($q) => $q->where('unit_id', $unitId))->get();
        $planned = $unitId ? 1.0 : ($scopeIds !== null ? (float) $units->count() : (float) $wo->planned_qty);
        $nodes = [];
        $records = [];
        $rounds = [];
        $failedRounds = [];
        foreach ($reports as $report) {
            if ($report->result === 'failed') $failedRounds[$report->unit_id] = ($failedRounds[$report->unit_id] ?? 0) + 1;
            $repair = $report->is_rework ? $shipmentRepairs->first(fn ($r) => (int) $r->unit_id === (int) $report->unit_id && $r->created_at <= $report->created_at
                && (! $r->completed_at || $r->completed_at >= $report->created_at)) : null;
            $rounds[$report->id] = $repair ? (int) $repair->round : ($failedRounds[$report->unit_id] ?? 0);
        }
        foreach ($operations as $op) {
            $key = 'production:'.$op->id;
            $history = $reports->where('work_order_operation_id', $op->id);
            $current = $units->where('current_operation_id', $op->id)->whereIn('status', ['waiting', 'in_progress', 'rework']);
            $done = $unitId ? ($history->contains('result', 'passed') ? 1 : 0) : ($scopeIds !== null ? (float) $history->where('result', 'passed')->pluck('unit_id')->unique()->count() : (float) $op->completed_qty);
            $rework = $history->where('is_rework', true)->count();
            $status = $wo->status === 'cancelled' ? 'cancelled' : ($current->isNotEmpty() && $current->contains('status', 'rework') ? 'in_progress' : $this->quantityStatus($done, $planned));
            $nodes[] = $this->node($key, $op->operation_name, $status, $done, $planned, ['rework_count' => $rework, 'time' => $status === 'completed' ? $history->where('result', 'passed')->last()?->created_at?->toDateTimeString() : null]);
            // 只有正式 submission_key 才能合并；报废按台审核，结论或审核说明不同仍须拆开。
            foreach ($history->groupBy(fn ($report) => $report->submission_key
                ? $report->work_order_operation_id.':'.$report->reporter_legacy_id.':'.$report->submission_key.':'.$report->result.':'
                    .hash('sha256', json_encode([$report->review_status, $report->reviewed_by_legacy_id, $report->review_remark]))
                : 'report:'.$report->id) as $batch) {
                $first = $batch->first();
                $participants = collect($first->participants_snapshot ?: []);
                $owner = $participants->first(fn ($p) => strtolower((string) ($p['role'] ?? '')) === 'owner');
                $round = $batch->max(fn ($r) => $rounds[$r->id] ?? 0);
                $statusLabel = match ($first->result) {
                    'passed' => $first->pass_mode === 'prepared' ? '已备料' : ($first->is_rework ? ($round > 0 ? '第 '.$round.' 次合格' : '返工合格') : '已完成'),
                    'failed' => '不合格', default => $first->review_status === 'pending' ? '报废待确认' : ($first->review_status === 'rejected' ? '报废已驳回' : '已报废'),
                };
                $media = $this->media($batch->flatMap(fn ($r) => $r->media ?: [])->all(), $batch->flatMap(fn ($r) => $r->photos ?: [])->all());
                $records[] = $this->record('report:'.$first->id, $key, $op->operation_name, [
                    'status' => $first->result === 'passed' ? 'completed' : ($first->result === 'failed' ? 'failed' : ($first->review_status !== 'pending' ? 'cancelled' : 'in_progress')),
                    'status_label' => $statusLabel, 'person' => $owner['employee_name'] ?? $first->reporter_name, 'operator_name' => $first->reporter_name,
                    'collaborators' => $participants->filter(fn ($p) => strtolower((string) ($p['role'] ?? '')) !== 'owner')->pluck('employee_name')->filter()->values()->all(),
                    'claimed_at' => $owner['joined_at'] ?? null, 'started_at' => $owner['started_at'] ?? null,
                    'completed_at' => $first->result === 'passed' ? $first->created_at?->toDateTimeString() : null,
                    'occurred_at' => $first->created_at?->toDateTimeString(), 'reason' => $first->reason,
                    'description' => implode('；', array_filter([$first->return_operation_name ? '退回'.$first->return_operation_name : null, $first->review_remark])),
                    'return_to' => $first->result === 'failed' ? ($first->return_operation_name ?: null) : null,
                    'units' => $batch->map(fn ($r) => ['id' => 'unit:'.$r->unit_id, 'serial_no' => $this->deviceLabel($r->serial_no, $wo->work_order_no, $links)])->values()->all(),
                    'quantity' => $batch->count(), 'round' => $round ?: null, 'submission_key' => $first->submission_key, 'media' => $media,
                    'is_cancelled' => $wo->status === 'cancelled', 'scope' => $batch->count() > 1 ? 'work_order' : 'unit',
                    'review_status' => $first->review_status, 'reviewed_by_name' => $first->reviewed_by_name,
                    'reviewed_at' => $batch->max('reviewed_at')?->toDateTimeString(),
                ]);
            }
            foreach ($current->groupBy(fn ($u) => $u->status === 'rework' ? 'rework' : 'normal') as $lane => $waiting) {
                $owner = $owners->get($op->id.':'.$lane);
                $timing = $owner ? $sessions->filter(fn ($s) => (int) $s->work_order_operation_id === (int) $op->id && $s->lane === $lane
                    && (($sessionUnitIds = OrderChangeLaborService::recordedUnitIds($s)) === null || array_intersect($sessionUnitIds, $waiting->pluck('id')->all()))
                    && (int) $s->employee_legacy_id === (int) $owner->employee_legacy_id && (! $owner->joined_at || $s->started_at >= $owner->joined_at->toDateTimeString()))->first() : null;
                $records[] = $this->record('current:'.$op->id.':'.$lane, $key, $op->operation_name, [
                    'status' => $lane === 'rework' ? 'rework' : ($owner || $waiting->contains('status', 'in_progress') ? 'in_progress' : 'pending'),
                    'status_label' => $lane === 'rework' ? '返工第 '.max(1, (int) $waiting->max('rework_count')).' 次' : ($owner ? '进行中' : '待接单'),
                    'person' => $owner?->employee_name, 'claimed_at' => $owner?->joined_at?->toDateTimeString(), 'started_at' => $timing?->started_at,
                    'collaborators' => $currentParticipants->filter(fn ($p) => (int) $p->work_order_operation_id === (int) $op->id && $p->lane === $lane && $p->role !== 'owner')->pluck('employee_name')->filter()->values()->all(),
                    'units' => $waiting->map(fn ($u) => ['id' => 'unit:'.$u->id, 'serial_no' => $this->deviceLabel($u->serial_no, $wo->work_order_no, $links)])->values()->all(), 'quantity' => $waiting->count(),
                    'round' => $lane === 'rework' ? (int) $waiting->max('rework_count') : null, 'scope' => 'work_order', 'scope_label' => '工序任务记录',
                ]);
            }
        }
        $receipts = MfgReceipt::query()->where('work_order_id', $wo->id)
            ->when($unitId, fn ($q) => $q->whereJsonContains('serial_nos', $units->first()?->serial_no))->orderBy('id')->get();
        if ($scopeIds !== null) $receipts = $receipts->map(function ($receipt) use ($units) {
            $matched = array_values(array_intersect((array) $receipt->serial_nos, $units->pluck('serial_no')->all()));
            if (!$matched) return null;
            $view = clone $receipt; $view->serial_nos = $matched; $view->qty = count($matched); return $view;
        })->filter()->values();
        $completion = $this->completion($wo, $operations->last(), $units, $receipts, $planned, $unitId, $links);
        $nodes = array_merge($nodes, $completion['nodes']);
        $records = array_merge($records, $completion['records']);
        $cutting = MfgCuttingRunAllocation::query()->where('work_order_id', $wo->id)->orderBy('id')->get();
        $runs = MfgCuttingRun::query()->whereIn('id', $cutting->pluck('cutting_run_id')->unique())->get()->keyBy('id');
        foreach ($cutting->groupBy(fn ($a) => $a->work_order_operation_id.':'.$a->cutting_run_id) as $allocations) {
            $first = $allocations->first();
            $run = $runs->get($first->cutting_run_id);
            $op = $operations->firstWhere('id', $first->work_order_operation_id);
            if (! $run || ! $op) continue;
            $runLines = collect($run->lines ?: [])->keyBy('line_key');
            $items = $allocations->groupBy('line_key')->map(fn ($rows, $lineKey) => ['name' => $runLines->get($lineKey)['component_item_name'] ?? $lineKey,
                'pieces' => $rows->sum('pieces'), 'quantity' => $rows->sum('pieces'), 'unit_name' => '件'])->values()->all();
            $records[] = $this->record('cutting:'.$first->work_order_operation_id.':'.$run->id, 'production:'.$op->id, $op->operation_name, [
                'status' => 'completed', 'status_label' => '工单级开料来源', 'person' => $run->finished_by_name ?: $run->started_by_name,
                'started_at' => $run->started_at?->toDateTimeString(), 'completed_at' => $run->finished_at?->toDateTimeString(), 'quantity' => null,
                'scope' => 'work_order', 'scope_label' => $unitId ? '所属工单的开料记录' : '工单级开料记录',
                'cutting_runs' => [['id' => (int) $run->id, 'run_no' => $run->run_no, 'items' => $items]],
            ]);
        }
        $destination = $this->destination($wo, $units, $links, $completion['handoff_issue_ids']);
        if ($destination) {
            $nodes = array_merge($nodes, $destination['nodes']);
            $records = array_merge($records, $destination['records']);
        }
        $materials = $wo->materials()->orderBy('id')->get();
        $matItemIds = $materials->pluck('component_item_id')->unique()->filter();
        $matItems = Item::query()->whereIn('id', $matItemIds)->get(['id', 'spec', 'model', 'material_grade'])->keyBy('id');
        $bomItems = is_array($wo->bom_snapshot) ? collect(data_get($wo->bom_snapshot, 'items', []))->keyBy('id') : collect();
        $materialList = $materials->map(function ($m) use ($wo, $matItems, $bomItems) {
            $item = $matItems->get($m->component_item_id);
            $bomLine = $bomItems->get($m->bom_item_id);
            $specInfo = \App\Services\Erp\Manufacturing\WorkOrderQueryService::materialSpecInfo($item, $bomLine);
            $req = (float) $m->required_qty;
            $iss = (float) $m->issued_qty;
            $isComplete = $iss + 0.00001 >= $req;
            return [
                'id' => (int) $m->id,
                'component_item_id' => (int) $m->component_item_id,
                'component_item_code' => $m->component_item_code,
                'component_item_name' => $m->component_item_name,
                'specification' => $specInfo['specification'] ?: ($item?->spec ?: '-'),
                'cut_spec' => $specInfo['cut_spec'] ?? null,
                'unit_name' => $m->unit_name ?: '件',
                'required_qty' => $req,
                'issued_qty' => $iss,
                'status' => $isComplete ? 'completed' : ($iss > 0 ? 'partial' : 'pending'),
                'status_label' => $isComplete ? '已领齐' : ($iss > 0 ? '部分领料' : '待领料'),
                'work_order_no' => $wo->work_order_no,
                'work_order_id' => (int) $wo->id,
            ];
        })->values()->all();
        return ['nodes' => $nodes, 'records' => $records, 'destination_label' => $completion['destination_label'], 'materials' => $materialList];
    }

    /** receipt 是库存记账事实；是否直交只由同 receipt_id 的正式交接日志证明。 */
    private function completion(MfgWorkOrder $wo, ?MfgWorkOrderOperation $last, Collection $units, Collection $receipts, float $planned, ?int $unitId, bool $links): array
    {
        $allHandoffs = MfgWorkOrderLog::query()->where('work_order_id', $wo->id)->whereIn('action', ['handoff', 'handoff_shipment'])->orderBy('id')->get();
        $logs = $allHandoffs->filter(fn ($log) => $receipts->contains('id', (int) data_get($log->payload, 'receipt_id')));
        $handoffs = $logs->keyBy(fn ($log) => (int) data_get($log->payload, 'receipt_id'));
        $targetNames = MfgWorkOrderOperation::query()->whereIn('id', $logs->map(fn ($log) => data_get($log->payload, 'operation_id'))->filter())->pluck('operation_name', 'id');
        $labels = ['store' => \App\Services\Erp\Manufacturing\ReceiptService::allowedLocationPurposes($wo) === ['general'] ? '半成品入库' : '成品入库',
            'handoff' => '直接交接', 'handoff_shipment' => '直接交接待发', 'choice' => '交接或入库'];
        $pendingLabels = ['store' => '待入库', 'handoff' => '待交接', 'handoff_shipment' => '待交接', 'choice' => '待选择去向'];
        $groups = [];
        $records = [];
        $pendingReceiptQty = 0.0;
        foreach ($receipts as $receipt) {
            $qty = $unitId ? 1.0 : (float) $receipt->qty;
            $receiptUnits = collect($receipt->serial_nos ?: [])->when($unitId, fn ($names) => $names->filter(fn ($name) => $name === $units->first()?->serial_no))
                ->map(fn ($name) => ['serial_no' => $this->deviceLabel($name, $wo->work_order_no, $links)])->values()->all();
            $receiptContext = ['quantity' => $qty, 'scope' => $unitId ? 'unit' : 'work_order', 'units' => $receiptUnits,
                'receipt_id' => (int) $receipt->id, 'completion_kind' => 'store', 'is_cancelled' => $wo->status === 'cancelled'];
            if ($receipt->confirmation_source === 'manual' && $receipt->requested_at) {
                $records[] = $this->record('receipt-request:'.$receipt->id, 'completion:'.$wo->id.':store', '提交入库', $receiptContext + [
                    'status' => 'completed', 'status_label' => '已提交', 'person' => $receipt->operator_name,
                    'completed_at' => $receipt->requested_at->toDateTimeString(), 'description' => $receipt->receipt_no]);
            }
            if ($receipt->status === 'rejected') {
                $records[] = $this->record('receipt-reject:'.$receipt->id, 'completion:'.$wo->id.':store', '仓库驳回', $receiptContext + [
                    'status' => 'failed', 'status_label' => '已驳回', 'person' => $receipt->rejected_by_name,
                    'occurred_at' => $receipt->rejected_at?->toDateTimeString(), 'description' => $receipt->receipt_no.' · '.$receipt->reject_reason]);
            }
            if ($receipt->status === 'pending') {
                $pendingReceiptQty += $qty;
                $groups['store'] ??= ['done' => 0.0, 'pending' => 0.0, 'time' => null];
                $groups['store']['pending'] += $qty;
                $pendingLabels['store'] = '待仓库确认';
            }
            if ($receipt->status !== 'confirmed' || ! $receipt->inventory_transaction_id) continue;
            $log = $handoffs->get($receipt->id);
            $kind = $log?->action ?: 'store';
            $groups[$kind] ??= ['done' => 0.0, 'pending' => 0.0, 'time' => null];
            $groups[$kind]['done'] += $qty;
            $time = $receipt->confirmed_at?->toDateTimeString();
            $groups[$kind]['time'] = max($groups[$kind]['time'], $time);
            $target = $targetNames[data_get($log?->payload, 'operation_id')] ?? null;
            $records[] = $this->record('completion-receipt:'.$receipt->id, 'completion:'.$wo->id.':'.$kind, $labels[$kind], [
                'status' => 'completed', 'status_label' => $kind === 'store' ? '已入库' : '已交接',
                'person' => $log?->operator_name ?: ($receipt->confirmed_by_name ?: ($receipt->confirmation_source === 'legacy_posted' ? $receipt->operator_name : null)),
                'completed_at' => $time, 'quantity' => $qty, 'scope' => $unitId ? 'unit' : 'work_order',
                'description' => implode(' · ', array_filter([$target ? '交接至'.$target : null, $receipt->receipt_no])),
                'units' => collect($receipt->serial_nos ?: [])->when($unitId, fn ($names) => $names->filter(fn ($name) => $name === $units->first()?->serial_no))
                    ->map(fn ($name) => ['serial_no' => $this->deviceLabel($name, $wo->work_order_no, $links)])->values()->all(),
                'receipt_id' => (int) $receipt->id, 'shipment_id' => data_get($log?->payload, 'shipment_id'), 'issue_id' => data_get($log?->payload, 'issue_id'),
                'completion_kind' => $kind, 'is_cancelled' => $wo->status === 'cancelled',
            ]);
        }
        $remaining = max(0.0, $planned - collect($groups)->sum('done') - $pendingReceiptQty - $units->whereIn('status', ['scrapped', 'cancelled'])->count());
        $canContinue = in_array($wo->status, ['planned', 'released', 'in_progress', 'paused'], true);
        $mode = $last && in_array($last->output_mode, ['warehouse_optional', 'warehouse_required'], true) ? $last->output_mode : 'flow_only';
        $future = [];
        if ($remaining > 0.00001 && $last && $canContinue) {
            if ($mode === 'warehouse_optional') $future['choice'] = $remaining;
            elseif ($mode === 'warehouse_required') $future['store'] = $remaining;
            else {
                // options 只决定尚未选择的未来批次；历史不按当前缺口重算。
                $options = app(TaskCompletionService::class)->options($wo, $last);
                if ($options && $options['handoff_available']) {
                    $target = MfgWorkOrderOperation::query()->find($options['next_operation_id']);
                    $kind = $target?->execution_context === 'shipment' ? 'handoff_shipment' : 'handoff';
                    $qty = isset($options['remaining_qty']) ? min($remaining, floor(max(0, (float) $options['remaining_qty']) + 0.00001)) : $remaining;
                    if ($qty > 0) $future[$kind] = $qty;
                    if ($remaining > $qty + 0.00001) $future['store'] = $remaining - $qty;
                } else $future['store'] = $remaining;
            }
        }
        foreach ($future as $kind => $qty) {
            $groups[$kind] ??= ['done' => 0.0, 'pending' => 0.0, 'time' => null];
            $groups[$kind]['pending'] += $qty;
        }
        $nodes = [];
        foreach ($groups as $kind => $group) {
            $total = $group['done'] + $group['pending'];
            $status = $wo->status === 'cancelled' ? 'cancelled' : $this->quantityStatus($group['done'], $total);
            $nodes[] = $this->node('completion:'.$wo->id.':'.$kind, $labels[$kind], $status, $group['done'], $total, [
                'completion_kind' => $kind, 'output_mode' => $mode, 'status_label' => $group['pending'] > 0 ? $pendingLabels[$kind] : ($kind === 'store' ? '已入库' : '已交接'),
                'time' => $status === 'completed' ? $group['time'] : null,
            ]);
        }
        $destinationLabel = $mode === 'warehouse_required' ? $labels['store'] : ($mode === 'warehouse_optional' && $remaining > 0.00001 && $canContinue ? $labels['choice']
            : collect(array_keys($groups))->map(fn ($kind) => $labels[$kind])->unique()->implode(' / '));
        return ['nodes' => $nodes, 'records' => $records, 'destination_label' => $destinationLabel ?: null,
            'handoff_issue_ids' => $allHandoffs->map(fn ($log) => data_get($log->payload, 'issue_id'))->filter()->unique()->all()];
    }

    private function destination(MfgWorkOrder $wo, Collection $units, bool $links, array $handoffIssueIds): ?array
    {
        $parent = DB::table('erp_mfg_work_order_links as l')->join('erp_mfg_work_orders as p', 'p.id', '=', 'l.parent_work_order_id')->where('l.child_work_order_id', $wo->id)->first(['p.id', 'p.work_order_no']);
        if (! $parent) return null;
        $components = MfgUnitComponent::query()->installed()->where('work_order_id', $parent->id)->where('component_item_id', $wo->item_id)
            ->whereIn('component_serial_no', $units->pluck('serial_no'))->get();
        $issues = MfgMaterialIssue::query()->where('work_order_id', $parent->id)->whereNotNull('inventory_transaction_id')->whereNotIn('id', $handoffIssueIds)->orderBy('id')->get();
        $balances = DB::table('erp_inventory_balances')->where('item_id', $wo->item_id)->where('batch_no', $wo->work_order_no)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $operations = MfgWorkOrderOperation::query()->whereIn('id', $issues->pluck('work_order_operation_id'))->pluck('operation_name', 'id');
        $key = 'destination:'.$wo->id.':received';
        $nodes = [];
        $records = [];
        foreach ($issues as $issue) {
            $qty = 0.0;
            foreach ($issue->issue_lines ?: [] as $row) {
                if ((int) ($row['component_item_id'] ?? 0) !== (int) $wo->item_id) continue;
                foreach ($row['picks'] ?? [] as $pick) if (in_array((int) ($pick['balance_id'] ?? 0), $balances, true)) $qty += (float) ($pick['qty'] ?? 0);
            }
            if ($qty <= 0) continue;
            $records[] = $this->record('material-issue:'.$issue->id, $key, ($operations[$issue->work_order_operation_id] ?? '上级工序').'领用', [
                'status' => 'completed', 'status_label' => '已领用', 'person' => $issue->operator_name, 'completed_at' => $issue->created_at?->toDateTimeString(), 'quantity' => $qty,
                'description' => $issue->issue_no, 'scope' => 'work_order', 'scope_label' => '本工单批次领用记录',
                'document_no' => $links ? $parent->work_order_no : null,
            ]);
        }
        if ($records) {
            $qty = (float) collect($records)->sum('quantity');
            $nodes[] = $this->node($key, '上级工序领用', 'completed', $qty, $qty, ['status_label' => '已领用', 'progress_label' => '本工单批次已领 '.$this->number($qty)]);
        }
        $assemblyKey = 'destination:'.$wo->id.':assembly';
        foreach ($components as $component) $records[] = $this->record('component:'.$component->id, $assemblyKey, '装配登记', [
            'status' => 'completed', 'status_label' => '已装入', 'person' => $component->installed_by_name, 'completed_at' => $component->installed_at?->toDateTimeString(),
            'units' => [['serial_no' => $this->deviceLabel($component->component_serial_no, $wo->work_order_no, $links)]], 'quantity' => 1, 'scope' => 'unit',
        ]);
        if ($components->isNotEmpty()) $nodes[] = $this->node($assemblyKey, '装配登记', 'completed', $components->count(), $components->count(),
            ['status_label' => '已装入', 'progress_label' => '已装 '.$components->count().' 台']);
        return $nodes ? ['nodes' => $nodes, 'records' => $records] : null;
    }

    /** 根单的直交待发和待发准备是同一个动作；工单详情仍保留完整完工去向。 */
    private function mergeShipmentHandoff(array $production, array $shipment, bool $quantityShipment): array
    {
        $handoffs = collect($production['nodes'])->where('completion_kind', 'handoff_shipment');
        if ($handoffs->isEmpty()) return [$production, $shipment];
        $keys = $handoffs->pluck('key')->all();
        $records = collect($production['records'])->whereIn('node_key', $keys);
        $preparations = collect($shipment['records'])->where('node_key', 'shipment:prepare');
        // 撤销后同一预留可供新发货单使用；只合并相同 receipt 与 shipment 的那次直交。
        $handoffKeys = $records->filter(fn ($record) => ! empty($record['receipt_id']) && ! empty($record['shipment_id']))
            ->mapWithKeys(fn ($record) => [(int) $record['receipt_id'].':'.(int) $record['shipment_id'] => true])->all();
        $production['nodes'] = array_values(array_filter($production['nodes'], fn ($node) => ! in_array($node['key'], $keys, true)));
        $production['records'] = array_values(array_filter($production['records'], fn ($record) => ! in_array($record['node_key'], $keys, true)));
        // 未启用 SN 时发货是整行数量事实，不能用选中单台裁切后的交接记录替换整批准备。
        if ($quantityShipment) return [$production, $shipment];
        $shipment['records'] = array_values(array_filter($shipment['records'], fn ($record) => $record['node_key'] !== 'shipment:prepare'
            || ! isset($handoffKeys[(int) ($record['receipt_id'] ?? 0).':'.(int) ($record['shipment_id'] ?? 0)])));
        $prepare = collect($shipment['nodes'])->firstWhere('key', 'shipment:prepare');
        foreach ($records as $record) {
            $proof = $preparations->where('receipt_id', $record['receipt_id'])->where('shipment_id', $record['shipment_id']);
            $cancelled = $record['is_cancelled'] || ($proof->isNotEmpty() ? $proof->every(fn ($p) => $p['is_cancelled']) : ($prepare['status'] ?? null) === 'cancelled');
            $shipment['records'][] = array_replace($record, ['node_key' => 'shipment:prepare', 'label' => '直接交接待发', 'is_cancelled' => $cancelled]);
        }
        $otherDirections = collect($production['nodes'])->filter(fn ($node) => ! empty($node['completion_kind']) && $node['status'] !== 'cancelled');
        $liveHandoffs = $handoffs->where('status', '!=', 'cancelled');
        $future = (float) $liveHandoffs->sum(fn ($node) => max(0, (float) $node['planned_qty'] - (float) $node['completed_qty']));
        $hasOtherPreparation = $preparations->contains(fn ($record) => ! $record['is_cancelled']
            && ! isset($handoffKeys[(int) ($record['receipt_id'] ?? 0).':'.(int) ($record['shipment_id'] ?? 0)]));
        foreach ($shipment['nodes'] as &$node) {
            if ($node['key'] !== 'shipment:prepare') continue;
            // 准备完成量只认当前有效发货事实，不能被某个直交子批次或已取消历史覆盖。
            $done = (float) $node['completed_qty'];
            $planned = max((float) $node['planned_qty'], $done + $future);
            $allDirect = ! $hasOtherPreparation && $otherDirections->isEmpty() && $liveHandoffs->isNotEmpty() && (float) $liveHandoffs->sum('planned_qty') + 0.00001 >= $planned;
            if ($allDirect) $node['label'] = '直接交接待发';
            $node['planned_qty'] = $planned;
            $node['progress_label'] = $this->number($done).'/'.$this->number($planned);
            $node['status'] = $node['status'] === 'cancelled' && $future <= 0 ? 'cancelled' : $this->quantityStatus($done, $planned);
            $node['status_label'] = $node['status'] === 'cancelled' ? '已取消' : ($node['status'] === 'completed' ? ($allDirect ? '已交接待发' : '已准备') : ($allDirect ? '待交接' : '待准备'));
            if ($node['status'] !== 'completed') $node['time'] = null;
        }
        unset($node);
        return [$production, $shipment];
    }

    private function sourceProduction(SalesOrder $order, SalesOrderLine $line, ?int $serialId, bool $links): array
    {
        $key = 'source:'.$line->id;
        $records = [];
        if ($serialId) {
            $serial = InventorySerial::query()->with('receipt.order')->findOrFail($serialId);
            $label = $serial->origin_type === 'purchase' ? '采购入库' : '库存来源';
            $description = $serial->source_document_no;
            if (! $links && $serial->source_document_type === 'mfg_work_order') $description = null;
            $records[] = $this->record('serial-source:'.$serial->id, $key, $label, ['status' => $serial->posted_at ? 'completed' : 'pending',
                'status_label' => $serial->posted_at ? '已入库' : '待入库', 'completed_at' => $serial->posted_at?->toDateTimeString(),
                'description' => implode(' · ', array_filter([$description ?: $serial->receipt?->receipt_no, $serial->receipt?->order?->purchase_order_no])),
                'quantity' => 1, 'scope' => 'unit', 'units' => [['id' => 'serial:'.$serial->id, 'serial_no' => $serial->serial_no]]]);
            return ['nodes' => [$this->node($key, $label, $serial->posted_at ? 'completed' : 'pending', $serial->posted_at ? 1 : 0, 1)], 'records' => $records];
        }
        $reservations = DB::table('erp_inventory_reservations')->where('source_type', 'sales_order')->where('source_order_id', $order->id)
            ->where('source_order_line_id', $line->id)->where('item_id', $line->item_id)->whereIn('reservation_status', ['active', 'converted_to_shipment', 'consumed', 'released'])->get();
        foreach ($reservations as $reservation) $records[] = $this->record('reservation:'.$reservation->id, $key, '库存备货', [
            'status' => 'completed', 'status_label' => match ($reservation->reservation_status) {'consumed' => '原预留已发货', 'released' => '原预留已释放', default => '已预留'},
            'quantity' => (float) $reservation->reserved_qty, 'is_cancelled' => $reservation->reservation_status === 'released',
            'description' => '批次 '.($links ? $reservation->batch_no : '已登记'), 'completed_at' => $reservation->reserved_at,
            'scope' => 'line', 'scope_label' => '订单行数量记录']);
        // 移待发会改写预留仓位；来源优先取本行发货准备快照，无快照才用当前预留。
        $origins = DB::table('erp_sales_shipment_lines as sl')->join('erp_sales_shipments as sh', 'sh.id', '=', 'sl.shipment_id')
            ->where('sh.sales_order_id', $order->id)->where('sl.sales_order_line_id', $line->id)->whereNotNull('sl.preparation_snapshot')
            ->select('sl.inventory_reservation_id', 'sl.item_id', 'sl.preparation_snapshot');
        $batches = DB::table('erp_inventory_reservations as r')->whereIn('r.id', $reservations->pluck('id'))
            ->leftJoinSub($origins, 'shipment_origin', fn ($join) => $join->on('shipment_origin.inventory_reservation_id', '=', 'r.id')->on('shipment_origin.item_id', '=', 'r.item_id'))
            ->selectRaw("r.item_id,
                COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(shipment_origin.preparation_snapshot, '$.warehouse_id')) AS UNSIGNED), r.warehouse_id) AS warehouse_id,
                COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(shipment_origin.preparation_snapshot, '$.location_id')) AS UNSIGNED), r.location_id) AS location_id,
                COALESCE(JSON_UNQUOTE(JSON_EXTRACT(shipment_origin.preparation_snapshot, '$.batch_no')), r.batch_no) AS batch_no")->distinct();
        $inbounds = DB::table('erp_inventory_transaction_items as i')->join('erp_inventory_transactions as tx', 'tx.id', '=', 'i.transaction_id')
            ->joinSub($batches, 'source_batch', fn ($join) => $join->on('source_batch.item_id', '=', 'i.item_id')->on('source_batch.warehouse_id', '=', 'i.warehouse_id')
                ->on('source_batch.location_id', '=', 'i.location_id')->on('source_batch.batch_no', '=', 'i.batch_no'))
            ->leftJoin('erp_purchase_receipts as pr', fn ($join) => $join->on('pr.id', '=', 'tx.source_id')->where('tx.source_type', 'purchase_receipt'))
            ->leftJoin('erp_purchase_orders as po', 'po.id', '=', 'pr.order_id')->where('tx.posting_status', 'posted')->where('i.change_qty', '>', 0)
            ->select(['i.id', 'i.change_qty', 'tx.source_type', 'tx.source_no', 'tx.posted_at', 'po.purchase_order_no'])->distinct()->orderBy('i.id')->get();
        foreach ($inbounds as $inbound) $records[] = $this->record('stock-inbound:'.$inbound->id, $key, $inbound->source_type === 'purchase_receipt' ? '采购入库来源' : '库存入库来源', [
            'status' => 'completed', 'status_label' => '已入库', 'quantity' => (float) $inbound->change_qty, 'completed_at' => $inbound->posted_at,
            'description' => implode(' · ', array_filter([! $links && $inbound->source_type === 'mfg_work_order' ? null : $inbound->source_no, $inbound->purchase_order_no])),
            'scope' => 'batch', 'scope_label' => '同批库存入库记录']);
        $planned = (float) ($line->item_base_required_qty ?: $line->order_qty);
        $done = (float) $reservations->whereIn('reservation_status', ['active', 'converted_to_shipment', 'consumed'])->sum('reserved_qty');
        $occupied = $reservations->whereIn('reservation_status', ['active', 'converted_to_shipment'])->sum('reserved_qty');
        $consumed = $reservations->where('reservation_status', 'consumed')->sum('reserved_qty');
        $status = $this->quantityStatus($done, $planned);
        return ['nodes' => [$this->node($key, '库存 / 采购备货', $status, $done, $planned,
            ['status_label' => match ($status) {'completed' => '已备货', 'in_progress' => '部分备货', default => '待备货'},
                'progress_label' => '已备货 '.$this->number($done).'/'.$this->number($planned).'；当前占用 '.$this->number($occupied).'；已发货 '.$this->number($consumed)])], 'records' => $records];
    }

    /** 只装载所选 SN（或当前页 SN）的发货事实；null 表示明确的订单行数量视图。 */
    private function shipmentData(SalesOrderLine $line, ?array $serialIds): array
    {
        $query = SalesShipmentLine::query()->with(['shipment', 'reservation'])->where('sales_order_line_id', $line->id)->where('item_id', $line->item_id)
            ->whereHas('shipment', fn ($q) => $q->where('sales_order_id', $line->sales_order_id));
        if ($serialIds !== null) $query->where(function ($q) use ($serialIds) {
            if ($serialIds === []) $q->whereRaw('1 = 0');
            foreach ($serialIds as $id) $q->orWhereJsonContains('serial_snapshot->inventory_serial_ids', (int) $id);
        });
        $lines = $query->orderBy('id')->get()->keyBy('id');
        $contents = ShipmentPackingContent::query()->with('package')->whereIn('shipment_line_id', $lines->keys())
            ->when($serialIds !== null, function ($q) use ($serialIds) {
                $q->where(function ($q) use ($serialIds) {
                    if ($serialIds === []) $q->whereRaw('1 = 0');
                    foreach ($serialIds as $id) $q->orWhereJsonContains('serial_snapshot->inventory_serial_ids', (int) $id);
                });
            })->get();
        $results = ShipmentSerialResult::query()->whereIn('shipment_line_id', $lines->keys())->when($serialIds !== null, fn ($q) => $q->whereIn('inventory_serial_id', $serialIds))->orderBy('round')->orderBy('id')->get();
        // 换机会把包裹内容改挂新 SN；旧 SN 的正式逐台成果仍能证明其历史作业。
        $operations = ShipmentPackingOperation::query()->with(['package', 'participants'])
            ->where(fn ($q) => $q->whereIn('package_id', $contents->pluck('package_id')->unique())->orWhereIn('id', $results->pluck('operation_id')->unique()))
            ->orderBy('sequence')->orderBy('id')->get()
            ->filter(fn ($op) => array_intersect($op->packing_content_ids ?: [], $contents->pluck('id')->all()) !== [] || $results->contains('operation_id', $op->id));
        $repairs = ShipmentRework::query()->whereIn('shipment_line_id', $lines->keys())->orderBy('id')->get();
        $returnOperations = MfgWorkOrderOperation::query()->whereIn('id', $repairs->pluck('return_operation_id')->filter())->pluck('operation_name', 'id');
        $dispatches = ShipmentDispatch::query()->whereIn('shipment_id', $lines->pluck('shipment_id')->unique())->orderBy('id')->get();
        $logs = DB::table('erp_shipment_packing_logs')->whereIn('operation_id', $operations->pluck('id'))->whereIn('action', ['complete', 'inspect'])->orderBy('id')->get();
        $replacements = SalesShipmentLine::query()->whereIn('replaces_line_id', $lines->keys())->get(['id', 'replaces_line_id', 'serial_snapshot']);
        $replacementSerialIds = $replacements->flatMap(fn ($r) => $r->serial_snapshot['inventory_serial_ids'] ?? [])->unique();
        $serialNos = InventorySerial::query()->whereIn('id', $replacementSerialIds)->pluck('serial_no', 'id');
        $actorIds = $results->pluck('completed_by_legacy_id')->merge($results->pluck('inspected_by_legacy_id'))->merge($logs->pluck('operator_legacy_id'))
            ->merge($operations->pluck('owner_legacy_id'))->merge($operations->pluck('inspected_by_legacy_id'))->filter()->unique();
        $names = DB::table('erp_legacy_admin_users')->whereIn('legacy_id', $actorIds)->get(['legacy_id', 'nickname', 'username'])
            ->mapWithKeys(fn ($u) => [$u->legacy_id => $u->nickname ?: $u->username]);
        return compact('lines', 'contents', 'operations', 'results', 'repairs', 'returnOperations', 'dispatches', 'logs', 'replacements', 'serialNos', 'names');
    }

    private function shipment(array $data, SalesOrderLine $orderLine, ?int $serialId, bool $quantity, ?MfgWorkOrder $sourceWorkOrder = null): array
    {
        $lines = $data['lines']->filter(fn ($line) => $quantity || ($serialId && in_array($serialId, $line->serial_snapshot['inventory_serial_ids'] ?? [], true)));
        $contents = $data['contents']->whereIn('shipment_line_id', $lines->keys())->filter(fn ($content) => $quantity || ($serialId && in_array($serialId, $content->serial_snapshot['inventory_serial_ids'] ?? [], true)));
        $lineResults = $data['results']->whereIn('shipment_line_id', $lines->keys())->filter(fn ($r) => $quantity || (int) $r->inventory_serial_id === $serialId);
        $operations = $data['operations']->filter(fn ($op) => array_intersect($op->packing_content_ids ?: [], $contents->pluck('id')->all()) !== [] || $lineResults->contains('operation_id', $op->id));
        $activeLines = $lines->filter(fn ($line) => ! $this->cancelledShipmentLine($line));
        $planned = $quantity ? (float) ($orderLine->item_base_required_qty ?: max(0, (float) $orderLine->order_qty - (float) $orderLine->cancelled_qty)) : 1.0;
        $prepared = $activeLines->filter(fn ($line) => $line->prepared_at)->sum(fn ($line) => $quantity ? (float) $line->base_qty : 1);
        $prepareStatus = $this->quantityStatus($prepared, $planned);
        $nodes = ['shipment:prepare' => $this->node('shipment:prepare', '待发准备', $prepareStatus, $prepared, $planned,
            ['status_label' => $prepareStatus === 'completed' ? '已准备' : '待准备',
                'time' => $prepared + 0.00001 >= $planned ? $activeLines->max('prepared_at')?->toDateTimeString() : null])];
        $records = [];
        foreach ($lines as $line) {
            $cancelled = $this->cancelledShipmentLine($line);
            if ($line->prepared_at) $records[] = $this->record('prepared:'.$line->id, 'shipment:prepare', '待发准备', [
                'status' => 'completed', 'status_label' => '已移至待发区', 'completed_at' => $line->prepared_at->toDateTimeString(),
                'quantity' => $quantity ? (float) $line->base_qty : 1, 'description' => $line->shipment?->shipment_no,
                'is_cancelled' => $cancelled, 'scope' => $quantity ? 'line' : 'unit',
                'receipt_id' => (int) data_get($line->reservation?->reservation_snapshot, 'mfg_receipt_id', 0), 'shipment_id' => (int) $line->shipment_id,
            ]);
            $routes = $contents->where('shipment_line_id', $line->id)->pluck('routing_snapshot')->filter();
            if ($routes->isEmpty() && $line->packing_routing_snapshot) $routes = collect([$line->packing_routing_snapshot]);
            foreach ($routes as $route) foreach (data_get($route, 'operations', []) as $snapshot) {
                if (($snapshot['execution_context'] ?? 'production') !== 'shipment') continue;
                $key = $this->shipmentNodeKey((int) $line->shipment_id, (int) ($route['routing_id'] ?? 0), $snapshot['routing_operation_id'] ?? $snapshot['sequence'] ?? 0, (int) ($route['version'] ?? 0));
                $nodes[$key] ??= $this->node($key, (string) ($snapshot['operation_name'] ?? ''), $cancelled ? 'cancelled' : 'pending', 0, 0,
                    ['sequence' => (int) ($snapshot['sequence'] ?? 0), 'shipment_type' => $snapshot['shipment_operation_type'] ?? 'packing']);
                $nodes[$key]['planned_qty'] += $quantity ? (float) $line->base_qty : 1;
                if (! $cancelled) $nodes[$key]['status'] = 'pending';
            }
            if ($line->line_status === 'replaced') {
                $replacement = $data['replacements']->firstWhere('replaces_line_id', $line->id);
                $newNos = collect($replacement?->serial_snapshot['inventory_serial_ids'] ?? [])->map(fn ($id) => $data['serialNos'][$id] ?? null)->filter()->implode('、');
                $records[] = $this->record('replaced:'.$line->id, 'shipment:prepare', '设备替换', ['status' => 'cancelled', 'status_label' => '已替换',
                    'description' => $newNos !== '' ? '已替换为 '.$newNos : '已替换设备', 'is_cancelled' => true]);
            }
        }
        $nodeFacts = [];
        $nodeOperationIds = [];
        foreach ($operations as $op) {
            $key = $this->shipmentNodeKey((int) $op->shipment_id, (int) $op->routing_id, $op->routing_operation_id ?: $op->sequence, (int) $op->routing_version_snapshot);
            $opContents = $contents->whereIn('id', $op->packing_content_ids ?: []);
            $relatedLineIds = $opContents->pluck('shipment_line_id')->merge($lineResults->where('operation_id', $op->id)->pluck('shipment_line_id'))->unique();
            $opLines = $lines->whereIn('id', $relatedLineIds);
            $opResults = $data['results']->where('operation_id', $op->id)->whereIn('shipment_line_id', $opLines->keys())
                ->filter(fn ($r) => $quantity || (int) $r->inventory_serial_id === $serialId);
            $cancelled = $op->status === 'CANCELLED' || $opLines->every(fn ($line) => $this->cancelledShipmentLine($line));
            $nodes[$key] ??= $this->node($key, $op->operation_name_snapshot, $cancelled ? 'cancelled' : 'pending', 0, 0,
                ['sequence' => (int) $op->sequence, 'shipment_type' => $op->shipment_operation_type_snapshot]);
            $nodeOperationIds[$key][] = (int) $op->id;
            $activeOpLines = $opLines->reject(fn ($line) => $this->cancelledShipmentLine($line));
            $currentResults = $opResults->filter(fn ($r) => $activeOpLines->has($r->shipment_line_id) && (int) $r->round === (int) $opLines->get($r->shipment_line_id)?->quality_round);
            $selectedQty = $quantity ? (float) $opContents->whereIn('shipment_line_id', $activeOpLines->keys())->sum('base_qty') : 1.0;
            if ($selectedQty <= 0 && $cancelled) $selectedQty = (float) ($opContents->sum('base_qty') ?: $opResults->pluck('shipment_line_id')->unique()->count());
            $done = $currentResults->isNotEmpty() ? (float) $currentResults->where('status', 'completed')->count() : ($op->status === 'COMPLETED' ? $selectedQty : 0.0);
            $status = $cancelled ? 'cancelled' : ($done + 0.00001 >= $selectedQty ? 'completed' : ($op->claimed_at || $op->started_at || $currentResults->contains(fn ($r) => $r->status !== 'waiting') ? 'in_progress' : 'pending'));
            $finishedAt = $currentResults->map(fn ($r) => $r->quality_result === 'passed' ? $r->inspected_at : ($r->status === 'completed' ? $r->completed_at : null))->filter()->max();
            $nodeFacts[$key][] = ['done' => $done, 'planned' => $selectedQty, 'status' => $status,
                'time' => $status === 'completed' ? ($finishedAt?->toDateTimeString() ?: $op->completed_at?->toDateTimeString()) : null];
            $packing = $op->shipment_operation_type_snapshot === 'packing';
            $packingLogs = $packing ? $data['logs']->where('operation_id', $op->id)->filter(function ($log) use ($serialId, $quantity) {
                $payload = json_decode($log->payload ?: '{}', true) ?: [];
                return $quantity || ($serialId && in_array($serialId, array_map('intval', $payload['inventory_serial_ids'] ?? []), true));
            }) : collect();
            $scope = $packing || $opResults->isEmpty() ? 'package' : 'unit';
            $base = ['package_no' => $op->package?->package_no, 'scope' => $scope, 'scope_label' => $scope === 'package' ? '包裹作业记录' : '逐台作业记录',
                'is_cancelled' => $cancelled, 'quantity' => $selectedQty];
            if ($opResults->isNotEmpty() && $packingLogs->isEmpty()) {
                // 质检轮次是整台设备共用的（试水退回会让整台进入下一轮），不能当作本工序的第几次；
                // 本工序自己的次数 = 该设备在这道工序上已有的结果序号。
                // 只数真正做过的结果：每轮开始时系统会预建“等待”行，那不是一次作业。
                $attempts = $opResults->filter(fn ($r) => $r->completed_at || $r->inspected_at || $r->quality_result)
                    ->groupBy('shipment_line_id')->map(fn ($rows) => $rows->sortBy('round')->values()->pluck('id')->flip());
                foreach ($opResults as $result) {
                    $line = $opLines->get($result->shipment_line_id);
                    $round = (int) $result->round;
                    $attempt = ($attempts->get($result->shipment_line_id)[$result->id] ?? 0) + 1;
                    $repair = $data['repairs']->first(fn ($r) => (int) $r->shipment_line_id === (int) $result->shipment_line_id && (int) $r->round === $round);
                    $media = $this->media($result->media ?: []);
                    if ($result->completed_at) $records[] = $this->record('serial-result:'.$result->id.':complete', $key, $op->operation_name_snapshot,
                        ['status' => 'completed', 'status_label' => $packing ? '打包完成' : '第 '.$attempt.' 次作业完成', 'person' => $data['names'][$result->completed_by_legacy_id] ?? null,
                            'completed_at' => $result->completed_at->toDateTimeString(), 'round' => $packing ? null : $round, 'attempt' => $packing ? null : $attempt, 'quantity' => 1,
                            'media' => array_values(array_filter($media, fn ($m) => ($m['stage'] ?? 'complete') !== 'inspect')),
                            'is_cancelled' => $this->cancelledShipmentLine($line)] + $base);
                    if ($result->inspected_at || $result->quality_result) $records[] = $this->record('serial-result:'.$result->id.':inspect', $key, $op->operation_name_snapshot,
                        ['status' => $result->quality_result === 'passed' ? 'completed' : 'failed', 'status_label' => ($packing ? '打包检验' : '第 '.$attempt.' 次').($result->quality_result === 'passed' ? '合格' : '不合格'),
                            'person' => $data['names'][$result->inspected_by_legacy_id] ?? null, 'completed_at' => $result->quality_result === 'passed' ? $result->inspected_at?->toDateTimeString() : null,
                            'occurred_at' => $result->inspected_at?->toDateTimeString(), 'round' => $packing ? null : $round, 'attempt' => $packing ? null : $attempt,
                            'return_to' => $result->quality_result === 'passed' || ! $repair ? null : ($data['returnOperations'][$repair->return_operation_id] ?? '返修区'),
                            'reason' => $result->reason, 'quantity' => 1, 'media' => array_values(array_filter($media, fn ($m) => ($m['stage'] ?? null) === 'inspect')),
                            'description' => $repair ? '退回'.($data['returnOperations'][$repair->return_operation_id] ?? '返修区').($repair->completed_at ? '；返修完成 '.$repair->completed_at->toDateTimeString() : '')
                                .($repair->returned_at ? '；已返回待发区 '.$repair->returned_at->toDateTimeString() : '') : null,
                            'is_cancelled' => $this->cancelledShipmentLine($line)] + $base);
                    if (! $result->completed_at && (int) $result->round === (int) $line?->quality_round) $records[] = $this->record('serial-result:'.$result->id.':current', $key, $op->operation_name_snapshot,
                        ['status' => $op->started_at || $op->claimed_at ? 'in_progress' : 'pending', 'status_label' => $op->claimed_at ? '进行中' : '待接单',
                            'person' => $data['names'][$op->owner_legacy_id] ?? null, 'claimed_at' => $op->claimed_at?->toDateTimeString(),
                            'started_at' => $op->started_at?->toDateTimeString(), 'round' => $packing ? null : $round, 'quantity' => 1] + $base);
                }
            } else {
                $logs = $packingLogs->isNotEmpty() ? $packingLogs : $data['logs']->where('operation_id', $op->id);
                foreach ($logs as $log) {
                    $payload = json_decode($log->payload ?: '{}', true) ?: [];
                    $inspection = $log->action === 'inspect';
                    $passed = ($payload['result'] ?? null) === 'passed';
                    $records[] = $this->record('packing-log:'.$log->id, $key, $op->operation_name_snapshot,
                        ['status' => $inspection && ! $passed ? 'failed' : 'completed', 'status_label' => $inspection ? ($passed ? '检验合格' : '检验不合格') : ($packing ? '本次打包完成' : '作业完成'),
                            'person' => $data['names'][$log->operator_legacy_id] ?? null, 'completed_at' => $inspection && ! $passed ? null : $log->created_at, 'occurred_at' => $log->created_at,
                            'reason' => $payload['reason'] ?? null, 'media' => $this->media($payload['media'] ?? []),
                            'quantity' => $inspection ? ($packing && ! empty($payload['inventory_serial_ids']) ? count(array_unique($payload['inventory_serial_ids'])) : $selectedQty)
                                : (float) ($payload['completed_base_qty'] ?? $selectedQty)] + $base);
                }
                if ($logs->isEmpty() || ! in_array($op->status, ['COMPLETED', 'CANCELLED'], true)) {
                    $live = ! in_array($op->status, ['COMPLETED', 'CANCELLED'], true);
                    $owner = $op->participants->first(fn ($p) => $p->is_active && strtoupper($p->role) === 'OWNER');
                    $records[] = $this->record('packing:'.$op->id.':current', $key, $op->operation_name_snapshot,
                        ['status' => $status, 'status_label' => match ($op->status) {'COMPLETED' => '已完成', 'WAIT_QUALITY' => '待检验', 'CANCELLED' => '已取消', default => $op->claimed_at ? '进行中' : '待接单'},
                            'person' => $live ? $owner?->employee_name_snapshot : null,
                            'collaborators' => $live ? $op->participants->filter(fn ($p) => $p->is_active && strtoupper($p->role) !== 'OWNER')->pluck('employee_name_snapshot')->values()->all() : [],
                            'claimed_at' => $live ? $op->claimed_at?->toDateTimeString() : null, 'started_at' => $live ? $op->started_at?->toDateTimeString() : null,
                            'completed_at' => $op->status === 'COMPLETED' ? $op->completed_at?->toDateTimeString() : null,
                            'media' => $logs->isEmpty() ? $this->media($op->media ?: []) : [],
                            'description' => '包裹累计 '.$this->number($op->completed_base_qty).'/'.$this->number($op->planned_base_qty)] + $base);
                }
            }
        }
        foreach ($nodeFacts as $key => $facts) {
            $active = collect($facts)->where('status', '!=', 'cancelled');
            $nodes[$key]['completed_qty'] = $active->sum('done');
            $nodes[$key]['planned_qty'] = $active->isEmpty() ? collect($facts)->sum('planned') : $active->sum('planned');
            $nodes[$key]['status'] = $active->isEmpty() ? 'cancelled' : ($active->every(fn ($f) => $f['status'] === 'completed') ? 'completed' : ($active->contains('status', 'in_progress') ? 'in_progress' : 'pending'));
            $nodes[$key]['progress_label'] = $this->number($nodes[$key]['completed_qty']).'/'.$this->number($nodes[$key]['planned_qty']);
            // 发货质量轮次会同时推进所有工序，不能据此认定某道工序或某箱已经返工；
            // 这里只数本工序自己的不合格次数。
            $nodes[$key]['rework_count'] = ($nodes[$key]['shipment_type'] ?? null) === 'packing' ? 0
                : $data['results']->whereIn('operation_id', $nodeOperationIds[$key] ?? [])
                    ->filter(fn ($r) => $quantity || (int) $r->inventory_serial_id === $serialId)->where('quality_result', 'failed')->count();
            $nodes[$key]['time'] = $nodes[$key]['status'] === 'completed' ? $active->pluck('time')->filter()->max() : null;
        }
        if (count($nodes) === 1 && $sourceWorkOrder) {
            // 尚无发货单时仍展示已知的本机冻结发货路线；库存无冻结来源时不猜节点。
            foreach (data_get($sourceWorkOrder->routing_snapshot, 'operations', []) as $snapshot) {
                if (($snapshot['execution_context'] ?? 'production') !== 'shipment' || empty($snapshot['operation_name'])) continue;
                $key = 'shipment:planned:'.$sourceWorkOrder->id.':'.($snapshot['routing_operation_id'] ?? $snapshot['sequence']);
                $nodes[$key] = $this->node($key, $snapshot['operation_name'], 'pending', 0, $planned, ['sequence' => (int) ($snapshot['sequence'] ?? 0),
                    'shipment_type' => $snapshot['shipment_operation_type'] ?? null, 'status_label' => '待处理']);
            }
        }
        $dispatched = 0.0;
        foreach ($data['dispatches'] as $dispatch) {
            $included = $lines->whereIn('id', $dispatch->shipment_line_ids ?: []);
            if ($included->isEmpty()) continue;
            $qty = $quantity ? (float) $included->sum('base_qty') : 1.0;
            $dispatched += $qty;
            $records[] = $this->record('dispatch:'.$dispatch->id, 'shipment:dispatch', $dispatch->delivery_mode === 'pickup' ? '提货' : '发货', [
                'status' => 'completed', 'status_label' => $dispatch->delivery_mode === 'pickup' ? '已提货' : '已发货', 'person' => $dispatch->operator_name,
                'completed_at' => $dispatch->dispatched_at?->toDateTimeString(), 'quantity' => $qty,
                'description' => implode(' · ', array_filter([$dispatch->dispatch_no, $dispatch->carrier_name, $dispatch->tracking_no])),
                'scope' => $quantity ? 'line' : 'unit', 'is_cancelled' => $included->every(fn ($l) => $this->cancelledShipmentLine($l))]);
        }
        $relatedDispatches = $data['dispatches']->filter(fn ($d) => array_intersect($d->shipment_line_ids ?: [], $lines->keys()->all()) !== []);
        $lastDispatch = $relatedDispatches->last();
        $deliveryModes = $relatedDispatches->pluck('delivery_mode')->unique();
        $dispatchStatus = $this->quantityStatus($dispatched, $planned);
        $nodes['shipment:dispatch'] = $this->node('shipment:dispatch', '发货', $dispatchStatus, $dispatched, $planned,
            ['status_label' => $dispatchStatus === 'completed' ? ($deliveryModes->count() > 1 ? '已发出' : ($deliveryModes->first() === 'pickup' ? '已提货' : '已发货')) : '待发货',
                'time' => $dispatched + 0.00001 >= $planned ? $lastDispatch?->dispatched_at?->toDateTimeString() : null]);
        if ($lines->isNotEmpty() && $activeLines->isEmpty()) {
            $nodes['shipment:prepare']['status'] = 'cancelled';
            $nodes['shipment:dispatch']['status'] = 'cancelled';
            $nodes['shipment:prepare']['status_label'] = '已取消';
            $nodes['shipment:dispatch']['status_label'] = '已取消';
        }
        $prepare = $nodes['shipment:prepare'];
        $dispatch = $nodes['shipment:dispatch'];
        $routeNodes = collect($nodes)->except(['shipment:prepare', 'shipment:dispatch'])->sortBy('sequence')->values()->all();
        $nodes = array_merge([$prepare], $routeNodes, [$dispatch]);
        return ['nodes' => $nodes, 'records' => $records, 'shipment_no' => $activeLines->last()?->shipment?->shipment_no ?: $lines->last()?->shipment?->shipment_no];
    }

    private function shipmentNodeKey(int $shipmentId, int $routingId, int|string $operationId, int $version): string { return 'shipment:'.$shipmentId.':'.$routingId.':v'.$version.':'.$operationId; }
    private function cancelledShipmentLine(SalesShipmentLine $line): bool { return in_array($line->line_status, ['cancelled', 'replaced'], true) || $line->shipment?->shipment_status === 'cancelled'; }

    private function deviceRows(SalesOrder $order, SalesOrderLine $line, Collection $rows, bool $workOrderLinks = true, bool $historical = false): array
    {
        if ($rows->isEmpty()) return [];
        $serialIds = $rows->pluck('inventory_serial_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $shipment = $this->shipmentData($line, $serialIds);
        $dispositions = CancellationProductionDisposition::where('sales_order_id', $order->id)->whereIn('work_order_unit_id', $rows->pluck('unit_id')->filter())->get()->keyBy('work_order_unit_id');
        $result = [];
        foreach ($rows as $row) {
            $serialId = $row->inventory_serial_id ? (int) $row->inventory_serial_id : null;
            $lines = $shipment['lines']->filter(fn ($l) => $serialId && in_array($serialId, $l->serial_snapshot['inventory_serial_ids'] ?? [], true));
            $active = $lines->filter(fn ($l) => ! $this->cancelledShipmentLine($l));
            $latest = $active->last();
            $label = $row->operation_name ?: ($row->stored_at ? ($row->work_order_id ? '已完工' : '已入库') : ($row->completed_at ? '待完成去向' : '未开工'));
            if ($row->status === 'pending_receipt') $label = '待仓库确认';
            $key = $row->current_operation_id ? 'production:'.$row->current_operation_id : ($row->stored_at ? 'stored' : 'pending');
            $status = in_array($row->status, ['stored', 'completed'], true) ? 'completed' : ($row->status === 'in_progress' ? 'in_progress' : 'pending');
            if ($latest) {
                $view = $this->shipment($shipment, $line, $serialId, false);
                $firstOpen = collect($view['nodes'])->first(fn ($node) => ! in_array($node['status'], ['completed', 'cancelled'], true));
                if ($latest->line_status === 'shipped') { $key = 'shipment:dispatch'; $label = '已发货'; $status = 'completed'; }
                elseif ($latest->line_status === 'prepared') {
                    $key = $firstOpen['key'] ?? 'shipment:dispatch';
                    $label = $firstOpen['label'] ?? '待发货';
                    $status = $firstOpen['status'] ?? 'pending';
                }
            }
            if ($row->status === 'rework' || $latest?->line_status === 'repair') { $key = 'rework'; $label = $row->operation_name ? $row->operation_name.'（返工）' : '返修待返回'; $status = 'in_progress'; }
            if (in_array($row->status, ['cancelled', 'scrapped'], true)) { $key = $row->status; $label = $row->status === 'scrapped' ? '已报废' : '已取消'; $status = 'cancelled'; }
            if (! $latest && $lines->contains('line_status', 'replaced')) { $key = 'replaced'; $label = '已替换'; $status = 'cancelled'; }
            $direct = (int) $row->source_order_id === (int) $order->id && (int) $row->source_line_id === (int) $line->id;
            if ($direct && (($line->item_id && (int) $row->source_item_id !== (int) $line->item_id) || ($line->sku_id && $row->source_sku_id && (int) $row->source_sku_id !== (int) $line->sku_id))) {
                $key = 'historical'; $label = '历史设备'; $status = 'cancelled';
            }
            if (! $latest && $row->source_order_id === null && $row->source_type === 'stock' && $row->stock_reason === 'order_released'
                && $row->source_order_no === $order->sales_order_no && (int) $row->source_line_no === (int) $line->line_no) {
                $key = 'historical'; $label = '已转备货'; $status = 'cancelled';
            }
            $disposition = $row->unit_id ? $dispositions->get($row->unit_id) : null;
            if ($disposition && (!$latest || $historical)) {
                $key = 'historical'; $label = ['pending' => '待接单人决定', 'postponed' => '暂停等待决定', 'terminating' => '退料或报废处理中',
                    'stock' => '已转备货', 'stored' => '历史已入库台件', 'cancelled' => '已取消后续生产'][$disposition->status] ?? '历史台件'; $status = 'cancelled';
            } elseif ($historical) { $key = 'historical'; $label = '历史台件'; $status = 'cancelled'; }
            $updated = collect([$row->updated_at, $latest?->prepared_at?->toDateTimeString(), $latest?->dispatched_at?->toDateTimeString()])->filter()->max();
            $result[] = ['id' => $row->key, 'key' => $row->key, 'unit_id' => $row->unit_id ? (int) $row->unit_id : null,
                'inventory_serial_id' => $serialId, 'work_order_id' => $row->work_order_id ? (int) $row->work_order_id : null,
                'serial_no' => $this->deviceLabel($row->serial_no, $row->work_order_no, $workOrderLinks), 'current_node' => ['key' => $key, 'label' => $label, 'status' => $status],
                'updated_at' => $updated, 'status' => $status, 'is_historical' => $status === 'cancelled',
                'disposition_id' => $disposition?->id, 'disposition_status' => $disposition?->status, 'sales_order_change_id' => $disposition?->sales_order_change_id];
        }
        return $result;
    }

    private function node(string $key, string $label, string $status, float $done, float $planned, array $extra = []): array
    {
        return $extra + ['key' => $key, 'label' => $label, 'status' => $status, 'completed_qty' => $done, 'planned_qty' => $planned,
            'progress_label' => $this->number($done).'/'.$this->number($planned), 'rework_count' => 0, 'time' => null];
    }

    private function record(string $key, string $nodeKey, string $label, array $facts = []): array
    {
        return $facts + ['key' => $key, 'node_key' => $nodeKey, 'label' => $label, 'status' => 'pending', 'status_label' => '待接单',
            'person' => null, 'operator_name' => null, 'collaborators' => [], 'claimed_at' => null, 'started_at' => null, 'completed_at' => null, 'occurred_at' => null,
            'reason' => null, 'description' => null, 'media' => [], 'units' => [], 'quantity' => 0, 'round' => null, 'scope' => 'unit',
            'scope_label' => null, 'package_no' => null, 'is_cancelled' => false, 'cutting_runs' => [],
            'unit_labels' => array_values(array_filter(array_column($facts['units'] ?? [], 'serial_no')))];
    }

    private function media(array $items, array $photos = []): array
    {
        $merged = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! in_array($item['type'] ?? 'image', ['image', 'video'], true) || empty($item['url'])) continue;
            $media = array_intersect_key($item + ['type' => 'image'], array_flip(['type', 'url', 'path', 'poster_url', 'duration', 'size', 'stage']));
            $identity = (($media['path'] ?? null) ?: $media['url']).':'.($media['stage'] ?? '');
            if (isset($merged[$identity])) continue;
            if (collect($merged)->contains(fn ($existing) => $existing['url'] === $media['url'] && ($existing['stage'] ?? null) === ($media['stage'] ?? null))) continue;
            $merged[$identity] = $media;
        }
        // 旧照片与新 media 合并去重；同一媒体在完成、质检两个正式事件中仍各自保留。
        foreach ($photos as $photo) {
            $photo = is_string($photo) ? ['type' => 'image', 'url' => $photo] : $photo;
            if (! is_array($photo) || empty($photo['url']) || collect($merged)->contains(fn ($existing) => $existing['url'] === $photo['url'])) continue;
            $merged['photo:'.$photo['url']] = ['type' => 'image', 'url' => $photo['url']];
        }
        return array_values($merged);
    }

    private function number(mixed $value): string { return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.'); }
    private function deviceLabel(string $serialNo, ?string $workOrderNo, bool $links): string
    {
        return ! $links && $workOrderNo && str_starts_with($serialNo, $workOrderNo.'-') ? substr($serialNo, strlen($workOrderNo)) : $serialNo;
    }
    private function historical(MfgWorkOrder $wo, SalesOrderLine $line): bool
    {
        return $line->line_status === 'removed' || ($line->item_id && (int) $wo->item_id !== (int) $line->item_id)
            || ($line->sku_id && $wo->sku_id && (int) $wo->sku_id !== (int) $line->sku_id);
    }
    private function transferred(MfgWorkOrder $wo, SalesOrder $order, SalesOrderLine $line): bool
    {
        return $wo->sales_order_id === null && $wo->source_type === 'stock' && $wo->stock_reason === 'order_released'
            && $wo->sales_order_no === $order->sales_order_no && (int) $wo->sales_order_line_no === (int) $line->line_no;
    }
    private function historicalProduction(array $production): array
    {
        $production['nodes'] = array_map(fn ($node) => array_replace($node, ['status' => 'cancelled']), $production['nodes']);
        $production['records'] = array_map(fn ($record) => array_replace($record, ['is_cancelled' => true]), $production['records']);
        return $production;
    }
    private function quantityStatus(float $done, float $planned): string { return $planned > 0 && $done + 0.00001 >= $planned ? 'completed' : ($done > 0 ? 'in_progress' : 'pending'); }
}
