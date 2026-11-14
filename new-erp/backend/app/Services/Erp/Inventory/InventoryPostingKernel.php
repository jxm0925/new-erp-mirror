<?php

namespace App\Services\Erp\Inventory;

use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventoryBatch;
use App\Models\Erp\InventoryLocationBalance;
use App\Models\Erp\InventoryTransaction;
use App\Models\Erp\InventoryTransactionItem;
use App\Models\Erp\Item;
use App\Models\Erp\Location;
use App\Services\Erp\CuttingDecimal;
use App\Services\Erp\DocumentNumberService;
use App\Services\Erp\InventoryAlertApplicationService;
use App\Services\Erp\InventoryAvailabilityService;
use App\Services\Erp\PurchaseReceiptCostAdjustmentService;
use App\Services\Erp\WarehouseManagementScopeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 库存过账内核：与业务单据无关，只认“流水头 + 过账行”。
 * 采购、生产、销售、调拨、盘点等业务适配器统一经 begin() 开流水、apply() 逐行过账。
 */
class InventoryPostingKernel
{
    public function __construct(
        private readonly InventoryAvailabilityService $availability,
        private readonly PhysicalPostingGuard $physicalGuard,
        private readonly OriginalReturnProofGuard $originalReturns,
        private readonly WarehouseManagementScopeService $scope,
        private readonly DocumentNumberService $numbers,
        private readonly InventoryAlertApplicationService $alerts,
        private readonly StocktakeFreezeGuard $freeze,
    )
    {
    }

    /** 开一张库存流水（统一取号）。 */
    public function begin(array $attributes): InventoryTransaction
    {
        return InventoryTransaction::create(['transaction_no' => $this->nextNo('ITX')] + $attributes);
    }

