<?php

namespace App\Services\Erp;

use App\Models\Erp\InventoryAdjustmentItem;
use App\Models\Erp\InventoryAdjustmentItemPhysical;
use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventoryTransactionItem;
use App\Models\Erp\Item;
use App\Models\Erp\PurchaseReceipt;
use App\Models\Erp\PurchaseReceiptAllocationPhysical;
use App\Models\Erp\PurchaseReceiptItem;
use App\Models\Erp\PurchaseReceiptItemAllocation;
use App\Models\Erp\PurchaseReturnItem;
use App\Models\Erp\PurchaseReturnItemPhysical;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns the identity side of physical-managed inventory.
 *
 * InventoryService remains the only quantity/value ledger writer. This service
 * creates or moves exactly one material identity for every accepted +/-1 ledger
 * line, inside the caller's transaction.
 */
final class MaterialPhysicalService
{
    public function __construct(private readonly DocumentNumberService $numbers) {}

    public function replaceReceiptAllocationEntries(
        PurchaseReceiptItemAllocation $allocation,
        Item $item,
        array $entries,
    ): void {
        $allocation->physicalEntries()->delete();
        if ($item->materialManagementMode() !== 'physical') {
            if ($entries !== []) {
                throw ValidationException::withMessages(['physical_entries' => "物料 {$item->item_code} 不是实物管理物料，不能填写钢板实物尺寸。"]);
            }
            return;
        }
        $this->assertSheetItem($item);
        foreach (array_values($entries) as $index => $entry) {
            PurchaseReceiptAllocationPhysical::create([
                'allocation_id' => $allocation->id,
                'sequence_no' => $index + 1,
                'dimensions' => $this->dimensions($entry['dimensions'] ?? $entry),
            ]);
        }
    }

    public function assertReceiptReady(PurchaseReceipt $receipt): void
    {
        $receipt->loadMissing(['items.item', 'items.allocations.physicalEntries']);
        foreach ($receipt->items as $line) {
            $item = $line->item;
            if (!$item || $item->materialManagementMode() !== 'physical') {
                foreach ($line->allocations as $allocation) {
                    if ($allocation->physicalEntries->isNotEmpty()) {
                        throw ValidationException::withMessages(['physical_entries' => "物料 {$item?->item_code} 不是实物管理物料，不能登记实物身份。"]);
                    }
                }
                continue;
            }
            $this->assertSheetItem($item);
            $qualified = $this->qualifiedBaseQuantity($line);
            if (!$this->isWhole($qualified)) {
                throw ValidationException::withMessages(['physical_entries' => "实物管理物料 {$item->item_code} 的合格基本数量必须是整数张。"]);
            }
            foreach ($line->allocations as $allocation) {
                $quantity = (string) $allocation->base_qty;
                if (!$this->isWhole($quantity)) {
                    throw ValidationException::withMessages(['physical_entries' => "实物管理物料 {$item->item_code} 的每个库位分配数量必须是整数张。"]);
                }
                $expected = (int) round((float) $quantity);
                if ($allocation->physicalEntries->count() !== $expected) {
                    throw ValidationException::withMessages([
                        'physical_entries' => "物料 {$item->item_code} 在库位 #{$allocation->location_id} 分配 {$expected} 张，必须逐张填写 {$expected} 份真实尺寸。",
                    ]);
                }
                foreach ($allocation->physicalEntries as $entry) $this->dimensions($entry->dimensions);
            }
        }
    }

