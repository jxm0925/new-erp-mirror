<?php

namespace App\Services\Erp;

use App\Domain\Finance\Money;
use App\Models\Erp\InventoryBalance;
use App\Models\Erp\Item;
use App\Models\Erp\PurchaseLog;
use App\Models\Erp\PurchaseReceiptItem;
use App\Services\Erp\Inventory\StocktakeFreezeGuard;
use App\Services\Erp\Manufacturing\PurchaseCostManufacturingRecalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** 重估实际来源的现行成本；原数量、发生顺序和单据关系始终保留。 */
class PurchaseCostInventoryRecalculator
{
    private ?PurchaseCostManufacturingRecalculator $manufacturing = null;
    private ?PurchaseCostSalesProjectionService $sales = null;
    private array $rows = [];
    private array $items = [];
    private array $physicals = [];
    private array $physicalLinks = [];
    private array $physicalCosts = [];
    private array $serialFlows = [];
    private array $purchaseSerialCosts = [];
    private array $oldPurchaseSerialCosts = [];
    private array $receiptSerialCosts = [];
    private array $newSerialCosts = [];
    private array $oldSerialCosts = [];

    public function plan(PurchaseReceiptItem $line, string $newCost): array
    {
        if (DB::transactionLevel() < 1) return DB::transaction(fn () => $this->plan($line, $newCost));
        $this->rows = $this->items = $this->physicals = $this->physicalLinks = $this->physicalCosts = [];
        $this->serialFlows = $this->purchaseSerialCosts = $this->oldPurchaseSerialCosts = $this->receiptSerialCosts = [];
        $this->newSerialCosts = $this->oldSerialCosts = [];
        $this->manufacturing = app(PurchaseCostManufacturingRecalculator::class);
        $this->sales = app(PurchaseCostSalesProjectionService::class);
        $posted = (bool) $line->is_stock_item_snapshot && $line->receipt?->stock_post_status === 'posted';
        $empty = ['supported' => true, 'reason' => null, 'posted' => $posted, 'version' => 'not_posted',
            'stock_cost' => (string) $line->inventory_cost_amount, 'movement_costs' => [], 'updates' => [], 'impact_summary' => $this->summary([])];
        if (! $posted) return $empty;
        $newCost = Money::normalize($newCost);
        if (bccomp($newCost, '0', 4) < 0) $this->fail('核定后的采购成本不能为负。');
        $this->loadItems([(int) $line->item_id]);
        $source = collect($this->rows)->filter(fn ($row) => $row->source_type === 'purchase_receipt'
            && (int) $row->source_id === (int) $line->receipt_id && (int) $row->source_item_id === (int) $line->id
            && in_array($row->transaction_type, ['purchase_receipt_posting', 'purchase_receipt_quality_release'], true));
        if ($source->isEmpty() || $source->contains(fn ($row) => $row->posting_status !== 'posted' || bccomp((string) $row->change_qty, '0', 8) <= 0))
            $this->fail('找不到该到货明细完整、有效的原入库流水。');
        $unitId = (int) ($line->base_unit_id ?: $this->items[$line->item_id]->unit_id);
        if ($source->contains(fn ($row) => $row->transaction_source_type !== 'purchase_receipt'
            || (int) $row->transaction_source_id !== (int) $line->receipt_id || (int) $row->item_id !== (int) $line->item_id
            || (int) $row->unit_id !== $unitId || (string) $row->batch_no !== (string) $line->batch_no))
            $this->fail('原采购入库的单据来源、物料、批次或基本单位与该到货明细不一致。');
        $qty = $source->reduce(fn ($sum, $row) => bcadd($sum, (string) $row->change_qty, 8), '0');
        if (bccomp($qty, (string) $line->final_stockable_base_qty, 8) !== 0) $this->fail('原采购入库数量与到货明细不一致，不能推测来源。');
        $seed = []; $amountLeft = $newCost; $qtyLeft = $qty;
        $oldSourceCost = $source->reduce(fn ($sum, $row) => bcadd($sum, (string) $row->cost_amount, 4), '0');
        foreach ($source as $row) {
            $seed[$row->id] = CuttingDecimal::share($amountLeft, $qtyLeft, (string) $row->change_qty);
            $amountLeft = bcsub($amountLeft, $seed[$row->id], 4); $qtyLeft = bcsub($qtyLeft, (string) $row->change_qty, 8);
        }
        // 旧实现只允许整批在库补差；折入原来源后把其金额重估为零，保留原流水与审计，避免再次计入。
        foreach ($this->rows as $row) if ($row->source_type === PurchaseReceiptCostAdjustmentService::SOURCE
            && (int) $row->source_item_id === (int) $line->id) {
            $log = PurchaseLog::query()->whereKey($row->source_id)->lockForUpdate()->first();
            if (! $log || $log->action !== PurchaseReceiptCostAdjustmentService::ACTION || (int) $log->target_id !== (int) $line->receipt_id
                || (int) data_get($log->evidence, 'receipt_item_id') !== (int) $line->id || bccomp((string) $row->change_qty, '0', 8) !== 0)
                $this->fail('历史采购成本补差缺少对应的正式更正记录。');
            $seed[$row->id] = '0.0000';
            $oldSourceCost = bcadd($oldSourceCost, (string) $row->cost_amount, 4);
        }
        if (bccomp($oldSourceCost, (string) $line->inventory_cost_amount, 4) !== 0)
            $this->fail('该到货明细的原入库金额及历史财务更正合计与当前采购成本不一致，不能覆盖不明差额。');
        $costs = $this->baseline(); $seen = []; $stable = false;
        // 迭代次数由真实流水节点数决定；相同状态再次出现表示成本依赖成环，不能猜测或截断金额。
        for ($pass = 0; $pass <= count($this->rows) + 1; $pass++) {
            $costs = array_replace($costs, $seed);
            $before = $costs;
            $this->purchaseSerialCosts = $this->sales->purchaseSerialCosts($costs);
            $this->oldPurchaseSerialCosts = $this->sales->purchaseSerialCosts($costs, true);
            $this->manufacturing->setPurchaseSerialCosts($this->purchaseSerialCosts, $this->oldPurchaseSerialCosts);
            $manufacturingCosts = $this->manufacturing->deriveMovementCosts($costs);
            $this->receiptSerialCosts = $this->manufacturing->receiptSerialCosts($costs);
            $this->newSerialCosts = array_replace($this->purchaseSerialCosts, $this->manufacturing->serialCosts($costs));
            $this->oldSerialCosts = array_replace($this->oldPurchaseSerialCosts, $this->manufacturing->oldSerialCosts($costs));
            $serialItemIds = DB::table('erp_inventory_serials')->whereIntegerInRaw('id', array_keys($this->newSerialCosts))->pluck('item_id')->all();
            $this->loadItems($serialItemIds);
            $derived = array_replace($seed, $manufacturingCosts,
                $this->sales->deriveMovementCosts($costs, $this->newSerialCosts, $this->receiptSerialCosts));
            $derived = array_replace($derived, app(PurchaseCostPurchaseProjectionService::class)->deriveMovementCosts($costs));
            $this->discover($derived);
            $costs = array_replace($this->baseline(), $costs, $derived, $seed);
            foreach ($costs as &$amount) $amount = bcadd((string) $amount, '0', 4);
            unset($amount);
            $costs = $this->replay($costs, array_replace($derived, $seed));
            ksort($costs);
            if ($costs === $before) { $stable = true; break; }
            $state = hash('sha256', json_encode($costs, JSON_THROW_ON_ERROR));
            if (isset($seen[$state])) $this->fail('成本来源存在循环依赖，不能自动核定；请先核对相关退回及制造来源。');
            $seen[$state] = true;
        }
        if (! $stable) $this->fail('成本来源重算尚未收敛，请核对制造及退回的实际来源。');
        $this->physicalCosts = $this->derivePhysicalCosts($costs);
        $updates = $this->inventoryUpdates($costs);
        $manufacturingChanges = $this->manufacturing->plannedChanges($costs);
        $purchaseChanges = app(PurchaseCostPurchaseProjectionService::class)->plannedChanges($costs);
        $summary = array_replace($this->summary(array_merge($updates, $manufacturingChanges)), $this->sales->impactSummary($costs));
        $version = hash('sha256', json_encode([$line->id, $this->rows, $updates, $costs, $manufacturingChanges, $purchaseChanges,
            $this->manufacturing->fingerprint($costs), $this->sales->fingerprint($costs)], JSON_THROW_ON_ERROR));
        return array_replace($empty, ['version' => $version, 'movement_costs' => $costs, 'updates' => $updates,
            'receipt_id' => (int) $line->receipt_id, 'receipt_item_id' => (int) $line->id,
            'old_inventory_cost_amount' => (string) $line->inventory_cost_amount, 'new_inventory_cost_amount' => $newCost,
            'impact_summary' => $summary]);
    }