    /**
     * 唯一的在库数量过账入口：锁物料、校验库位/实物/原事实退回、计算成本、
     * 更新批次余额与库位汇总、写流水明细并刷新预警。业务单据只负责组装行。
     * 盘点冻结等全局校验统一在入口执行。
     */
    public function apply(InventoryTransaction $transaction, array $line, ?array &$serialCosts = null): InventoryTransactionItem
    {
        $item = Item::query()->whereKey($line['item_id'])->lockForUpdate()->firstOrFail();
        $this->freeze->assertWritable($transaction, $line);
        if ((float) $line['change_qty'] > 0) {
            $item = $this->originalReturns->supports($transaction, $line)
                ? ($this->originalReturns->verify($transaction, $line) ?? $item)
                : $this->assertForwardStockLocator($line);
        }
        $locator = [
            'item_id' => $line['item_id'],
            'warehouse_id' => $line['warehouse_id'],
            'location_id' => $line['location_id'],
            'batch_no' => $line['batch_no'],
        ];
        $balance = InventoryBalance::query()->where($locator)->lockForUpdate()->first() ?? new InventoryBalance($locator);

        if ($transaction->transaction_type === PurchaseReceiptCostAdjustmentService::TYPE
            || ($line['cost_source_type'] ?? null) === PurchaseReceiptCostAdjustmentService::SOURCE) {
            return $this->applyCostAdjustment($transaction, $line, $item, $balance);
        }

        if ($item->materialManagementMode() === 'physical') {
            $this->physicalGuard->assertChange($transaction, $line, $item, $balance);
        }

        $onHand = (float) ($balance->quantity_on_hand ?? 0) + (float) $line['change_qty'];
        $previousValue = (float) ($balance->inventory_value ?? 0);
        $costAmount = array_key_exists('cost_amount', $line)
            ? (float) $line['cost_amount']
            : (float) $line['change_qty'] * (float) ($line['unit_cost'] ?? 0);
        $decimalLot = $item->materialManagementMode() === 'physical'
            || ! empty($line['material_lot_id']) || ! empty($balance->material_lot_id);
        $bySerial = isset($line['outbound_serial_ids']) && $item->materialManagementMode() !== 'physical'
            && ($line['outbound_serial_ids'] || $item->serialTrackingMode() !== 'none') && (float) $line['change_qty'] < 0;
        if ($bySerial) {
            $take = bcsub('0', (string) $line['change_qty'], 8); $serialCosts = [];
            $cost = SerialInventoryCost::outbound($balance, $take, $line['outbound_serial_ids'], $serialCosts);
            $costAmount = $line['cost_amount'] = bcsub('0', $cost, 4);
            $line['unit_cost'] = bcdiv($cost, $take, 8);
        }
        if ($decimalLot) {
            if (empty($line['material_lot_id'])) $line['material_lot_id'] = $balance->material_lot_id;
            if ($balance->material_lot_id && (int) $balance->material_lot_id !== (int) $line['material_lot_id'])
                throw ValidationException::withMessages(['material_lot_id' => '同一库存批次不能混入不同配置、阶段或来源的材料批次。']);
            if (! array_key_exists('cost_amount', $line)) throw ValidationException::withMessages(['cost_amount' => '可追溯材料批次必须传入权威总金额。']);
            $costAmount = (string) $line['cost_amount'];
            [$packingPhysicalTotal, $cuttingPhysicalTotal] = $this->physicalGuard->assertAuthoritativeTotal($transaction, $line, $item);
            if ((float) $line['change_qty'] < 0 && ! $packingPhysicalTotal && ! $cuttingPhysicalTotal && ! $bySerial) {
                $consumedQty = number_format(abs((float) $line['change_qty']), 8, '.', '');
                $costAmount = bcsub('0', CuttingDecimal::share((string) $balance->inventory_value, (string) $balance->quantity_on_hand, $consumedQty), 4);
            }
            $inventoryValue = bcadd((string) ($balance->inventory_value ?? '0'), $costAmount, 4);
            if (bccomp($inventoryValue, '0', 4) < 0) throw ValidationException::withMessages(['cost_amount' => '库存总金额不足，不能用归零掩盖成本差额。']);
        } elseif ($bySerial) $inventoryValue = bcadd((string) $balance->inventory_value, $costAmount, 4);
        else $inventoryValue = max(0, $previousValue + $costAmount);
        $locked = (float) ($balance->quantity_locked ?? 0);
        if ($onHand < 0 || $onHand - $locked < 0) {
            throw ValidationException::withMessages(['stock' => '当前库存不足，不能执行该调整。']);
        }

        $balance->fill([
            'unit_id' => $line['unit_id'] ?: $item->unit_id,
            'quantity_on_hand' => $onHand,
            'quantity_locked' => $locked,
            'quantity_available' => $this->availability->calculate($onHand, $locked, (float) ($balance->quantity_defective ?? 0), (float) ($balance->quantity_pending ?? 0)),
            'quantity_defective' => (float) ($balance->quantity_defective ?? 0),
            'quantity_pending' => (float) ($balance->quantity_pending ?? 0),
            'inventory_value' => $inventoryValue,
            'average_unit_cost' => $onHand > 0 ? $inventoryValue / $onHand : 0,
            'last_transaction_at' => now(),
        ])->save();

        if ($decimalLot) $balance->update(['material_lot_id' => $line['material_lot_id'],
            'average_unit_cost' => $onHand > 0 ? bcdiv($inventoryValue, number_format($onHand, 8, '.', ''), 8) : '0']);

        $locationLocator = [
            'item_id' => $line['item_id'],
            'warehouse_id' => $line['warehouse_id'],
            'location_id' => $line['location_id'],
        ];
        $locationBalance = InventoryLocationBalance::query()->where($locationLocator)->lockForUpdate()->first()
            ?? new InventoryLocationBalance($locationLocator);
        $locationOnHand = (float) ($locationBalance->quantity_on_hand ?? 0) + (float) $line['change_qty'];
        $locationLocked = (float) ($locationBalance->quantity_locked ?? 0);
        $locationBalance->fill([
            'unit_id' => $line['unit_id'] ?: $item->unit_id,
            'quantity_on_hand' => $locationOnHand,
            'quantity_locked' => $locationLocked,
            'quantity_available' => $this->availability->calculate($locationOnHand, $locationLocked, (float) ($locationBalance->quantity_defective ?? 0), (float) ($locationBalance->quantity_pending ?? 0)),
            'quantity_defective' => (float) ($locationBalance->quantity_defective ?? 0),
            'quantity_pending' => (float) ($locationBalance->quantity_pending ?? 0),
            'last_transaction_at' => now(),
        ])->save();

        InventoryBatch::firstOrCreate([
            'item_id' => $line['item_id'],
            'batch_no' => $line['batch_no'],
        ], [
            'warehouse_id' => $line['warehouse_id'],
            'location_id' => $line['location_id'],
            'source_type' => $line['source_type'],
            'source_id' => $line['source_id'],
            'status' => 'enabled',
        ]);

        $transactionItem = InventoryTransactionItem::create([
            'transaction_id' => $transaction->id,
            'transaction_no' => $transaction->transaction_no,
            'item_id' => $line['item_id'],
            'item_code' => $item->item_code,
            'item_name' => $item->item_name,
            'warehouse_id' => $line['warehouse_id'],
            'location_id' => $line['location_id'],
            'batch_no' => $line['batch_no'],
            'unit_id' => $line['unit_id'] ?: $item->unit_id,
            'change_qty' => $line['change_qty'],
            'unit_cost' => (float) ($line['unit_cost'] ?? 0),
            'cost_amount' => $costAmount,
            'purchase_amount_snapshot' => $line['purchase_amount_snapshot'] ?? null,
            'freight_amount_snapshot' => $line['freight_amount_snapshot'] ?? 0,
            'other_purchase_cost_amount_snapshot' => $line['other_purchase_cost_amount_snapshot'] ?? 0,
            'cost_source_type' => $line['cost_source_type'] ?? null,
            'balance_after_qty' => $balance->quantity_on_hand,
            'source_type' => $line['source_type'],
            'source_id' => $line['source_id'],
            'source_item_id' => $line['source_item_id'] ?? null,
            'remark' => $line['remark'] ?? null,
        ]);
        if ($decimalLot) {
            $transactionItem->update(['material_lot_id' => $line['material_lot_id']]);
            InventoryBatch::where('item_id', $line['item_id'])->where('batch_no', $line['batch_no'])->update(['material_lot_id' => $line['material_lot_id']]);
        }

        // Alert facts belong to the same inventory transaction. The alert service deduplicates identical state/severity.
        $this->alerts->recalculateForItemWarehouse(
            (int) $line['item_id'], (int) $line['warehouse_id'], 'inventory_transaction',
        );

        return $transactionItem;
    }

