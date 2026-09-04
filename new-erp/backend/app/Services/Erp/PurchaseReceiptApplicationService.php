<?php

namespace App\Services\Erp;

use App\Models\Erp\PurchaseLog;
use App\Models\Erp\PurchaseOrder;
use App\Models\Erp\PurchaseOrderItem;
use App\Models\Erp\PurchaseReceipt;
use App\Models\Erp\PurchaseReceiptItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseReceiptApplicationService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly PurchaseConversionApplicationService $conversions,
    ) {
    }

    public function generateFromOrder(int $orderId, ?string $operatorName): PurchaseReceipt
    {
        return DB::transaction(function () use ($orderId, $operatorName): PurchaseReceipt {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($orderId);
            $items = PurchaseOrderItem::query()
                ->with('item')
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->get();

            $this->assertOrderCanGenerate($order);
            $openDraft = $this->normalDrafts($order->id)->lockForUpdate()->first();
            if ($openDraft) {
                throw ValidationException::withMessages([
                    'receipt' => "该采购订单已有未确认到货单 {$openDraft->receipt_no}，请先编辑或确认该单，不能重复生成。",
                ]);
            }

            $allocations = $this->draftAllocations($items->pluck('id'));
            $available = $items->mapWithKeys(fn (PurchaseOrderItem $item) => [
                $item->id => max(0, (float) $item->remaining_qty - (float) ($allocations[$item->id] ?? 0)),
            ]);
            if ($available->every(fn (float $quantity) => $quantity <= 0.00000001)) {
                throw ValidationException::withMessages(['receipt' => '采购订单已无可生成的剩余到货数量。']);
            }

            $receipt = PurchaseReceipt::create([
                'receipt_no' => $this->numbers->next('purchase_receipt', 'PRC'),
                'order_id' => $order->id,
                'supplier_id' => $order->supplier_id,
                'receipt_date' => now()->toDateString(),
                'receipt_status' => 'draft',
                'confirm_status' => 'draft',
                'stock_post_status' => 'pending',
                'settlement_mode' => 'normal',
                'data_source' => 'manual',
                'remark' => "由采购订单 {$order->purchase_order_no} 生成",
            ]);

            foreach ($items as $line) {
                $quantity = (float) ($available[$line->id] ?? 0);
                if ($quantity <= 0.00000001) continue;
                $conversion = $this->conversions->receiptLineSnapshot([
                    'order_item_id' => $line->id,
                    'item_id' => $line->item_id,
                    'is_stock_item_snapshot' => (bool) $line->item?->is_stock_item,
                    'receipt_qty' => $quantity,
                ]);
                PurchaseReceiptItem::create([
                    'receipt_id' => $receipt->id,
                    'order_item_id' => $line->id,
                    'item_id' => $line->item_id,
                    'receipt_qty' => $quantity,
                    'qualified_qty' => $quantity,
                    'unit_price' => $line->unit_price,
                    'receipt_cost' => $quantity * (float) $line->unit_price,
                    'inventory_posting_status' => $line->item?->is_stock_item ? 'pending' : 'not_required',
                    ...$conversion,
                    'data_source' => 'manual',
                ]);
            }

            $this->refreshReceiptTotal($receipt->id);
            PurchaseLog::create([
                'target_type' => 'purchase_receipt',
                'target_id' => $receipt->id,
                'action' => 'create_from_order',
                'content' => "由采购订单 {$order->purchase_order_no} 生成；草稿数量已占用，确认前禁止重复生成。",
                'operator' => $operatorName ?: '系统任务',
            ]);

            return $receipt->fresh(['items.item.unit', 'order', 'supplier']);
        }, 5);
    }

    public function assertDraftAllocation(array $payload, ?PurchaseReceipt $currentReceipt = null): void
    {
        if ($currentReceipt?->settlement_mode === 'replacement_no_charge') return;

        $orderId = (int) ($payload['order_id'] ?? 0);
        if (!$orderId) {
            if (collect($payload['items'] ?? [])->contains(fn (array $line) => !empty($line['order_item_id']))) {
                throw ValidationException::withMessages(['order_id' => '填写采购订单明细时必须同时关联采购订单。']);
            }
            return;
        }

        $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($orderId);
        $items = PurchaseOrderItem::query()
            ->where('order_id', $order->id)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $this->assertOrderCanGenerate($order);
        if ((int) $payload['supplier_id'] !== (int) $order->supplier_id) {
            throw ValidationException::withMessages(['supplier_id' => '到货单供应商必须与采购订单供应商一致。']);
        }

        $otherDraft = $this->normalDrafts($order->id)
            ->when($currentReceipt, fn ($query) => $query->where('id', '!=', $currentReceipt->id))
            ->lockForUpdate()
            ->first();
        if ($otherDraft) {
            throw ValidationException::withMessages([
                'order_id' => "该采购订单已有未确认到货单 {$otherDraft->receipt_no}，请先处理该草稿。",
            ]);
        }

        $allocations = $this->draftAllocations($items->keys(), $currentReceipt?->id);
        $requested = collect($payload['items'] ?? [])->groupBy('order_item_id');
        foreach ($requested as $orderItemId => $lines) {
            if (!$orderItemId || !$items->has((int) $orderItemId)) {
                throw ValidationException::withMessages(['items' => '到货明细必须关联当前采购订单的明细行。']);
            }
            $orderItem = $items->get((int) $orderItemId);
            if ($lines->contains(fn (array $line) => (int) $line['item_id'] !== (int) $orderItem->item_id)) {
                throw ValidationException::withMessages(['items' => '到货物料与采购订单明细不一致。']);
            }
            $quantity = (float) $lines->sum(fn (array $line) => (float) ($line['receipt_qty'] ?? 0));
            $available = max(0, (float) $orderItem->remaining_qty - (float) ($allocations[$orderItem->id] ?? 0));
            if ($quantity - $available > 0.00000001) {
                throw ValidationException::withMessages([
                    'items' => "物料 {$orderItem->item_id} 本次到货 {$quantity} 超过可用剩余数量 {$available}。",
                ]);
            }
        }

        if ($requested->contains(fn ($lines, $orderItemId) => !$orderItemId)) {
            throw ValidationException::withMessages(['items' => '关联采购订单的到货明细必须填写订单明细 ID。']);
        }
    }

    /**
     * 换货免费补发到货单只允许录入验收信息，来源与商业字段必须使用数据库快照。
     */
    public function protectReplacementDraft(PurchaseReceipt $receipt, array $payload): array
    {
        if ($receipt->settlement_mode !== 'replacement_no_charge') return $payload;

        $storedLines = PurchaseReceiptItem::query()
            ->where('receipt_id', $receipt->id)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $submitted = collect($payload['items'] ?? [])->keyBy(fn (array $line) => (int) ($line['id'] ?? 0));

        if ($storedLines->isEmpty()
            || $submitted->keys()->filter()->sort()->values()->all() !== $storedLines->keys()->sort()->values()->all()) {
            throw ValidationException::withMessages([
                'items' => '换货补发到货单的来源明细不可新增、删除或替换。',
            ]);
        }

        $lockedFields = [
            'order_item_id', 'item_id', 'purchase_unit_id', 'purchase_unit_name_snapshot',
            'conversion_factor_snapshot', 'base_unit_id', 'base_unit_name_snapshot',
            'receipt_qty', 'unit_price', 'tax_rate', 'receipt_cost', 'batch_no',
            'standard_base_qty', 'allow_actual_conversion', 'data_source',
        ];

        $payload['order_id'] = $receipt->order_id;
        $payload['supplier_id'] = $receipt->supplier_id;
        $payload['items'] = $storedLines->map(function (PurchaseReceiptItem $stored) use ($submitted, $lockedFields): array {
            $line = $submitted->get($stored->id, []);
            foreach ($lockedFields as $field) $line[$field] = $stored->getAttribute($field);
            $line['id'] = $stored->id;
            return $line;
        })->values()->all();

        return $payload;
    }

    public function decorateOrders(Collection $orders): Collection
    {
        if ($orders->isEmpty()) return $orders;

        $orderIds = $orders->pluck('id');
        $drafts = PurchaseReceipt::query()
            ->whereIn('order_id', $orderIds)
            ->where('confirm_status', 'draft')
            ->where(fn ($query) => $query->whereNull('settlement_mode')->orWhere('settlement_mode', '!=', 'replacement_no_charge'))
            ->with('items:id,receipt_id,order_item_id,receipt_qty')
            ->get()
            ->groupBy('order_id');

        return $orders->each(function (PurchaseOrder $order) use ($drafts): void {
            $openDrafts = $drafts->get($order->id, collect());
            $pendingByItem = $openDrafts->flatMap->items
                ->groupBy('order_item_id')
                ->map(fn (Collection $lines) => (float) $lines->sum('receipt_qty'));
            $pending = (float) $pendingByItem->sum();
            $available = (float) $order->items->sum(fn (PurchaseOrderItem $item) =>
                max(0, (float) $item->remaining_qty - (float) ($pendingByItem[$item->id] ?? 0))
            );
            $order->setAttribute('open_receipt_count', $openDrafts->count());
            $order->setAttribute('open_receipt_id', $openDrafts->first()?->id);
            $order->setAttribute('open_receipt_no', $openDrafts->first()?->receipt_no);
            $order->setAttribute('pending_receipt_qty', $pending);
            $order->setAttribute('available_receipt_qty', $available);
            $order->setAttribute('can_generate_receipt',
                $order->audit_status === 'approved'
                && !in_array($order->purchase_status, ['closed', 'cancelled', 'received'], true)
                && $order->receipt_status !== 'received'
                && $openDrafts->isEmpty()
                && $available > 0.00000001
            );
        });
    }

    public function decorateOrder(PurchaseOrder $order): PurchaseOrder
    {
        return $this->decorateOrders(collect([$order]))->first();
    }

    private function normalDrafts(int $orderId)
    {
        return PurchaseReceipt::query()
            ->where('order_id', $orderId)
            ->where('confirm_status', 'draft')
            ->where(fn ($query) => $query->whereNull('settlement_mode')->orWhere('settlement_mode', '!=', 'replacement_no_charge'));
    }

    private function draftAllocations(Collection $orderItemIds, ?int $excludeReceiptId = null): Collection
    {
        if ($orderItemIds->isEmpty()) return collect();

        return PurchaseReceiptItem::query()
            ->join('erp_purchase_receipts as receipt', 'receipt.id', '=', 'erp_purchase_receipt_items.receipt_id')
            ->whereIn('erp_purchase_receipt_items.order_item_id', $orderItemIds)
            ->where('receipt.confirm_status', 'draft')
            ->where(fn ($query) => $query->whereNull('receipt.settlement_mode')->orWhere('receipt.settlement_mode', '!=', 'replacement_no_charge'))
            ->when($excludeReceiptId, fn ($query) => $query->where('receipt.id', '!=', $excludeReceiptId))
            ->groupBy('erp_purchase_receipt_items.order_item_id')
            ->selectRaw('erp_purchase_receipt_items.order_item_id, SUM(erp_purchase_receipt_items.receipt_qty) as allocated_qty')
            ->pluck('allocated_qty', 'order_item_id');
    }

    private function assertOrderCanGenerate(PurchaseOrder $order): void
    {
        if ($order->audit_status !== 'approved') {
            throw ValidationException::withMessages(['audit_status' => '未审核采购订单不能生成到货单。']);
        }
        if (in_array($order->purchase_status, ['closed', 'cancelled', 'received'], true) || $order->receipt_status === 'received') {
            throw ValidationException::withMessages(['receipt_status' => '已关闭、已取消或已全部到货的订单不能生成到货单。']);
        }
    }

    private function refreshReceiptTotal(int $receiptId): void
    {
        $totals = PurchaseReceiptItem::query()
            ->where('receipt_id', $receiptId)
            ->selectRaw('COALESCE(SUM(receipt_qty),0) qty, COALESCE(SUM(qualified_qty),0) q, COALESCE(SUM(unqualified_qty),0) uq, COALESCE(SUM(receipt_cost),0) amount')
            ->first();
        PurchaseReceipt::whereKey($receiptId)->update([
            'total_receipt_qty' => $totals->qty,
            'total_qualified_qty' => $totals->q,
            'total_unqualified_qty' => $totals->uq,
            'total_amount' => $totals->amount,
        ]);
    }
}
