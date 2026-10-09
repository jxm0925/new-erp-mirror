<?php

namespace App\Services\Erp;

use App\Models\Erp\InventoryTransactionItem;
use App\Models\Erp\Item;
use App\Models\Erp\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class WarehouseManagementScopeService
{
    public function assertWarehouseItem(
        Item|int $item,
        Warehouse|int $warehouse,
        string $field = 'warehouse_id',
        ?string $expectedScope = null,
    ): Warehouse {
        return $this->assertForward($item, $warehouse, $expectedScope, $field)['warehouse'];
    }

    /** The caller's business transaction retains these locks until posting commits. */
    public function assertForward(
        Item|int $item,
        Warehouse|int $warehouse,
        ?string $expectedScope = null,
        string $field = 'warehouse_id',
    ): array {
        $this->assertTransaction();
        $item = Item::query()->whereKey($item instanceof Item ? $item->id : $item)->lockForUpdate()->first();
        $warehouse = Warehouse::query()->whereKey($warehouse instanceof Warehouse ? $warehouse->id : $warehouse)->lockForUpdate()->first();
        if (!$item || !in_array($item->management_scope, ['factory', 'office'], true)) {
            throw ValidationException::withMessages([$field => '物料管理范围未确定，请核对物料档案后再办理库存业务。']);
        }
        if (!$warehouse || !in_array($warehouse->management_scope, ['factory', 'office'], true)) {
            throw ValidationException::withMessages([$field => '仓库管理范围未确定，请核对仓库档案后再办理库存业务。']);
        }
        if (!in_array($warehouse->status, ['active', 'enabled'], true)) {
            throw ValidationException::withMessages([$field => '所选仓库不存在或已停用。']);
        }
        if ($item->management_scope !== $warehouse->management_scope
            || ($expectedScope !== null && $item->management_scope !== $expectedScope)) {
            throw ValidationException::withMessages([$field => '物料、单据和仓库的管理范围必须一致，办公用品和工厂物料应分开入库。']);
        }
        return ['item' => $item, 'warehouse' => $warehouse, 'management_scope' => $item->management_scope];
    }

    /**
     * A return may restore an old fact after master data changed. This exception
     * proves the exact outbound fact and locator; it never grants a new destination.
     * Formal return services still own their cumulative quantity reservations.
     */
    public function assertOriginalReturn(InventoryTransactionItem|int $source, array $line): Item
    {
        $this->assertTransaction();
        $source = InventoryTransactionItem::query()->with('transaction')->whereKey($source instanceof InventoryTransactionItem ? $source->id : $source)
            ->lockForUpdate()->first();
        if (!$source || $source->transaction?->posting_status !== 'posted' || (float) $source->change_qty >= 0 || (float) ($line['change_qty'] ?? 0) <= 0
            || (float) $line['change_qty'] > abs((float) $source->change_qty) + 0.00000001
            || (int) $source->item_id !== (int) ($line['item_id'] ?? 0)
            || (int) $source->warehouse_id !== (int) ($line['warehouse_id'] ?? 0)
            || (int) $source->location_id !== (int) ($line['location_id'] ?? 0)
            || (string) $source->batch_no !== (string) ($line['batch_no'] ?? '')) {
            throw ValidationException::withMessages(['stock' => '历史退回只能恢复已证实的原出库物料、仓库、库位和批次，数量不能超过原出库事实。']);
        }
        Warehouse::query()->whereKey($source->warehouse_id)->lockForUpdate()->firstOrFail();
        return Item::query()->whereKey($source->item_id)->lockForUpdate()->firstOrFail();
    }

    private function assertTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Warehouse scope validation requires the caller business transaction.');
        }
    }
}