    private function applyCostAdjustment(InventoryTransaction $transaction, array $line, Item $item, InventoryBalance $balance): InventoryTransactionItem
    {
        $proof = $this->physicalGuard->assertCostAdjustment($transaction, $line, $item, $balance);
        $value = bcadd((string) $balance->inventory_value, (string) $line['cost_amount'], 4);
        $quantity = (string) $balance->quantity_on_hand;
        if (bccomp($value, '0', 4) < 0 || bccomp($quantity, '0', 8) <= 0) {
            throw ValidationException::withMessages(['cost_amount' => '成本补差后金额不能为负，且必须存在完整在库数量。']);
        }
        $average = bcdiv($value, $quantity, 8);
        if (bccomp($average, '9999999999.99999999', 8) > 0) {
            throw ValidationException::withMessages(['cost_amount' => '更正后的单位成本超过库存金额精度范围，请核对金额及入库数量。']);
        }
        // 金额补差不重存任何数量或库位汇总，避免浮点重算改变原库存事实。
        $balance->update(['inventory_value' => $value, 'average_unit_cost' => $average, 'last_transaction_at' => now()]);
        foreach ($proof['physicals'] as $physical) {
            DB::table('erp_material_physicals')->where('id', $physical['id'])->update([
                'total_cost' => $physical['new_cost'], 'business_version' => (int) $physical['business_version'] + 1, 'updated_at' => now(),
            ]);
        }
        if ($proof['holding_id']) {
            // 仓库持有位置通过余额引用权威金额；保持 quantity/total_cost 为 NULL。
            DB::table('erp_material_holdings')->where('id', $proof['holding_id'])->update([
                'business_version' => (int) $proof['holding_version'] + 1, 'updated_at' => now(),
            ]);
        }
        return InventoryTransactionItem::create([
            'transaction_id' => $transaction->id, 'transaction_no' => $transaction->transaction_no,
            'item_id' => $item->id, 'item_code' => $item->item_code, 'item_name' => $item->item_name,
            'warehouse_id' => $balance->warehouse_id, 'location_id' => $balance->location_id, 'batch_no' => $balance->batch_no,
            'unit_id' => $balance->unit_id, 'material_lot_id' => $balance->material_lot_id,
            'change_qty' => '0', 'unit_cost' => $balance->average_unit_cost, 'cost_amount' => (string) $line['cost_amount'],
            'balance_after_qty' => $quantity, 'cost_source_type' => PurchaseReceiptCostAdjustmentService::SOURCE,
            'source_type' => $line['source_type'], 'source_id' => $line['source_id'], 'source_item_id' => $line['source_item_id'],
            'remark' => $line['remark'] ?? null,
        ]);
    }

