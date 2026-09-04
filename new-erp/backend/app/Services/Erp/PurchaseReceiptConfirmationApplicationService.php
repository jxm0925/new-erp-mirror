<?php

namespace App\Services\Erp;

use App\Models\Erp\Item;
use App\Models\Erp\PurchaseLog;
use App\Models\Erp\PurchaseOrder;
use App\Models\Erp\PurchaseOrderItem;
use App\Models\Erp\PurchasePriceHistory;
use App\Models\Erp\PurchaseReceipt;
use App\Models\Erp\PurchaseReceiptItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseReceiptConfirmationApplicationService
{
    public function __construct(
        private readonly UnitConversionDomainService $units,
        private readonly PurchaseFinancialFactService $finance,
        private readonly PurchaseReceiptAllocationService $allocations,
        private readonly InventorySerialApplicationService $serials,
        private readonly PurchaseReceiptSettlementService $settlements,
        private readonly PurchaseSettlementSourceApplicationService $settlementSources,
        private readonly PurchaseExchangeApplicationService $exchanges,
        private readonly SupplierPerformanceService $performance,
        private readonly SupplierCapabilityService $capabilities,
    ) {
    }

    public function confirm(int $receiptId, ?int $operatorId, ?string $operatorName): PurchaseReceipt
    {
        return DB::transaction(function () use ($receiptId, $operatorId, $operatorName): PurchaseReceipt {
            $receipt = PurchaseReceipt::query()
                ->with(['items.baseUnit', 'items.item', 'order.items'])
                ->lockForUpdate()
                ->findOrFail($receiptId);
            if ($receipt->confirm_status !== 'draft') {
                throw ValidationException::withMessages(['receipt' => '只有草稿到货单可以确认。']);
            }

            $isReplacement = $receipt->settlement_mode === 'replacement_no_charge';
            if (!$isReplacement && !$receipt->order_id && blank($receipt->remark)) {
                throw ValidationException::withMessages(['remark' => '无采购订单的手工到货必须填写原因备注。']);
            }
            if (!$isReplacement && $receipt->items->contains(
                fn (PurchaseReceiptItem $line) => (float) $line->unit_price <= 0 || (float) $line->receipt_cost <= 0
            )) {
                throw ValidationException::withMessages(['items' => '普通采购到货存在零单价或零成本明细，不能确认。']);
            }
            if (!$isReplacement && $receipt->order) $this->assertOrderCanReceive($receipt->order);

            $hasStockItems = false;
            $physicalTotal = 0.0;
            $contractTotal = 0.0;
            $replacementTotal = 0.0;

            foreach ($receipt->items as $line) {
                $this->confirmLine($receipt, $line, $isReplacement, $operatorId);
                $line->refresh();
                $hasStockItems = $hasStockItems || (bool) $line->is_stock_item_snapshot;
                $physicalTotal += (float) $line->physical_received_base_qty;
                $contractTotal += (float) $line->contract_fulfilled_base_qty;
                $replacementTotal += (float) $line->replacement_received_base_qty;
            }

            $receipt->refresh()->load(['items.item', 'items.allocations', 'order']);
            $this->allocations->ensureForConfirmation($receipt);
            $this->serials->registerAcceptedReceipt($receipt);

            $requiresPosting = $receipt->items->contains(
                fn (PurchaseReceiptItem $line) => $line->is_stock_item_snapshot && (float) $line->final_stockable_base_qty > 0
            );
            $receipt->update([
                'receipt_status' => 'confirmed',
                'confirm_status' => 'confirmed',
                'has_stock_items' => $hasStockItems,
                'stock_post_status' => $hasStockItems ? 'pending' : 'not_required',
                'fulfillment_status' => ! $hasStockItems
                    ? 'fulfilled'
                    : ($requiresPosting ? 'pending_inventory_posting' : 'pending_quality'),
                'physical_received_base_qty' => round($physicalTotal, 8),
                'contract_fulfilled_base_qty' => round($contractTotal, 8),
                'replacement_received_base_qty' => round($replacementTotal, 8),
                'currency_snapshot' => $receipt->order?->currency ?: 'CNY',
                'tax_mode_snapshot' => $receipt->order?->tax_mode ?: 'tax_included',
                'finance_fact_status' => 'frozen',
            ]);

            $this->settlements->refresh($receipt);
            $this->settlementSources->syncReceipt($receipt->id, $operatorId, $operatorName);
            $this->exchanges->syncReplacementReceiptConfirmed($receipt->fresh(), $operatorName ?: '系统');
            if ($receipt->order_id && !$isReplacement) $this->refreshOrderReceiptStatus((int) $receipt->order_id);
            PurchaseLog::create([
                'target_type' => 'purchase_receipt',
                'target_id' => $receipt->id,
                'action' => 'confirm',
                'content' => $requiresPosting
                    ? '确认到货；库存物料进入待库存过账。'
                    : '确认到货；非库存物料验收后直接履约完成，不进入库存。',
                'operator' => $operatorName ?: '系统',
            ]);

            return $receipt->fresh(['items.item', 'items.allocations', 'order', 'supplier']);
        }, 5);
    }

    private function confirmLine(PurchaseReceipt $receipt, PurchaseReceiptItem $line, bool $isReplacement, ?int $operatorId): void
    {
        if ((float) $line->receipt_qty <= 0) {
            throw ValidationException::withMessages(['items' => '到货数量必须大于 0。']);
        }
        if (abs(((float) $line->qualified_qty + (float) $line->unqualified_qty) - (float) $line->receipt_qty) > 0.00000001) {
            throw ValidationException::withMessages(['items' => '合格采购数量与不合格采购数量之和必须等于本次到货采购数量。']);
        }

        $base = $this->units->calculateReceiptBaseQuantity(
            $line->receipt_qty,
            $line->conversion_factor_snapshot,
            $line->actual_base_qty === null ? null : (float) $line->actual_base_qty,
            (bool) $line->allow_actual_conversion,
            $line->difference_reason,
            $line->baseUnit,
        );
        $quality = $this->units->calculateReceiptQualityBaseQuantities(
            $line->receipt_qty,
            $line->qualified_qty,
            $line->unqualified_qty,
            $base['actual_base_qty'],
            $line->baseUnit,
        );
        $stockManaged = (bool) $line->item?->is_stock_item;
        $physicalBase = (float) $base['actual_base_qty'];
        $qualifiedBase = (float) $quality['qualified_base_qty'];

        $line->update([
            ...$base,
            ...$quality,
            ...$this->finance->freezeReceiptLine($receipt, $line),
            'is_stock_item_snapshot' => $stockManaged,
            'quality_fact_origin' => 'original_inspection',
            'original_received_qty' => $line->receipt_qty,
            'original_qualified_qty' => $line->qualified_qty,
            'original_unqualified_qty' => $line->unqualified_qty,
            'original_received_base_qty' => $physicalBase,
            'original_qualified_base_qty' => $qualifiedBase,
            'original_unqualified_base_qty' => (float) $quality['unqualified_base_qty'],
            'final_stockable_base_qty' => $stockManaged ? $qualifiedBase : 0,
            'physical_received_base_qty' => $physicalBase,
            'contract_fulfilled_base_qty' => $isReplacement ? 0 : $physicalBase,
            'replacement_received_base_qty' => $isReplacement ? $physicalBase : 0,
            'inventory_posting_status' => $stockManaged && $qualifiedBase > 0 ? 'pending' : 'not_required',
        ]);

        if (!$stockManaged) {
            $line->allocations()->delete();
            $line->update([
                'warehouse_id' => null,
                'location_id' => null,
                'batch_no' => null,
                'serial_text' => null,
                'serial_entries' => null,
                'serial_number_source' => null,
            ]);
        }

        $orderItem = null;
        if ($line->order_item_id && !$isReplacement) {
            $orderItem = PurchaseOrderItem::query()->lockForUpdate()->findOrFail($line->order_item_id);
            if ((float) $line->receipt_qty > (float) $orderItem->remaining_qty + 0.00000001) {
                throw ValidationException::withMessages(['items' => '到货数量不能超过订单剩余数量。']);
            }
            $received = (float) $orderItem->received_qty + (float) $line->receipt_qty;
            $remaining = max(0, (float) $orderItem->order_qty - $received);
            $orderItem->update([
                'received_qty' => $received,
                'remaining_qty' => $remaining,
                'receipt_cost' => (float) $orderItem->receipt_cost + (float) $line->receipt_cost,
                'line_status' => $remaining > 0 ? 'partial' : 'received',
            ]);
        }

        if ($isReplacement) return;

        PurchasePriceHistory::create([
            'supplier_id' => $receipt->supplier_id,
            'item_id' => $line->item_id,
            'unit_id' => $line->purchase_unit_id,
            'base_unit_id' => $line->base_unit_id,
            'conversion_factor_snapshot' => $line->conversion_factor_snapshot,
            'order_id' => $receipt->order_id,
            'receipt_id' => $receipt->id,
            'price' => $line->unit_price,
            'base_unit_price' => $this->units->calculateBaseUnitPrice($line->unit_price, $line->conversion_factor_snapshot),
            'currency' => $receipt->order?->currency ?: 'CNY',
            'tax_mode' => $receipt->order?->tax_mode ?: 'tax_included',
            'tax_rate' => $orderItem?->tax_rate ?: $line->tax_rate,
            'receipt_cost' => $line->receipt_cost,
            'effective_date' => $receipt->receipt_date ?: now()->toDateString(),
            'data_source' => 'manual',
        ]);
        Item::whereKey($line->item_id)->update([
            'last_purchase_price' => $this->units->calculateBaseUnitPrice($line->unit_price, $line->conversion_factor_snapshot),
        ]);
        $this->performance->refresh((int) $receipt->supplier_id, (int) $line->item_id);
        $this->capabilities->saveItemRelation((int) $receipt->supplier_id, [
            'item_id' => $line->item_id,
            'capability_source' => 'purchase_history',
            'relation_status' => 'active',
            'effective_at' => $receipt->receipt_date ?: now(),
            'change_reason' => '实际采购到货形成历史供货能力',
            'remark' => '来源到货单 '.$receipt->receipt_no,
        ], $operatorId);
    }

    private function assertOrderCanReceive(PurchaseOrder $order): void
    {
        if ($order->audit_status !== 'approved') {
            throw ValidationException::withMessages(['audit_status' => '未审核采购订单不能确认到货。']);
        }
        if (in_array($order->purchase_status, ['closed', 'cancelled'], true) || $order->receipt_status === 'received') {
            throw ValidationException::withMessages(['receipt_status' => '已关闭、已取消或已全部到货的订单不能再次确认正常到货。']);
        }
    }

    private function refreshOrderReceiptStatus(int $orderId): void
    {
        $items = PurchaseOrderItem::query()->where('order_id', $orderId)->get();
        $total = (float) $items->sum('order_qty');
        $received = (float) $items->sum('received_qty');
        $receiptStatus = $received <= 0 ? 'not_received' : ($received + 0.00000001 >= $total ? 'received' : 'partial');
        PurchaseOrder::query()->whereKey($orderId)->update([
            'receipt_status' => $receiptStatus,
            'purchase_status' => $receiptStatus === 'received' ? 'received' : 'partially_received',
        ]);
    }
}
