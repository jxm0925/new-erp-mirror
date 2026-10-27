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
                $evidence = $this->legacyProductionEvidence($line, $source['source_evidence'] ?? null);
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
            $matches = app(ProductionMasterDataService::class)->defaultRoutingMatches((int) $line->item_id,
                $orderLine?->product_id ? (int) $orderLine->product_id : null, $orderLine?->sku_id ? (int) $orderLine->sku_id : null, false);
            if ($matches->count() > 1) $blockers[] = '该产品的默认包装工艺不唯一，请先核对工艺配置。';
            elseif ($matches->count() === 1) $route = app(ProductionMasterDataService::class)->snapshot($matches->first(), (int) $line->item_id);
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

    private function legacyProductionEvidence(SalesShipmentLine $line, ?array $frozen = null): ?array
    {
        $balanceId = data_get($line->preparation_snapshot, 'inventory_balance_id');
        if (! $balanceId) $balanceId = DB::table('erp_inventory_reservations')->where('id', $line->inventory_reservation_id)->value('inventory_balance_id');
        $balance = $balanceId ? DB::table('erp_inventory_balances')->where('id', $balanceId)->where('item_id', $line->item_id)->first() : null;
        $item = Item::find($line->item_id);
        $lot = $balance?->material_lot_id ? DB::table('erp_material_lots')->where('id', $balance->material_lot_id)->where('item_id', $line->item_id)
            ->where('material_form', 'PRODUCT')->where('source_type', 'production_output_record')->whereNull('configuration_id')->whereNull('stage_id')->first() : null;
        if (! $lot || $item?->item_type !== 'finished_product' || $item->materialManagementMode() !== 'quantity') return null;
        if (($frozen['version'] ?? null) === 2) return $this->legacyTransferredEvidence($line, $balance, $lot, $frozen);
        if ($frozen !== null && (($frozen['version'] ?? null) !== 1 || ! is_array($frozen['inbound_facts'] ?? null))) return null;
        $facts = DB::table('erp_inventory_transaction_items as inbound')->join('erp_inventory_transactions as tx', 'tx.id', '=', 'inbound.transaction_id')
            ->where('inbound.item_id', $line->item_id)->where('inbound.material_lot_id', $lot->id)->where('inbound.warehouse_id', $balance->warehouse_id)
            ->where('inbound.location_id', $balance->location_id)->where('inbound.batch_no', $line->batch_no)->where('inbound.change_qty', '>', 0)
            ->where('inbound.source_type', 'work_order_finished_goods_receipt')->where('tx.transaction_type', 'finished_goods_receipt')->where('tx.posting_status', 'posted')
            ->when($frozen !== null, fn ($query) => $query->whereIn('inbound.id', array_column($frozen['inbound_facts'] ?? [], 'id')))
            ->orderBy('inbound.id')->get(['inbound.id', 'inbound.transaction_id', 'inbound.change_qty', 'inbound.source_type', 'inbound.source_id'])->map(fn ($row) => (array) $row)->all();
        $quantity = array_reduce($facts, fn ($sum, $fact) => bcadd($sum, (string) $fact['change_qty'], 8), '0');
        if ($facts === [] || bccomp($quantity, (string) $line->base_qty, 8) < 0) {
            return $frozen === null ? $this->legacyTransferredEvidence($line, $balance, $lot) : null;
        }
        return ['version' => 1, 'source_origin' => 'legacy_production_stock', 'material_lot_id' => (int) $lot->id,
            'inventory_balance_id' => (int) $balance->id, 'item_id' => (int) $line->item_id, 'source_output_id' => (int) $lot->source_id, 'inbound_facts' => $facts];
    }

    /** 历史无号成品按正式流水守恒追溯；冻结截止后自身待发移位、出库不能改写原来源。 */
    private function legacyTransferredEvidence(SalesShipmentLine $line, object $balance, object $lot, ?array $frozen = null): ?array
    {
        if ((string) $balance->batch_no !== (string) $line->batch_no || (int) $balance->unit_id !== (int) $line->unit_id
            || bccomp((string) $line->base_qty, '0', 8) <= 0) return null;
        $cutoff = $frozen['ledger_through_id'] ?? null;
        if ($frozen !== null && (! is_int($cutoff) || $cutoff <= 0)) return null;
        $facts = DB::table('erp_inventory_transaction_items as fact')->join('erp_inventory_transactions as tx', 'tx.id', '=', 'fact.transaction_id')
            ->where('fact.item_id', $line->item_id)->where('fact.batch_no', $line->batch_no)->where('tx.posting_status', 'posted')
            ->when($cutoff !== null, fn ($query) => $query->where('fact.id', '<=', $cutoff))->orderBy('fact.id')
            ->get(['fact.id', 'fact.transaction_id', 'fact.item_id', 'fact.warehouse_id', 'fact.location_id', 'fact.batch_no', 'fact.unit_id',
                'fact.material_lot_id', 'fact.change_qty', 'fact.cost_amount', 'fact.source_type', 'fact.source_id', 'fact.source_item_id',
                'tx.transaction_type', 'tx.source_type as transaction_source_type', 'tx.source_id as transaction_source_id'])
            ->map(fn ($fact) => (array) $fact)->all();
        if ($facts === [] || ($cutoff !== null && (int) end($facts)['id'] !== $cutoff)) return null;
        $pairs = $this->legacyMovementPairs($facts, (int) $lot->id, (int) $line->unit_id);
        $returns = $this->legacyReturnLinks($facts);
        $proven = []; $moving = []; $sold = []; $returned = []; $restocked = []; $receipts = []; $returnProof = [];
        foreach ($facts as $fact) {
            $key = $fact['warehouse_id'].':'.$fact['location_id'];
            $quantity = (string) $fact['change_qty'];
            $available = $proven[$key] ?? '0';
            $matchesLot = (int) $fact['material_lot_id'] === (int) $lot->id && (int) $fact['unit_id'] === (int) $line->unit_id;
            if (bccomp($quantity, '0', 8) < 0) {
                $required = bcsub('0', $quantity, 8);
                $covered = $matchesLot && bccomp($available, $required, 8) >= 0;
                if ($covered && isset($pairs[$fact['id']])) $moving[$pairs[$fact['id']]] = $required;
                if ($covered && $fact['transaction_type'] === 'sales_shipment_outbound' && $fact['source_type'] === 'sales_shipment') $sold[$fact['id']] = $required;
                // 无法证明的入库不扩充来源；所有出库先扣证明量，不能用回流重复放大原入库。
                $proven[$key] = bccomp($available, $required, 8) > 0 ? bcsub($available, $required, 8) : '0';
                continue;
            }
            if (! $matchesLot || bccomp($quantity, '0', 8) <= 0) continue;
            $credit = $moving[$fact['id']] ?? '0';
            if ($fact['source_type'] === 'work_order_finished_goods_receipt' && $fact['transaction_type'] === 'finished_goods_receipt') {
                $credit = $quantity; $receipts[] = $fact;
            } elseif ($fact['source_type'] === 'sales_return_receipt' && $fact['transaction_type'] === 'sales_return_inbound'
                && $fact['transaction_source_type'] === 'sales_return_receipt' && (int) $fact['transaction_source_id'] === (int) $fact['source_id']) {
                $remaining = $quantity; $eventProof = [];
                foreach ($returns[$fact['source_item_id']] ?? [] as $link) {
                    if ((int) $link['receipt_id'] !== (int) $fact['source_id'] || (int) $link['item_id'] !== (int) $fact['item_id']
                        || (int) $link['warehouse_id'] !== (int) $fact['warehouse_id'] || (int) $link['location_id'] !== (int) $fact['location_id']
                        || (string) $link['batch_no'] !== (string) $fact['batch_no']
                        || ($link['base_unit_id'] !== null && (int) $link['base_unit_id'] !== (int) $fact['unit_id'])) continue;
                    $limit = bcsub((string) $link['allocated_base_qty'], $returned[$link['id']] ?? '0', 8);
                    $lineLimit = bcsub((string) $link['restock_base_qty'], $restocked[$fact['source_item_id']] ?? '0', 8);
                    $take = $remaining;
                    foreach ([$limit, $lineLimit, $sold[$link['outbound_transaction_item_id']] ?? '0'] as $maximum) {
                        if (bccomp($maximum, $take, 8) < 0) $take = $maximum;
                    }
                    if (bccomp($take, '0', 8) <= 0) continue;
                    $remaining = bcsub($remaining, $take, 8);
                    $returned[$link['id']] = bcadd($returned[$link['id']] ?? '0', $take, 8);
                    $restocked[$fact['source_item_id']] = bcadd($restocked[$fact['source_item_id']] ?? '0', $take, 8);
                    $sold[$link['outbound_transaction_item_id']] = bcsub($sold[$link['outbound_transaction_item_id']], $take, 8);
                    $eventProof[] = ['inbound_id' => (int) $fact['id'], 'allocation_id' => (int) $link['id'],
                        'outbound_id' => (int) $link['outbound_transaction_item_id'], 'quantity' => $take];
                    if (bccomp($remaining, '0', 8) === 0) break;
                }
                if (bccomp($remaining, '0', 8) === 0) { $credit = $quantity; array_push($returnProof, ...$eventProof); }
            }
            $proven[$key] = bcadd($available, $credit, 8);
        }
        $quantity = $proven[$balance->warehouse_id.':'.$balance->location_id] ?? '0';
        if ($receipts === [] || bccomp($quantity, (string) $line->base_qty, 8) < 0) return null;
        return ['version' => 2, 'source_origin' => 'legacy_production_stock', 'material_lot_id' => (int) $lot->id,
            'inventory_balance_id' => (int) $balance->id, 'item_id' => (int) $line->item_id, 'source_output_id' => (int) $lot->source_id,
            'ledger_through_id' => (int) end($facts)['id'], 'proven_qty' => $quantity, 'inbound_facts' => $receipts,
            // 只冻结已发生的退回分配；后续分次收货增加 posted 数量或调整未接收额度不改旧证明。
            'ledger_hash' => hash('sha256', json_encode([$facts, $pairs, $returnProof], JSON_THROW_ON_ERROR))];
    }

    /** 调拨必须有已过账单据和真实源余额；发货内部移位只认同一次正式日志的成对流水。 */
    private function legacyMovementPairs(array $facts, int $lotId, int $unitId): array
    {
        $groups = [];
        foreach ($facts as $fact) {
            if (in_array($fact['source_type'], ['inventory_transfer', 'sales_shipment_movement'], true)) {
                $groups[$fact['transaction_id'].':'.$fact['source_type'].':'.$fact['source_item_id']][] = $fact;
            }
        }
        $pairs = [];
        foreach ($groups as $group) {
            if (count($group) !== 2) continue;
            [$out, $in] = $group;
            if (bccomp((string) $out['change_qty'], '0', 8) >= 0 || bccomp((string) $in['change_qty'], '0', 8) <= 0
                || bccomp(bcadd((string) $out['change_qty'], (string) $in['change_qty'], 8), '0', 8) !== 0
                || bccomp(bcadd((string) $out['cost_amount'], (string) $in['cost_amount'], 4), '0', 4) !== 0
                || bccomp((string) $out['cost_amount'], '0', 4) > 0 || bccomp((string) $in['cost_amount'], '0', 4) < 0
                || (int) $out['material_lot_id'] !== $lotId || (int) $in['material_lot_id'] !== $lotId
                || (int) $out['unit_id'] !== $unitId || (int) $in['unit_id'] !== $unitId
                || (int) $out['source_id'] !== (int) $in['source_id'] || (int) $out['location_id'] === (int) $in['location_id']
                || $out['transaction_source_type'] !== $out['source_type'] || (int) $out['transaction_source_id'] !== (int) $out['source_id']) continue;
            if ($out['source_type'] === 'inventory_transfer') {
                $transfer = DB::table('erp_inventory_transfer_lines as line')->join('erp_inventory_transfers as transfer', 'transfer.id', '=', 'line.transfer_id')
                    ->join('erp_inventory_balances as balance', 'balance.id', '=', 'line.inventory_balance_id')
                    ->where('line.id', $out['source_item_id'])->where('line.transfer_id', $out['source_id'])->where('transfer.transfer_status', 'posted')
                    ->where('transfer.inventory_transaction_id', $out['transaction_id'])->where('transfer.source_warehouse_id', $out['warehouse_id'])
                    ->where('transfer.target_warehouse_id', $in['warehouse_id'])->where('line.item_id', $out['item_id'])
                    ->where('line.source_location_id', $out['location_id'])->where('line.target_location_id', $in['location_id'])
                    ->where('line.batch_no', $out['batch_no'])->where('line.unit_id', $unitId)->where('line.quantity', $in['change_qty'])
                    ->where('line.cost_amount', $in['cost_amount'])->where('balance.item_id', $out['item_id'])->where('balance.warehouse_id', $out['warehouse_id'])
                    ->where('balance.location_id', $out['location_id'])->where('balance.batch_no', $out['batch_no'])->where('balance.material_lot_id', $lotId)
                    ->where('balance.unit_id', $unitId)->exists();
                if (! $transfer || $out['transaction_type'] !== 'inventory_transfer') continue;
            } else {
                $movement = DB::table('erp_sales_shipment_logs as log')->join('erp_sales_shipment_lines as line', 'line.shipment_id', '=', 'log.shipment_id')
                    ->where('log.id', $out['source_id'])->where('log.action', 'internal_move')->where('line.id', $out['source_item_id'])
                    ->where('line.item_id', $out['item_id'])->where('line.batch_no', $out['batch_no'])->where('line.unit_id', $unitId)->where('line.base_qty', $in['change_qty'])->exists();
                if (! $movement || $out['transaction_type'] !== 'shipment_internal_transfer') continue;
            }
            $pairs[$out['id']] = (int) $in['id'];
        }
        return $pairs;
    }

    /** 退回只能恢复已证明的原销售出库数量，并受原成本分摊及本次合格入库数量约束。 */
    private function legacyReturnLinks(array $facts): array
    {
        $ids = array_column(array_filter($facts, fn ($fact) => $fact['source_type'] === 'sales_return_receipt'), 'source_item_id');
        if ($ids === []) return [];
        return DB::table('erp_sales_return_receipt_items as line')->join('erp_sales_return_receipts as receipt', 'receipt.id', '=', 'line.receipt_id')
            ->join('erp_sales_return_cost_allocations as allocation', 'allocation.sales_return_item_id', '=', 'line.sales_return_item_id')
            ->whereIn('line.id', $ids)->where('receipt.receipt_status', 'confirmed')->where('receipt.stock_post_status', 'posted')
            ->whereIn('allocation.allocation_status', ['reserved', 'posted'])->orderBy('line.id')->orderBy('allocation.id')
            ->get(['line.id as receipt_line_id', 'line.receipt_id', 'line.item_id', 'line.warehouse_id', 'line.location_id', 'line.batch_no', 'line.base_unit_id',
                'line.restock_base_qty', 'allocation.id', 'allocation.outbound_transaction_item_id', 'allocation.allocated_base_qty'])
            ->groupBy('receipt_line_id')->map(fn ($rows) => $rows->map(fn ($row) => (array) $row)->all())->all();
    }
}
