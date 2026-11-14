<?php

namespace App\Services\Erp;

use App\Domain\Finance\Money;
use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventoryTransaction;
use App\Models\Erp\Item;
use App\Models\Erp\PurchaseLog;
use App\Models\Erp\PurchaseReceiptItem;
use App\Services\Erp\Inventory\InventoryPostingKernel;
use App\Services\Erp\Inventory\StocktakeFreezeGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** 财务例外更正只补仍完整在库且独占来源的采购成本，不重写原入库事实。 */
class PurchaseReceiptCostAdjustmentService
{
    public const TYPE = 'purchase_receipt_cost_adjustment';
    public const SOURCE = 'purchase_finance_correction';
    public const ACTION = 'finance_actual_amount_corrected';

    public function __construct(
        private readonly InventoryPostingKernel $kernel,
        private readonly MaterialPhysicalService $physicals,
        private readonly StocktakeFreezeGuard $freeze,
    )
    {
    }

    public function preview(PurchaseReceiptItem $line): array
    {
        $posted = (bool) $line->is_stock_item_snapshot && $line->receipt?->stock_post_status === 'posted';
        $result = ['supported' => true, 'reason' => null, 'stock_cost' => (string) $line->inventory_cost_amount,
            'posted' => $posted, 'version' => ''];
        if (! $posted) return $result;
        try {
            $plan = DB::transaction(fn () => $this->plan($line));
            $result['stock_cost'] = $plan['cost'];
            $result['version'] = $plan['version'];
        } catch (ValidationException $exception) {
            $result['supported'] = false;
            $result['reason'] = collect($exception->errors())->flatten()->first();
        }
        return $result;
    }

    /** 调用方必须先锁到货单并创建更正日志；金额事实、补差和日志随同一个事务提交。 */
    public function apply(PurchaseReceiptItem $line, string $oldCost, string $newCost, int $logId, ?int $operatorId): ?InventoryTransaction
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Purchase cost correction requires the receipt application transaction.');
        if (! $line->is_stock_item_snapshot || $line->receipt?->stock_post_status !== 'posted') return null;
        $oldCost = Money::normalize($oldCost);
        $newCost = Money::normalize($newCost);
        if (bccomp($oldCost, '0', 4) < 0 || bccomp($newCost, '0', 4) < 0) $this->fail('采购库存成本不能为负。');
        if (bccomp($oldCost, $newCost, 4) === 0) return null;

        $log = PurchaseLog::query()->lockForUpdate()->findOrFail($logId);
        $evidence = $log->evidence ?? [];
        if ($log->action !== self::ACTION || $log->target_type !== 'purchase_receipt' || (int) $log->target_id !== (int) $line->receipt_id
            || (int) ($evidence['receipt_item_id'] ?? 0) !== (int) $line->id
            || bccomp((string) ($evidence['old_inventory_cost_amount'] ?? '-1'), $oldCost, 4) !== 0
            || bccomp((string) ($evidence['new_inventory_cost_amount'] ?? '-1'), $newCost, 4) !== 0) {
            $this->fail('采购成本更正必须绑定本行正式财务更正日志及原、新金额。');
        }
        $existing = InventoryTransaction::query()->where('source_type', self::SOURCE)->where('source_id', $log->id)
            ->where('transaction_type', self::TYPE)->first();
        if ($existing) return $existing;

