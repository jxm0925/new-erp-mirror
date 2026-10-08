<?php

namespace App\Services\Erp;

use App\Models\Erp\ProductionOutputRecord;
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
        $routes = []; $blockers = [];
        foreach ($sources as $source) {
            if (! $source['output_record_id']) {
                // Missing or ambiguous production evidence is not ordinary legacy stock.
                if (($source['source_origin'] ?? 'legacy_stock') !== 'legacy_stock') $blockers[] = '所选库存生产来源无法唯一确认，不能绕过生产和质检检查。';
                continue;
            }
            $output = ProductionOutputRecord::find($source['output_record_id']);
            $wo = $output ? WorkOrder::find($output->work_order_id) : null;
            if (! $wo || ! $wo->routing_snapshot) { $blockers[] = '生产来源缺少冻结工艺。'; continue; }
            $routes[hash('sha256', json_encode($wo->routing_snapshot))] = $wo->routing_snapshot;
            $table = $output->source_target_type === 'unit_operation' ? 'erp_production_unit_operations' : 'erp_production_quantity_operations';
            $target = DB::table($table)->where('id', $output->source_target_id)->first();
            $productionNodes = collect(data_get($wo->routing_snapshot, 'operations', []))->filter(fn ($node) => ($node['execution_context'] ?? 'production') === 'production');
            if (! $target || $productionNodes->where('sequence', '>', $target->sequence_no_snapshot)->isNotEmpty()) $blockers[] = '该库存仍有未完成生产工序，请先续接生产。';
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
        $route = $routes === [] ? null : reset($routes);
        if ($route === null) {
            $routing = ProductionRouting::query()->where('output_item_id', $line->item_id)->where('status', 'active')->where('is_default', true)->first();
            if ($routing) $route = app(ProductionMasterDataService::class)->snapshot($routing);
        }
        return ['routing_snapshot' => $route, 'source_rows' => $sources, 'production_ready' => $blockers === [], 'blockers' => array_values(array_unique($blockers))];
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
            foreach ($serialIds as $id) {
                $serial = DB::table('erp_inventory_serials')->where('id', $id)->where('item_id', $line->item_id)->first();
                $outputId = $serial && $serial->source_document_type === 'production_output' ? (int) $serial->source_document_id : null;
                $origin = ! $serial ? 'unresolved' : ($serial->source_document_type === 'production_output' ? 'production' : 'legacy_stock');
                $rows[] = $this->row($line, $outputId, '1', $factor, (int) $id, $origin);
            }
            $remaining = bcsub((string) $line->base_qty, (string) count($serialIds), 8);
            if (bccomp($remaining, '0', 8) !== 0) $rows[] = $this->row($line, null, $remaining, bcmul($remaining, $factor, 8), null, 'unresolved');
            return $rows;
        }
        $reservation = DB::table('erp_inventory_reservations')->where('id', $line->inventory_reservation_id)->first();
        $balance = $reservation ? DB::table('erp_inventory_balances')->where('id', $reservation->inventory_balance_id)->first() : null;
        if (! $balance) $balance = DB::table('erp_inventory_balances')->where('item_id', $line->item_id)
            ->where('warehouse_id', $line->warehouse_id)->where('location_id', $line->location_id)->where('batch_no', $line->batch_no)->first();
        $outputId = $balance ? $this->outputIdForBalance($balance) : null;
        $origin = ! $balance ? 'unresolved' : ($this->hasProductionSource($balance) ? 'production' : 'legacy_stock');
        return [$this->row($line, $outputId, (string) $line->base_qty, (string) $line->sales_qty, null, $origin)];
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
            if (! in_array($origin, ['production', 'legacy_stock', 'unresolved'], true)) $origin = 'unresolved';
            $rows[] = $this->row($line, $outputId, (string) $source['base_qty'], (string) $source['sales_qty'],
                empty($source['inventory_serial_id']) ? null : (int) $source['inventory_serial_id'], $origin);
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

    private function row(SalesShipmentLine $line, ?int $outputId, string $qty, string $salesQty, ?int $serialId, ?string $origin = null): array
    {
        return ['source_key' => $line->id.':'.($serialId ?: 'batch'), 'shipment_line_id' => (int) $line->id,
            'sales_order_line_id' => (int) $line->sales_order_line_id, 'output_record_id' => $outputId,
            'base_qty' => $qty, 'sales_qty' => $salesQty, 'inventory_serial_id' => $serialId,
            'source_origin' => $origin ?? ($outputId ? 'production' : 'legacy_stock'),
            'trace_status' => $outputId ? 'complete' : 'pending'];
    }
}