    public function createPurchaseReceiptPhysicals(
        PurchaseReceiptItem $line,
        PurchaseReceiptItemAllocation $allocation,
        InventoryTransactionItem $transactionItem,
    ): array {
        $entries = PurchaseReceiptAllocationPhysical::query()
            ->where('allocation_id', $allocation->id)
            ->orderBy('sequence_no')
            ->lockForUpdate()
            ->get();
        if ($entries->count() !== (int) round((float) $allocation->base_qty)
            || $entries->contains(fn ($entry) => $entry->physical_material_id !== null)) {
            throw ValidationException::withMessages(['physical_entries' => '采购入库实物明细数量不一致或已经生成过实物，不能继续过账。']);
        }

        $balance = InventoryBalance::query()->where([
            'item_id' => $line->item_id,
            'warehouse_id' => $allocation->warehouse_id,
            'location_id' => $allocation->location_id,
            'batch_no' => $line->batch_no,
        ])->lockForUpdate()->firstOrFail();
        $holding = $this->warehouseHolding($balance, 'FULL_STOCK');
        $remainingCost = (string) $transactionItem->cost_amount;
        $remainingCount = (string) $entries->count();
        $ids = [];
        foreach ($entries as $entry) {
            $cost = CuttingDecimal::share($remainingCost, $remainingCount, '1');
            $id = $this->createPhysical(
                (int) $line->item_id,
                (int) $transactionItem->id,
                (int) $holding->material_lot_id,
                (int) $holding->id,
                $entry->dimensions,
                $cost,
            );
            $entry->update([
                'physical_material_id' => $id,
                'inventory_transaction_item_id' => $transactionItem->id,
            ]);
            $ids[] = $id;
            $remainingCost = bcsub($remainingCost, $cost, 4);
            $remainingCount = bcsub($remainingCount, '1', 0);
        }
        if (bccomp($remainingCost, '0', 4) !== 0) {
            throw new \LogicException('Purchase physical cost allocation did not conserve the transaction amount.');
        }
        return $ids;
    }

    public function replaceAdjustmentEntries(
        InventoryAdjustmentItem $line,
        Item $item,
        InventoryBalance $balance,
        array $entries,
    ): void {
        $line->physicalEntries()->delete();
        if ($item->materialManagementMode() !== 'physical') {
            if ($entries !== []) {
                throw ValidationException::withMessages(['physical_entries' => "物料 {$item->item_code} 不是实物管理物料，不能填写实物调整明细。"]);
            }
            return;
        }
        $this->assertSheetItem($item);
        $quantity = (string) abs((float) $line->change_qty);
        if (!$this->isWhole($quantity) || (int) round((float) $quantity) !== count($entries)) {
            throw ValidationException::withMessages(['physical_entries' => "物料 {$item->item_code} 调整 {$line->change_qty} 张，必须逐张填写 ".(int) round((float) $quantity).' 条实物明细。']);
        }
        $direction = (float) $line->change_qty > 0 ? 'increase' : 'decrease';
        $ids = [];
        foreach (array_values($entries) as $index => $entry) {
            if ($direction === 'increase') {
                if (!empty($entry['physical_material_id'])) {
                    throw ValidationException::withMessages(['physical_entries' => '盘盈新增实物不能引用已有实物身份。']);
                }
                $dimensions = $this->dimensions($entry['dimensions'] ?? null);
                $cost = $this->money($entry['total_cost'] ?? null);
                $physicalId = null;
            } else {
                $physicalId = (int) ($entry['physical_material_id'] ?? 0);
                if (!$physicalId || in_array($physicalId, $ids, true)) {
                    throw ValidationException::withMessages(['physical_entries' => '盘亏必须逐张选择互不重复的现有实物。']);
                }
                $physical = $this->assertWarehousePhysical($physicalId, $item->id, $balance->id);
                $this->assertPhysicalNotInOpenDocument($physicalId, $line->adjustment_id);
                $dimensions = null;
                $cost = (string) $physical->total_cost;
                $ids[] = $physicalId;
            }
            InventoryAdjustmentItemPhysical::create([
                'adjustment_item_id' => $line->id,
                'direction' => $direction,
                'sequence_no' => $index + 1,
                'physical_material_id' => $physicalId,
                'dimensions' => $dimensions,
                'total_cost' => $cost,
            ]);
        }
    }

