<?php

namespace App\Services\Erp;

use App\Models\Erp\Item;
use App\Models\Erp\ProductionRouting;
use App\Models\Erp\SalesShipmentLine;
use Illuminate\Support\Facades\DB;

/** 发货打包用的库存来源与包装工艺解析（不依赖生产执行数据）。 */
class ShipmentSourceResolver
{
    public function contextForShipmentLine(SalesShipmentLine $line): array
    {
        $sources = $this->sources($line);
        $routes = []; $blockers = []; $continuations = [];
        foreach ($sources as $source) {
            if (($source['source_origin'] ?? '') === 'mfg_production') {
                $evidence = $this->manufacturingEvidence($line, (int) ($source['inventory_serial_id'] ?? 0));
                if (! $evidence || ! $this->sameEvidence($source['source_evidence'] ?? null, $evidence)) $blockers[] = '制造入库来源或原设备身份无法确认，请核对正式入库单。';
                elseif (! empty($evidence['routing_snapshot'])) $routes[$evidence['work_order_id']] = $evidence['routing_snapshot'];
                continue;
            }
            if (($source['source_origin'] ?? '') === 'legacy_production_stock') {
                $evidence = $this->legacyProductionEvidence($line);
                if (! $evidence || ! $this->sameEvidence($source['source_evidence'] ?? null, $evidence)) $blockers[] = '历史成品缺少匹配原批次的正式入库证明。';
                continue;
            }
            // 新生产模块上线前，发货只认库存来源；来源无法唯一确认时仍然拦截。
            if (! app(ShipmentDirectSourcePolicy::class)->hasDirectEvidence($source)
                && ($source['source_origin'] ?? 'legacy_stock') !== 'legacy_stock') $blockers[] = '所选库存来源无法唯一确认，请核对库存批次。';
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
                $outputId = null;
                $origin = ! $serial ? 'unresolved' : ($serial->source_document_type === 'production_output' ? 'production' : 'legacy_stock');
                $evidence = ! $outputId && $origin === 'legacy_stock' ? $this->directEvidence($line, $serial ? (array) $serial : null) : null;
                if ($serial?->source_document_type === 'mfg_receipt') {
                    $evidence = $this->manufacturingEvidence($line, (int) $id);
                    $origin = $evidence ? 'mfg_production' : 'unresolved';
                }
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
        $outputId = null;
        $origin = ! $balance ? 'unresolved' : ($this->hasProductionSource($balance) ? 'production' : 'legacy_stock');
        $evidence = ! $outputId && $origin === 'legacy_stock' ? $this->directEvidence($line) : null;
        if ($origin === 'production' && ($legacy = $this->legacyProductionEvidence($line))) { $origin = 'legacy_production_stock'; $evidence = $legacy; }
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
            if (! in_array($origin, array_merge(['production', 'mfg_production', 'legacy_production_stock', 'legacy_stock', 'unresolved'], ShipmentDirectSourcePolicy::ORIGINS), true)) $origin = 'unresolved';
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
        return $balance->material_lot_id && DB::table('erp_material_lots')->where('id', $balance->material_lot_id)
            ->where('source_type', 'production_output_record')->exists();
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
        $origin = $line->preparation_snapshot ?? [];
        return $this->inboundFactsAt((int) $line->item_id, (int) ($origin['warehouse_id'] ?? $line->warehouse_id),
            (int) ($origin['location_id'] ?? $line->location_id), (string) $line->batch_no);
    }

    /**
     * 某库存批次行的直接来源入库事实：采购到货、手工调整，以及携带来源口径的库存调拨入库。
     * 调拨入库的 cost_source_type 记录其来源口径（见 ShipmentDirectSourcePolicy::TRANSFER_*）。
     */
    public function inboundFactsAt(int $itemId, int $warehouseId, int $locationId, string $batchNo): \Illuminate\Database\Query\Builder
    {
        return DB::table('erp_inventory_transaction_items as inbound')->join('erp_inventory_transactions as tx', 'tx.id', '=', 'inbound.transaction_id')
            ->where('inbound.item_id', $itemId)->where('inbound.warehouse_id', $warehouseId)
            ->where('inbound.location_id', $locationId)->where('inbound.batch_no', $batchNo)
            ->where('inbound.change_qty', '>', 0)->where('tx.posting_status', 'posted')
            ->where(fn ($types) => $types->where(fn ($purchase) => $purchase->where('inbound.source_type', 'purchase_receipt')
                ->whereIn('tx.transaction_type', ['purchase_receipt_posting', 'purchase_receipt_quality_release']))
                ->orWhere(fn ($stock) => $stock->where('inbound.source_type', 'inventory_adjustment')->where('tx.source_type', 'inventory_adjustment')->where('tx.transaction_type', 'manual_adjustment'))
                ->orWhere(fn ($transfer) => $transfer->where('inbound.source_type', 'inventory_transfer')->where('tx.transaction_type', 'inventory_transfer')
                    ->whereIn('inbound.cost_source_type', ShipmentDirectSourcePolicy::TRANSFER_SOURCES)))
            ->select('inbound.id', 'inbound.transaction_id', 'inbound.item_id', 'inbound.warehouse_id', 'inbound.location_id', 'inbound.batch_no', 'inbound.unit_id',
                'inbound.source_type', 'inbound.source_id', 'inbound.source_item_id', 'inbound.change_qty', 'inbound.cost_source_type', 'tx.transaction_type', 'tx.posting_status');
    }

    private function validFrozenDirectEvidence(SalesShipmentLine $line, array $source): bool
    {
        if (! app(ShipmentDirectSourcePolicy::class)->hasDirectEvidence($source)) return false;
        $evidence = $source['source_evidence'];
        foreach (['item_id', 'warehouse_id', 'location_id', 'batch_no', 'unit_id', 'inventory_reservation_id'] as $field) {
            $value = in_array($field, ['warehouse_id', 'location_id'], true) ? data_get($line->preparation_snapshot, $field, $line->{$field}) : $line->{$field};
            if ((string) ($evidence[$field] ?? '') !== (string) $value) return false;
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
        if (! $shipment || ! in_array($shipment->shipment_status, ['partially_shipped', 'shipped', 'completed'], true) || ! $shipment->shipped_at || $line->line_status !== 'shipped') return false;
        $posted = DB::table('erp_inventory_transaction_items as outbound')->join('erp_inventory_transactions as tx', 'tx.id', '=', 'outbound.transaction_id')
            ->where(fn ($q) => $q->where(fn ($old) => $old->where('tx.source_type', 'sales_shipment')->where('tx.source_id', $line->shipment_id))
                ->orWhere(fn ($batch) => $batch->where('tx.source_type', 'sales_shipment_dispatch')->whereIn('tx.source_id', DB::table('erp_shipment_dispatches')->where('shipment_id', $line->shipment_id)->select('id'))))
            ->where('tx.transaction_type', 'sales_shipment_outbound')->where('tx.posting_status', 'posted')
            ->where('outbound.source_type', 'sales_shipment')->where('outbound.source_id', $line->shipment_id)->where('outbound.source_item_id', $line->id)
            ->where('outbound.item_id', $line->item_id)->where('outbound.warehouse_id', $line->warehouse_id)->where('outbound.location_id', $line->location_id)
            ->where('outbound.batch_no', $line->batch_no)->where('outbound.change_qty', bcsub('0', (string) $line->base_qty, 8))
            ->select('outbound.*', 'tx.posting_status', 'tx.transaction_type')->first();
        if (! $posted) return false;
        $contents = DB::table('erp_shipment_packing_contents')->where('shipment_id', $line->shipment_id)->where('shipment_line_id', $line->id)->get()
            ->map(fn ($content) => (array) $content)->all();
        $operations = DB::table('erp_shipment_packing_operations')->where('shipment_id', $line->shipment_id)->get()->map(fn ($op) => (array) $op)->all();
        foreach ($operations as &$operation) {
            $result = DB::table('erp_shipment_serial_results')->where('operation_id', $operation['id'])->where('shipment_line_id', $line->id)->where('round', $line->quality_round)->first();
            if ($result) {
                $operation['planned_base_qty'] = '1'; $operation['completed_base_qty'] = $result->status === 'completed' ? '1' : '0';
                $operation['status'] = $result->status === 'completed' ? 'COMPLETED' : 'WAITING'; $operation['quality_result'] = $result->quality_result;
            }
        }
        unset($operation);
        return app(ShipmentDirectSourcePolicy::class)->shipmentCompleted($line->getAttributes(), $shipment->getAttributes(), (array) $posted,
            $line->packing_routing_snapshot ?? [], $contents, $operations);
    }

    private function row(SalesShipmentLine $line, ?int $outputId, string $qty, string $salesQty, ?int $serialId, ?string $origin = null, ?array $evidence = null): array
    {
        $row = ['source_key' => $line->id.':'.($serialId ?: 'batch'), 'shipment_line_id' => (int) $line->id,
            'sales_order_line_id' => (int) $line->sales_order_line_id, 'output_record_id' => $outputId,
            'base_qty' => $qty, 'sales_qty' => $salesQty, 'inventory_serial_id' => $serialId,
            'source_origin' => $origin ?? ($outputId ? 'production' : 'legacy_stock'),
            'trace_status' => $outputId || ($origin === 'mfg_production' && $evidence !== null) ? 'complete' : 'pending'];
        if ($evidence !== null) $row['source_evidence'] = $evidence;
        if (app(ShipmentDirectSourcePolicy::class)->hasDirectEvidence($row) && $this->directShipmentCompleted($line)) $row['trace_status'] = 'complete';
        return $row;
    }

    private function sameEvidence(?array $frozen, array $current): bool
    {
        // MySQL JSON objects may reorder keys; lists and scalar types remain authoritative.
        return $frozen !== null && $this->canonicalSnapshot($frozen) === $this->canonicalSnapshot($current);
    }

    private function canonicalSnapshot(array $snapshot): array
    {
        if (! array_is_list($snapshot)) ksort($snapshot, SORT_STRING);
        foreach ($snapshot as &$value) if (is_array($value)) $value = $this->canonicalSnapshot($value);
        return $snapshot;
    }

    private function manufacturingEvidence(SalesShipmentLine $line, int $serialId): ?array
    {
        $serial = $serialId ? DB::table('erp_inventory_serials')->where('id', $serialId)->where('item_id', $line->item_id)->where('source_document_type', 'mfg_receipt')->first() : null;
        if (! $serial) return null;
        $receipt = DB::table('erp_mfg_receipts')->where('id', $serial->source_document_id)->first();
        $unit = DB::table('erp_mfg_work_order_units')->where('inventory_serial_id', $serial->id)->where('serial_no', $serial->serial_no)->first();
        $wo = $receipt ? DB::table('erp_mfg_work_orders')->where('id', $receipt->work_order_id)->where('item_id', $line->item_id)->first() : null;
        if (! $receipt || ! $wo || ! $unit || (int) $unit->work_order_id !== (int) $wo->id) return null;
        $posted = DB::table('erp_inventory_transactions')->where('id', $receipt->inventory_transaction_id)->where('source_type', 'mfg_receipt')
            ->where('source_id', $receipt->id)->where('transaction_type', 'production_receipt_inbound')->where('posting_status', 'posted')->first();
        if (! $posted) return null;
        return ['version' => 1, 'source_origin' => 'mfg_production', 'mfg_receipt_id' => (int) $receipt->id,
            'work_order_id' => (int) $wo->id, 'unit_id' => (int) $unit->id, 'inventory_serial_id' => (int) $serial->id,
            'inventory_transaction_id' => (int) $posted->id, 'routing_snapshot' => json_decode($wo->routing_snapshot ?: 'null', true)];
    }

    private function legacyProductionEvidence(SalesShipmentLine $line): ?array
    {
        $balanceId = data_get($line->preparation_snapshot, 'inventory_balance_id');
        if (! $balanceId) $balanceId = DB::table('erp_inventory_reservations')->where('id', $line->inventory_reservation_id)->value('inventory_balance_id');
        $balance = $balanceId ? DB::table('erp_inventory_balances')->where('id', $balanceId)->where('item_id', $line->item_id)->first() : null;
        $item = Item::find($line->item_id);
        $lot = $balance?->material_lot_id ? DB::table('erp_material_lots')->where('id', $balance->material_lot_id)->where('item_id', $line->item_id)
            ->where('material_form', 'PRODUCT')->where('source_type', 'production_output_record')->whereNull('configuration_id')->whereNull('stage_id')->first() : null;
        if (! $lot || $item?->item_type !== 'finished_product' || $item->materialManagementMode() !== 'quantity') return null;
        $facts = DB::table('erp_inventory_transaction_items as inbound')->join('erp_inventory_transactions as tx', 'tx.id', '=', 'inbound.transaction_id')
            ->where('inbound.item_id', $line->item_id)->where('inbound.material_lot_id', $lot->id)->where('inbound.warehouse_id', $balance->warehouse_id)
            ->where('inbound.location_id', $balance->location_id)->where('inbound.batch_no', $line->batch_no)->where('inbound.change_qty', '>', 0)
            ->where('inbound.source_type', 'work_order_finished_goods_receipt')->where('tx.transaction_type', 'finished_goods_receipt')->where('tx.posting_status', 'posted')
            ->orderBy('inbound.id')->get(['inbound.id', 'inbound.transaction_id', 'inbound.change_qty', 'inbound.source_type', 'inbound.source_id'])->map(fn ($row) => (array) $row)->all();
        if ($facts === [] || bccomp((string) collect($facts)->sum('change_qty'), (string) $line->base_qty, 8) < 0) return null;
        return ['version' => 1, 'source_origin' => 'legacy_production_stock', 'material_lot_id' => (int) $lot->id,
            'inventory_balance_id' => (int) $balance->id, 'item_id' => (int) $line->item_id, 'source_output_id' => (int) $lot->source_id, 'inbound_facts' => $facts];
    }
}
