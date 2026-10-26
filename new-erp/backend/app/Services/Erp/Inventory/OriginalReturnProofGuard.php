<?php

namespace App\Services\Erp\Inventory;

use App\Models\Erp\InventoryTransaction;
use App\Models\Erp\InventoryTransactionItem;
use App\Models\Erp\Item;
use App\Services\Erp\WarehouseManagementScopeService;
use Illuminate\Validation\ValidationException;

/**
 * 原事实退回证明：退回入库不走“新建库存”校验，而是逐条核对原出库流水。
 * 新的退回类业务（如换货退回）只需在 RULES 中登记“入库类型 => 原出库类型”。
 */
class OriginalReturnProofGuard
{
    private const RULES = [
        'sales_return_inbound' => 'sales_shipment_outbound',
    ];

    public function __construct(private readonly WarehouseManagementScopeService $scope)
    {
    }

    public function supports(InventoryTransaction $transaction, array $line): bool
    {
        return ! empty($line['original_return_transaction_items']) && isset(self::RULES[$transaction->transaction_type]);
    }

    public function verify(InventoryTransaction $transaction, array $line): ?Item
    {
        $originalType = self::RULES[$transaction->transaction_type];
        $item = null;
        $proofs = collect($line['original_return_transaction_items'])->groupBy('id')
            ->map(fn ($rows) => $rows->reduce(fn ($sum, $row) => bcadd($sum, (string) $row['quantity'], 8), '0.00000000'));
        $restored = '0.00000000';
        foreach ($proofs as $sourceId => $quantity) {
            $source = InventoryTransactionItem::query()->with('transaction')->whereKey($sourceId)->lockForUpdate()->first();
            if (!$source || $source->transaction?->transaction_type !== $originalType) {
                throw ValidationException::withMessages(['stock' => '历史退回缺少对应业务的原出库流水证明。']);
            }
            $item = $this->scope->assertOriginalReturn($source, array_replace($line, ['change_qty' => $quantity]));
            $restored = bcadd($restored, $quantity, 8);
        }
        if (bccomp($restored, (string) $line['change_qty'], 8) !== 0) {
            throw ValidationException::withMessages(['stock' => '历史退回数量必须与原出库事实分配完全一致。']);
        }
        return $item;
    }

    /** 由原出库流水明细构造证明；库位/批次不一致时返回空（由调用方走普通入库）。 */
    public function proofFor(int $sourceId, array $locator, float $quantity): array
    {
        $source = InventoryTransactionItem::query()->whereKey($sourceId)->lockForUpdate()->first();
        if (!$source || (int) $source->item_id !== (int) $locator['item_id']
            || (int) $source->warehouse_id !== (int) $locator['warehouse_id']
            || (int) $source->location_id !== (int) $locator['location_id']
            || (string) $source->batch_no !== (string) $locator['batch_no']) return [];
        return [['id' => $source->id, 'quantity' => number_format($quantity, 8, '.', '')]];
    }
}