    /** 与财务核定共用事务；先重签依赖旧成本的来源证明，再提交新的成本值。 */
    public function apply(array $plan, PurchaseLog $log): array
    {
        if (DB::transactionLevel() < 1 || ! $this->manufacturing || ! $this->sales) throw new \LogicException('Cost repricing requires the same planned application transaction.');
        if ($plan['posted'] && ($log->action !== PurchaseReceiptCostAdjustmentService::ACTION || $log->target_type !== 'purchase_receipt'
            || (int) $log->target_id !== (int) $plan['receipt_id'] || (int) data_get($log->evidence, 'receipt_item_id') !== (int) $plan['receipt_item_id']
            || bccomp((string) data_get($log->evidence, 'old_inventory_cost_amount', '-1'), $plan['old_inventory_cost_amount'], 4) !== 0
            || bccomp((string) data_get($log->evidence, 'new_inventory_cost_amount', '-1'), $plan['new_inventory_cost_amount'], 4) !== 0))
            $this->fail('成本重算必须绑定该到货明细的正式财务核定记录。');
        $evidence = [];
        if ($plan['posted']) {
            $evidence = array_merge($this->sales->apply($plan['movement_costs']), $this->manufacturing->apply($plan['movement_costs']));
            $evidence = array_merge($evidence, app(PurchaseCostPurchaseProjectionService::class)->apply($plan['movement_costs']));
            foreach ($plan['updates'] as $update) {
                $row = DB::table($update['table'])->where('id', $update['id'])->lockForUpdate()->first();
                foreach ($update['before'] as $field => $value) if ((string) ($row->{$field} ?? '') !== (string) ($value ?? ''))
                    $this->fail('成本来源或库存金额已变化，请刷新后重新核定。');
                DB::table($update['table'])->where('id', $update['id'])->update($update['after']);
                $evidence[] = $update;
            }
        }
        $summary = $plan['impact_summary'];
        return ['evidence' => $evidence, 'impact_summary' => $summary];
    }

