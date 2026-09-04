<?php

namespace App\Services\Erp;

use App\Models\Erp\PurchaseLog;
use App\Models\Erp\PurchaseOrder;
use App\Models\Erp\PurchasePlan;
use App\Models\Erp\PurchasePlanItem;
use App\Models\Erp\PurchasePlanSupplierSplit;
use App\Models\Erp\PurchaseRequest;
use App\Services\Erp\ApprovalIntegrations\PurchaseOrderApprovalIntegration;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseWorkflowApplicationService
{
    public function __construct(
        private readonly PurchaseFinancialFactService $finance,
        private readonly PurchaseOrderApprovalIntegration $approvalIntegration,
    )
    {
    }

    public function approvePlan(int $id, ?string $operator): PurchasePlan
    {
        return DB::transaction(function () use ($id, $operator): PurchasePlan {
            $plan = PurchasePlan::query()->lockForUpdate()->findOrFail($id);
            if ($plan->plan_status !== 'submitted' || $plan->audit_status !== 'pending') {
                throw ValidationException::withMessages(['plan_status' => '只有已提交且待审核的采购计划可以审核通过。']);
            }
            $plan->update([
                'plan_status' => 'approved',
                'audit_status' => 'approved',
                'approved_by' => $operator,
                'approved_at' => now(),
            ]);
            $this->log('purchase_plan', $plan->id, 'approve', '采购计划审核通过', $operator);

            return $plan->fresh(['items.item', 'items.splits.supplier']);
        }, 5);
    }

    public function rejectPlan(int $id, string $reason, ?string $operator): PurchasePlan
    {
        return DB::transaction(function () use ($id, $reason, $operator): PurchasePlan {
            $plan = PurchasePlan::query()->lockForUpdate()->findOrFail($id);
            if ($plan->plan_status !== 'submitted' || $plan->audit_status !== 'pending') {
                throw ValidationException::withMessages(['plan_status' => '只有待审核的采购计划可以驳回。']);
            }
            $plan->update([
                'plan_status' => 'draft',
                'audit_status' => 'rejected',
                'approved_by' => null,
                'approved_at' => null,
            ]);
            $this->log('purchase_plan', $plan->id, 'reject', '采购计划已驳回。原因：'.$reason, $operator);

            return $plan->fresh(['items.item', 'items.splits.supplier']);
        }, 5);
    }

    public function confirmRequest(int $id, ?string $operator): PurchaseRequest
    {
        return $this->requestTransition($id, ['draft'], 'confirmed', 'confirm', '采购需求已确认，可进入采购计划', $operator);
    }

    public function cancelRequest(int $id, ?string $operator): PurchaseRequest
    {
        return $this->requestTransition($id, ['draft'], 'cancelled', 'cancel', '采购需求已取消', $operator);
    }

    public function closeRequest(int $id, ?string $operator): PurchaseRequest
    {
        return $this->requestTransition($id, ['confirmed', 'partially_planned'], 'closed', 'close', '采购需求已关闭', $operator);
    }

    public function submitOrder(int $id, ?string $operator, ?object $initiator = null): PurchaseOrder
    {
        return DB::transaction(function () use ($id, $operator, $initiator): PurchaseOrder {
            $order = PurchaseOrder::query()->with('items')->lockForUpdate()->findOrFail($id);
            if ($order->purchase_status !== 'draft' && $order->audit_status !== 'rejected') {
                throw ValidationException::withMessages(['purchase_status' => '只有草稿或已驳回的采购订单可以重新提交审批。']);
            }
            if ($order->items->isEmpty()) {
                throw ValidationException::withMessages(['items' => '采购订单没有明细，不能提交审批。']);
            }
            if ($order->items->contains(fn ($item) => (float) $item->unit_price <= 0)) {
                throw ValidationException::withMessages(['items' => '采购订单存在未确认采购单价的明细，请补全单价后再提交。']);
            }

            $order = $this->updateOrder($order, ['purchase_status' => 'submitted', 'audit_status' => 'pending'], 'submit', '采购订单已提交审批', $operator);
            if ($initiator) $order->setAttribute('approval_task', $this->approvalIntegration->submitted($order, $initiator));
            return $order;
        }, 5);
    }

    public function approveOrder(int $id, ?string $operator): PurchaseOrder
    {
        return DB::transaction(function () use ($id, $operator): PurchaseOrder {
            $order = PurchaseOrder::query()->with('items')->lockForUpdate()->findOrFail($id);
            if ($order->purchase_status !== 'submitted' || $order->audit_status !== 'pending') {
                throw ValidationException::withMessages(['purchase_status' => '只有已提交且待审核的采购订单可以审核。']);
            }
            if ($order->receipt_status !== 'not_received') {
                throw ValidationException::withMessages(['receipt_status' => '该订单已经发生到货，不能重复审核。']);
            }

            foreach ($order->items as $line) {
                $facts = $this->finance->amountFacts((float) $line->amount, (float) $line->tax_rate, (string) $order->tax_mode);
                $line->update([
                    'currency_snapshot' => $order->currency ?: 'CNY',
                    'tax_mode_snapshot' => $order->tax_mode ?: 'tax_included',
                    ...$facts,
                    'contract_amount_snapshot' => $facts['amount_incl_tax'],
                    'commercial_snapshot_at' => now(),
                ]);
            }

            return $this->updateOrder($order, [
                'purchase_status' => 'processing',
                'audit_status' => 'approved',
                'amount_excl_tax' => round((float) $order->items()->sum('amount_excl_tax'), 4),
                'amount_incl_tax' => round((float) $order->items()->sum('amount_incl_tax') + (float) $order->freight_amount, 4),
                'finance_fact_status' => 'frozen',
            ], 'approve', '采购订单审核通过，商业金额与单位换算快照已冻结', $operator);
        }, 5);
    }

    public function rejectOrder(int $id, ?string $operator): PurchaseOrder
    {
        return DB::transaction(function () use ($id, $operator): PurchaseOrder {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($id);
            if ($order->purchase_status !== 'submitted' || $order->audit_status !== 'pending') {
                throw ValidationException::withMessages(['purchase_status' => '只有待审核采购订单可以驳回。']);
            }

            return $this->updateOrder($order, ['purchase_status' => 'draft', 'audit_status' => 'rejected'], 'reject', '采购订单已驳回，可修改后重新提交', $operator);
        }, 5);
    }

    public function cancelOrder(int $id, ?string $operator): PurchaseOrder
    {
        return DB::transaction(function () use ($id, $operator): PurchaseOrder {
            $order = PurchaseOrder::query()->with(['receipts', 'items'])->lockForUpdate()->findOrFail($id);
            $this->releasePlanAllocation($order);
            if (!in_array($order->purchase_status, ['draft', 'submitted'], true)) {
                throw ValidationException::withMessages(['purchase_status' => '只有草稿或待审核且未执行的采购订单可以取消；已审核订单请关闭未履约数量。']);
            }
            if ($order->receipts->contains(fn ($receipt) => $receipt->confirm_status === 'confirmed')) {
                throw ValidationException::withMessages(['receipt_status' => '该订单已发生有效到货，不能取消。']);
            }

            return $this->updateOrder($order, ['purchase_status' => 'cancelled', 'receipt_status' => 'cancelled'], 'cancel', '采购订单已取消', $operator);
        }, 5);
    }

    public function closeOrder(int $id, ?string $operator): PurchaseOrder
    {
        return DB::transaction(function () use ($id, $operator): PurchaseOrder {
            $order = PurchaseOrder::query()->with(['items', 'receipts'])->lockForUpdate()->findOrFail($id);
            if (!in_array($order->purchase_status, ['processing', 'partially_received'], true)) {
                throw ValidationException::withMessages(['purchase_status' => '只有执行中或部分到货且仍有未履约数量的采购订单可以关闭。']);
            }
            if ($order->items->every(fn ($item) => (float) $item->remaining_qty <= 0)) {
                throw ValidationException::withMessages(['receipt_status' => '采购订单已经全部到货，不需要关闭未履约数量。']);
            }
            if ($order->receipts->contains(fn ($receipt) =>
                $receipt->confirm_status === 'draft' && $receipt->settlement_mode !== 'replacement_no_charge'
            )) {
                throw ValidationException::withMessages(['receipt_status' => '该订单仍有待确认到货单，请先确认或处理该草稿后再关闭。']);
            }

            return $this->updateOrder($order, ['purchase_status' => 'closed'], 'close', '采购订单剩余未履约数量已关闭', $operator);
        }, 5);
    }

    private function releasePlanAllocation(PurchaseOrder $order): void
    {
        $planIds = [];
        foreach ($order->items as $item) {
            if (!$item->plan_split_id) continue;
            $split = PurchasePlanSupplierSplit::query()->lockForUpdate()->find($item->plan_split_id);
            if (!$split || (int) $split->order_id !== (int) $order->id) continue;

            // Plan split quantities are base-unit quantities. Clamp the
            // release for legacy malformed orders whose planned snapshot was
            // previously multiplied by the package factor twice.
            $releasedBaseQty = min((float) $split->ordered_qty, (float) $item->planned_base_qty);
            $split->update([
                'ordered_qty' => max(0, (float) $split->ordered_qty - $releasedBaseQty),
                'order_id' => null,
                'order_item_id' => null,
                'split_status' => 'not_ordered',
            ]);
            if ($item->plan_item_id) {
                $planItem = PurchasePlanItem::query()->lockForUpdate()->find($item->plan_item_id);
                if ($planItem) {
                    $planItem->update(['ordered_qty' => max(0, (float) $planItem->ordered_qty - $releasedBaseQty)]);
                }
            }
            $planIds[] = (int) $split->plan_id;
        }

        foreach (array_unique($planIds) as $planId) {
            $orderedCount = PurchasePlanSupplierSplit::where('plan_id', $planId)->where('split_status', 'ordered')->count();
            $totalCount = PurchasePlanSupplierSplit::where('plan_id', $planId)->count();
            PurchasePlan::whereKey($planId)->update([
                'order_status' => $orderedCount === 0 ? 'not_ordered' : ($orderedCount < $totalCount ? 'partially_ordered' : 'order_generated'),
            ]);
        }
    }

    private function requestTransition(int $id, array $allowed, string $to, string $action, string $message, ?string $operator): PurchaseRequest
    {
        return DB::transaction(function () use ($id, $allowed, $to, $action, $message, $operator): PurchaseRequest {
            $request = PurchaseRequest::query()->lockForUpdate()->findOrFail($id);
            if (!in_array($request->request_status, $allowed, true)) {
                throw ValidationException::withMessages(['request_status' => $action === 'close'
                    ? '只有已确认或部分转计划且仍有剩余数量的采购需求可以关闭；已计划完成的需求不能关闭。'
                    : '当前采购需求状态不允许执行该操作，请刷新页面后重试。']);
            }
            if ($action === 'close' && (float) $request->planned_qty >= (float) $request->request_qty) {
                throw ValidationException::withMessages(['request_status' => '采购需求已经全部进入采购计划，不能再关闭。']);
            }

            $updates = ['request_status' => $to, 'status' => $to];
            if ($to === 'confirmed') $updates += ['confirmed_by' => $operator, 'confirmed_at' => now()];
            if ($to === 'closed') $updates += ['closed_at' => now()];
            if ($to === 'cancelled') $updates += ['cancelled_at' => now()];
            $request->update($updates);
            $this->log('purchase_request', $request->id, $action, $message, $operator);

            return $request->fresh(['items.item.unit', 'items.warehouse']);
        }, 5);
    }

    private function updateOrder(PurchaseOrder $order, array $updates, string $action, string $message, ?string $operator): PurchaseOrder
    {
        $order->update($updates);
        $this->log('purchase_order', $order->id, $action, $message, $operator);

        return $order->fresh(['items.item.unit', 'supplier']);
    }

    private function log(string $targetType, int $targetId, string $action, string $content, ?string $operator): void
    {
        PurchaseLog::create([
            'target_type' => $targetType,
            'target_id' => $targetId,
            'action' => $action,
            'operator' => $operator ?: '系统任务',
            'content' => $content,
        ]);
    }
}
