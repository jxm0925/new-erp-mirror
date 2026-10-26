<?php

namespace App\Services\Erp\Inventory;

use App\Models\Erp\InventoryTransaction;
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
        $stocktake = DB::table('erp_inventory_stocktakes')
            ->where('stocktake_status', 'counting')
            ->where('warehouse_id', (int) $line['warehouse_id'])
            ->where(fn ($query) => $query->whereNull('location_id')->orWhere('location_id', (int) $line['location_id']))
            ->first(['id', 'stocktake_no']);
        if (! $stocktake) return;
        if ($transaction->source_type === 'inventory_adjustment' && DB::table('erp_inventory_adjustments')
            ->where('id', $transaction->source_id)->value('reason') === self::EXEMPT_ADJUSTMENT_REASON) return;
        throw ValidationException::withMessages([
            'stock' => "该仓库/库位正在盘点（{$stocktake->stocktake_no}），盘点完成或取消前不能出入库。",
        ]);
    }
}