    private function loadItems(array $ids): void
    {
        $ids = array_values(array_diff(array_unique(array_map('intval', $ids)), array_keys($this->items)));
        if ($ids === []) return;
        foreach (Item::query()->whereIntegerInRaw('id', $ids)->orderBy('id')->lockForUpdate()->get() as $item) $this->items[$item->id] = $item;
        foreach (DB::table('erp_inventory_transaction_items as m')->join('erp_inventory_transactions as t', 't.id', '=', 'm.transaction_id')
            ->whereIntegerInRaw('m.item_id', $ids)->select('m.*', 't.transaction_type', 't.posting_status',
                't.source_type as transaction_source_type', 't.source_id as transaction_source_id')->orderBy('m.id')->lockForUpdate()->get() as $row)
            $this->rows[$row->id] = $row;
        ksort($this->rows);
        foreach (DB::table('erp_material_physicals')->whereIntegerInRaw('item_id', $ids)->orderBy('id')->lockForUpdate()->get() as $physical)
            $this->physicals[$physical->id] = $physical;
        foreach (DB::table('erp_mfg_cutting_batches')->orderBy('id')->get(['input_lines']) as $batch)
            foreach (json_decode($batch->input_lines ?: '[]', true, 512, JSON_THROW_ON_ERROR) as $input)
                if (! empty($input['physical_material_id']) && ! empty($input['transaction_item_id']))
                    $this->physicalLinks[$input['transaction_item_id']] = [(int) $input['physical_material_id']];
        $linked = [];
        foreach (['erp_purchase_return_item_physicals', 'erp_inventory_adjustment_item_physicals'] as $table)
            foreach (DB::table($table)->whereIntegerInRaw('physical_material_id', array_keys($this->physicals))->whereNotNull('inventory_transaction_item_id')->lockForUpdate()->get() as $link)
                $linked[$link->inventory_transaction_item_id][] = (int) $link->physical_material_id;
        $this->physicalLinks = array_replace($this->physicalLinks, $linked);
        foreach (DB::table('erp_shipment_packing_materials')->whereIntegerInRaw('item_id', $ids)->lockForUpdate()->get() as $material) {
            $physicalIds = json_decode($material->serial_snapshot ?: '{}', true, 512, JSON_THROW_ON_ERROR)['physical_material_ids'] ?? [];
            if ($physicalIds === []) continue;
            foreach ($this->rows as $row) if ($row->source_type === 'shipment_packing_operation' && (int) $row->source_item_id === (int) $material->id)
                $this->physicalLinks[$row->id] = array_map('intval', $physicalIds);
        }
        $this->loadSerialFlows();
    }

