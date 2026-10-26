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

    public function assertWritable(InventoryTransaction $transaction, array $line): void
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Inventory posting requires an application transaction.');
        // 与开盘共用仓库范围锁；当前读不受业务事务已有的 RR 快照影响。
        Warehouse::query()->whereKey((int) $line['warehouse_id'])->lockForUpdate()->firstOrFail();
        $stocktake = DB::table('erp_inventory_stocktakes')
            ->where('stocktake_status', 'counting')
            ->where('warehouse_id', (int) $line['warehouse_id'])
            ->where(fn ($query) => $query->whereNull('location_id')->orWhere('location_id', (int) $line['location_id']))
            ->lockForUpdate()->first(['id', 'stocktake_no']);
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
