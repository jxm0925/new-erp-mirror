<?php

namespace App\Services\Erp;

use App\Models\Erp\SalesOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** 采购成本沿正式出入库流水传播；销售数量、设备身份、工时和费用事实保持原样。 */
class PurchaseCostSalesProjectionService
{
    private array $serialMovementParts = [];
    private array $serialMovementOldParts = [];
    private array $partialSerialMovements = [];
    private array $purchaseSerialEventParts = [];

    public function partialMovementCosts(): array { return $this->partialSerialMovements; }

    public function fingerprint(array $movementCosts): string
    {
        $movements = DB::table('erp_inventory_transaction_items')->whereIntegerInRaw('id', array_keys($movementCosts))
            ->whereIn('source_type', ['sales_shipment', 'sales_shipment_movement', 'sales_return_receipt', 'shipment_packing_operation', 'aftersales_reship'])
            ->orderBy('id')->lockForUpdate()->get();
        $lineIds = $movements->whereIn('source_type', ['sales_shipment', 'sales_shipment_movement'])->pluck('source_item_id');
        $lines = DB::table('erp_sales_shipment_lines')->whereIn('id', $lineIds)->orderBy('id')->lockForUpdate()->get();
        $allocations = DB::table('erp_sales_return_cost_allocations')->whereIn('outbound_transaction_item_id', $movements->where('source_type', 'sales_shipment')->pluck('id'))
            ->orderBy('id')->lockForUpdate()->get();
        $identities = DB::table('erp_sales_return_serial_identities')->whereIn('sales_return_item_id', $allocations->pluck('sales_return_item_id'))
            ->orderBy('id')->lockForUpdate()->get();
        $packing = DB::table('erp_shipment_packing_contents')->whereIn('shipment_line_id', $lineIds)->orderBy('id')->lockForUpdate()->get();
        $materials = DB::table('erp_shipment_packing_materials')->whereIn('inventory_transaction_item_id', $movements->pluck('id'))->orderBy('id')->lockForUpdate()->get();
        $reships = DB::table('erp_aftersales_reship_items')->whereIn('transaction_item_id', $movements->pluck('id'))->orderBy('id')->lockForUpdate()->get();
        $serialIds = $lines->flatMap(fn ($line) => json_decode($line->serial_snapshot ?: '{}', true)['inventory_serial_ids'] ?? [])
            ->merge($reships->pluck('inventory_serial_id'))->filter()->unique();
        $serialEvents = DB::table('erp_inventory_serial_events')->whereIn('inventory_serial_id', $serialIds)
            ->whereIn('event_type', ['production_receipt_in', 'inventory_posted', 'sales_return_inbound'])->orderBy('id')->lockForUpdate()->get();
        $serials = DB::table('erp_inventory_serials')->whereIn('id', $serialIds)->orderBy('id')->lockForUpdate()->get();
        $purchaseSources = DB::table('erp_purchase_receipt_items')->whereIn('id', $serials->pluck('source_receipt_item_id')->filter())->orderBy('id')->lockForUpdate()->get();
        return hash('sha256', json_encode([$movements, $lines, $allocations, $identities, $packing, $materials, $reships, $serialEvents, $serials, $purchaseSources], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function impactSummary(array $movementCosts): array
    {
        $changed = DB::table('erp_inventory_transaction_items as movement')
            ->join('erp_inventory_transactions as transaction', 'transaction.id', '=', 'movement.transaction_id')
            ->whereIntegerInRaw('movement.id', array_keys($movementCosts))->where('transaction.posting_status', 'posted')->orderBy('movement.id')
            ->get(['movement.*'])->filter(fn ($row) => bccomp((string) $row->cost_amount, (string) $movementCosts[$row->id], 4) !== 0 || $this->serialMapChanged($row));
        $outbounds = $changed->where('source_type', 'sales_shipment');
        $returnIds = DB::table('erp_sales_return_cost_allocations as allocation')
            ->join('erp_sales_return_items as item', 'item.id', '=', 'allocation.sales_return_item_id')
            ->whereIn('allocation.outbound_transaction_item_id', $outbounds->pluck('id'))->pluck('item.sales_return_id')->all();
        $postedReturnIds = DB::table('erp_sales_return_receipts')->whereIn('id', $changed->where('source_type', 'sales_return_receipt')->pluck('source_id'))
            ->pluck('sales_return_id')->all();
        return ['sales_shipment_count' => $outbounds->pluck('source_id')->unique()->count(),
            'sales_return_count' => count(array_unique([...$returnIds, ...$postedReturnIds])),
            'packing_material_count' => $changed->where('source_type', 'shipment_packing_operation')->pluck('source_item_id')->unique()->count(),
            'aftersales_reship_count' => $changed->where('source_type', 'aftersales_reship')->pluck('source_id')->unique()->count()];
    }

    /** 退货依赖原出库，而不是退回时所在批次的当前均价。 */
    public function deriveMovementCosts(array $movementCosts, array $serialCosts = [], array $receiptSerialCosts = []): array
    {
        if ($movementCosts === []) return [];
        $derived = $this->serialMovementCosts($movementCosts, $serialCosts, $receiptSerialCosts);
        $movementCosts = array_replace($movementCosts, $derived);
        $outbounds = DB::table('erp_inventory_transaction_items')->whereIntegerInRaw('id', array_keys($movementCosts))
            ->where('source_type', 'sales_shipment')->where('change_qty', '<', 0)->orderBy('id')->lockForUpdate()->get()
            ->filter(fn ($row) => bccomp((string) $row->cost_amount, (string) $movementCosts[$row->id], 4) !== 0 || $this->serialMapChanged($row))->keyBy('id');
        if ($outbounds->isEmpty()) return $derived;
        $returnItemIds = DB::table('erp_sales_return_cost_allocations')->whereIn('outbound_transaction_item_id', $outbounds->keys())
            ->pluck('sales_return_item_id')->unique();
        if ($returnItemIds->isEmpty()) return $derived;
        $returnedQuantities = []; $quantityPools = [];
        foreach (app(SalesReturnCostTraceService::class)->postedSegments($returnItemIds->all()) as $segment) {
            $source = $segment['source']; $inbound = $segment['movement'];
            if (! $outbounds->has($source->id)) continue;
            if ($segment['serial_id']) {
                $derived[$inbound->id] = $this->serialCost($source, $segment['serial_id'], $movementCosts);
                continue;
            }
            $total = $this->absolute((string) $movementCosts[$source->id]);
            // 部分编号金额先由库存池重放收敛；不能用上一轮整笔金额误判该批剩余数量为负成本。
            if (isset($this->partialSerialMovements[$source->id]) && bccomp($total, $this->partialSerialMovements[$source->id]['new_known_cost'], 4) < 0) continue;
            $quantityPools[$source->id] ??= app(SalesReturnCostTraceService::class)->quantityPool($source, $total, $this->serialMovementParts[$source->id] ?? []);
            $previousQty = $returnedQuantities[$source->id] ?? '0.00000000';
            $nextQty = bcadd($previousQty, $segment['quantity'], 8);
            $sourceQty = $quantityPools[$source->id]['quantity'];
            if (bccomp($nextQty, $sourceQty, 8) > 0) $this->fail('销售退货累计入库数量超过对应原出库数量。');
            $total = $quantityPools[$source->id]['cost'];
            $before = bccomp($previousQty, '0', 8) === 0 ? '0.0000' : CuttingDecimal::share($total, $sourceQty, $previousQty);
            $derived[$inbound->id] = bcsub(CuttingDecimal::share($total, $sourceQty, $nextQty), $before, 4);
            $returnedQuantities[$source->id] = $nextQty;
        }
        return $derived;
    }

    private function serialMovementCosts(array $movementCosts, array $serialCosts, array $receiptSerialCosts): array
    {
        $this->serialMovementParts = [];
        $this->serialMovementOldParts = $this->partialSerialMovements = [];
        $serialCosts = array_replace($this->purchaseSerialCosts($movementCosts), $serialCosts);
        if ($serialCosts === []) return [];
        $movements = DB::table('erp_inventory_transaction_items')->whereIntegerInRaw('id', array_keys($movementCosts))->where('change_qty', '<', 0)
            ->whereIn('source_type', ['sales_shipment', 'sales_shipment_movement', 'aftersales_reship', 'shipment_packing_operation'])->orderBy('id')->lockForUpdate()->get();
        $lines = DB::table('erp_sales_shipment_lines')->whereIn('id', $movements->whereIn('source_type', ['sales_shipment', 'sales_shipment_movement'])->pluck('source_item_id'))
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $materials = DB::table('erp_shipment_packing_materials')->whereIn('id', $movements->where('source_type', 'shipment_packing_operation')->pluck('source_item_id'))
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $derived = [];
        foreach ($movements as $movement) {
            $line = in_array($movement->source_type, ['sales_shipment', 'sales_shipment_movement'], true) ? $lines->get($movement->source_item_id) : null;
            $material = $movement->source_type === 'shipment_packing_operation' ? $materials->get($movement->source_item_id) : null;
            $snapshot = json_decode(($line?->serial_snapshot ?? $material?->serial_snapshot) ?: '{}', true);
            $ids = $movement->source_type === 'aftersales_reship'
                ? DB::table('erp_aftersales_reship_items')->where('transaction_item_id', $movement->id)->whereNotNull('inventory_serial_id')->orderBy('id')->lockForUpdate()->pluck('inventory_serial_id')->map(fn ($id) => (int) $id)->all()
                : array_map('intval', $snapshot['inventory_serial_ids'] ?? []);
            if ($ids === [] || (array_intersect($ids, array_keys($serialCosts)) === []
                && bccomp((string) $movement->cost_amount, (string) $movementCosts[$movement->id], 4) === 0)) continue;
            if (count($ids) !== count(array_unique($ids)) || bccomp((string) count($ids), $this->absolute((string) $movement->change_qty, 8), 8) > 0) $this->fail('实际出库设备编号超过本次出库数量。');
            $sources = json_decode($line?->packing_source_snapshot ?: '[]', true) ?: [];
            $sortedIds = $ids; sort($sortedIds);
            $originalTotal = $this->absolute((string) $movement->cost_amount);
            $originalQuantity = $this->absolute((string) $movement->change_qty, 8);
            $legacyShare = bcdiv($originalTotal, $originalQuantity, 4);
            $cost = $oldCost = '0.0000';
            foreach ($ids as $serialId) {
                $legacyPart = (string) ($snapshot['actual_material_costs'][$serialId] ??
                    (bccomp($originalQuantity, (string) count($ids), 8) === 0 && $serialId === end($sortedIds)
                        ? bcsub($originalTotal, bcmul($legacyShare, (string) (count($ids) - 1), 4), 4) : $legacyShare));
                $returned = DB::table('erp_sales_return_serial_identities')->where('inventory_serial_id', $serialId)
                    ->whereNotNull('inventory_transaction_item_id')->where('inventory_transaction_item_id', '<', $movement->id)
                    ->orderByDesc('inventory_transaction_item_id')->lockForUpdate()->first();
                if ($returned) {
                    $inbound = DB::table('erp_inventory_transaction_items')->where('id', $returned->inventory_transaction_item_id)->lockForUpdate()->first();
                    if (! $inbound || (int) $inbound->item_id !== (int) $movement->item_id) $this->fail('再次发出的设备缺少原退货入库成本来源。');
                    $part = (string) ($movementCosts[$inbound->id] ?? $inbound->cost_amount);
                    $oldPart = (string) $inbound->cost_amount;
                } else {
                    $source = collect($sources)->first(fn ($row) => (int) ($row['inventory_serial_id'] ?? 0) === $serialId);
                    $receiptId = (int) ($source['source_evidence']['mfg_receipt_id'] ?? 0);
                    $part = $receiptId ? ($receiptSerialCosts[$receiptId][$serialId] ?? null) : ($serialCosts[$serialId] ?? null);
                    $part ??= $this->recordedSerialCost($serialId, $receiptId);
                    // 未受影响且缺逐台标记的旧编号保留原出库份额，不套用本次新池价。
                    if ($part === null && ! array_key_exists($serialId, $serialCosts)) $part = $legacyPart;
                    if ($part === null) $this->fail('发货设备冻结来源缺少对应生产入库的逐台成本，不能改用其他设备均价。');
                    $oldPart = $this->recordedSerialCost($serialId, $receiptId) ?? $legacyPart;
                }
                $oldPart = (string) ($snapshot['actual_material_costs'][$serialId] ?? $oldPart);
                $cost = bcadd($cost, $part, 4);
                $oldCost = bcadd($oldCost, $oldPart, 4);
                $this->serialMovementParts[$movement->id][$serialId] = $part;
                $this->serialMovementOldParts[$movement->id][$serialId] = $oldPart;
            }
            if (bccomp((string) count($ids), $this->absolute((string) $movement->change_qty, 8), 8) < 0) {
                $this->partialSerialMovements[$movement->id] = ['known_quantity' => (string) count($ids),
                    'new_known_cost' => $cost, 'old_known_cost' => $oldCost, 'serial_ids' => $ids];
                continue;
            }
            $derived[$movement->id] = bcsub('0', $cost, 4);
            if ($movement->source_type === 'sales_shipment_movement') {
                $pair = DB::table('erp_inventory_transaction_items')->where('transaction_id', $movement->transaction_id)
                    ->where('source_type', 'sales_shipment_movement')->where('source_item_id', $movement->source_item_id)->where('change_qty', '>', 0)->orderBy('id')->lockForUpdate()->get();
                if ($pair->count() !== 1 || bccomp((string) $pair->first()->change_qty, $this->absolute((string) $movement->change_qty, 8), 8) !== 0) $this->fail('设备待发或返修移位缺少数量守恒的对应入库流水。');
                $derived[$pair->first()->id] = $cost;
            }
        }
        return $derived;
    }

    public function purchaseSerialCosts(array $movementCosts, bool $original = false): array
    {
        if (! $original) $this->purchaseSerialEventParts = [];
        $changed = DB::table('erp_inventory_transaction_items')->whereIntegerInRaw('id', array_keys($movementCosts))
            ->where('source_type', 'purchase_receipt')->where('change_qty', '>', 0)->orderBy('id')->lockForUpdate()->get()
            ->filter(fn ($row) => bccomp((string) $row->cost_amount, (string) $movementCosts[$row->id], 4) !== 0);
        $parts = [];
        foreach ($changed->pluck('source_item_id')->unique() as $sourceItemId) {
            $serials = DB::table('erp_inventory_serials')->where('source_receipt_item_id', $sourceItemId)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($serials->isEmpty()) continue;
            $inbounds = DB::table('erp_inventory_transaction_items as movement')->join('erp_inventory_transactions as transaction', 'transaction.id', '=', 'movement.transaction_id')
                ->where('movement.source_type', 'purchase_receipt')->where('movement.source_item_id', $sourceItemId)->where('movement.change_qty', '>', 0)
                ->where('transaction.posting_status', 'posted')->orderBy('movement.id')->lockForUpdate()->get(['movement.*']);
            $corrections = DB::table('erp_inventory_transaction_items as movement')->join('erp_inventory_transactions as transaction', 'transaction.id', '=', 'movement.transaction_id')
                ->where('movement.source_type', PurchaseReceiptCostAdjustmentService::SOURCE)->where('movement.source_item_id', $sourceItemId)
                ->where('transaction.posting_status', 'posted')->orderBy('movement.id')->lockForUpdate()->get(['movement.*']);
            $events = DB::table('erp_inventory_serial_events')->whereIn('inventory_serial_id', $serials->keys())
                ->where('event_type', 'inventory_posted')->where('document_type', 'purchase_receipt')->orderBy('inventory_serial_id')->orderBy('id')->lockForUpdate()->get();
            if ($events->pluck('inventory_serial_id')->unique()->count() !== $events->count()) $this->fail('采购设备存在重复的原入库身份事件。');
            $locator = fn ($row) => $row->warehouse_id.':'.$row->location_id.':'.$row->batch_no;
            $eventGroups = $events->groupBy($locator);
            foreach ($inbounds->groupBy($locator) as $key => $rows) {
                $cohort = $eventGroups->get($key, collect());
                $quantity = $rows->reduce(fn ($sum, $row) => bcadd($sum, (string) $row->change_qty, 8), '0.00000000');
                if (bccomp($quantity, (string) $cohort->count(), 8) < 0) $this->fail('采购入库库位的设备编号超过实际入库数量。');
                $correctionLeft = $corrections->filter(fn ($row) => $locator($row) === $key)
                    ->reduce(fn ($sum, $row) => bcadd($sum, (string) ($original ? $row->cost_amount : ($movementCosts[$row->id] ?? $row->cost_amount)), 4), '0.0000');
                $quantityLeft = $quantity; $factStates = []; $poolCost = '0.0000'; $poolQuantity = $quantity;
                foreach ($rows as $row) {
                    $correction = CuttingDecimal::share($correctionLeft, $quantityLeft, (string) $row->change_qty);
                    $cost = bcadd((string) ($original ? $row->cost_amount : ($movementCosts[$row->id] ?? $row->cost_amount)), $correction, 4);
                    $factStates[$row->id] = ['cost' => $cost, 'quantity' => (string) $row->change_qty];
                    $poolCost = bcadd($poolCost, $cost, 4); $correctionLeft = bcsub($correctionLeft, $correction, 4);
                    $quantityLeft = bcsub($quantityLeft, (string) $row->change_qty, 8);
                }
                foreach ($cohort->groupBy(fn ($event) => (int) (json_decode($event->event_payload ?: '{}', true)['inventory_transaction_item_id'] ?? 0)) as $factId => $group) {
                    if (! $factId) continue;
                    $state = $factStates[$factId] ?? null;
                    if (! $state || bccomp($state['quantity'], (string) $group->count(), 8) < 0) $this->fail('采购设备原入库流水与逐台身份数量不一致。');
                    $assigned = $this->purchaseEventParts($group, $serials, (int) $sourceItemId, (int) $rows->first()->source_id, $state['cost'], $state['quantity'], $original, $parts);
                    $poolCost = bcsub($poolCost, $assigned, 4); $poolQuantity = bcsub($poolQuantity, (string) $group->count(), 8);
                }
                $unboundEvents = $cohort->filter(fn ($event) => empty(json_decode($event->event_payload ?: '{}', true)['inventory_transaction_item_id']));
                $this->purchaseEventParts($unboundEvents, $serials, (int) $sourceItemId, (int) $rows->first()->source_id, $poolCost, $poolQuantity, $original, $parts);
            }
            if ($events->whereNotIn('inventory_serial_id', array_keys($parts))->isNotEmpty()) $this->fail('采购设备原入库库位缺少对应正入库流水。');
        }
        return $parts;
    }

    private function purchaseEventParts(iterable $events, $serials, int $sourceItemId, int $receiptId, string $cost, string $quantity, bool $original, array &$parts): string
    {
        $allocated = '0.0000';
        foreach ($events as $event) {
            $serial = $serials[$event->inventory_serial_id]; $payload = json_decode($event->event_payload ?: '{}', true);
            if ((int) ($payload['receipt_item_id'] ?? 0) !== $sourceItemId || (int) $event->document_id !== (int) $serial->source_document_id
                || (int) $serial->source_document_id !== $receiptId || bccomp($quantity, '1', 8) < 0) $this->fail('采购设备原入库事件与到货来源行或数量不一致。');
            $part = $original && isset($payload['actual_material_cost']) ? (string) $payload['actual_material_cost'] : CuttingDecimal::share($cost, $quantity, '1');
            $parts[$serial->id] = $part; $allocated = bcadd($allocated, $part, 4);
            $cost = bcsub($cost, $part, 4); $quantity = bcsub($quantity, '1', 8);
            if (bccomp($cost, '0', 4) < 0 || (bccomp($quantity, '0', 8) === 0 && bccomp($cost, '0', 4) !== 0)) $this->fail('采购设备已分配成本超过原入库金额。');
            if (! $original) $this->purchaseSerialEventParts[$event->id] = $part;
        }
        return $allocated;
    }

    public function recordedSerialCost(int $serialId, int $receiptId = 0): ?string
    {
        $serial = DB::table('erp_inventory_serials')->where('id', $serialId)->lockForUpdate()->first();
        if (! $serial) return null;
        if (! $receiptId && $serial->source_document_type === 'purchase_receipt' && $serial->source_receipt_item_id) {
            $events = DB::table('erp_inventory_serial_events')->where('inventory_serial_id', $serialId)->where('event_type', 'inventory_posted')
                ->where('document_type', 'purchase_receipt')->where('document_id', $serial->source_document_id)->orderBy('id')->lockForUpdate()->get();
            if ($events->count() !== 1) return null;
            $payload = json_decode($events->first()->event_payload ?: '{}', true);
            if (isset($payload['actual_material_cost'])) return (string) $payload['actual_material_cost'];
            $line = DB::table('erp_purchase_receipt_items')->where('id', $serial->source_receipt_item_id)->lockForUpdate()->first();
            if (! $line || (int) $line->receipt_id !== (int) $serial->source_document_id) return null;
            $event = $events->first();
            $sourceIds = DB::table('erp_inventory_serials')->where('source_receipt_item_id', $serial->source_receipt_item_id)->pluck('id');
            $ids = DB::table('erp_inventory_serial_events')->whereIn('inventory_serial_id', $sourceIds)->where('event_type', 'inventory_posted')
                ->where('document_type', 'purchase_receipt')->where('document_id', $serial->source_document_id)->where('warehouse_id', $event->warehouse_id)
                ->where('location_id', $event->location_id)->where('batch_no', $event->batch_no)->orderBy('inventory_serial_id')->lockForUpdate()->pluck('inventory_serial_id')->map(fn ($id) => (int) $id)->all();
            $rows = DB::table('erp_inventory_transaction_items as movement')->join('erp_inventory_transactions as transaction', 'transaction.id', '=', 'movement.transaction_id')
                ->where('movement.source_type', 'purchase_receipt')->where('movement.source_item_id', $line->id)->where('movement.source_id', $serial->source_document_id)
                ->where('movement.warehouse_id', $event->warehouse_id)->where('movement.location_id', $event->location_id)->where('movement.batch_no', $event->batch_no)
                ->where('movement.change_qty', '>', 0)->where('transaction.posting_status', 'posted')->orderBy('movement.id')->lockForUpdate()->get(['movement.*']);
            $quantity = $rows->reduce(fn ($sum, $row) => bcadd($sum, (string) $row->change_qty, 8), '0.00000000');
            if (bccomp($quantity, (string) count($ids), 8) < 0 || count($ids) !== count(array_unique($ids))) return null;
            $index = array_search($serialId, $ids, true);
            if ($index === false) return null;
            $total = $rows->reduce(fn ($sum, $row) => bcadd($sum, (string) $row->cost_amount, 4), '0.0000');
            $corrections = DB::table('erp_inventory_transaction_items as movement')->join('erp_inventory_transactions as transaction', 'transaction.id', '=', 'movement.transaction_id')
                ->where('movement.source_type', PurchaseReceiptCostAdjustmentService::SOURCE)->where('movement.source_item_id', $line->id)
                ->where('movement.warehouse_id', $event->warehouse_id)->where('movement.location_id', $event->location_id)->where('movement.batch_no', $event->batch_no)
                ->where('transaction.posting_status', 'posted')->orderBy('movement.id')->lockForUpdate()->get(['movement.cost_amount']);
            $total = $corrections->reduce(fn ($sum, $row) => bcadd($sum, (string) $row->cost_amount, 4), $total);
            foreach ($ids as $id) {
                $share = CuttingDecimal::share($total, $quantity, '1');
                if ($id === $serialId) return $share;
                $total = bcsub($total, $share, 4); $quantity = bcsub($quantity, '1', 8);
            }
            return null;
        }
        if (! $receiptId && $serial->source_document_type !== 'mfg_receipt') return null;
        $receiptId = $receiptId ?: (int) $serial->source_document_id;
        $receipt = DB::table('erp_mfg_receipts')->where('id', $receiptId)->where('status', 'confirmed')->lockForUpdate()->first();
        if (! $receipt) return null;
        $events = DB::table('erp_inventory_serial_events')->where('inventory_serial_id', $serialId)->where('event_type', 'production_receipt_in')
            ->where('document_type', 'mfg_receipt')->where('document_id', $receiptId)->orderBy('id')->lockForUpdate()->get();
        if ($events->count() !== 1) return null;
        $payload = json_decode($events->first()->event_payload ?: '{}', true);
        if (isset($payload['actual_material_cost'])) return (string) $payload['actual_material_cost'];
        $serialNos = json_decode($receipt->serial_nos ?: '[]', true);
        if (! is_array($serialNos) || count($serialNos) !== count(array_unique($serialNos)) || bccomp((string) count($serialNos), (string) $receipt->qty, 8) !== 0) return null;
        sort($serialNos, SORT_STRING); $index = array_search($serial->serial_no, $serialNos, true);
        if ($index === false) return null;
        $share = bcdiv((string) $receipt->cost_amount, (string) count($serialNos), 4);
        return $index === count($serialNos) - 1 ? bcsub((string) $receipt->cost_amount, bcmul($share, (string) (count($serialNos) - 1), 4), 4) : $share;
    }

    private function serialMapChanged(object $movement): bool
    {
        if ($movement->source_type !== 'sales_shipment' || ! isset($this->serialMovementParts[$movement->id])) return false;
        $line = DB::table('erp_sales_shipment_lines')->where('id', $movement->source_item_id)->lockForUpdate()->first(['serial_snapshot']);
        $snapshot = json_decode($line?->serial_snapshot ?: '{}', true);
        $ids = array_map('intval', $snapshot['inventory_serial_ids'] ?? []); sort($ids);
        if ($ids === []) $this->fail('逐台成本重算缺少原发货设备编号。');
        $total = $this->absolute((string) $movement->cost_amount); $share = bcdiv($total, (string) count($ids), 4);
        foreach ($ids as $index => $id) {
            $old = $snapshot['actual_material_costs'][$id] ?? $this->serialMovementOldParts[$movement->id][$id]
                ?? ($index === count($ids) - 1 ? bcsub($total, bcmul($share, (string) (count($ids) - 1), 4), 4) : $share);
            if (bccomp((string) $old, (string) ($this->serialMovementParts[$movement->id][$id] ?? $old), 4) !== 0) return true;
        }
        return false;
    }

    /** 编排器在写入流水新金额之前调用；此处先核对旧来源证明，随后同一事务写入投影。 */
    public function apply(array $movementCosts): array
    {
        if ($movementCosts === []) return [];
        $movements = DB::table('erp_inventory_transaction_items as movement')
            ->join('erp_inventory_transactions as transaction', 'transaction.id', '=', 'movement.transaction_id')
            ->whereIntegerInRaw('movement.id', array_keys($movementCosts))->where('transaction.posting_status', 'posted')
            ->orderBy('movement.id')->lockForUpdate()->get(['movement.*', 'transaction.transaction_type'])
            ->filter(fn ($row) => bccomp((string) $row->cost_amount, (string) $movementCosts[$row->id], 4) !== 0 || $this->serialMapChanged($row))->keyBy('id');
        if ($movements->isEmpty()) return [];
        $evidence = app(ShipmentSourceResolver::class)->repriceLegacyCostEvidence($movements->mapWithKeys(fn ($row) => [$row->id => $movementCosts[$row->id]])->all());
        foreach ($this->purchaseSerialEventParts as $eventId => $cost) {
            $event = DB::table('erp_inventory_serial_events')->where('id', $eventId)->lockForUpdate()->first();
            if (! $event || $event->event_type !== 'inventory_posted' || $event->document_type !== 'purchase_receipt') $this->fail('采购设备原入库成本事件已变化。');
            $before = json_decode($event->event_payload ?: '{}', true); $after = $before;
            $after['actual_material_cost'] = $cost;
            if ($before == $after) continue;
            DB::table('erp_inventory_serial_events')->where('id', $eventId)->update(['event_payload' => json_encode($after, JSON_THROW_ON_ERROR)]);
            $evidence[] = ['table' => 'erp_inventory_serial_events', 'id' => (int) $eventId,
                'before' => ['event_payload' => $before], 'after' => ['event_payload' => $after]];
        }
        $shipmentIds = DB::table('erp_sales_shipment_lines')->whereIn('id', $movements->where('source_type', 'sales_shipment')->pluck('source_item_id'))
            ->pluck('shipment_id')->unique();
        $orderIds = DB::table('erp_sales_shipments')->whereIn('id', $shipmentIds)->pluck('sales_order_id')->all();
        $returnOrderIds = DB::table('erp_sales_return_receipts as receipt')->join('erp_sales_returns as document', 'document.id', '=', 'receipt.sales_return_id')
            ->whereIn('receipt.id', $movements->where('source_type', 'sales_return_receipt')->pluck('source_id'))->pluck('document.sales_order_id')->all();
        $orders = SalesOrder::query()->whereIn('id', array_unique([...$orderIds, ...$returnOrderIds]))->orderBy('id')->lockForUpdate()->get();
        foreach ($movements as $movement) {
            $cost = $this->absolute((string) $movementCosts[$movement->id]);
            if ($movement->source_type === 'sales_shipment' && $movement->transaction_type === 'sales_shipment_outbound') {
                $line = DB::table('erp_sales_shipment_lines')->where('id', $movement->source_item_id)->lockForUpdate()->first();
                if (! $line || (int) $line->item_id !== (int) $movement->item_id
                    || bccomp((string) $line->base_qty, $this->absolute((string) $movement->change_qty, 8), 8) !== 0) $this->fail('发货成本流水与实际发货行不一致。');
                $this->write('erp_sales_shipment_lines', $line, ['cost_amount_snapshot' => $cost,
                    'unit_cost_snapshot' => bcdiv($cost, (string) $line->base_qty, 8)], $evidence);
                $snapshot = json_decode($line->serial_snapshot ?: '{}', true);
                $ids = array_map('intval', $snapshot['inventory_serial_ids'] ?? []);
                if ($ids !== [] && bccomp((string) count($ids), (string) $line->base_qty, 8) <= 0) {
                    $beforeSnapshot = $snapshot; $parts = [];
                    foreach ($ids as $id) $parts[$id] = $this->serialCost($movement, $id, $movementCosts);
                    $snapshot['actual_material_costs'] = $parts;
                    if ($beforeSnapshot != $snapshot) {
                        DB::table('erp_sales_shipment_lines')->where('id', $line->id)->update(['serial_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
                        $evidence[] = ['table' => 'erp_sales_shipment_lines', 'id' => (int) $line->id,
                            'before' => ['serial_snapshot' => $beforeSnapshot], 'after' => ['serial_snapshot' => $snapshot]];
                    }
                }
            }
            if ($movement->source_type === 'shipment_packing_operation') {
                $material = DB::table('erp_shipment_packing_materials')->where('inventory_transaction_item_id', $movement->id)->lockForUpdate()->first();
                if (! $material || (int) $material->id !== (int) $movement->source_item_id) $this->fail('包装材料耗用缺少对应的实际成本快照。');
                $this->write('erp_shipment_packing_materials', $material, ['cost_amount_snapshot' => $cost], $evidence);
            }
            if ($movement->source_type === 'aftersales_reship') {
                $rows = DB::table('erp_aftersales_reship_items')->where('transaction_item_id', $movement->id)->orderBy('id')->lockForUpdate()->get();
                $quantity = $rows->reduce(fn ($sum, $row) => bcadd($sum, (string) $row->qty, 8), '0.00000000');
                if ($rows->isEmpty() || bccomp($quantity, $this->absolute((string) $movement->change_qty, 8), 8) !== 0) $this->fail('售后补发设备与实际出库数量不一致。');
                $knownCost = '0.0000'; $knownQuantity = '0.00000000';
                foreach ($rows as $row) if (isset($this->serialMovementParts[$movement->id][$row->inventory_serial_id])) {
                    $knownCost = bcadd($knownCost, $this->serialMovementParts[$movement->id][$row->inventory_serial_id], 4);
                    $knownQuantity = bcadd($knownQuantity, '1', 8);
                }
                $leftCost = bcsub($cost, $knownCost, 4); $leftQty = bcsub($quantity, $knownQuantity, 8);
                if (bccomp($leftCost, '0', 4) < 0 || bccomp($leftQty, '0', 8) < 0) $this->fail('售后补发已指定设备成本超过本次出库事实。');
                foreach ($rows as $row) {
                    // 多台补发拆行共享一条出库流水；分配总额，避免每台重复挂整笔成本。
                    $known = $this->serialMovementParts[$movement->id][$row->inventory_serial_id] ?? null;
                    $unidentified = bcsub((string) $row->qty, $known === null ? '0' : '1', 8);
                    $poolShare = bccomp($unidentified, '0', 8) > 0 ? CuttingDecimal::share($leftCost, $leftQty, $unidentified) : '0.0000';
                    $share = bcadd($known ?? '0.0000', $poolShare, 4);
                    $this->write('erp_aftersales_reship_items', $row, ['cost_amount' => $share,
                        'unit_cost' => bcadd(bcdiv($share, (string) $row->qty, 8), '0.0000005', 6)], $evidence);
                    $leftCost = bcsub($leftCost, $poolShare, 4); $leftQty = bcsub($leftQty, $unidentified, 8);
                }
            }
        }
        $outbounds = $movements->where('source_type', 'sales_shipment')->where('transaction_type', 'sales_shipment_outbound');
        foreach (DB::table('erp_sales_return_cost_allocations')->whereIn('outbound_transaction_item_id', $outbounds->keys())->orderBy('id')->lockForUpdate()->get() as $allocation) {
            $source = $outbounds[$allocation->outbound_transaction_item_id];
            $cost = $this->absolute((string) $movementCosts[$source->id]); $quantity = $this->absolute((string) $source->change_qty, 8);
            $bound = DB::table('erp_sales_return_serial_identities')->where('cost_allocation_id', $allocation->id)->orderBy('id')->lockForUpdate()->get();
            $allocatedCost = bccomp((string) $allocation->allocated_base_qty, '0', 8) === 0 ? '0.0000'
                : CuttingDecimal::share($cost, $quantity, (string) $allocation->allocated_base_qty);
            if ($bound->isNotEmpty() && bccomp((string) $bound->count(), (string) $allocation->allocated_base_qty, 8) === 0) {
                $allocatedCost = $bound->reduce(fn ($sum, $identity) => bcadd($sum, $this->serialCost($source, (int) $identity->inventory_serial_id, $movementCosts), 4), '0.0000');
            }
            $this->write('erp_sales_return_cost_allocations', $allocation, ['unit_cost_snapshot' => bcdiv($cost, $quantity, 8),
                'cost_amount_snapshot' => $allocatedCost], $evidence);
        }
        foreach (DB::table('erp_sales_return_serial_identities')->whereIn('outbound_transaction_item_id', $outbounds->keys())->orderBy('id')->lockForUpdate()->get() as $identity) {
            $source = $outbounds[$identity->outbound_transaction_item_id];
            $cost = $this->serialCost($source, (int) $identity->inventory_serial_id, $movementCosts);
            $this->write('erp_sales_return_serial_identities', $identity, ['unit_cost_snapshot' => bcdiv($this->absolute((string) $movementCosts[$source->id]), $this->absolute((string) $source->change_qty, 8), 8),
                'cost_amount_snapshot' => $cost], $evidence);
        }
        foreach (DB::table('erp_sales_shipments')->whereIn('id', $shipmentIds)->orderBy('id')->lockForUpdate()->get() as $shipment) {
            $total = DB::table('erp_sales_shipment_lines')->where('shipment_id', $shipment->id)->orderBy('id')->lockForUpdate()->get()
                ->reduce(fn ($sum, $line) => bcadd($sum, (string) $line->cost_amount_snapshot, 4), '0.0000');
            $this->write('erp_sales_shipments', $shipment, ['actual_cost_amount' => $total], $evidence);
        }
        foreach ($orders as $order) {
            $before = (string) $order->actual_sales_cost_amount;
            app(SalesOrderActualCostService::class)->refresh((int) $order->id, $movementCosts);
            $after = (string) $order->fresh()->actual_sales_cost_amount;
            if (bccomp($before, $after, 4) !== 0) $evidence[] = ['table' => 'erp_sales_orders', 'id' => (int) $order->id,
                'before' => ['actual_sales_cost_amount' => $before], 'after' => ['actual_sales_cost_amount' => $after]];
        }
        return $evidence;
    }

    private function serialCost(object $outbound, int $serialId, array $movementCosts): string
    {
        if (isset($this->serialMovementParts[$outbound->id][$serialId])) return $this->serialMovementParts[$outbound->id][$serialId];
        $line = DB::table('erp_sales_shipment_lines')->where('id', $outbound->source_item_id)->first(['serial_snapshot']);
        $snapshot = json_decode($line?->serial_snapshot ?: '{}', true);
        $ids = array_values(array_unique(array_map('intval', $snapshot['inventory_serial_ids'] ?? [])));
        sort($ids);
        $index = array_search($serialId, $ids, true);
        $quantity = $this->absolute((string) $outbound->change_qty, 8);
        if ($index === false || bccomp($quantity, (string) count($ids), 8) < 0) $this->fail('退回设备编号与原出库逐台数量不一致。');
        $total = $this->absolute((string) ($movementCosts[$outbound->id] ?? $outbound->cost_amount));
        $stored = $snapshot['actual_material_costs'] ?? [];
        if (count($stored) === count($ids) && count(array_intersect(array_map('intval', array_keys($stored)), $ids)) === count($ids)
            && (bccomp($quantity, (string) count($ids), 8) === 0
                ? bccomp(array_reduce($stored, fn ($sum, $cost) => bcadd($sum, (string) $cost, 4), '0.0000'), $total, 4) === 0
                : bccomp(array_reduce($stored, fn ($sum, $cost) => bcadd($sum, (string) $cost, 4), '0.0000'), $total, 4) <= 0)) return (string) $stored[$serialId];
        $share = bcdiv($total, $quantity, 4);
        return bccomp($quantity, (string) count($ids), 8) === 0 && $index === count($ids) - 1 ? bcsub($total, bcmul($share, (string) (count($ids) - 1), 4), 4) : $share;
    }

    private function write(string $table, object $row, array $after, array &$evidence): void
    {
        $before = [];
        foreach ($after as $key => $value) $before[$key] = $row->{$key};
        if (collect($after)->every(fn ($value, $key) => $before[$key] !== null && bccomp((string) $before[$key], (string) $value, str_contains($key, 'unit_cost') ? 8 : 4) === 0)) return;
        DB::table($table)->where('id', $row->id)->update($after);
        $evidence[] = ['table' => $table, 'id' => (int) $row->id, 'before' => $before, 'after' => $after];
    }

    private function absolute(string $value, int $scale = 4): string { return bccomp($value, '0', $scale) < 0 ? bcsub('0', $value, $scale) : bcadd($value, '0', $scale); }
    private function fail(string $message): never { throw ValidationException::withMessages(['inventory_cost_amount' => $message]); }
}