    public function assertAdjustmentReady(InventoryAdjustmentItem $line, InventoryBalance $balance): void
    {
        $line->loadMissing(['item', 'physicalEntries']);
        if ($line->item?->materialManagementMode() !== 'physical') {
            if ($line->physicalEntries->isNotEmpty()) {
                throw ValidationException::withMessages(['physical_entries' => '普通数量物料不能携带实物调整明细。']);
            }
            return;
        }
        $expectedDirection = (float) $line->change_qty > 0 ? 'increase' : 'decrease';
        $expectedCount = (int) round(abs((float) $line->change_qty));
        if (!$this->isWhole((string) abs((float) $line->change_qty))
            || $line->physicalEntries->count() !== $expectedCount
            || $line->physicalEntries->contains(fn ($entry) => $entry->direction !== $expectedDirection)) {
            throw ValidationException::withMessages(['physical_entries' => '实物调整明细与调整方向或张数不一致。']);
        }
        foreach ($line->physicalEntries as $entry) {
            if ($expectedDirection === 'increase') {
                if ($entry->physical_material_id || $entry->inventory_transaction_item_id) {
                    throw ValidationException::withMessages(['physical_entries' => '盘盈实物已经过账或身份状态异常。']);
                }
                $this->dimensions($entry->dimensions);
                $this->money((string) $entry->total_cost);
            } else {
                $this->assertWarehousePhysical((int) $entry->physical_material_id, (int) $line->item_id, (int) $balance->id);
                $this->assertPhysicalNotInOpenDocument((int) $entry->physical_material_id, (int) $line->adjustment_id);
            }
        }
    }

    public function createAdjustmentIncrease(
        InventoryAdjustmentItemPhysical $entry,
        InventoryAdjustmentItem $line,
        InventoryBalance $balance,
        InventoryTransactionItem $transactionItem,
    ): int {
        $entry = InventoryAdjustmentItemPhysical::query()->lockForUpdate()->findOrFail($entry->id);
        if ($entry->direction !== 'increase' || $entry->physical_material_id || $entry->inventory_transaction_item_id) {
            throw ValidationException::withMessages(['physical_entries' => '盘盈实物明细已经处理或方向错误。']);
        }
        $holding = $this->warehouseHolding($balance, 'FULL_STOCK');
        $id = $this->createPhysical(
            (int) $line->item_id,
            (int) $transactionItem->id,
            (int) $holding->material_lot_id,
            (int) $holding->id,
            $entry->dimensions,
            (string) $entry->total_cost,
        );
        $entry->update(['physical_material_id' => $id, 'inventory_transaction_item_id' => $transactionItem->id]);
        return $id;
    }

    public function completeAdjustmentDecrease(
        InventoryAdjustmentItemPhysical $entry,
        InventoryAdjustmentItem $line,
        InventoryTransactionItem $transactionItem,
        int $operatorId = 0,
    ): void {
        $entry = InventoryAdjustmentItemPhysical::query()->lockForUpdate()->findOrFail($entry->id);
        $physical = DB::table('erp_material_physicals')->where('id', $entry->physical_material_id)->lockForUpdate()->first();
        $source = $physical ? DB::table('erp_material_holdings')->where('id', $physical->current_holding_id)->lockForUpdate()->first() : null;
        if (!$physical || !$source || $entry->direction !== 'decrease' || $entry->inventory_transaction_item_id
            || $physical->status !== 'AVAILABLE' || $source->position_type !== 'WAREHOUSE') {
            throw ValidationException::withMessages(['physical_entries' => '盘亏实物已发生其他流转，不能继续过账。']);
        }
        $targetId = $this->nonWarehouseHolding((int) $source->material_lot_id, 'ADJUSTMENT', (int) $line->adjustment_id, (string) $physical->total_cost, 'ADJUSTED_OUT');
        $this->movePhysical($physical, $source, $targetId, 'ADJUST_OUT', 'ADJUSTED_OUT', (int) $transactionItem->transaction_id, $operatorId);
        $entry->update(['inventory_transaction_item_id' => $transactionItem->id]);
    }