        $plan = $this->plan($line);
        if (bccomp($plan['cost'], $oldCost, 4) !== 0) $this->fail('库存成本与更正前金额不一致，请刷新核对，不能覆盖历史差额。');
        $remainingCost = $newCost;
        $remainingQty = $plan['quantity'];
        $adjustments = [];
        foreach ($plan['balances'] as $row) {
            $target = CuttingDecimal::share($remainingCost, $remainingQty, $row['quantity']);
            $row['new_value'] = $target;
            $row['cost_amount'] = bcsub($target, $row['old_value'], 4);
            $physicalCost = $target;
            $physicalQty = $row['quantity'];
            foreach ($row['physicals'] as &$physical) {
                $physical['new_cost'] = CuttingDecimal::share($physicalCost, $physicalQty, $physical['quantity']);
                $physicalCost = bcsub($physicalCost, $physical['new_cost'], 4);
                $physicalQty = bcsub($physicalQty, $physical['quantity'], 8);
            }
            unset($physical);
            $adjustments[] = $row;
            $remainingCost = bcsub($remainingCost, $target, 4);
            $remainingQty = bcsub($remainingQty, $row['quantity'], 8);
        }
        if (bccomp($remainingCost, '0', 4) !== 0 || bccomp($remainingQty, '0', 8) !== 0) {
            throw new \LogicException('Purchase cost correction allocation did not conserve the total.');
        }
        // 守卫逐行核对已落库的金额方案，不能靠调用参数声明绕过实物身份保护。
        $log->update(['evidence' => array_replace($evidence, ['inventory_adjustments' => $adjustments])]);
        $first = $adjustments[0];
        $transaction = $this->kernel->begin([
            'transaction_type' => self::TYPE, 'source_type' => self::SOURCE, 'source_id' => $log->id,
            'source_no' => $line->receipt->receipt_no, 'posting_status' => 'posted',
            'warehouse_id' => $first['warehouse_id'], 'location_id' => $first['location_id'],
            'transaction_date' => $evidence['business_date'] ?? now()->toDateString(), 'posted_by' => $operatorId, 'posted_at' => now(),
            'created_by' => $operatorId, 'remark' => '采购实际金额更正，仅调整在库成本',
        ]);
        foreach ($adjustments as $row) {
            if (bccomp($row['cost_amount'], '0', 4) === 0) continue;
            $this->kernel->apply($transaction, [
                'item_id' => $line->item_id, 'warehouse_id' => $row['warehouse_id'], 'location_id' => $row['location_id'],
                'batch_no' => $row['batch_no'], 'unit_id' => $row['unit_id'], 'material_lot_id' => $row['material_lot_id'],
                'change_qty' => '0', 'cost_amount' => $row['cost_amount'], 'cost_source_type' => self::SOURCE,
                'source_type' => self::SOURCE, 'source_id' => $log->id, 'source_item_id' => $line->id,
                'remark' => '采购实际金额更正：保留原入库数量及流水',
            ]);
        }
        return $transaction;
    }

    private function plan(PurchaseReceiptItem $line): array
    {
        $item = Item::query()->whereKey($line->item_id)->lockForUpdate()->firstOrFail();
        $source = DB::table('erp_inventory_transaction_items as movement')
            ->join('erp_inventory_transactions as tx', 'tx.id', '=', 'movement.transaction_id')
            ->where('movement.source_type', 'purchase_receipt')->where('movement.source_id', $line->receipt_id)
            ->where('movement.source_item_id', $line->id)
            ->whereIn('tx.transaction_type', ['purchase_receipt_posting', 'purchase_receipt_quality_release'])
            ->select('movement.*', 'tx.posting_status')->orderBy('movement.id')->lockForUpdate()->get();
        if ($source->isEmpty() || $source->contains(fn ($row) => $row->posting_status !== 'posted' || (int) $row->item_id !== (int) $line->item_id
            || (string) $row->batch_no !== (string) $line->batch_no || bccomp((string) $row->change_qty, '0', 8) <= 0)) {
            $this->fail('找不到本行完整、有效的原采购入库事实，不能自动补差。');
        }
        $context = new InventoryTransaction(['source_type' => self::SOURCE, 'source_id' => 0, 'transaction_type' => self::TYPE]);
        foreach ($source->unique(fn ($row) => $row->warehouse_id.':'.$row->location_id) as $row) {
            $this->freeze->assertWritable($context, ['item_id' => $line->item_id, 'warehouse_id' => $row->warehouse_id,
                'location_id' => $row->location_id, 'batch_no' => $row->batch_no]);
        }
        // shortcut: 仅支持未出库且整批独占来源的库存；存在耗用、调拨或混批时须另行建设来源重估。
        $movements = DB::table('erp_inventory_transaction_items as movement')
            ->join('erp_inventory_transactions as tx', 'tx.id', '=', 'movement.transaction_id')
            ->where('movement.item_id', $line->item_id)->where('movement.batch_no', $line->batch_no)
            ->select('movement.*', 'tx.transaction_type', 'tx.posting_status', 'tx.source_type as transaction_source_type', 'tx.source_id as transaction_source_id')
            ->orderBy('movement.id')->lockForUpdate()->get();
        $sourceIds = $source->pluck('id')->all();
        $logs = [];
        foreach ($movements as $movement) {
            if ($movement->posting_status !== 'posted') $this->fail('该批次包含已冲销或状态异常的流水，不能自动补差。');
            if (in_array($movement->id, $sourceIds)) continue;
            if ($movement->source_type !== self::SOURCE || $movement->transaction_source_type !== self::SOURCE
                || (int) $movement->source_id !== (int) $movement->transaction_source_id
                || (int) $movement->source_item_id !== (int) $line->id || $movement->transaction_type !== self::TYPE
                || bccomp((string) $movement->change_qty, '0', 8) !== 0) {
                $this->fail('该批次已耗用、退货、调拨或混入其他来源，当前不能自动更正在库成本。');
            }
            $log = $logs[$movement->source_id] ??= PurchaseLog::query()->lockForUpdate()->find($movement->source_id);
            if (! $log || $log->action !== self::ACTION || $log->target_type !== 'purchase_receipt'
                || (int) $log->target_id !== (int) $line->receipt_id || (int) data_get($log->evidence, 'receipt_item_id') !== (int) $line->id) {
                $this->fail('历史库存补差缺少对应的正式财务更正记录。');
            }
        }
        foreach ($logs as $id => $log) {
            $delta = $movements->where('source_type', self::SOURCE)->where('source_id', $id)
                ->reduce(fn ($sum, $row) => bcadd($sum, (string) $row->cost_amount, 4), '0');
            if (bccomp($delta, bcsub((string) data_get($log->evidence, 'new_inventory_cost_amount', '-1'),
                (string) data_get($log->evidence, 'old_inventory_cost_amount', '-1'), 4), 4) !== 0) {
                $this->fail('历史库存补差金额与财务更正记录不一致。');
            }
        }
        $balances = InventoryBalance::query()->where('item_id', $line->item_id)->where('batch_no', $line->batch_no)
            ->orderBy('id')->lockForUpdate()->get();
        $rows = [];
        $totalQty = '0';
        $totalCost = '0';
        foreach ($balances as $balance) {
            $at = $movements->where('warehouse_id', $balance->warehouse_id)->where('location_id', $balance->location_id);
            $qty = $at->reduce(fn ($sum, $row) => bcadd($sum, (string) $row->change_qty, 8), '0');
            $cost = $at->reduce(fn ($sum, $row) => bcadd($sum, (string) $row->cost_amount, 4), '0');
            if (bccomp($qty, '0', 8) <= 0 || bccomp($qty, (string) $balance->quantity_on_hand, 8) !== 0
                || bccomp($cost, (string) $balance->inventory_value, 4) !== 0 || bccomp($cost, '0', 4) < 0) {
                $this->fail('原入库数量、现存数量或库存金额无法逐库位核平，不能自动补差。');
            }
            foreach (['quantity_locked', 'quantity_defective', 'quantity_pending'] as $bucket) {
                if (bccomp((string) $balance->{$bucket}, '0', 8) !== 0) $this->fail('该批次存在锁定、待检或不良库存，请先完成相关业务。');
            }
            if (bccomp((string) $balance->quantity_available, $qty, 8) !== 0
                || DB::table('erp_inventory_reservations')->where('inventory_balance_id', $balance->id)
                    ->whereIn('reservation_status', ['active', 'partially_released', 'converted_to_shipment'])->exists()) {
                $this->fail('该批次存在业务占用，不能更改已被引用的成本。');
            }
            $originals = $source->where('warehouse_id', $balance->warehouse_id)->where('location_id', $balance->location_id);
            if ($originals->isEmpty() || $originals->contains(fn ($entry) => (int) $entry->unit_id !== (int) $balance->unit_id
                || ($entry->material_lot_id && (int) $entry->material_lot_id !== (int) $balance->material_lot_id))) {
                $this->fail('原采购入库单位或材料批次与当前余额不一致。');
            }
            $physicalRows = [];
            $holding = null;
            if ($item->materialManagementMode() === 'physical') {
                $physicalRows = $this->physicalRows($item, $balance, $originals->pluck('id')->all(), $qty, $cost, $holding);
            }
            $rows[] = ['balance_id' => $balance->id, 'item_id' => $item->id, 'warehouse_id' => $balance->warehouse_id,
                'location_id' => $balance->location_id, 'batch_no' => $balance->batch_no, 'unit_id' => $balance->unit_id,
                'material_lot_id' => $balance->material_lot_id, 'quantity' => $qty, 'old_value' => $cost,
                'holding_id' => $holding?->id, 'holding_version' => $holding?->business_version,
                'source_transaction_item_ids' => $originals->pluck('id')->all(), 'physicals' => $physicalRows];
            $totalQty = bcadd($totalQty, $qty, 8);
            $totalCost = bcadd($totalCost, $cost, 4);
        }
        $sourceQty = $source->reduce(fn ($sum, $row) => bcadd($sum, (string) $row->change_qty, 8), '0');
        if ($rows === [] || bccomp($totalQty, $sourceQty, 8) !== 0
            || bccomp($totalQty, (string) $line->final_stockable_base_qty, 8) !== 0) {
            $this->fail('本行采购入库尚未完整在库，不能自动更正在库成本。');
        }
        return ['balances' => $rows, 'quantity' => $totalQty, 'cost' => $totalCost,
            'version' => hash('sha256', json_encode([$source->all(), $movements->all(), $rows], JSON_THROW_ON_ERROR))];
    }

    private function physicalRows(Item $item, InventoryBalance $balance, array $sourceIds, string $qty, string $cost, ?object &$holding): array
    {
        $holding = DB::table('erp_material_holdings')->where('inventory_balance_id', $balance->id)->lockForUpdate()->first();
        if (! $holding || $holding->position_type !== 'WAREHOUSE' || $holding->status !== 'ACTIVE'
            || (int) $holding->position_id !== (int) $balance->warehouse_id || (int) $holding->material_lot_id !== (int) $balance->material_lot_id
            || $holding->quantity !== null || $holding->total_cost !== null) $this->fail('仓库实物持有位置或金额权威不一致。');
        $physicals = DB::table('erp_material_physicals')->whereIn('source_transaction_item_id', $sourceIds)->orderBy('id')->lockForUpdate()->get();
        $rows = [];
        $physicalQty = '0';
        $physicalCost = '0';
        foreach ($physicals as $physical) {
            if ((int) $physical->current_holding_id !== (int) $holding->id || $physical->status !== 'AVAILABLE'
                || (int) $physical->item_id !== (int) $item->id || (int) $physical->material_lot_id !== (int) $balance->material_lot_id
                || $physical->first_cut_at || $physical->parent_physical_id || $physical->material_form !== 'FULL_STOCK'
                || bccomp((string) $physical->total_cost, '0', 4) < 0) {
                $this->fail('本行实物已移动、加工或不完整，不能自动补差。');
            }
            $this->physicals->assertPhysicalNotInOpenDocument((int) $physical->id);
            if (DB::table('erp_material_physical_transfers')->where('physical_material_id', $physical->id)->whereIn('status', ['PENDING', 'IN_TRANSIT'])->exists()) {
                $this->fail('本行实物存在未完成的调拨，不能更正成本。');
            }
            $quantity = $this->physicals->inventoryQuantity($item, $physical);
            $rows[] = ['id' => $physical->id, 'source_transaction_item_id' => $physical->source_transaction_item_id,
                'holding_id' => $physical->current_holding_id, 'business_version' => $physical->business_version,
                'quantity' => $quantity, 'old_cost' => (string) $physical->total_cost, 'dimensions' => $physical->dimensions];
            $physicalQty = bcadd($physicalQty, $quantity, 8);
            $physicalCost = bcadd($physicalCost, (string) $physical->total_cost, 4);
        }
        if ($rows === [] || bccomp($qty, $physicalQty, 8) !== 0 || bccomp($cost, $physicalCost, 4) !== 0
            || DB::table('erp_material_physicals')->where('current_holding_id', $holding->id)->whereNotIn('id', $physicals->pluck('id')->all())->exists()) {
            $this->fail('逐件实物数量、成本与原采购来源或库存余额不一致。');
        }
        return $rows;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['inventory_cost_amount' => $message]);
    }
}