    private function loadSerialFlows(): void
    {
        $bySource = [];
        foreach ($this->rows as $row) $bySource[$row->source_type][(int) $row->source_id][] = $row;
        $add = function (object $row, array $ids): void {
            if ($ids !== []) $this->serialFlows[$row->id] = array_values(array_unique(array_map('intval', array_filter($ids))));
        };
        foreach (DB::table('erp_inventory_transfer_lines')->whereIntegerInRaw('item_id', array_keys($this->items))->lockForUpdate()->get() as $line)
            foreach ($bySource['inventory_transfer'][$line->transfer_id] ?? [] as $row) if ((int) $row->source_item_id === (int) $line->id)
                $add($row, json_decode($line->serial_ids ?: '[]', true, 512, JSON_THROW_ON_ERROR));
        foreach (DB::table('erp_inventory_adjustment_serials as s')->join('erp_inventory_adjustment_items as l', 'l.id', '=', 's.adjustment_item_id')
            ->whereIntegerInRaw('l.item_id', array_keys($this->items))->whereNotNull('s.inventory_serial_id')->select('s.*', 'l.adjustment_id')->lockForUpdate()->get()
            ->groupBy(fn ($serial) => $serial->adjustment_id.':'.$serial->adjustment_item_id.':'.$serial->direction) as $group) {
            $serial = $group->first();
            foreach ($bySource['inventory_adjustment'][$serial->adjustment_id] ?? [] as $row)
                if ((int) $row->source_item_id === (int) $serial->adjustment_item_id
                    && (bccomp((string) $row->change_qty, '0', 8) < 0) === ($serial->direction === 'decrease'))
                    $add($row, $group->pluck('inventory_serial_id')->all());
        }
        foreach (DB::table('erp_purchase_return_items')->whereIntegerInRaw('item_id', array_keys($this->items))->lockForUpdate()->get() as $line) {
            $ids = DB::table('erp_purchase_return_item_serials')->where('purchase_return_item_id', $line->id)->orderBy('inventory_serial_id')->lockForUpdate()->pluck('inventory_serial_id')->all();
            if (! $ids && $line->source_inventory_quality_event_id) {
                $id = DB::table('erp_inventory_quality_events')->where('id', $line->source_inventory_quality_event_id)->lockForUpdate()->value('inventory_serial_id');
                if ($id) $ids = [(int) $id];
            }
            foreach ($bySource['purchase_return'][$line->return_id] ?? [] as $row) if ((int) $row->source_item_id === (int) $line->id) $add($row, $ids);
        }
        foreach (DB::table('erp_inventory_quality_events')->whereIntegerInRaw('item_id', array_keys($this->items))->whereNotNull('inventory_serial_id')->lockForUpdate()->get() as $event)
            foreach ($bySource['inventory_quality_event'][$event->id] ?? [] as $row) $add($row, [(int) $event->inventory_serial_id]);
        foreach (DB::table('erp_inventory_serial_events as e')->join('erp_inventory_serials as s', 's.id', '=', 'e.inventory_serial_id')
            ->whereIntegerInRaw('s.item_id', array_keys($this->items))->select('e.*', 's.item_id')->orderBy('e.id')->lockForUpdate()->get() as $event) {
            $payload = json_decode($event->event_payload ?: '{}', true, 512, JSON_THROW_ON_ERROR);
            $exactId = (int) ($payload['inventory_transaction_item_id'] ?? $payload['transaction_item_id'] ?? 0);
            $matches = $exactId && isset($this->rows[$exactId]) ? [$this->rows[$exactId]] : [];
            if (! $matches && in_array($event->event_type, ['inventory_posted', 'production_receipt_in', 'production_material_issued', 'production_material_returned'], true)) {
                foreach ($bySource[$event->document_type][$event->document_id] ?? [] as $row) {
                    if ((int) $row->item_id !== (int) $event->item_id || $this->locator($row) !== $this->locator($event)) continue;
                    if ($event->event_type === 'inventory_posted' && (int) $row->source_item_id !== (int) ($payload['receipt_item_id'] ?? 0)) continue;
                    if (($event->event_type === 'production_material_issued') !== (bccomp((string) $row->change_qty, '0', 8) < 0)) continue;
                    $matches[] = $row;
                }
            }
            // 无逐笔关联且同来源同库位有多次入库时，不擅自把编号分派到某一笔。
            if (count($matches) === 1) {
                $row = $matches[0];
                $this->serialFlows[$row->id] = array_values(array_unique([...($this->serialFlows[$row->id] ?? []), (int) $event->inventory_serial_id]));
            }
        }
        foreach (DB::table('erp_sales_shipment_lines')->whereIntegerInRaw('item_id', array_keys($this->items))->lockForUpdate()->get() as $line) {
            $ids = json_decode($line->serial_snapshot ?: '{}', true, 512, JSON_THROW_ON_ERROR)['inventory_serial_ids'] ?? [];
            foreach ($this->rows as $row) if (in_array($row->source_type, ['sales_shipment', 'sales_shipment_movement'], true) && (int) $row->source_item_id === (int) $line->id) $add($row, $ids);
        }
        foreach (DB::table('erp_sales_return_serial_identities')->whereIntegerInRaw('inventory_transaction_item_id', array_keys($this->rows))->lockForUpdate()->get() as $identity)
            $add($this->rows[$identity->inventory_transaction_item_id], [(int) $identity->inventory_serial_id]);
        foreach (DB::table('erp_shipment_packing_materials')->whereIntegerInRaw('item_id', array_keys($this->items))->lockForUpdate()->get() as $material) {
            $ids = json_decode($material->serial_snapshot ?: '{}', true, 512, JSON_THROW_ON_ERROR)['inventory_serial_ids'] ?? [];
            foreach ($this->rows as $row) if ($row->source_type === 'shipment_packing_operation' && (int) $row->source_item_id === (int) $material->id) $add($row, $ids);
        }
        foreach (DB::table('erp_aftersales_reship_items')->whereIntegerInRaw('transaction_item_id', array_keys($this->rows))->whereNotNull('inventory_serial_id')
            ->lockForUpdate()->get()->groupBy('transaction_item_id') as $id => $group) $add($this->rows[$id], $group->pluck('inventory_serial_id')->all());
        foreach (DB::table('erp_mfg_material_return_lines')->whereIntegerInRaw('return_id', array_keys($bySource['mfg_material_return'] ?? []))
            ->whereNotNull('inventory_serial_id')->lockForUpdate()->get() as $line)
            foreach ($bySource['mfg_material_return'][$line->return_id] ?? [] as $row) if ((int) $row->source_item_id === (int) $line->id) $add($row, [(int) $line->inventory_serial_id]);
        $balances = DB::table('erp_inventory_balances')->whereIntegerInRaw('item_id', array_keys($this->items))->get()->keyBy('id');
        foreach (DB::table('erp_mfg_material_issues')->whereIntegerInRaw('id', array_keys($bySource['mfg_material_issue'] ?? []))->lockForUpdate()->get() as $issue)
            foreach (json_decode($issue->issue_lines ?: '[]', true, 512, JSON_THROW_ON_ERROR) as $line)
                foreach ($bySource['mfg_material_issue'][$issue->id] as $row) if ((int) $row->source_item_id === (int) $line['material_id']) {
                    $ids = [];
                    foreach ($line['picks'] ?? [] as $pick) if (isset($balances[$pick['balance_id']]) && $this->locator($balances[$pick['balance_id']]) === $this->locator($row))
                        $ids = [...$ids, ...($pick['serial_ids'] ?? [])];
                    $add($row, $ids);
                }
    }

    private function discover(array $costs): void
    {
        $unknown = array_diff(array_keys($costs), array_keys($this->rows));
        if ($unknown === []) return;
        $rows = DB::table('erp_inventory_transaction_items')->whereIntegerInRaw('id', $unknown)->get(['id', 'item_id', 'cost_amount']);
        if ($rows->count() !== count($unknown)) $this->fail('派生成本引用的原库存流水不存在，不能推测来源。');
        $ids = [];
        foreach ($rows as $row) if (bccomp((string) $row->cost_amount, (string) $costs[$row->id], 4) !== 0) $ids[] = (int) $row->item_id;
        $this->loadItems($ids);
    }

    private function baseline(): array
    {
        $costs = [];
        foreach ($this->rows as $id => $row) $costs[$id] = bcadd((string) $row->cost_amount, '0', 4);
        return $costs;
    }

