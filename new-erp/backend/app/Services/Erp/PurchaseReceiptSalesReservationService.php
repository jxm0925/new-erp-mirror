<?php

namespace App\Services\Erp;

use App\Models\Erp\{InventoryBalance, InventoryTransaction, PurchaseReceipt, PurchaseReceiptItem, PurchaseRequestItem, PurchaseRequestSalesLink, SalesOrder, SalesOrderFulfillment, SalesOrderPurchaseLink};
use Illuminate\Support\Facades\DB;

/** 采购正式入库的增量预算：先匹配显式归属，再用剩余同物料数量回补已确认采购尾差。 */
class PurchaseReceiptSalesReservationService
{
    public function __construct(private readonly SalesOrderInventoryLockService $locks) {}

    public function reservePostedReceipt(PurchaseReceipt $receipt, InventoryTransaction $transaction, ?int $actorId): void
    {
        // 两个采购过账入口均在原事务内调用；入库、预留和待发明细任一失败必须一起回滚。
        if (DB::transactionLevel() < 1) throw new \LogicException('采购入库自动占用必须与库存过账在同一事务内执行。');
        $scopes = [];
        $lines = $receipt->items()->with(['orderItem.request', 'orderItem.requestItem.request', 'orderItem.planItem.request', 'orderItem.planItem.requestItem.request'])
            ->orderBy('id')->get()->keyBy('id');
        foreach ($transaction->items()->where('source_type', 'purchase_receipt')->where('source_id', $receipt->id)
            ->where('change_qty', '>', 0)->orderBy('id')->get() as $movement) {
            $line = $lines->get($movement->source_item_id);
            if (!$line || (int) $line->item_id !== (int) $movement->item_id) continue;
            $balance = InventoryBalance::query()->where('item_id', $movement->item_id)->where('warehouse_id', $movement->warehouse_id)
                ->where('location_id', $movement->location_id)->where('batch_no', $movement->batch_no)->where('unit_id', $movement->unit_id)->lockForUpdate()->first();
            if (!$balance) continue;
            $scopes[$line->id] ??= ['item_id' => (int) $movement->item_id, 'unit_id' => (int) $movement->unit_id,
                'limits' => [], 'targets' => $this->priorityTargets($line)];
            $scopes[$line->id]['limits'][$balance->id] = bcadd($scopes[$line->id]['limits'][$balance->id] ?? '0', (string) $movement->change_qty, 8);
        }
        if (!$scopes) return;
        $actor = $actorId ? DB::table('erp_legacy_admin_users')->where('legacy_id', $actorId)->first() : null;
        $operator = (string) (($actor->nickname ?? null) ?: ($actor->username ?? '系统'));
        $candidates = SalesOrderFulfillment::query()->select('erp_sales_order_fulfillments.*')
            ->join('erp_sales_orders as arrival_order', 'arrival_order.id', '=', 'erp_sales_order_fulfillments.sales_order_id')
            ->where(function ($query) use ($scopes): void {
                foreach ($scopes as $scope) $query->orWhere(fn ($pair) => $pair->where('erp_sales_order_fulfillments.item_id', $scope['item_id'])
                    ->where('erp_sales_order_fulfillments.base_unit_id', $scope['unit_id']));
            })
            ->where('erp_sales_order_fulfillments.fulfillment_type', 'purchase')->where('erp_sales_order_fulfillments.demand_status', 'confirmed')
            ->where('erp_sales_order_fulfillments.reservation_status', 'awaiting_purchase')
            ->where('arrival_order.order_status', 'confirmed')->whereNull('arrival_order.cancelled_at')->whereNull('arrival_order.short_closed_at')
            ->orderByRaw('COALESCE(arrival_order.priority, 3) ASC')->orderBy('arrival_order.created_at')->orderBy('arrival_order.id')
            ->orderBy('erp_sales_order_fulfillments.sales_order_line_id')->orderBy('erp_sales_order_fulfillments.id')
            // 到货确认可能已建立 RR 读快照；当前读必须看见等待期间刚提交的采购尾差，不能错过唯一一批到货。
            ->lockForUpdate()->get();
        $changedOrders = [];
        // 必须先完成全单显式归属，才能兜底，避免前一到货行抢走后一行的销售专用份额。
        foreach ([true, false] as $explicit) {
            foreach ($candidates as $candidate) {
                $limits = []; $protectedLimits = []; $scopeIds = []; $supplementScopeIds = [];
                foreach ($scopes as $lineId => $scope) {
                    if ($scope['item_id'] !== (int) $candidate->item_id || $scope['unit_id'] !== (int) $candidate->base_unit_id) continue;
                    // 显式订单可借同单无归属余量凑足最小销售单位，其他已有显式归属的份额仍保护。
                    $unassignedSupplement = $explicit && !$scope['targets'];
                    foreach ($scope['limits'] as $balanceId => $qty) $protectedLimits[$balanceId] = bcadd($protectedLimits[$balanceId] ?? '0', $unassignedSupplement ? '0' : $qty, 8);
                    if ($unassignedSupplement) $supplementScopeIds[] = $lineId;
                    if ($explicit && !collect($scope['targets'])->contains(fn ($target) => $target['order_id'] === (int) $candidate->sales_order_id
                        && (!$target['line_id'] || $target['line_id'] === (int) $candidate->sales_order_line_id))) continue;
                    foreach ($scope['limits'] as $balanceId => $qty) $limits[$balanceId] = bcadd($limits[$balanceId] ?? '0', $qty, 8);
                    $scopeIds[] = $lineId;
                }
                if (!$limits || !array_filter($limits, fn ($qty) => bccomp($qty, '0', 8) > 0)) continue;
                // 同物料多个明细/库位先汇总，再由权威锁库算法按销售单位精度截取完整可履约数量。
                $chargeScopeIds = array_merge($scopeIds, $supplementScopeIds);
                $reservations = $this->locks->lockPurchaseTail((int) $candidate->id, $limits, $protectedLimits, $transaction, $chargeScopeIds, $actorId, $operator);
                foreach ($reservations as $reservation) {
                    $remaining = (string) $reservation->reserved_qty;
                    // 旧余额补足销售单位的部分不属于本次到货预算，只扣除本候选实际使用的到货份额。
                    foreach ($chargeScopeIds as $scopeId) {
                        $balanceId = (int) $reservation->inventory_balance_id;
                        $available = $scopes[$scopeId]['limits'][$balanceId] ?? '0';
                        $take = bccomp($available, $remaining, 8) < 0 ? $available : $remaining;
                        $scopes[$scopeId]['limits'][$balanceId] = bcsub($available, $take, 8);
                        $remaining = bcsub($remaining, $take, 8);
                        if (bccomp($remaining, '0', 8) <= 0) break;
                    }
                    $changedOrders[(int) $reservation->source_order_id] = true;
                }
            }
        }
        foreach (array_keys($changedOrders) as $orderId) {
            app(SalesShipmentApplicationService::class)->issueForOrderWithReservedItems(SalesOrder::findOrFail($orderId), $operator);
        }
    }

