<?php

namespace App\Services\Erp;

use App\Domain\Finance\Money;
use App\Models\Erp\{PurchaseOrder, PurchaseOrderItem, SalesOrder, SalesOrderLog, SalesOrderPurchaseLink};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Procurement attribution only: never posts inventory, payables or cash. */
class SalesOrderPurchaseLinkApplicationService
{
    public function add(int $orderId, array $data, string $operator): SalesOrderPurchaseLink
    {
        return DB::transaction(function () use ($orderId, $data, $operator) {
            $order = SalesOrder::query()->lockForUpdate()->findOrFail($orderId);
            $quantity = bcadd((string) $data['purchase_qty'], '0', 8);
            $hash = hash('sha256', json_encode([$orderId, (int) $data['purchase_order_item_id'], $quantity, trim($data['reason'])]));
            $existing = SalesOrderPurchaseLink::where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($existing) {
                if ($existing->request_hash !== $hash) $this->fail('idempotency_key', '重复请求的内容已改变，请刷新后重试。');
                return $existing;
            }
            $this->version($order, $data);
            if (!in_array($order->order_status, ['confirmed', 'closed'], true)) $this->fail('sales_order_id', '仅已确认或已关闭的正式销售订单可以新增采购关联。');
            $item = PurchaseOrderItem::findOrFail($data['purchase_order_item_id']);
            $purchase = PurchaseOrder::query()->lockForUpdate()->findOrFail($item->order_id);
            $item = PurchaseOrderItem::query()->lockForUpdate()->findOrFail($item->id);
            if ($purchase->audit_status !== 'approved' || in_array($purchase->purchase_status, ['cancelled', 'voided'], true)) {
                $this->fail('purchase_order_item_id', '请选择已审核且未取消的采购订单明细。');
            }
            // Locking current reads remain correct after waiting under MySQL REPEATABLE READ.
            $active = SalesOrderPurchaseLink::where('purchase_order_item_id', $item->id)->where('status', 'active')->lockForUpdate()->get();
            $allocated = $active->reduce(fn ($sum, $row) => bcadd($sum, $row->purchase_qty, 8), '0');
            if (bccomp($quantity, '0', 8) <= 0 || bccomp(bcadd($allocated, $quantity, 8), (string) $item->purchase_qty, 8) > 0) {
                $this->fail('purchase_qty', '关联数量必须大于零，且不能超过该采购明细尚未归属的数量。');
            }
            $amount = $item->contract_amount_snapshot === null ? null
                : Money::normalize(bcdiv(bcmul((string) $item->contract_amount_snapshot, $quantity, 16), (string) $item->purchase_qty, 8));
            if ($amount !== null) {
                $remaining = Money::sub((string) $item->contract_amount_snapshot, $active->reduce(fn ($sum, $row) => Money::add($sum, $row->contract_amount ?? '0'), '0'));
                if (bccomp(bcadd($allocated, $quantity, 8), (string) $item->purchase_qty, 8) === 0 || Money::compare($amount, $remaining) > 0) $amount = $remaining;
            }
            $link = SalesOrderPurchaseLink::create([
                'sales_order_id' => $orderId, 'purchase_order_id' => $purchase->id, 'purchase_order_item_id' => $item->id,
                'purchase_qty' => $quantity, 'contract_amount' => $amount, 'currency' => $item->currency_snapshot ?: $purchase->currency,
                'source_snapshot' => ['order_no' => $purchase->purchase_order_no, 'supplier_id' => $purchase->supplier_id,
                    'supplier_name' => $purchase->supplier?->supplier_name, 'item_id' => $item->item_id,
                    'item_code' => $item->item?->item_code, 'item_name' => $item->item?->item_name,
                    'spec_model' => $item->spec_model ?: ($item->item?->spec ?: $item->item?->model),
                    'purchase_unit_id' => $item->purchase_unit_id, 'purchase_unit_name' => $item->purchaseUnit?->unit_name,
                    'source_purchase_qty' => $item->purchase_qty, 'source_contract_amount' => $item->contract_amount_snapshot],
                'status' => 'active', 'idempotency_key' => $data['idempotency_key'], 'request_hash' => $hash,
                'reason' => trim($data['reason']), 'created_by' => $operator,
            ]);
            $order->update(['purchase_link_version' => $order->purchase_link_version + 1]);
            $this->log($order, $link, 'purchase_link_add', $operator, $link->reason);
            return $link;
        }, 5);
    }

    public function reverse(int $orderId, int $linkId, array $data, string $operator): SalesOrderPurchaseLink
    {
        return DB::transaction(function () use ($orderId, $linkId, $data, $operator) {
            $order = SalesOrder::query()->lockForUpdate()->findOrFail($orderId);
            $link = SalesOrderPurchaseLink::where('sales_order_id', $orderId)->findOrFail($linkId);
            PurchaseOrder::query()->lockForUpdate()->findOrFail($link->purchase_order_id);
            PurchaseOrderItem::query()->lockForUpdate()->findOrFail($link->purchase_order_item_id);
            $link = SalesOrderPurchaseLink::query()->lockForUpdate()->findOrFail($linkId);
            if ($link->status === 'reversed') {
                if ($link->reverse_reason !== trim($data['reason'])) $this->fail('reason', '该关联已撤销，撤销原因不可覆盖。');
                return $link;
            }
            $this->version($order, $data);
            $link->update(['status' => 'reversed', 'reverse_reason' => trim($data['reason']), 'reversed_by' => $operator, 'reversed_at' => now()]);
            $order->update(['purchase_link_version' => $order->purchase_link_version + 1]);
            $this->log($order, $link, 'purchase_link_reverse', $operator, $link->reverse_reason);
            return $link;
        }, 5);
    }

    private function version(SalesOrder $order, array $data): void
    {
        if ((int) $order->purchase_link_version !== (int) $data['version']) $this->fail('version', '订单采购关联已被修改，请刷新后重试。');
    }

    private function log(SalesOrder $order, SalesOrderPurchaseLink $link, string $action, string $operator, string $reason): void
    {
        SalesOrderLog::create(['sales_order_id' => $order->id, 'order_no_snapshot' => $order->sales_order_no,
            'action' => $action, 'operator' => $operator, 'content' => $reason, 'payload' => $link->toArray()]);
    }

    private function fail(string $field, string $message): never { throw ValidationException::withMessages([$field => $message]); }
}