    private function replay(array $costs, array $derived): array
    {
        $pools = []; $transfers = []; $residents = []; $carried = []; $physicalCosts = $this->derivePhysicalCosts($costs);
        $partial = $this->sales->partialMovementCosts();
        foreach ($this->rows as $id => $row) {
            if ($row->posting_status !== 'posted') {
                if (bccomp($costs[$id], (string) $row->cost_amount, 4) !== 0) $this->fail('受影响的库存流水已冲销或状态异常。');
                continue;
            }
            $key = $this->locator($row); $pool = $pools[$key] ?? ['qty' => '0', 'old' => '0', 'new' => '0'];
            $quantity = (string) $row->change_qty;
            $transferKey = $row->source_type.':'.$row->source_id.':'.$row->source_item_id.':'.$row->item_id;
            $transfer = in_array($row->source_type, ['inventory_transfer', 'material_physical_transfer', 'sales_shipment_movement'], true);
            $serialIds = $this->serialFlows[$id] ?? ($partial[$id]['serial_ids'] ?? []);
            sort($serialIds);
            if (bccomp($quantity, '0', 8) < 0) {
                $take = bcsub('0', $quantity, 8);
                $physicalIds = $this->physicalLinks[$id] ?? [];
                if (in_array($row->source_type, ['material_physical_transfer', 'material_physical_disposal'], true)) $physicalIds = [(int) $row->source_item_id];
                if ($physicalIds !== []) {
                    $total = '0'; $oldTotal = '0';
                    foreach ($physicalIds as $physicalId) {
                        if (! isset($physicalCosts[$physicalId])) $this->fail('实物 #'.$physicalId.' 缺少原始入库来源，不能推测成本。');
                        $total = bcadd($total, $physicalCosts[$physicalId], 4);
                        $oldTotal = bcadd($oldTotal, (string) $this->physicals[$physicalId]->total_cost, 4);
                    }
                    if (bccomp($oldTotal, $total, 4) !== 0 && bccomp(bcsub('0', (string) $row->cost_amount, 4), $oldTotal, 4) !== 0)
                        $this->fail('实物出库流水 #'.$id.' 与逐件原成本不一致。');
                    $costs[$id] = bcsub((string) $row->cost_amount, bcsub($total, $oldTotal, 4), 4);
                } elseif (($serialIds !== [] || (! empty($residents[$key]) && ! array_key_exists($id, $derived)))
                    && $this->items[$row->item_id]->materialManagementMode() !== 'physical') {
                    $serialCost = $this->serialOutbound($row, $pool, $take, $serialIds, $residents[$key] ?? [], $partial[$id] ?? null);
                    if ($serialCost !== null) $costs[$id] = bcsub('0', $serialCost, 4);
                    elseif (! array_key_exists($id, $derived) && bccomp($pool['new'], $pool['old'], 4) !== 0)
                        $costs[$id] = bcsub('0', CuttingDecimal::share($pool['new'], $pool['qty'], $take), 4);
                } elseif (! array_key_exists($id, $derived)) {
                    // 只重放受影响的原加权池，最后一次耗用带走尾差；零金额同样按真实数量流转。
                    if (bccomp($pool['new'], $pool['old'], 4) !== 0 && bccomp($take, $pool['qty'], 8) > 0)
                        $this->fail('库存流水 #'.$id.' 的实际来源数量不足，不能推测历史库存。');
                    $costs[$id] = bccomp($pool['new'], $pool['old'], 4) === 0 ? (string) $row->cost_amount :
                        bcsub('0', CuttingDecimal::share($pool['new'], $pool['qty'], $take), 4);
                }
                if (bccomp($costs[$id], (string) $row->cost_amount, 4) !== 0 && bccomp($take, $pool['qty'], 8) > 0)
                    $this->fail('库存流水 #'.$id.' 的实际来源数量不足，不能推测历史库存。');
                if (bccomp($costs[$id], (string) $row->cost_amount, 4) !== 0 && bccomp($costs[$id], '0', 4) > 0)
                    $this->fail('库存流水 #'.$id.' 重估后出库金额方向异常。');
                if ($transfer) $transfers[$transferKey] = ['cost' => bcsub('0', $costs[$id], 4), 'qty' => $take,
                    'old' => bcsub('0', (string) $row->cost_amount, 4), 'changed' => bccomp($costs[$id], (string) $row->cost_amount, 4) !== 0];
                foreach ($serialIds as $serialId) {
                    if (isset($residents[$key][$serialId])) $carried[$transferKey][$serialId] = $residents[$key][$serialId];
                    unset($residents[$key][$serialId]);
                }
            } elseif (bccomp($quantity, '0', 8) > 0 && $transfer) {
                $out = $transfers[$transferKey] ?? null;
                if (! $out || bccomp($out['qty'], $quantity, 8) !== 0) {
                    if (($out['changed'] ?? false) || bccomp($costs[$id], (string) $row->cost_amount, 4) !== 0)
                        $this->fail('调拨流水 #'.$id.' 缺少等量的正式调出来源。');
                } elseif ($out['changed'] || bccomp($costs[$id], (string) $row->cost_amount, 4) !== 0) {
                    if (bccomp((string) $row->cost_amount, $out['old'], 4) !== 0) $this->fail('调拨流水 #'.$id.' 的原调入、调出金额不一致。');
                    $costs[$id] = $out['cost'];
                }
            }
            $pool['qty'] = bcadd($pool['qty'], $quantity, 8);
            $pool['old'] = bcadd($pool['old'], (string) $row->cost_amount, 4);
            $pool['new'] = bcadd($pool['new'], $costs[$id], 4);
            if (bccomp($pool['new'], $pool['old'], 4) !== 0 && bccomp($pool['new'], '0', 4) < 0)
                $this->fail('库存流水 #'.$id.' 重估后库存成本为负。');
            $pools[$key] = $pool;
            if (bccomp($quantity, '0', 8) > 0 && $serialIds !== []) {
                $parts = $transfer ? ($carried[$transferKey] ?? []) : $this->inboundSerialParts($row, $serialIds, $costs[$id]);
                foreach ($parts as $serialId => $part) $residents[$key][$serialId] = $part;
            }
        }
        return $costs;
    }