    public function replacePurchaseReturnLinks(PurchaseReturnItem $line, array $physicalIds): void
    {
        $line->physicalLinks()->delete();
        $line->loadMissing(['item', 'sourceReceiptItem']);
        $item = $line->item;
        if (!$item || $item->materialManagementMode() !== 'physical') {
            if ($physicalIds !== []) {
                throw ValidationException::withMessages(['physical_material_ids' => '普通数量物料不能携带钢板实物身份。']);
            }
            return;
        }
        $this->assertSheetItem($item);
        if (!$this->isWhole((string) $line->requested_base_qty)
            || count($physicalIds) !== (int) round((float) $line->requested_base_qty)) {
            throw ValidationException::withMessages(['physical_material_ids' => "物料 {$item->item_code} 退货 {$line->requested_base_qty} 张，必须逐张选择相同数量的实物。"]);
        }
        $ids = array_values(array_unique(array_map('intval', $physicalIds)));
        if (count($ids) !== count($physicalIds)) {
            throw ValidationException::withMessages(['physical_material_ids' => '采购退货选择的实物存在重复。']);
        }
        $balance = InventoryBalance::query()->where([
            'item_id' => $line->item_id,
            'warehouse_id' => $line->warehouse_id,
            'location_id' => $line->location_id,
            'batch_no' => $line->batch_no,
        ])->lockForUpdate()->firstOrFail();
        foreach ($ids as $physicalId) {
            $physical = $this->assertWarehousePhysical($physicalId, (int) $line->item_id, (int) $balance->id);
            $sourceTransactionLine = DB::table('erp_inventory_transaction_items')->where('id', $physical->source_transaction_item_id)->first();
            if (!$sourceTransactionLine || $sourceTransactionLine->source_type !== 'purchase_receipt'
                || (int) $sourceTransactionLine->source_item_id !== (int) $line->source_receipt_item_id) {
                throw ValidationException::withMessages(['physical_material_ids' => '采购退货实物必须来源于当前采购到货明细。']);
            }
            $this->assertPhysicalNotInOpenDocument($physicalId, null, (int) $line->return_id);
            PurchaseReturnItemPhysical::create([
                'purchase_return_item_id' => $line->id,
                'physical_material_id' => $physicalId,
            ]);
        }
    }

    public function assertPurchaseReturnReady(PurchaseReturnItem $line, string|float|int|null $expectedQuantity = null): void
    {
        $line->loadMissing(['item', 'physicalLinks', 'sourceReceiptItem']);
        if ($line->item?->materialManagementMode() !== 'physical') {
            if ($line->physicalLinks->isNotEmpty()) {
                throw ValidationException::withMessages(['physical_material_ids' => '普通数量物料不能携带实物退货明细。']);
            }
            return;
        }
        $expectedQuantity ??= $line->approved_base_qty;
        if (!$this->isWhole((string) $expectedQuantity)
            || $line->physicalLinks->count() !== (int) round((float) $expectedQuantity)) {
            throw ValidationException::withMessages(['physical_material_ids' => '采购退货审核张数与所选实物数量不一致。']);
        }
        $balance = InventoryBalance::query()->where([
            'item_id' => $line->item_id,
            'warehouse_id' => $line->warehouse_id,
            'location_id' => $line->location_id,
            'batch_no' => $line->batch_no,
        ])->lockForUpdate()->firstOrFail();
        foreach ($line->physicalLinks as $link) {
            $this->assertWarehousePhysical((int) $link->physical_material_id, (int) $line->item_id, (int) $balance->id);
        }
    }

    public function completePurchaseReturn(
        PurchaseReturnItemPhysical $link,
        PurchaseReturnItem $line,
        InventoryTransactionItem $transactionItem,
        int $operatorId = 0,
    ): void {
        $link = PurchaseReturnItemPhysical::query()->lockForUpdate()->findOrFail($link->id);
        $physical = DB::table('erp_material_physicals')->where('id', $link->physical_material_id)->lockForUpdate()->first();
        $source = $physical ? DB::table('erp_material_holdings')->where('id', $physical->current_holding_id)->lockForUpdate()->first() : null;
        if (!$physical || !$source || $link->inventory_transaction_item_id || $physical->status !== 'AVAILABLE'
            || $source->position_type !== 'WAREHOUSE') {
            throw ValidationException::withMessages(['physical_material_ids' => '采购退货实物已发生其他流转，不能继续出库。']);
        }
        $targetId = $this->nonWarehouseHolding((int) $source->material_lot_id, 'SUPPLIER_RETURN', (int) $line->return_id, (string) $physical->total_cost, 'RETURNED');
        $this->movePhysical($physical, $source, $targetId, 'PURCHASE_RETURN', 'RETURNED', (int) $transactionItem->transaction_id, $operatorId);
        $link->update(['inventory_transaction_item_id' => $transactionItem->id]);
    }

