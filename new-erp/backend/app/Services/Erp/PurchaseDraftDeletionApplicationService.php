<?php

namespace App\Services\Erp;

use App\Models\Erp\InventorySerial;
use App\Models\Erp\PurchaseAttachment;
use App\Models\Erp\PurchaseLog;
use App\Models\Erp\PurchaseOrder;
use App\Models\Erp\PurchasePlan;
use App\Models\Erp\PurchasePlanItem;
use App\Models\Erp\PurchasePlanSupplierSplit;
use App\Models\Erp\PurchaseReceipt;
use App\Models\Erp\PurchaseRequest;
use App\Models\Erp\PurchaseRequestItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class PurchaseDraftDeletionApplicationService
{
    public function deleteRequest(int $id, ?string $operator = null): void
    {
        DB::transaction(function () use ($id, $operator): void {
            $request = PurchaseRequest::query()->lockForUpdate()->findOrFail($id);
            $this->assert(
                $request->request_status === 'draft'
                && $request->confirmed_at === null
                && $request->cancelled_at === null,
                '只有从未确认、未取消的采购需求草稿可以删除。'
            );
            $this->assert(!PurchasePlanItem::query()->where('request_id', $id)->exists(), '该采购需求已被采购计划引用，不能删除。');
            $this->recordDeletion('purchase_request', $id, '删除从未确认的采购需求草稿', $operator);
            $request->delete();
        }, 5);
    }

    public function deletePlan(int $id, ?string $operator = null): void
    {
        // 删除开始时捕获审计修订；若等待编辑事务后修订已变化，即使仍为草稿也要求刷新。
        // 单靠排队行锁会让“编辑成功后紧接着删除”两边都成功，吞掉本次并发编辑。
        $revision = (int) PurchaseLog::query()->where('target_type', 'purchase_plan')->where('target_id', $id)
            ->orderByDesc('id')->value('id');
        DB::transaction(function () use ($id, $operator, $revision): void {
            $plan = PurchasePlan::query()->with('items')->lockForUpdate()->findOrFail($id);
            $currentRevision = (int) PurchaseLog::query()->where('target_type', 'purchase_plan')->where('target_id', $id)
                ->orderByDesc('id')->lockForUpdate()->value('id');
            $this->assert($revision === $currentRevision, '采购计划已被其他操作修改，请刷新后重新确认删除。');
            $this->assert(
                $plan->plan_status === 'draft'
                && $plan->audit_status === 'pending'
                && $plan->approved_at === null,
                '只有从未提交审核的采购计划草稿可以删除；已驳回计划必须保留审核历史。'
            );
            $this->assert(!PurchaseOrder::query()->where('plan_id', $id)->exists(), '该采购计划已经生成采购订单，不能删除。');
            $this->assert(!PurchasePlanSupplierSplit::query()->where('plan_id', $id)
                ->where(fn ($query) => $query->whereNotNull('order_id')->orWhere('ordered_qty', '>', 0))->exists(), '该采购计划已有下游订单占用，不能删除。');
            $this->assert(!$this->hasApprovalHistory('purchase_plan', $id), '该采购计划已有提交或审核历史，不能删除。');

            $requestIds = [];
            foreach ($plan->items as $planItem) {
                if (!$planItem->request_item_id) continue;
                $requestItem = PurchaseRequestItem::query()->lockForUpdate()->find($planItem->request_item_id);
                if (!$requestItem) continue;
                $released = min((float) $requestItem->converted_qty, (float) ($planItem->required_qty ?: $planItem->plan_qty));
                $requestItem->update([
                    'converted_qty' => max(0, (float) $requestItem->converted_qty - $released),
                    'remaining_qty' => min((float) $requestItem->request_qty, (float) $requestItem->remaining_qty + $released),
                ]);
                if ($planItem->request_id) $requestIds[] = (int) $planItem->request_id;
            }
            foreach (array_unique($requestIds) as $requestId) $this->refreshRequest($requestId);

            $this->recordDeletion('purchase_plan', $id, '删除从未提交审核的采购计划草稿并释放需求占用', $operator);
            $plan->delete();
        }, 5);
    }

    public function deleteOrder(int $id, ?string $operator = null): void
    {
        DB::transaction(function () use ($id, $operator): void {
            $order = PurchaseOrder::query()->with(['items', 'receipts'])->lockForUpdate()->findOrFail($id);
            $this->assert(
                $order->purchase_status === 'draft'
                && $order->audit_status === 'pending'
                && $order->finance_fact_status === 'pending',
                '只有从未提交审核、未冻结财务事实的采购订单草稿可以删除；已驳回订单必须保留审核历史。'
            );
            $this->assert($order->receipts->isEmpty(), '该采购订单已经生成到货单，不能删除。');
            $this->assert(!$this->hasApprovalHistory('purchase_order', $id), '该采购订单已有提交或审核历史，不能删除。');
            $this->assert(!DB::table('erp_purchase_price_histories')->where('order_id', $id)->exists(), '该采购订单已经形成采购价格历史，不能删除。');

            $planIds = [];
            foreach ($order->items as $item) {
                if (!$item->plan_split_id) continue;
                $split = PurchasePlanSupplierSplit::query()->lockForUpdate()->find($item->plan_split_id);
                if (!$split || (int) $split->order_id !== $order->id) continue;
                $released = min((float) $split->ordered_qty, (float) $item->planned_base_qty);
                $split->update(['ordered_qty' => max(0, (float) $split->ordered_qty - $released), 'order_id' => null, 'order_item_id' => null, 'split_status' => 'not_ordered']);
                if ($item->plan_item_id && ($planItem = PurchasePlanItem::query()->lockForUpdate()->find($item->plan_item_id))) {
                    $planItem->update(['ordered_qty' => max(0, (float) $planItem->ordered_qty - $released)]);
                }
                $planIds[] = (int) $split->plan_id;
            }
            foreach (array_unique($planIds) as $planId) $this->refreshPlanOrderStatus($planId);

            $this->deleteAttachments('order', $id);
            $this->recordDeletion('purchase_order', $id, '删除从未提交审核的采购订单草稿并释放计划占用', $operator);
            $order->delete();
        }, 5);
    }

    public function deleteReceipt(int $id, ?string $operator = null): void
    {
        DB::transaction(function () use ($id, $operator): void {
            $receipt = PurchaseReceipt::query()->with('items')->lockForUpdate()->findOrFail($id);
            $this->assert($receipt->confirm_status === 'draft' && $receipt->receipt_status === 'draft' && $receipt->confirmed_at === null, '只有未确认的采购到货草稿可以删除。');
            $this->assert($receipt->stock_post_status === 'pending', '该到货单已经发生库存过账，不能删除。');
            $this->assert(!DB::table('erp_purchase_defect_handlings')->where('receipt_id', $id)->exists(), '该到货单已经产生不合格品处理，不能删除。');
            $this->assert(!DB::table('erp_purchase_returns')->where('source_receipt_id', $id)->exists(), '该到货单已经产生采购退货单，不能删除。');
            $this->assert(!DB::table('erp_purchase_exchange_orders')->where('source_receipt_id', $id)->orWhere('replacement_receipt_id', $id)->exists(), '该到货单已经进入采购换货流程，不能删除。');
            $this->assert(!DB::table('erp_inventory_transactions')->where('source_type', 'purchase_receipt')->where('source_id', $id)->exists(), '该到货单已经生成库存流水，不能删除。');

            $serials = InventorySerial::query()->where('source_receipt_id', $id)->lockForUpdate()->get();
            $this->assert(!$serials->contains(fn ($serial) => $serial->posted_at || $serial->inventory_balance_id), '该到货草稿包含已过账设备编号，不能删除。');
            if ($serials->isNotEmpty()) {
                DB::table('erp_inventory_serial_events')->whereIn('inventory_serial_id', $serials->pluck('id'))->delete();
                InventorySerial::query()->whereIn('id', $serials->pluck('id'))->delete();
            }
            $this->deleteAttachments('receipt', $id);
            $this->recordDeletion('purchase_receipt', $id, '删除未确认且未产生库存事实的采购到货草稿', $operator);
            $receipt->delete();
        }, 5);
    }

    private function refreshRequest(int $requestId): void
    {
        $request = PurchaseRequest::query()->with('items')->lockForUpdate()->find($requestId);
        if (!$request) return;
        $planned = (float) $request->items->sum('converted_qty');
        $total = (float) $request->items->sum('request_qty');
        // 释放草稿计划仅恢复数量，不撤销人工关闭/取消决定，避免旧需求被重新开放。
        $status = in_array($request->request_status, ['closed', 'cancelled'], true)
            ? $request->request_status
            : ($planned <= 0 ? 'confirmed' : ($planned < $total ? 'partially_planned' : 'planned'));
        $request->update(['planned_qty' => $planned, 'request_status' => $status, 'status' => $status]);
    }

    private function refreshPlanOrderStatus(int $planId): void
    {
        $total = PurchasePlanSupplierSplit::query()->where('plan_id', $planId)->count();
        $ordered = PurchasePlanSupplierSplit::query()->where('plan_id', $planId)->where('split_status', 'ordered')->count();
        PurchasePlan::query()->whereKey($planId)->update(['order_status' => $ordered === 0 ? 'not_ordered' : ($ordered < $total ? 'partially_ordered' : 'order_generated')]);
    }

    private function deleteAttachments(string $type, int $id): void
    {
        $attachments = PurchaseAttachment::query()
            ->where('document_type', $type)
            ->where('document_id', $id)
            ->lockForUpdate()
            ->get(['id', 'storage_disk', 'storage_path']);

        if ($attachments->isEmpty()) return;

        PurchaseAttachment::query()->whereKey($attachments->pluck('id'))->delete();
        $files = $attachments->map(fn (PurchaseAttachment $attachment) => [
            'disk' => $attachment->storage_disk,
            'path' => $attachment->storage_path,
        ])->all();

        DB::afterCommit(function () use ($files): void {
            foreach ($files as $file) {
                if (!$file['path']) continue;
                try {
                    Storage::disk($file['disk'] ?: config('filesystems.default'))->delete($file['path']);
                } catch (\Throwable $error) {
                    report($error);
                }
            }
        });
    }

    private function recordDeletion(string $type, int $id, string $content, ?string $operator): void
    {
        PurchaseLog::create([
            'target_type' => $type,
            'target_id' => $id,
            'action' => 'delete_draft',
            'content' => $content,
            'operator' => $operator ?: '系统任务',
        ]);
    }

    private function hasApprovalHistory(string $type, int $id): bool
    {
        return PurchaseLog::query()->where('target_type', $type)->where('target_id', $id)
            ->whereIn('action', ['submit', 'approve', 'reject'])->exists();
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) throw ValidationException::withMessages(['delete' => $message]);
    }
}
