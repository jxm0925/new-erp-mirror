<?php

namespace App\Services\Erp\Inventory;

use App\Models\Erp\InventoryTransaction;
use App\Models\Erp\InventoryPostingLog;
use App\Models\Erp\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 盘点冻结：盘点中的仓库（或指定库位）禁止任何在库数量变化，
 * 唯一例外是原因为“盘点差异”的库存调整（盘点完成过账、序列号/实物差异手工处理）。
 */
class StocktakeFreezeGuard
{
    public const EXEMPT_ADJUSTMENT_REASON = 'stocktaking_difference';

    /**
     * 锁协议（仓库行锁只用于“开盘/完成/取消盘点”与“过账”互斥，过账之间不互斥）：
     * 1. 过账：先锁物料（内核入口 X），再对仓库行与盘点中单据加共享锁（S）。同仓不同物料的过账可并行持有 S。
     *    入库路径的 WarehouseManagementScopeService 同样只加 S，避免同一事务内 S→X 升级造成并发过账互相死锁。
     * 2. InventoryStocktakeService::create()/lockCounting()（完成、取消、录入）对仓库行加排他锁（X）：
     *    等待正在进行的过账提交后才拍快照，同时挡住新的过账；之后用 NOWAIT 锁余额和物料，冲突即重试，不会反向等待过账。
     * 3. 完成盘点时同一事务已持有仓库 X，内核再加 S 不冲突。
     * 4. “盘点差异”豁免分支对调整单和快照行仍加 X，只在盘点过账时走到，频率很低。
     * 共享锁为当前读，不受业务事务已有的 RR 快照影响。
     */
    public function assertWritable(InventoryTransaction $transaction, array $line): void
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Inventory posting requires an application transaction.');
        Warehouse::query()->whereKey((int) $line['warehouse_id'])->sharedLock()->firstOrFail();
        $stocktake = DB::table('erp_inventory_stocktakes')
            ->where('stocktake_status', 'counting')
            ->where('warehouse_id', (int) $line['warehouse_id'])
            ->where(fn ($query) => $query->whereNull('location_id')->orWhere('location_id', (int) $line['location_id']))
            ->sharedLock()->first(['id', 'stocktake_no']);
        if (! $stocktake) return;
        if ($transaction->source_type === 'inventory_adjustment' && DB::table('erp_inventory_adjustments')
            ->where('id', $transaction->source_id)->lockForUpdate()->value('reason') === self::EXEMPT_ADJUSTMENT_REASON) {
            $snapshot = DB::table('erp_inventory_stocktake_lines')->where('stocktake_id', $stocktake->id)
                ->where('item_id', $line['item_id'])->where('warehouse_id', $line['warehouse_id'])
                ->where('location_id', $line['location_id'])->where('batch_no', $line['batch_no'])->lockForUpdate()->first(['id']);
            if (! $snapshot) throw ValidationException::withMessages(['stock' => '该批次不在当前盘点快照中，不能以盘点差异变更冻结库存。']);
            // 关联与调整流水同事务提交；多行共用一条日志，多盘点按真实范围分别关联。
            InventoryPostingLog::firstOrCreate([
                'source_type' => 'inventory_stocktake', 'source_id' => $stocktake->id,
                'transaction_type' => 'stocktake_difference', 'transaction_id' => $transaction->id,
            ], [
                'source_no' => $stocktake->stocktake_no, 'posting_status' => 'posted',
                'message' => '盘点差异调整过账', 'posted_by' => $transaction->posted_by, 'posted_at' => $transaction->posted_at,
            ]);
            return;
        }
        throw ValidationException::withMessages([
            'stock' => "该仓库/库位正在盘点（{$stocktake->stocktake_no}），盘点完成或取消前不能出入库。",
        ]);
    }
}