    private function assertForwardStockLocator(array $line): Item
    {
        $validated = $this->scope->assertForward((int) $line['item_id'], (int) $line['warehouse_id']);
        $location = Location::query()->whereKey((int) $line['location_id'])->lockForUpdate()->first();
        if (!$location || !in_array($location->status, ['active', 'enabled'], true)) {
            throw ValidationException::withMessages(['location_id' => '所选库位不存在或已停用。']);
        }
        if ((int) $location->warehouse_id !== (int) $validated['warehouse']->id) {
            throw ValidationException::withMessages(['location_id' => '所选库位必须属于当前入库仓库，不能使用其他仓库的库位。']);
        }
        if (!(bool) $validated['item']->is_stock_item) {
            throw ValidationException::withMessages(['stock' => '非库存物料不能建立新库存；历史原事实退回须提供真实原出库证明。']);
        }
        return $validated['item'];
    }

    /**
     * 库位汇总的状态数量（锁定/待检/不良）同步：批次行由业务自行更新后调用，
     * 统一按“可用 = 在库 - 锁定 - 不良 - 待检”重算；汇总行不存在时跳过，结果不低于 0。
     */
    public function adjustLocationBucket(InventoryBalance $balance, string $bucket, float $change): void
    {
        if (! in_array($bucket, ['quantity_locked', 'quantity_pending', 'quantity_defective'], true)) {
            throw new \InvalidArgumentException("Unsupported inventory bucket {$bucket}.");
        }
        $location = InventoryLocationBalance::query()
            ->where('item_id', $balance->item_id)
            ->where('warehouse_id', $balance->warehouse_id)
            ->where('location_id', $balance->location_id)
            ->lockForUpdate()
            ->first();
        if (! $location) return;

        $values = [
            'quantity_locked' => (float) $location->quantity_locked,
            'quantity_pending' => (float) $location->quantity_pending,
            'quantity_defective' => (float) $location->quantity_defective,
        ];
        $values[$bucket] = max(0, $values[$bucket] + $change);
        $location->update([
            $bucket => $values[$bucket],
            'quantity_available' => $this->availability->calculate(
                (float) $location->quantity_on_hand,
                $values['quantity_locked'],
                $values['quantity_defective'],
                $values['quantity_pending'],
            ),
            'last_transaction_at' => now(),
        ]);
    }

    /**
     * 严格变更状态数量（批次行 + 库位汇总同时变化）：不足即报错，不做归零掩盖。
     * 用于不良冻结/解冻等必须与在库数量同口径对账的场景。
     */
    public function changeStatusQuantity(array $locator, string $bucket, float $change, string $field, string $message): void
    {
        if (! in_array($bucket, ['quantity_locked', 'quantity_pending', 'quantity_defective'], true)) {
            throw new \InvalidArgumentException("Unsupported inventory bucket {$bucket}.");
        }
        $where = fn ($query) => $query->where('item_id', $locator['item_id'])->where('warehouse_id', $locator['warehouse_id'])
            ->where('location_id', $locator['location_id']);
        $balance = $where(InventoryBalance::query())->where('batch_no', $locator['batch_no'])->lockForUpdate()->firstOrFail();
        $location = $where(InventoryLocationBalance::query())->lockForUpdate()->firstOrFail();
        foreach ([$balance, $location] as $row) {
            $value = round((float) $row->{$bucket} + $change, 8);
            if ($value < -0.00000001) throw ValidationException::withMessages([$field => $message]);
            $values = ['quantity_locked' => (float) $row->quantity_locked, 'quantity_pending' => (float) $row->quantity_pending,
                'quantity_defective' => (float) $row->quantity_defective];
            $values[$bucket] = max(0, $value);
            $row->update([$bucket => $values[$bucket], 'quantity_available' => $this->availability->calculate((float) $row->quantity_on_hand,
                $values['quantity_locked'], $values['quantity_defective'], $values['quantity_pending']), 'last_transaction_at' => now()]);
        }
    }

    public function nextNo(string $prefix): string
    {
        // Several stock postings can share one second. A random three-digit suffix collided
        // during real production regression; use the same locked counter as other documents.
        return $this->numbers->next('inventory_transaction', $prefix);
    }
}