    private function priorityTargets(PurchaseReceiptItem $line): array
    {
        $targets = [];
        $source = $line->orderItem;
        if (!$source || (int) $source->item_id !== (int) $line->item_id) return [];
        foreach (SalesOrderPurchaseLink::where('purchase_order_item_id', $source->id)->where('status', 'active')->orderBy('id')->lockForUpdate()->get() as $link) {
            $targets[] = ['order_id' => (int) $link->sales_order_id, 'line_id' => null];
        }
        $requestItem = $source->requestItem ?: $source->planItem?->requestItem;
        $request = $requestItem?->request ?: ($source->request ?: $source->planItem?->request);
        if (!$request) return $targets;
        if ($request->source_type === 'sales' && ctype_digit((string) $request->source_id) && (int) $request->source_id > 0) {
            $targets[] = ['order_id' => (int) $request->source_id, 'line_id' => null];
        }
        if (!$requestItem) {
            $matches = PurchaseRequestItem::where('request_id', $request->id)->where('item_id', $line->item_id)->get();
            if ($matches->count() === 1) $requestItem = $matches->first();
        }
        if ($requestItem && (int) $requestItem->item_id === (int) $line->item_id && app(PurchaseRequestSalesAllocationService::class)->available()) {
            foreach (PurchaseRequestSalesLink::where('purchase_request_item_id', $requestItem->id)->where('status', 'active')->orderBy('id')->lockForUpdate()->get() as $link) {
                $targets[] = ['order_id' => (int) $link->sales_order_id, 'line_id' => (int) $link->sales_order_item_id];
            }
        }
        return $targets;
    }
}