    private function inboundSerialParts(object $row, array $ids, string $newAmount): array
    {
        $parts = []; $oldLeft = (string) $row->cost_amount; $newLeft = $newAmount; $qty = (string) $row->change_qty;
        foreach ($ids as $id) {
            if (bccomp($qty, '1', 8) < 0) $this->fail('库存流水 #'.$row->id.' 的编号数量超过实际入库数量。');
            if ($row->source_type === 'mfg_receipt') {
                $old = $this->sales->recordedSerialCost($id, (int) $row->source_id);
                $new = $this->receiptSerialCosts[$row->source_id][$id] ?? (bccomp($newAmount, (string) $row->cost_amount, 4) === 0 ? $old : null);
            } elseif ($row->source_type === 'purchase_receipt') {
                $old = $this->oldPurchaseSerialCosts[$id] ?? $this->sales->recordedSerialCost($id);
                $new = $this->purchaseSerialCosts[$id] ?? (bccomp($newAmount, (string) $row->cost_amount, 4) === 0 ? $old : null);
            } else { $old = $new = null; }
            $old ??= CuttingDecimal::share($oldLeft, $qty, '1');
            $new ??= CuttingDecimal::share($newLeft, $qty, '1');
            $parts[$id] = ['old' => $old, 'new' => $new];
            $oldLeft = bcsub($oldLeft, $old, 4); $newLeft = bcsub($newLeft, $new, 4); $qty = bcsub($qty, '1', 8);
        }
        return $parts;
    }

    private function serialOutbound(object $row, array $pool, string $take, array $ids, array $resident, ?array $partial): ?string
    {
        $changed = bccomp($pool['new'], $pool['old'], 4) !== 0 || $partial !== null;
        foreach ($resident as $part) $changed = $changed || bccomp($part['new'], $part['old'], 4) !== 0;
        foreach ($ids as $id) {
            if (! isset($resident[$id])) {
                $old = $this->oldSerialCosts[$id] ?? $this->sales->recordedSerialCost($id);
                $new = $this->newSerialCosts[$id] ?? $old;
                if ($old !== null && $new !== null) $resident[$id] = ['old' => $old, 'new' => $new];
            }
            if (isset($resident[$id])) $changed = $changed || bccomp($resident[$id]['new'], $resident[$id]['old'], 4) !== 0;
        }
        if (! $changed) return null;
        if (bccomp((string) count($ids), $take, 8) > 0) $this->fail('库存流水 #'.$row->id.' 的明确编号超过实际出库数量。');
        $knownTake = '0';
        foreach ($ids as $id) {
            if (! isset($resident[$id])) $this->fail('库存流水 #'.$row->id.' 的编号 #'.$id.' 缺少实际入库成本。');
            $knownTake = bcadd($knownTake, $resident[$id]['new'], 4);
        }
        $selectedResidentCost = $knownTake;
        if ($partial !== null) $knownTake = (string) $partial['new_known_cost'];
        $unknownQty = bcsub($take, (string) count($ids), 8);
        if (bccomp($unknownQty, '0', 8) === 0) return $knownTake;
        $knownPool = '0'; foreach ($resident as $part) $knownPool = bcadd($knownPool, $part['new'], 4);
        if ($partial !== null) $knownPool = bcadd(bcsub($knownPool, $selectedResidentCost, 4), $knownTake, 4);
        $remainingQty = bcsub($pool['qty'], (string) count($resident), 8); $remainingCost = bcsub($pool['new'], $knownPool, 4);
        if (bccomp($remainingQty, $unknownQty, 8) < 0 || bccomp($remainingCost, '0', 4) < 0)
            $this->fail('库存流水 #'.$row->id.' 的明确编号与无编号剩余池无法核平。');
        return bcadd($knownTake, CuttingDecimal::share($remainingCost, $remainingQty, $unknownQty), 4);
    }

    private function derivePhysicalCosts(array $costs): array
    {
        $groups = []; $result = [];
        foreach ($this->physicals as $physical) {
            $result[$physical->id] = (string) $physical->total_cost;
            if ($physical->source_transaction_item_id) $groups[$physical->source_transaction_item_id][] = $physical;
        }
        foreach ($groups as $sourceId => $physicals) {
            $source = $this->rows[$sourceId] ?? null;
            if (! $source || ! isset($costs[$sourceId])) continue;
            if (bccomp($costs[$sourceId], (string) $source->cost_amount, 4) === 0) continue;
            $qty = (string) $source->change_qty; $amount = $costs[$sourceId];
            if (bccomp($qty, '0', 8) <= 0) $this->fail('实物来源流水 #'.$sourceId.' 不是有效入库。');
            foreach ($physicals as $physical) {
                $physicalQty = app(MaterialPhysicalService::class)->inventoryQuantity($this->items[$physical->item_id], $physical);
                if (bccomp($physicalQty, $qty, 8) > 0) $this->fail('实物来源流水 #'.$sourceId.' 的逐件数量超过原入库数量。');
                $result[$physical->id] = CuttingDecimal::share($amount, $qty, $physicalQty);
                $amount = bcsub($amount, $result[$physical->id], 4); $qty = bcsub($qty, $physicalQty, 8);
            }
            if (bccomp($qty, '0', 8) !== 0 || bccomp($amount, '0', 4) !== 0) $this->fail('实物来源流水 #'.$sourceId.' 的逐件数量或成本不完整。');
        }
        return $result;
    }