    public function assertWarehousePhysical(int $physicalId, ?int $itemId = null, ?int $balanceId = null): object
    {
        $physical = DB::table('erp_material_physicals')->where('id', $physicalId)->lockForUpdate()->first();
        $holding = $physical ? DB::table('erp_material_holdings')->where('id', $physical->current_holding_id)->lockForUpdate()->first() : null;
        if (!$physical || !$holding || $physical->status !== 'AVAILABLE' || $holding->status !== 'ACTIVE'
            || $holding->position_type !== 'WAREHOUSE' || !$holding->inventory_balance_id
            || ($itemId && (int) $physical->item_id !== $itemId)
            || ($balanceId && (int) $holding->inventory_balance_id !== $balanceId)) {
            throw ValidationException::withMessages(['physical_material_id' => '所选实物不在指定仓库余额中、不可用或已经发生其他流转。']);
        }
        return $physical;
    }

    public function warehouseHolding(InventoryBalance $balance, string $form = 'FULL_STOCK', ?int $materialLotId = null): object
    {
        $balance = InventoryBalance::query()->lockForUpdate()->findOrFail($balance->id);
        $lotId = $materialLotId ?: (int) ($balance->material_lot_id ?? 0);
        if (!$lotId) {
            $lotId = DB::table('erp_material_lots')->insertGetId([
                'lot_no' => $this->numbers->next('material_lot', 'ML'),
                'item_id' => $balance->item_id,
                'material_form' => $form,
                'source_type' => 'inventory_balance',
                'source_id' => $balance->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        if ($balance->material_lot_id && (int) $balance->material_lot_id !== $lotId) {
            throw ValidationException::withMessages(['material_lot_id' => '目标库存余额已经属于另一材料批次，不能混放实物。']);
        }
        if (!$balance->material_lot_id) {
            $balance->update(['material_lot_id' => $lotId]);
            DB::table('erp_inventory_batches')->where('item_id', $balance->item_id)->where('batch_no', $balance->batch_no)
                ->update(['material_lot_id' => $lotId]);
        }
        $holding = DB::table('erp_material_holdings')->where('inventory_balance_id', $balance->id)->lockForUpdate()->first();
        if (!$holding) {
            $id = DB::table('erp_material_holdings')->insertGetId([
                'material_lot_id' => $lotId,
                'position_type' => 'WAREHOUSE',
                'position_id' => $balance->warehouse_id,
                'inventory_balance_id' => $balance->id,
                'quantity' => null,
                'total_cost' => null,
                'status' => 'ACTIVE',
                'business_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $holding = DB::table('erp_material_holdings')->where('id', $id)->first();
        }
        if ((int) $holding->material_lot_id !== $lotId || $holding->position_type !== 'WAREHOUSE' || $holding->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['holding' => '仓库实物持有位置与库存余额不一致。']);
        }
        return $holding;
    }

    public function nonWarehouseHolding(int $lotId, string $positionType, int $positionId, string $cost, string $status): int
    {
        return DB::table('erp_material_holdings')->insertGetId([
            'material_lot_id' => $lotId,
            'position_type' => $positionType,
            'position_id' => $positionId,
            'inventory_balance_id' => null,
            'quantity' => '1',
            'total_cost' => $cost,
            'status' => $status,
            'business_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function movePhysical(
        object $physical,
        object $sourceHolding,
        int $targetHoldingId,
        string $action,
        string $physicalStatus,
        ?int $inventoryTransactionId,
        int $operatorId = 0,
    ): void {
        DB::table('erp_material_movements')->insert([
            'movement_no' => $this->numbers->next('material_movement', 'MM'),
            'source_holding_id' => $sourceHolding->id,
            'target_holding_id' => $targetHoldingId,
            'action' => $action,
            'quantity' => '1',
            'total_cost' => (string) $physical->total_cost,
            'inventory_transaction_id' => $inventoryTransactionId,
            'operator_legacy_id' => $operatorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('erp_material_physicals')->where('id', $physical->id)->update([
            'current_holding_id' => $targetHoldingId,
            'status' => $physicalStatus,
            'business_version' => (int) $physical->business_version + 1,
            'updated_at' => now(),
        ]);
    }

    public function assertPhysicalNotInOpenDocument(int $physicalId, ?int $ignoreAdjustmentId = null, ?int $ignoreReturnId = null): void
    {
        $adjustment = DB::table('erp_inventory_adjustment_item_physicals as link')
            ->join('erp_inventory_adjustment_items as line', 'line.id', '=', 'link.adjustment_item_id')
            ->join('erp_inventory_adjustments as doc', 'doc.id', '=', 'line.adjustment_id')
            ->where('link.physical_material_id', $physicalId)
            ->whereIn('doc.adjustment_status', ['draft', 'submitted'])
            ->when($ignoreAdjustmentId, fn ($query) => $query->where('doc.id', '<>', $ignoreAdjustmentId))
            ->exists();
        $purchaseReturn = DB::table('erp_purchase_return_item_physicals as link')
            ->join('erp_purchase_return_items as line', 'line.id', '=', 'link.purchase_return_item_id')
            ->join('erp_purchase_returns as doc', 'doc.id', '=', 'line.return_id')
            ->where('link.physical_material_id', $physicalId)
            ->whereIn('doc.return_status', ['draft', 'submitted', 'pending_outbound'])
            ->when($ignoreReturnId, fn ($query) => $query->where('doc.id', '<>', $ignoreReturnId))
            ->exists();
        if ($adjustment || $purchaseReturn) {
            throw ValidationException::withMessages(['physical_material_id' => '该实物已被另一张未完成的调整单或采购退货单占用。']);
        }
    }

    private function createPhysical(
        int $itemId,
        int $sourceTransactionItemId,
        int $lotId,
        int $holdingId,
        array $dimensions,
        string $cost,
    ): int {
        $id = DB::table('erp_material_physicals')->insertGetId([
            'physical_no' => $this->numbers->next('material_physical', 'PLATE'),
            'item_id' => $itemId,
            'source_transaction_item_id' => $sourceTransactionItemId,
            'material_lot_id' => $lotId,
            'current_holding_id' => $holdingId,
            'material_form' => 'FULL_STOCK',
            'shape' => 'RECTANGLE',
            'dimensions' => json_encode($this->dimensions($dimensions), JSON_THROW_ON_ERROR),
            'total_cost' => $cost,
            'status' => 'AVAILABLE',
            'business_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('erp_material_physicals')->where('id', $id)->update(['root_physical_id' => $id]);
        return $id;
    }

    private function dimensions(mixed $value): array
    {
        if (!is_array($value) || count($value) !== 3
            || array_diff(array_keys($value), ['length_mm', 'width_mm', 'thickness_mm']) !== []) {
            throw ValidationException::withMessages(['dimensions' => '板材尺寸必须且只能包含 length_mm、width_mm、thickness_mm。']);
        }
        $result = [];
        foreach ($value as $key => $number) {
            if ((!is_string($number) && !is_int($number)) || !preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', (string) $number)
                || bccomp((string) $number, '0', 2) <= 0) {
                throw ValidationException::withMessages(['dimensions' => '板材长、宽、厚必须使用大于 0 且最多两位小数的十进制值。']);
            }
            $result[$key] = bcadd((string) $number, '0', 2);
        }
        return $result;
    }

    private function money(mixed $value): string
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^\d{1,14}(?:\.\d{1,4})?$/', (string) $value)) {
            throw ValidationException::withMessages(['total_cost' => '实物总成本必须使用非负且最多四位小数的十进制值。']);
        }
        return bcadd((string) $value, '0', 4);
    }

    private function assertSheetItem(Item $item): void
    {
        if ($item->cuttingMode() !== 'sheet') {
            throw ValidationException::withMessages(['item_id' => "实物管理物料 {$item->item_code} 必须配置为板材下料模式。"]);
        }
    }

    private function isWhole(string $value): bool
    {
        return bccomp($value, bcadd($value, '0', 0), 8) === 0 && bccomp($value, '0', 8) >= 0;
    }

    private function qualifiedBaseQuantity(PurchaseReceiptItem $line): string
    {
        if ($line->final_stockable_base_qty !== null) return (string) $line->final_stockable_base_qty;
        if ($line->qualified_base_qty !== null) return (string) $line->qualified_base_qty;
        return (string) $line->qualified_qty;
    }
}
