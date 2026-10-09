<?php

namespace App\Services\Erp;

use App\Models\Erp\Item;
use App\Models\Erp\InventoryTransaction;
use App\Models\Erp\InventoryPostingLog;
use App\Models\Erp\PurchaseOrder;
use App\Models\Erp\PurchaseReceipt;
use App\Models\Erp\PurchaseReceiptItem;
use Illuminate\Validation\ValidationException;

final class PurchaseReceiptManagementScopeService
{
    public function __construct(private readonly PurchaseManagementScopeService $scopes) {}

    /** IDs are issued only by the first-confirmation preflight in this transaction. */
    public function assertReceipt(PurchaseReceipt $receipt, array $pendingDraftScopeLineIds = []): string
    {
        $locked = PurchaseReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
        $scope = $this->scopes->assertDocumentScope($locked);
        if ($locked->order_id) {
            $order = PurchaseOrder::query()->whereKey($locked->order_id)->lockForUpdate()->firstOrFail();
            if ($this->scopes->assertDocumentScope($order) !== $scope) {
                throw ValidationException::withMessages(['management_scope' => '到货单管理范围必须与原采购订单一致。']);
            }
        }
        $lines = $locked->items()->orderBy('id')->lockForUpdate()->get();
        foreach ($lines as $line) $this->assertLine($line, $scope, $pendingDraftScopeLineIds);
        $receipt->setAttribute('management_scope', $scope);
        return $scope;
    }

    public function assertLine(PurchaseReceiptItem $line, ?string $expectedScope = null, array $pendingDraftScopeLineIds = []): Item
    {
        $receipt = PurchaseReceipt::query()->whereKey($line->receipt_id)->lockForUpdate()->firstOrFail();
        $scope = $this->scopes->assertScope($receipt->management_scope);
        $stored = PurchaseReceiptItem::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();
        $pendingDraftScope = $stored->management_scope_snapshot === null
            && in_array((int) $stored->id, $pendingDraftScopeLineIds, true)
            && $receipt->confirm_status === 'draft' && $receipt->receipt_status === 'draft';
        if (($expectedScope !== null && $expectedScope !== $scope) || (!$pendingDraftScope && $stored->management_scope_snapshot !== $scope)) {
            throw ValidationException::withMessages(['management_scope' => '到货单管理范围与明细来源快照不一致，请核对后重新建立草稿。']);
        }
        $item = $this->scopes->assertItemScope((int) $stored->item_id, $scope);
        $snapshot = $stored->is_stock_item_snapshot;
        if ($snapshot === null || (bool) $snapshot !== (bool) $item->is_stock_item
            || (is_array($stored->material_policy_snapshot)
                && array_key_exists('is_stock_managed', $stored->material_policy_snapshot)
                && (bool) $stored->material_policy_snapshot['is_stock_managed'] !== (bool) $snapshot)) {
            throw ValidationException::withMessages(['items' => '物料库存政策与到货单冻结快照不一致，请核对原政策并重新建立草稿；不能在确认或过账时改写库存口径。']);
        }
        foreach (['management_scope_snapshot', 'is_stock_item_snapshot', 'material_policy_snapshot'] as $field) {
            $line->setAttribute($field, $stored->getAttribute($field));
        }
        $line->setRelation('item', $item);
        return $item;
    }

    /** A legacy draft has no receipt fact yet; confirmed historical facts are never inferred. */
    public function prepareDraftConfirmation(PurchaseReceipt $receipt): array
    {
        $receipt = PurchaseReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
        $this->scopes->assertScope($receipt->management_scope);
        $pending = $receipt->items()->whereNull('management_scope_snapshot')->orderBy('id')->lockForUpdate()->get();
        if ($pending->isNotEmpty()) {
            if ($receipt->confirm_status !== 'draft' || $receipt->receipt_status !== 'draft'
                || $receipt->stock_post_status === 'posted'
                || InventoryTransaction::query()->where('source_type', 'purchase_receipt')->where('source_id', $receipt->id)->exists()
                || InventoryPostingLog::query()->where('source_type', 'purchase_receipt')->where('source_id', $receipt->id)->exists()
                || $pending->contains(fn ($line) => $line->is_stock_item_snapshot === null || $line->facts_frozen_at !== null
                    || $line->finance_fact_status === 'frozen' || $line->inventory_posting_status === 'posted'
                    || (float) $line->original_received_qty > 0 || (float) $line->original_received_base_qty > 0
                    || (float) $line->physical_received_base_qty > 0 || (float) $line->contract_fulfilled_base_qty > 0)) {
                throw ValidationException::withMessages(['management_scope' => '缺少范围快照的历史收货事实不能重新推断；仅首次确认前的明确草稿允许补齐范围。']);
            }
        }
        $ids = $pending->modelKeys();
        $this->assertReceipt($receipt, $ids);
        return $ids;
    }

    /** Persist only after quantity, allocation, physical and serial confirmation checks passed. */
    public function completeDraftScopeSnapshots(PurchaseReceipt $receipt, array $pendingDraftScopeLineIds): void
    {
        foreach ($pendingDraftScopeLineIds as $id) {
            $line = PurchaseReceiptItem::query()->where('receipt_id', $receipt->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->assertLine($line, $receipt->management_scope, $pendingDraftScopeLineIds);
            $line->update(['management_scope_snapshot' => $receipt->management_scope]);
        }
    }
}