    private function inventoryUpdates(array $costs): array
    {
        $updates = []; $totals = []; $affected = [];
        foreach ($this->rows as $id => $row) {
            if ($row->posting_status !== 'posted') continue;
            $key = $this->locator($row);
            $totals[$key] ??= ['qty' => '0', 'old' => '0', 'new' => '0'];
            foreach (['qty' => $row->change_qty, 'old' => $row->cost_amount, 'new' => $costs[$id]] as $field => $amount)
                $totals[$key][$field] = bcadd($totals[$key][$field], (string) $amount, $field === 'qty' ? 8 : 4);
            if (bccomp($costs[$id], (string) $row->cost_amount, 4) === 0) continue;
            $affected[$key] = true;
            $quantity = bccomp((string) $row->change_qty, '0', 8) < 0 ? bcsub('0', (string) $row->change_qty, 8) : (string) $row->change_qty;
            $unit = bccomp($quantity, '0', 8) > 0 ? bcdiv(ltrim($costs[$id], '-'), $quantity, 8) : '0.00000000';
            $updates[] = $this->update('erp_inventory_transaction_items', $row, ['cost_amount' => $costs[$id], 'unit_cost' => $unit]);
        }
        foreach (InventoryBalance::query()->whereIntegerInRaw('item_id', array_keys($this->items))->orderBy('id')->lockForUpdate()->get() as $balance) {
            $total = $totals[$this->locator($balance)] ?? ['qty' => '0', 'old' => '0', 'new' => '0'];
            if (! isset($affected[$this->locator($balance)])) continue;
            if (bccomp($total['qty'], (string) $balance->quantity_on_hand, 8) !== 0 || bccomp($total['old'], (string) $balance->inventory_value, 4) !== 0)
                $this->fail('库存余额 #'.$balance->id.' 与实际流水数量或金额不一致，不能覆盖历史差异。');
            if ($this->items[$balance->item_id]->materialManagementMode() === 'physical') {
                $holding = DB::table('erp_material_holdings')->where('inventory_balance_id', $balance->id)->lockForUpdate()->first();
                if (! $holding || $holding->position_type !== 'WAREHOUSE' || $holding->quantity !== null || $holding->total_cost !== null)
                    $this->fail('库存余额 #'.$balance->id.' 的实物持有金额权威不一致。');
                $physicalQty = $physicalValue = '0';
                foreach ($this->physicals as $physical) if ((int) $physical->current_holding_id === (int) $holding->id) {
                    $physicalQty = bcadd($physicalQty, app(MaterialPhysicalService::class)->inventoryQuantity($this->items[$physical->item_id], $physical), 8);
                    $physicalValue = bcadd($physicalValue, $this->physicalCosts[$physical->id], 4);
                }
                if (bccomp($physicalQty, $total['qty'], 8) !== 0 || bccomp($physicalValue, $total['new'], 4) !== 0)
                    $this->fail('库存余额 #'.$balance->id.' 的现存实物数量或重估金额无法核平。');
            }
            if (bccomp($total['new'], $total['old'], 4) === 0) continue;
            app(StocktakeFreezeGuard::class)->assertWritable(new \App\Models\Erp\InventoryTransaction(['transaction_type' => 'purchase_cost_recalculation']),
                ['item_id' => $balance->item_id, 'warehouse_id' => $balance->warehouse_id, 'location_id' => $balance->location_id, 'batch_no' => $balance->batch_no]);
            if (bccomp($total['new'], '0', 4) < 0 || (bccomp($total['qty'], '0', 8) === 0 && bccomp($total['new'], '0', 4) !== 0))
                $this->fail('库存余额 #'.$balance->id.' 重估后金额与剩余数量不守恒。');
            $average = bccomp($total['qty'], '0', 8) > 0 ? bcdiv($total['new'], $total['qty'], 8) : '0.00000000';
            $updates[] = $this->update('erp_inventory_balances', $balance, ['inventory_value' => $total['new'], 'average_unit_cost' => $average]);
        }
        foreach ($this->physicalCosts as $id => $cost) if (bccomp($cost, (string) $this->physicals[$id]->total_cost, 4) !== 0) {
            $physical = $this->physicals[$id];
            $updates[] = $this->update('erp_material_physicals', $physical, ['total_cost' => $cost,
                'business_version' => (int) $physical->business_version + 1]);
            $holding = DB::table('erp_material_holdings')->where('id', $physical->current_holding_id)->lockForUpdate()->first();
            if (! $holding) $this->fail('实物 #'.$id.' 缺少当前持有位置。');
            if (in_array($holding->position_type, ['DISPOSAL', 'ADJUSTMENT', 'SUPPLIER_RETURN', 'SHIPMENT_PACKING'], true)) {
                $updates[] = $this->update('erp_material_holdings', $holding, ['total_cost' => $cost,
                    'business_version' => (int) $holding->business_version + 1]);
                foreach (DB::table('erp_material_movements')->where('target_holding_id', $holding->id)
                    ->whereIn('action', ['DISPOSE', 'ADJUST_OUT', 'PURCHASE_RETURN', 'PACKING_CONSUME'])->lockForUpdate()->get() as $movement)
                    $updates[] = $this->update('erp_material_movements', $movement, ['total_cost' => $cost]);
            }
        }
        foreach (DB::table('erp_material_physical_transfers')->whereIntegerInRaw('physical_material_id', array_keys($this->physicalCosts))->orderBy('id')->lockForUpdate()->get() as $transfer) {
            $cost = $this->physicalCosts[$transfer->physical_material_id];
            if (bccomp($cost, (string) $transfer->total_cost, 4) === 0) continue;
            $after = ['total_cost' => $cost];
            if ($transfer->status === 'IN_TRANSIT') {
                $after['expected_version'] = (int) $transfer->expected_version + 1;
                $after['business_version'] = (int) $transfer->business_version + 1;
            }
            $updates[] = $this->update('erp_material_physical_transfers', $transfer, $after);
            $transactionIds = array_filter([$transfer->inventory_transaction_id, $transfer->outbound_transaction_id, $transfer->inbound_transaction_id]);
            foreach (DB::table('erp_material_movements')->whereIntegerInRaw('inventory_transaction_id', $transactionIds)
                ->whereIn('action', ['TRANSFER', 'TRANSFER_OUT', 'TRANSFER_IN'])->lockForUpdate()->get() as $movement)
                $updates[] = $this->update('erp_material_movements', $movement, ['total_cost' => $cost]);
            if ($transfer->status === 'IN_TRANSIT') {
                $holding = DB::table('erp_material_holdings')->where('id', $transfer->transit_holding_id)->lockForUpdate()->first();
                if (! $holding || $holding->position_type !== 'TRANSIT') $this->fail('实物在途持有来源缺失。');
                $updates[] = $this->update('erp_material_holdings', $holding, ['total_cost' => $cost, 'business_version' => (int) $holding->business_version + 1]);
            }
        }
        foreach (DB::table('erp_material_physical_disposals')->whereIntegerInRaw('physical_material_id', array_keys($this->physicalCosts))->lockForUpdate()->get() as $disposal) {
            $cost = $this->physicalCosts[$disposal->physical_material_id];
            if (bccomp($cost, (string) $disposal->total_cost, 4) !== 0)
                $updates[] = $this->update('erp_material_physical_disposals', $disposal, ['total_cost' => $cost]);
        }
        foreach (DB::table('erp_inventory_transfer_lines')->whereIntegerInRaw('item_id', array_keys($this->items))->orderBy('id')->lockForUpdate()->get() as $transfer) {
            $snapshot = json_decode($transfer->source_snapshot ?: '{}', true, 512, JSON_THROW_ON_ERROR); $id = $snapshot['outbound_transaction_item_id'] ?? null;
            if (! $id || ! isset($costs[$id]) || bccomp(ltrim($costs[$id], '-'), (string) ($transfer->cost_amount ?? '0'), 4) === 0) continue;
            $cost = bcsub('0', $costs[$id], 4); $snapshot['unit_cost'] = bcdiv($cost, (string) $transfer->quantity, 8);
            if (isset($snapshot['inbound_facts'])) foreach ($snapshot['inbound_facts'] as &$fact) {
                if (isset($costs[$fact['id'] ?? 0])) $fact['cost_amount'] = $costs[$fact['id']];
            }
            unset($fact);
            $updates[] = $this->update('erp_inventory_transfer_lines', $transfer, ['cost_amount' => $cost, 'source_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
        }
        return $updates;
    }

    private function locator(object $row): string { return $row->item_id.':'.$row->warehouse_id.':'.$row->location_id.':'.$row->batch_no; }

    private function update(string $table, object $row, array $after): array
    {
        foreach (['unit_cost', 'average_unit_cost'] as $field) if (isset($after[$field])
            && bccomp((string) $after[$field], '9999999999.99999999', 8) > 0)
            $this->fail('重估后的单位成本超过系统金额精度范围，请核对实际金额与原数量。');
        $before = []; foreach ($after as $field => $_) $before[$field] = $row->{$field};
        return ['table' => $table, 'id' => (int) $row->id, 'before' => $before, 'after' => $after];
    }

    private function summary(array $changes): array
    {
        $tables = ['inventory_balance_count' => 'erp_inventory_balances', 'work_order_count' => 'erp_mfg_work_orders',
            'finished_receipt_count' => 'erp_mfg_receipts', 'sales_shipment_count' => 'erp_sales_shipments',
            'sales_return_count' => 'erp_sales_returns', 'packing_material_count' => 'erp_shipment_packing_materials',
            'aftersales_reship_count' => 'erp_aftersales_reship_orders'];
        $out = []; foreach ($tables as $key => $table) $out[$key] = count(array_unique(array_column(array_filter($changes, fn ($row) => $row['table'] === $table), 'id')));
        $workOrders = [];
        foreach (['erp_mfg_material_issues', 'erp_mfg_material_returns', 'erp_mfg_cutting_batches', 'erp_mfg_cutting_allocations',
            'erp_mfg_cutting_run_allocations', 'erp_mfg_receipts'] as $table) {
            $ids = array_column(array_filter($changes, fn ($row) => $row['table'] === $table), 'id');
            if ($ids !== []) $workOrders = array_merge($workOrders, DB::table($table)->whereIntegerInRaw('id', $ids)->whereNotNull('work_order_id')->pluck('work_order_id')->all());
        }
        $out['work_order_count'] = count(array_unique($workOrders));
        return $out;
    }

    private function fail(string $message): never { throw ValidationException::withMessages(['inventory_cost_amount' => $message]); }
}
