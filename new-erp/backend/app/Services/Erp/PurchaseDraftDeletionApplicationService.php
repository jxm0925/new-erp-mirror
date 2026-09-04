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
use Illuminate\Validation\ValidationException;

class PurchaseDraftDeletionApplicationService
{
    public function deleteRequest(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $request = PurchaseRequest::query()->lockForUpdate()->findOrFail($id);
            $this->assert($request->request_status === 'draft', '只有未确认的采购需求草稿可以删除。');
            $this->assert(!PurchasePlanItem::query()->where('request_id', $id)->exists(), '该采购需求已被采购计划引用，不能删除。');
            $this->deleteTrace('purchase_request', $id);
            $request->delete();
        }, 5);
    }

    public function deletePlan(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $plan = PurchasePlan::query()->with('items')->lockForUpdate()->findOrFail($id);
            $this->assert($plan->plan_status === 'draft', '只有草稿采购计划可以删除。');
            $this->assert(!PurchaseOrder::query()->where('plan_id', $id)->exists(), '该采购计划已经生成采购订单，不能删除。');
            $this->assert(!PurchasePlanSupplierSplit::query()->where('plan_id', $id)
                ->where(fn ($query) => $query->whereNotNull('order_id')->orWhere('ordered_qty', '>', 0))->exists(), '该采购计划已有下游订单占用，不能删除。');

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

            $this->deleteTrace('purchase_plan', $id);
            $plan->delete();
        }, 5);
    }

    public function deleteOrder(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $order = PurchaseOrder::query()->with(['items', 'receipts'])->lockForUpdate()->findOrFail($id);
            $this->assert($order->purchase_status === 'draft', '只有草稿或驳回后返回草稿的采购订单可以删除。');
            $this->assert($order->receipts->isEmpty(), '该采购订单已经生成到货单，不能删除。');
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
            $this->deleteTrace('purchase_order', $id);
            $order->delete();
        }, 5);
    }

    public function deleteReceipt(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $receipt = PurchaseReceipt::query()->with('items')->lockForUpdate()->findOrFail($id);
            $this->assert($receipt->confirm_status === 'draft' && $receipt->receipt_status === 'draft', '只有未确认的采购到货草稿可以删除。');
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
            $this->deleteTrace('purchase_receipt', $id);
            $receipt->delete();
        }, 5);
    }

    private function refreshRequest(int $requestId): void
    {
        $request = PurchaseRequest::query()->with('items')->lockForUpdate()->find($requestId);
        if (!$request) return;
        $planned = (float) $request->items->sum('converted_qty');
        $total = (float) $request->items->sum('request_qty');
        $status = $planned <= 0 ? 'confirmed' : ($planned < $total ? 'partially_planned' : 'planned');
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
        PurchaseAttachment::query()->where('document_type', $type)->where('document_id', $id)->update(['status' => 'deleted', 'deleted_by' => '删除草稿', 'deleted_at' => now()]);
    }

    private function deleteTrace(string $type, int $id): void
    {
        PurchaseLog::query()->where('target_type', $type)->where('target_id', $id)->delete();
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) throw ValidationException::withMessages(['delete' => $message]);
    }
}
