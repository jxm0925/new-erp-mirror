<?php

namespace App\Services\Erp;

use App\Models\Erp\ProductionOutputRecord;
use App\Models\Erp\Item;
use App\Models\Erp\ProductionRouting;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesShipmentLine;
use App\Models\Erp\WorkOrder;
use Illuminate\Support\Facades\DB;

class ProductionShipmentSourceResolver
{
    public function forOrder(SalesOrder $order): array
    {
        $lines = SalesShipmentLine::query()->whereHas('shipment', fn ($q) => $q->where('sales_order_id', $order->id)
            ->whereIn('shipment_status', ['shipped', 'completed'])->whereNotNull('shipped_at'))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('erp_inventory_transactions as tx')
                ->join('erp_inventory_transaction_items as posted_item', 'posted_item.transaction_id', '=', 'tx.id')
                ->where('tx.source_type', 'sales_shipment')->whereColumn('tx.source_id', 'erp_sales_shipment_lines.shipment_id')
                ->where('tx.transaction_type', 'sales_shipment_outbound')
                ->where('tx.posting_status', 'posted')
                ->where('posted_item.source_type', 'sales_shipment')->whereColumn('posted_item.source_id', 'erp_sales_shipment_lines.shipment_id')
                ->whereColumn('posted_item.source_item_id', 'erp_sales_shipment_lines.id')->whereColumn('posted_item.item_id', 'erp_sales_shipment_lines.item_id')
                ->whereColumn('posted_item.warehouse_id', 'erp_sales_shipment_lines.warehouse_id')->whereColumn('posted_item.location_id', 'erp_sales_shipment_lines.location_id')
                ->whereRaw("COALESCE(posted_item.batch_no, '') = COALESCE(erp_sales_shipment_lines.batch_no, '')")
                ->whereRaw('posted_item.change_qty = -erp_sales_shipment_lines.base_qty'))->orderBy('id')->get();
        return $lines->flatMap(fn ($line) => $this->sources($line))->all();
    }

    public function contextForShipmentLine(SalesShipmentLine $line): array
    {
        $sources = $this->sources($line);
        $routes = []; $blockers = []; $continuations = [];
        foreach ($sources as $source) {
            if (! $source['output_record_id']) {
                // Missing or ambiguous production evidence is not ordinary legacy stock.
                if (($source['source_origin'] ?? 'legacy_stock') !== 'legacy_stock'
                    && ! app(ShipmentDirectSourcePolicy::class)->hasDirectEvidence($source)) $blockers[] = '所选库存来源无法唯一确认，不能绕过生产和质检检查。';
                continue;
            }
            $output = ProductionOutputRecord::find($source['output_record_id']);
            $wo = $output ? WorkOrder::find($output->work_order_id) : null;
            if (! $wo || ! $wo->routing_snapshot) { $blockers[] = '生产来源缺少冻结工艺。'; continue; }
            $routes[hash('sha256', json_encode($wo->routing_snapshot))] = $wo->routing_snapshot;
            $table = $output->source_target_type === 'unit_operation' ? 'erp_production_unit_operations' : 'erp_production_quantity_operations';
            $target = DB::table($table)->where('id', $output->source_target_id)->first();
            $productionNodes = collect(data_get($wo->routing_snapshot, 'operations', []))->filter(fn ($node) => ($node['execution_context'] ?? 'production') === 'production')->sortBy('sequence');
            $remaining = $target ? $productionNodes->where('sequence', '>', $target->sequence_no_snapshot) : collect();
            if (! $target || $remaining->isNotEmpty()) {
                $next = $remaining->first();
                $checkpoint = $target ? $productionNodes->firstWhere('routing_operation_id', (int) $target->routing_operation_id_snapshot) : null;
                $dry = $next && (new ProductionInventoryCheckpointPolicy)->isDryAssembly((int) $wo->output_item_id,
                    (int) $output->output_item_id, $output->output_mode_snapshot, $checkpoint['stage_code'] ?? null, $next['stage_code'] ?? null);
                $blockers[] = $dry ? '所选总装干库存尚未完成试水，请先办理续接领用并完成试水。' : '该库存仍有未完成生产工序，请先续接生产。';
                if ($next) $continuations[] = $this->continuationContext($line, $source, $wo, $output, $next);
            }
            $queue = [(int) $output->id]; $seen = [];
            while ($queue !== []) {
                $ancestorId = array_pop($queue); if (isset($seen[$ancestorId])) continue; $seen[$ancestorId] = true;
                $ancestor = ProductionOutputRecord::find($ancestorId);
                if (! $ancestor) { $blockers[] = '生产来源链缺少产出事实。'; continue; }
                if (in_array($ancestor->status, ['QUALITY_FAILED', 'REWORK', 'REJECTED', 'HANDOVER_REJECTED', 'CANCELLED'], true)
                    || ($ancestor->quality_mode_snapshot === 'required'
                        && ! DB::table('erp_production_quality_inspections')->where('output_record_id', $ancestor->id)
                            ->where('status', 'COMPLETED')->where('result', 'passed')->exists())) $blockers[] = '来源生产工序质检尚未合格。';
                foreach (DB::table('erp_production_output_lineage_links')->where('child_output_record_id', $ancestorId)->pluck('parent_output_record_id') as $parentId) $queue[] = (int) $parentId;
            }
        }
        if (count($routes) > 1) $blockers[] = '发货行包含不同冻结工艺，请按来源分别配置包装。';
        // A direct shipment has no WO to freeze its route. Its own saved route is
        // therefore authoritative; later default-route edits cannot rewrite a sale.
        $route = $line->packing_source_snapshot !== null ? $line->packing_routing_snapshot : ($routes === [] ? null : reset($routes));
        if ($route === null && $line->packing_source_snapshot === null) {
            $orderLine = $line->orderLine;
            $matches = ProductionRouting::query()->where('output_item_id', $line->item_id)->where('status', 'active')->where('is_default', true)
                ->where(fn ($q) => $q->whereNull('product_id')->orWhere('product_id', $orderLine?->product_id))
                ->where(fn ($q) => $q->whereNull('sku_id')->orWhere('sku_id', $orderLine?->sku_id))->get();
            $rank = static fn ($candidate): int => ($candidate->sku_id ? 2 : 0) + ($candidate->product_id ? 1 : 0);
            $highest = $matches->max($rank);
            $matches = $matches->filter(fn ($candidate) => $rank($candidate) === $highest);
            if ($matches->count() > 1) $blockers[] = '该产品的默认包装工艺不唯一，请先核对工艺配置。';
            elseif ($matches->count() === 1) $route = app(ProductionMasterDataService::class)->snapshot($matches->first());
        }
        return ['routing_snapshot' => $route, 'source_rows' => $sources, 'production_ready' => $blockers === [],
            'blockers' => array_values(array_unique($blockers)), 'continuations' => $continuations];
    }

    private function continuationContext(SalesShipmentLine $line, array $source, WorkOrder $wo, ProductionOutputRecord $output, array $next): array
    {
        $sameOrder = $wo->source_type !== 'stock_prebuild';
        $existing = DB::table('erp_work_order_inventory_continuations as continuation')
            ->join('erp_production_internal_issue_tasks as issue', 'issue.id', '=', 'continuation.internal_issue_task_id')
            ->join('erp_work_orders as receiving', 'receiving.id', '=', 'continuation.work_order_id')
            ->leftJoin('erp_sales_order_production_requirements as demand', 'demand.id', '=', 'receiving.production_demand_id')
            ->where('continuation.source_output_record_id', $output->id)
            ->when($sameOrder, fn ($q) => $q->where('continuation.work_order_id', $wo->id),
                fn ($q) => $q->where('demand.sales_order_line_id', $line->sales_order_line_id))
            ->when($source['inventory_serial_id'] ?? null, fn ($q, $id) => $q->where('continuation.inventory_serial_id', $id))
            ->whereIn('continuation.status', ['RESERVED', 'RECEIVED'])->orderBy('continuation.id')
            ->first(['continuation.work_order_id', 'continuation.production_unit_id', 'issue.id as issue_id', 'issue.status as issue_status']);
        $balanceId = DB::table('erp_inventory_balances')->where('item_id', $line->item_id)->where('warehouse_id', $line->warehouse_id)
            ->where('location_id', $line->location_id)->where('batch_no', $line->batch_no)->value('id');
        return ['source_output_record_id' => (int) $output->id, 'source_work_order_id' => (int) $wo->id,
            'source_work_order_no' => $wo->work_order_no, 'production_unit_id' => $existing?->production_unit_id ?: $output->production_unit_id,
            'target_work_order_id' => $sameOrder ? (int) $wo->id : ($existing ? (int) $existing->work_order_id : null),
            'inventory_balance_id' => $balanceId ? (int) $balanceId : null, 'inventory_serial_id' => $source['inventory_serial_id'] ?? null,
            'base_qty' => $source['base_qty'], 'start_operation_name' => $next['operation_name'] ?? '',
            'start_stage_code' => $next['stage_code'] ?? null, 'internal_issue_task_id' => $existing ? (int) $existing->issue_id : null,
            'internal_issue_status' => $existing?->issue_status, 'continuation_mode' => $sameOrder ? 'same_work_order' : 'new_work_order'];
    }

    private function sources(SalesShipmentLine $line): array
    {
        // Requirements freeze the actual output, serial and quantity before stock moves.
        // A returned SN may later be produced and warehoused again; its live record must
        // never rewrite the source of an already dispatched shipment.
        if ($line->packing_source_snapshot !== null) return $this->snapshotRows($line);
        $rows = []; $serialIds = (array) data_get($line->serial_snapshot, 'inventory_serial_ids', []);
        $factor = bccomp((string) $line->base_qty, '0', 8) > 0 ? bcdiv((string) $line->sales_qty, (string) $line->base_qty, 12) : '0';
        if ($serialIds !== []) {
            $allocatedSalesQty = '0';
            foreach (array_values($serialIds) as $index => $id) {
                $serial = DB::table('erp_inventory_serials')->where('id', $id)->where('item_id', $line->item_id)->first();
                $outputId = $serial && $serial->source_document_type === 'production_output' ? (int) $serial->source_document_id : null;
                $origin = ! $serial ? 'unresolved' : ($serial->source_document_type === 'production_output' ? 'production' : 'legacy_stock');
                $evidence = ! $outputId && $origin === 'legacy_stock' ? $this->directEvidence($line, $serial ? (array) $serial : null) : null;
                $last = $index === count($serialIds) - 1 && bccomp((string) $line->base_qty, (string) count($serialIds), 8) === 0;
                $salesQty = app(ShipmentDirectSourcePolicy::class)->salesQuantitySlice((string) $line->sales_qty, (string) $line->base_qty, '1', $allocatedSalesQty, $last);
                $allocatedSalesQty = bcadd($allocatedSalesQty, $salesQty, 8);
                $rows[] = $this->row($line, $outputId, '1', $salesQty, (int) $id, $evidence['source_origin'] ?? $origin, $evidence);
            }
            $remaining = bcsub((string) $line->base_qty, (string) count($serialIds), 8);
            if (bccomp($remaining, '0', 8) > 0) {
                $evidence = $this->directEvidence($line);
                $rows[] = $this->row($line, null, $remaining, bcsub((string) $line->sales_qty, $allocatedSalesQty, 8), null, $evidence['source_origin'] ?? 'unresolved', $evidence);
            } elseif (bccomp($remaining, '0', 8) < 0) {
                $rows[] = $this->row($line, null, $remaining, bcmul($remaining, $factor, 8), null, 'unresolved');
            }
            return $rows;
        }
        $reservation = DB::table('erp_inventory_reservations')->where('id', $line->inventory_reservation_id)->first();
        $balance = $reservation ? DB::table('erp_inventory_balances')->where('id', $reservation->inventory_balance_id)->first() : null;
        if (! $balance) $balance = DB::table('erp_inventory_balances')->where('item_id', $line->item_id)
            ->where('warehouse_id', $line->warehouse_id)->where('location_id', $line->location_id)->where('batch_no', $line->batch_no)->first();
        $outputId = $balance ? $this->outputIdForBalance($balance) : null;
        $origin = ! $balance ? 'unresolved' : ($this->hasProductionSource($balance) ? 'production' : 'legacy_stock');
        $evidence = ! $outputId && $origin === 'legacy_stock' ? $this->directEvidence($line) : null;
        return [$this->row($line, $outputId, (string) $line->base_qty, (string) $line->sales_qty, null, $evidence['source_origin'] ?? $origin, $evidence)];
    }

    private function snapshotRows(SalesShipmentLine $line): array
    {
        $snapshot = $line->packing_source_snapshot;
        $rows = []; $baseTotal = '0'; $salesTotal = '0';
        if (! is_array($snapshot) || $snapshot === []) return [$this->row($line, null, (string) $line->base_qty, (string) $line->sales_qty, null, 'unresolved')];
        foreach ($snapshot as $source) {
            if (! is_array($source) || ! preg_match('/^\d+(?:\.\d+)?$/D', (string) ($source['base_qty'] ?? ''))
                || ! preg_match('/^\d+(?:\.\d+)?$/D', (string) ($source['sales_qty'] ?? ''))
                || bccomp((string) $source['base_qty'], '0', 8) <= 0) {
                return [$this->row($line, null, (string) $line->base_qty, (string) $line->sales_qty, null, 'unresolved')];
            }
            $outputId = empty($source['output_record_id']) ? null : (int) $source['output_record_id'];
            $origin = $source['source_origin'] ?? ($outputId ? 'production' : 'legacy_stock');
            if (! in_array($origin, array_merge(['production', 'legacy_stock', 'unresolved'], ShipmentDirectSourcePolicy::ORIGINS), true)) $origin = 'unresolved';
            $evidence = isset($source['source_evidence']) && is_array($source['source_evidence']) ? $source['source_evidence'] : null;
            if (in_array($origin, ShipmentDirectSourcePolicy::ORIGINS, true) && ! $this->validFrozenDirectEvidence($line, $source)) {
                $origin = 'unresolved'; $evidence = null;
            }
            $rows[] = $this->row($line, $outputId, (string) $source['base_qty'], (string) $source['sales_qty'],
                empty($source['inventory_serial_id']) ? null : (int) $source['inventory_serial_id'], $origin, $evidence);
            $baseTotal = bcadd($baseTotal, (string) $source['base_qty'], 8); $salesTotal = bcadd($salesTotal, (string) $source['sales_qty'], 8);
        }
        if (bccomp($baseTotal, (string) $line->base_qty, 8) !== 0 || bccomp($salesTotal, (string) $line->sales_qty, 8) !== 0) {
            return [$this->row($line, null, (string) $line->base_qty, (string) $line->sales_qty, null, 'unresolved')];
        }
        return $rows;
    }

    private function hasProductionSource(object $balance): bool
    {
        if ($balance->material_lot_id && DB::table('erp_material_lots')->where('id', $balance->material_lot_id)
            ->where('source_type', 'production_output_record')->exists()) return true;
        return DB::table('erp_production_output_warehouse_postings as posting')->join('erp_production_output_records as output', 'output.id', '=', 'posting.output_record_id')
            ->where('posting.warehouse_id', $balance->warehouse_id)->where('posting.location_id', $balance->location_id)
            ->where('posting.batch_no', $balance->batch_no)->where('posting.status', 'POSTED')->where('output.output_item_id', $balance->item_id)->exists();
    }

    public function outputIdForBalance(object $balance): ?int
    {
        if ($balance->material_lot_id) {
            $lot = DB::table('erp_material_lots')->where('id', $balance->material_lot_id)->first();
            if ($lot && $lot->source_type === 'production_output_record') return (int) $lot->source_id;
        }
        $ids = DB::table('erp_production_output_warehouse_postings')->where('warehouse_id', $balance->warehouse_id)
            ->where('location_id', $balance->location_id)->where('batch_no', $balance->batch_no)->where('status', 'POSTED')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('erp_production_output_records as output')
                ->whereColumn('output.id', 'erp_production_output_warehouse_postings.output_record_id')->where('output.output_item_id', $balance->item_id))
            ->distinct()->pluck('output_record_id');
        return $ids->count() === 1 ? (int) $ids->first() : null;
    }

    private function directEvidence(SalesShipmentLine $line, ?array $serial = null): ?array
    {
        $reservation = DB::table('erp_inventory_reservations')->where('id', $line->inventory_reservation_id)->first();
        $balance = $reservation ? DB::table('erp_inventory_balances')->where('id', $reservation->inventory_balance_id)->first() : null;
        if (! $reservation || ! $balance) return null;
        $item = Item::find($line->item_id);
        // The order's explicit supply decision wins over later master-data edits.
        // A missing historical decision may use current qualification only when
        // the actual stock has no production-origin marker and has a real receipt.
        $strategy = data_get($line->orderLine?->item_match_snapshot, 'fulfillment_strategy');
        $purchase = $strategy === 'purchase' || ($strategy === null && ($item?->isPurchaseFulfilledItem() ?? false));
        $facts = $this->inboundFacts($line)->get()->map(fn ($fact) => (array) $fact)->all();
        return app(ShipmentDirectSourcePolicy::class)->inventoryEvidence($line->getAttributes(), (array) $reservation, (array) $balance,
            $facts, $purchase, $this->hasProductionSource($balance), $serial);
    }

    private function inboundFacts(SalesShipmentLine $line): \Illuminate\Database\Query\Builder
    {
        return DB::table('erp_inventory_transaction_items as inbound')->join('erp_inventory_transactions as tx', 'tx.id', '=', 'inbound.transaction_id')
            ->where('inbound.item_id', $line->item_id)->where('inbound.warehouse_id', $line->warehouse_id)
            ->where('inbound.location_id', $line->location_id)->where('inbound.batch_no', $line->batch_no)
            ->where('inbound.change_qty', '>', 0)->where('tx.posting_status', 'posted')
            ->where(fn ($types) => $types->where(fn ($purchase) => $purchase->where('inbound.source_type', 'purchase_receipt')
                ->whereIn('tx.transaction_type', ['purchase_receipt_posting', 'purchase_receipt_quality_release']))
                ->orWhere(fn ($stock) => $stock->where('inbound.source_type', 'inventory_adjustment')->where('tx.source_type', 'inventory_adjustment')->where('tx.transaction_type', 'manual_adjustment')))
            ->select('inbound.id', 'inbound.transaction_id', 'inbound.item_id', 'inbound.warehouse_id', 'inbound.location_id', 'inbound.batch_no', 'inbound.unit_id',
                'inbound.source_type', 'inbound.source_id', 'inbound.source_item_id', 'inbound.change_qty', 'tx.transaction_type', 'tx.posting_status');
    }

    private function validFrozenDirectEvidence(SalesShipmentLine $line, array $source): bool
    {
        if (! app(ShipmentDirectSourcePolicy::class)->hasDirectEvidence($source)) return false;
        $evidence = $source['source_evidence'];
        foreach (['item_id', 'warehouse_id', 'location_id', 'batch_no', 'unit_id', 'inventory_reservation_id'] as $field) {
            if ((string) ($evidence[$field] ?? '') !== (string) $line->{$field}) return false;
        }
        if ((int) ($source['inventory_serial_id'] ?? 0) !== (int) ($evidence['inventory_serial_id'] ?? 0)) return false;
        $ids = array_column($evidence['inbound_facts'], 'id');
        $live = $this->inboundFacts($line)->whereIn('inbound.id', $ids)->get()->keyBy('id');
        foreach ($evidence['inbound_facts'] as $fact) {
            $row = $live->get($fact['id'] ?? 0);
            if (! $row) return false;
            foreach ($fact as $key => $value) if ((string) ($row->{$key} ?? '') !== (string) $value) return false;
        }
        return count($ids) === $live->count();
    }

    private function directShipmentCompleted(SalesShipmentLine $line): bool
    {
        $shipment = $line->shipment;
        if (! $shipment || ! in_array($shipment->shipment_status, ['shipped', 'completed'], true) || ! $shipment->shipped_at) return false;
        $posted = DB::table('erp_inventory_transaction_items as outbound')->join('erp_inventory_transactions as tx', 'tx.id', '=', 'outbound.transaction_id')
            ->where('tx.source_type', 'sales_shipment')->where('tx.source_id', $line->shipment_id)->where('tx.transaction_type', 'sales_shipment_outbound')->where('tx.posting_status', 'posted')
            ->where('outbound.source_type', 'sales_shipment')->where('outbound.source_id', $line->shipment_id)->where('outbound.source_item_id', $line->id)
            ->where('outbound.item_id', $line->item_id)->where('outbound.warehouse_id', $line->warehouse_id)->where('outbound.location_id', $line->location_id)
            ->where('outbound.batch_no', $line->batch_no)->where('outbound.change_qty', bcsub('0', (string) $line->base_qty, 8))
            ->select('outbound.*', 'tx.posting_status', 'tx.transaction_type')->first();
        if (! $posted) return false;
        $contents = DB::table('erp_shipment_packing_contents')->where('shipment_id', $line->shipment_id)->where('shipment_line_id', $line->id)->get()
            ->map(fn ($content) => (array) $content)->all();
        $operations = DB::table('erp_shipment_packing_operations')->where('shipment_id', $line->shipment_id)->get()->map(fn ($op) => (array) $op)->all();
        return app(ShipmentDirectSourcePolicy::class)->shipmentCompleted($line->getAttributes(), $shipment->getAttributes(), (array) $posted,
            $line->packing_routing_snapshot ?? [], $contents, $operations);
    }

    private function row(SalesShipmentLine $line, ?int $outputId, string $qty, string $salesQty, ?int $serialId, ?string $origin = null, ?array $evidence = null): array
    {
        $row = ['source_key' => $line->id.':'.($serialId ?: 'batch'), 'shipment_line_id' => (int) $line->id,
            'sales_order_line_id' => (int) $line->sales_order_line_id, 'output_record_id' => $outputId,
            'base_qty' => $qty, 'sales_qty' => $salesQty, 'inventory_serial_id' => $serialId,
            'source_origin' => $origin ?? ($outputId ? 'production' : 'legacy_stock'),
            'trace_status' => $outputId ? 'complete' : 'pending'];
        if ($evidence !== null) $row['source_evidence'] = $evidence;
        if (app(ShipmentDirectSourcePolicy::class)->hasDirectEvidence($row) && $this->directShipmentCompleted($line)) $row['trace_status'] = 'complete';
        return $row;
    }
}
