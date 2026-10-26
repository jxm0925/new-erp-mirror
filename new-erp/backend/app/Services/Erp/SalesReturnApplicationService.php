<?php

namespace App\Services\Erp;

use App\Services\Erp\Inventory\SalesReturnInventoryPosting;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesOrderLine;
use App\Models\Erp\InventoryTransactionItem;
use App\Models\Erp\SalesReturn;
use App\Models\Erp\SalesReturnCostAllocation;
use App\Models\Erp\SalesReturnDisposition;
use App\Models\Erp\SalesReturnItem;
use App\Models\Erp\SalesReturnLog;
use App\Models\Erp\SalesReturnReceipt;
use App\Models\Erp\SalesReturnReceiptItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesReturnApplicationService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly SalesReturnInventoryPosting $inventory,
        private readonly SalesOrderStateCoordinator $orderStates,
        private readonly SalesReturnSettlementService $settlements,
    ) {
    }

    public function create(array $payload, ?int $operatorId, ?string $operatorName): SalesReturn
    {
        return DB::transaction(function () use ($payload, $operatorId, $operatorName): SalesReturn {
            $order = SalesOrder::query()->lockForUpdate()->findOrFail($payload['sales_order_id']);
            $order->setRelation('lines', $order->lines()->orderBy('id')->lockForUpdate()->get());
            $lineIds = collect($payload['items'])->pluck('sales_order_line_id')->unique()->values();
            $lines = $order->lines->whereIn('id', $lineIds)->keyBy('id');
            if ($lines->count() !== $lineIds->count()) {
                throw ValidationException::withMessages(['items' => '退货明细必须来自所选销售订单。']);
            }

            $returnNo = !empty($payload['reservation_token'])
                ? $this->numbers->reservedNumber(
                    $payload['reservation_token'],
                    'sales_return',
                    $operatorId,
                    $payload['creation_session_id'] ?? null,
                )
                : $this->numbers->next('sales_return', 'SR');

            $salesReturn = SalesReturn::create([
                'return_no' => $returnNo,
                'sales_order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'return_date' => $payload['return_date'] ?? now()->toDateString(),
                'return_status' => 'draft',
                'return_reason' => $payload['return_reason'],
                'remark' => $payload['remark'] ?? null,
                'created_by' => $operatorId,
            ]);
            $this->settlements->initialize($salesReturn, $payload['return_type'] ?? null);

            foreach ($payload['items'] as $row) {
                /** @var SalesOrderLine $line */
                $line = $lines->get((int) $row['sales_order_line_id']);
                if (!$line->item_id || in_array($line->line_type, ['service', 'no_delivery', 'fee', 'auxiliary'], true)) {
                    throw ValidationException::withMessages(['items' => '只有已实际发货且具有履约物料快照的实物订单行可以退货。']);
                }

                $requestedSalesQty = (float) $row['requested_sales_qty'];
                $availableSalesQty = $this->availableSalesQuantity($line, null);
                if ($requestedSalesQty <= 0 || $requestedSalesQty > $availableSalesQty + 0.00000001) {
                    throw ValidationException::withMessages(['items' => "销售退货数量超过可退数量，当前最多可退 {$availableSalesQty} 个销售单位。"]);
                }

                $factor = $this->fulfillmentFactor($line);
                $returnItem = SalesReturnItem::create([
                    'sales_return_id' => $salesReturn->id,
                    'sales_order_line_id' => $line->id,
                    'fulfillment_id' => $row['fulfillment_id'] ?? null,
                    'item_id' => $line->item_id,
                    'base_unit_id' => $line->item_base_unit_id ?: $line->item?->unit_id,
                    'requested_sales_qty' => $requestedSalesQty,
                    'requested_base_qty' => round($requestedSalesQty * $factor, 8),
                    'fulfillment_snapshot' => [
                        'sales_order_no' => $order->sales_order_no,
                        'sales_order_line_id' => $line->id,
                        'product_id' => $line->product_id,
                        'sku_id' => $line->sku_id,
                        'item_id' => $line->item_id,
                        'item_name' => $line->item_name,
                        'item_snapshot' => $line->item_snapshot,
                        'shipped_qty' => (float) $line->shipped_qty,
                        'fulfillment_factor_snapshot' => $factor,
                        'base_unit_id' => $line->item_base_unit_id ?: $line->item?->unit_id,
                    ],
                    'remark' => $row['remark'] ?? null,
                ]);

                $this->settlements->initializeItem($returnItem, $line, $row['refund_unit_price'] ?? null);

                // Lock and reserve exact original outbound facts now. This keeps a
                // later return receipt from being valued with today's moving cost.
                $this->allocateOriginalOutboundCosts($returnItem, $line);
            }

            if (!empty($payload['reservation_token'])) {
                $this->numbers->consume(
                    $payload['reservation_token'],
                    'sales_return',
                    $returnNo,
                    $operatorId,
                    'sales_return',
                    $salesReturn->id,
                );
            }
            $this->log($salesReturn, 'create', null, 'draft', $operatorId, $operatorName, '新增销售退货单');

            return $this->load($salesReturn);
        }, 5);
    }

    public function confirm(int $id, ?int $operatorId, ?string $operatorName): SalesReturn
    {
        return DB::transaction(function () use ($id, $operatorId, $operatorName): SalesReturn {
            $return = $this->lockReturn($id);
            if ($return->return_status !== 'draft') {
                throw ValidationException::withMessages(['return_status' => '只有草稿销售退货单可以确认。']);
            }
            foreach ($return->items as $item) {
                $available = $this->availableSalesQuantity($item->orderLine, $return->id);
                if ((float) $item->requested_sales_qty > $available + 0.00000001) {
                    throw ValidationException::withMessages(['items' => '原订单可退数量已经变化，请返回编辑后重新确认。']);
                }
            }
            $return->update([
                'return_status' => 'pending_receipt',
                'confirmed_by' => $operatorId,
                'confirmed_at' => now(),
            ]);
            $this->log($return, 'confirm', 'draft', 'pending_receipt', $operatorId, $operatorName, '确认销售退货，等待客户寄回');

            return $this->load($return);
        }, 5);
    }

    public function receive(array $payload, ?int $operatorId, ?string $operatorName): SalesReturnReceipt
    {
        return DB::transaction(function () use ($payload, $operatorId, $operatorName): SalesReturnReceipt {
            $return = $this->lockReturn((int) $payload['sales_return_id']);
            if (!in_array($return->return_status, ['pending_receipt', 'partial_received'], true)) {
                throw ValidationException::withMessages(['return_status' => '只有待收货或部分收货的销售退货单可以登记退货到货。']);
            }

            $receiptNo = !empty($payload['reservation_token'])
                ? $this->numbers->reservedNumber(
                    $payload['reservation_token'],
                    'sales_return_receipt',
                    $operatorId,
                    $payload['creation_session_id'] ?? null,
                )
                : $this->numbers->next('sales_return_receipt', 'SRR');
            $receipt = SalesReturnReceipt::create([
                'receipt_no' => $receiptNo,
                'sales_return_id' => $return->id,
                'receipt_date' => $payload['receipt_date'] ?? now()->toDateString(),
                'receipt_status' => 'confirmed',
                'stock_post_status' => 'pending',
                'remark' => $payload['remark'] ?? null,
                'created_by' => $operatorId,
                'confirmed_by' => $operatorId,
                'confirmed_at' => now(),
            ]);

            foreach ($payload['items'] as $row) {
                /** @var SalesReturnItem|null $returnItem */
                $returnItem = $return->items->firstWhere('id', (int) $row['sales_return_item_id']);
                if (!$returnItem) {
                    throw ValidationException::withMessages(['items' => '退货到货明细不属于当前销售退货单。']);
                }
                $received = (float) $row['received_base_qty'];
                $restock = (float) ($row['restock_base_qty'] ?? 0);
                $pending = (float) ($row['pending_base_qty'] ?? 0);
                $scrap = (float) ($row['scrap_base_qty'] ?? 0);
                $rejected = (float) ($row['rejected_base_qty'] ?? 0);
                if (abs($received - ($restock + $pending + $scrap + $rejected)) > 0.00000001) {
                    throw ValidationException::withMessages(['items' => '可重新入库、待处理、报废和退回客户数量之和必须等于本次实际收到数量。']);
                }
                $remaining = (float) $returnItem->requested_base_qty - (float) $returnItem->received_base_qty;
                if ($received <= 0 || $received > $remaining + 0.00000001) {
                    throw ValidationException::withMessages(['items' => "本次实收数量超过剩余待收数量，当前最多可收 {$remaining} 个基本单位。"]);
                }
                if ($restock > 0 && (empty($row['warehouse_id']) || empty($row['location_id']) || empty($row['batch_no']))) {
                    throw ValidationException::withMessages(['items' => '存在可重新入库数量时，必须选择仓库、库位并确认退货批次。']);
                }

                $receiptLine = SalesReturnReceiptItem::create([
                    'receipt_id' => $receipt->id,
                    'sales_return_item_id' => $returnItem->id,
                    'item_id' => $returnItem->item_id,
                    'warehouse_id' => $row['warehouse_id'] ?? null,
                    'location_id' => $row['location_id'] ?? null,
                    'batch_no' => $row['batch_no'] ?? null,
                    'base_unit_id' => $returnItem->base_unit_id,
                    'received_base_qty' => $received,
                    'restock_base_qty' => $restock,
                    'pending_base_qty' => $pending,
                    'scrap_base_qty' => $scrap,
                    'rejected_base_qty' => $rejected,
                    'inspection_remark' => $row['inspection_remark'] ?? null,
                ]);
                app(SalesReturnIdentityService::class)->recordReceipt($returnItem, $receiptLine, $row, $operatorId);
                $returnItem->increment('received_base_qty', $received);
                $returnItem->increment('restock_base_qty', $restock);
                $returnItem->increment('pending_base_qty', $pending);
                $returnItem->increment('scrap_base_qty', $scrap);
                $returnItem->increment('rejected_base_qty', $rejected);
            }

            if (!empty($payload['reservation_token'])) {
                $this->numbers->consume(
                    $payload['reservation_token'],
                    'sales_return_receipt',
                    $receiptNo,
                    $operatorId,
                    'sales_return_receipt',
                    $receipt->id,
                );
            }

            $return = $this->lockReturn((int) $return->id);
            $allReceived = $return->items->every(fn (SalesReturnItem $item) => (float) $item->received_base_qty >= (float) $item->requested_base_qty);
            $from = $return->return_status;
            $return->update(['return_status' => $allReceived ? 'received' : 'partial_received']);
            $this->settlements->refresh($return);
            $this->log($return, 'receive', $from, $return->return_status, $operatorId, $operatorName, "销售退货到货 {$receiptNo}");

            if ((float) $receipt->items()->sum('restock_base_qty') <= 0) {
                $receipt->update(['stock_post_status' => 'not_required', 'posted_by' => $operatorId, 'posted_at' => now()]);
                $this->refreshCompletion($return);
            }

            return $receipt->fresh(['salesReturn.order', 'items.salesReturnItem', 'items.item', 'items.warehouse', 'items.location']);
        }, 5);
    }

    public function postReceipt(int $receiptId, ?int $operatorId, ?string $operatorName): SalesReturnReceipt
    {
        return DB::transaction(function () use ($receiptId, $operatorId, $operatorName): SalesReturnReceipt {
            $returnId = SalesReturnReceipt::query()->whereKey($receiptId)->value('sales_return_id');
            $return = $this->lockReturn((int) $returnId);
            // 库存、日志和订单投影必须一起提交；内层过账只使用外层事务的保存点。
            $this->inventory->postSalesReturnReceipt($receiptId, $operatorId);
            $receipt = SalesReturnReceipt::query()->lockForUpdate()->findOrFail($receiptId);
            $this->log($return, 'post_receipt', null, null, $operatorId, $operatorName, "销售退货到货 {$receipt->receipt_no} 重新入库完成");
            $this->refreshCompletion($return);

            return $receipt->fresh(['salesReturn.order', 'items.salesReturnItem', 'items.item', 'items.warehouse', 'items.location']);
        }, 5);
    }

    /**
     * 待处理数量处置：restock 生成处置到货单（待入库过账，按原发运成本入库）；scrap 报废；rejected 退回客户（不冲减应收）。
     * 原到货行的待处理数量转出，序列身份随之迁移，处置记录留痕。
     */
    public function disposePending(int $returnId, array $payload, ?int $operatorId, ?string $operatorName): SalesReturn
    {
        return DB::transaction(function () use ($returnId, $payload, $operatorId, $operatorName): SalesReturn {
            $return = $this->lockReturn($returnId);
            if (!in_array($return->return_status, ['partial_received', 'received'], true)) {
                throw ValidationException::withMessages(['return_status' => '只有部分收货或已收货的销售退货单可以处置待处理数量。']);
            }
            $source = SalesReturnReceiptItem::query()->with([
                'receipt' => fn ($query) => $query->lockForUpdate(),
                'salesReturnItem' => fn ($query) => $query->lockForUpdate(),
            ])->lockForUpdate()->findOrFail($payload['receipt_item_id']);
            if ((int) $source->receipt?->sales_return_id !== (int) $return->id) {
                throw ValidationException::withMessages(['receipt_item_id' => '到货明细不属于当前销售退货单。']);
            }
            $disposition = (string) $payload['disposition'];
            $qty = round((float) $payload['base_qty'], 8);
            if ($qty <= 0 || $qty > (float) $source->pending_base_qty + 0.00000001) {
                throw ValidationException::withMessages(['base_qty' => '处置数量必须大于 0 且不超过该到货行待处理数量 '.(float) $source->pending_base_qty.'。']);
            }
            $serialIds = array_values($payload['serial_ids'] ?? []);
            if ($serialIds && count($serialIds) != $qty) {
                throw ValidationException::withMessages(['serial_ids' => '所选序列号数量必须等于处置数量。']);
            }
            $returnItem = SalesReturnItem::query()->lockForUpdate()->findOrFail($source->sales_return_item_id);

            $target = $source;
            $receiptNo = null;
            if ($disposition === 'restock') {
                $warehouseId = $payload['warehouse_id'] ?? $source->warehouse_id;
                $locationId = $payload['location_id'] ?? $source->location_id;
                // 序列商品入库批次必须与原发货批次一致，沿用原到货行批次。
                $batchNo = $serialIds ? $source->batch_no : ($payload['batch_no'] ?? $source->batch_no);
                if (!$warehouseId || !$locationId || !$batchNo) {
                    throw ValidationException::withMessages(['items' => '转入库必须选择仓库、库位并确认退货批次。']);
                }
                $receiptNo = $this->numbers->next('sales_return_receipt', 'SRR');
                $receipt = SalesReturnReceipt::create([
                    'receipt_no' => $receiptNo,
                    'sales_return_id' => $return->id,
                    'receipt_date' => now()->toDateString(),
                    'receipt_status' => 'confirmed',
                    'stock_post_status' => 'pending',
                    'remark' => "待处理转入库（来源到货单 {$source->receipt->receipt_no}）".(!empty($payload['remark']) ? '；'.$payload['remark'] : ''),
                    'created_by' => $operatorId,
                    'confirmed_by' => $operatorId,
                    'confirmed_at' => now(),
                ]);
                $target = SalesReturnReceiptItem::create([
                    'receipt_id' => $receipt->id,
                    'sales_return_item_id' => $returnItem->id,
                    'item_id' => $returnItem->item_id,
                    'warehouse_id' => $warehouseId,
                    'location_id' => $locationId,
                    'batch_no' => $batchNo,
                    'base_unit_id' => $returnItem->base_unit_id,
                    'received_base_qty' => $qty,
                    'restock_base_qty' => $qty,
                    'inspection_remark' => $payload['remark'] ?? null,
                ]);
                // 数量从原到货行转出，保持各到货行“实收 = 各去向之和”。
                $source->update([
                    'received_base_qty' => round((float) $source->received_base_qty - $qty, 8),
                    'pending_base_qty' => round((float) $source->pending_base_qty - $qty, 8),
                ]);
            } else {
                $source->update([
                    'pending_base_qty' => round((float) $source->pending_base_qty - $qty, 8),
                    $disposition.'_base_qty' => round((float) $source->{$disposition.'_base_qty'} + $qty, 8),
                ]);
            }
            app(SalesReturnIdentityService::class)->redisposePending($source, $target, $disposition, $serialIds, $operatorId);
            $returnItem->update([
                'pending_base_qty' => round((float) $returnItem->pending_base_qty - $qty, 8),
                $disposition.'_base_qty' => round((float) $returnItem->{$disposition.'_base_qty'} + $qty, 8),
            ]);
            SalesReturnDisposition::create([
                'sales_return_id' => $return->id,
                'sales_return_item_id' => $returnItem->id,
                'source_receipt_item_id' => $source->id,
                'target_receipt_item_id' => $target->id,
                'disposition' => $disposition,
                'base_qty' => $qty,
                'remark' => $payload['remark'] ?? null,
                'operator_id' => $operatorId,
                'operator_name' => $operatorName,
            ]);
            $this->settlements->refresh($return);
            $label = ['restock' => '转入库', 'scrap' => '报废', 'rejected' => '退回客户'][$disposition];
            $this->log($return, 'dispose', null, null, $operatorId, $operatorName,
                "待处理数量 {$qty} {$label}".($receiptNo ? "，生成处置到货单 {$receiptNo} 待入库过账" : ''));
            $this->refreshCompletion($return);

            return $this->load($return);
        }, 5);
    }

    public function cancel(int $id, ?int $operatorId, ?string $operatorName): SalesReturn
    {
        return DB::transaction(function () use ($id, $operatorId, $operatorName): SalesReturn {
            $return = $this->lockReturn($id);
            if (!in_array($return->return_status, ['draft', 'pending_receipt'], true)
                || $return->items->sum('received_base_qty') > 0) {
                throw ValidationException::withMessages(['return_status' => '已经发生退货到货的销售退货单不能取消。']);
            }
            $from = $return->return_status;
            $return->update(['return_status' => 'cancelled', 'cancelled_by' => $operatorId, 'cancelled_at' => now()]);
            $this->log($return, 'cancel', $from, 'cancelled', $operatorId, $operatorName, '取消销售退货单');

            return $this->load($return);
        }, 5);
    }

    public function close(int $id, ?int $operatorId, ?string $operatorName): SalesReturn
    {
        return DB::transaction(function () use ($id, $operatorId, $operatorName): SalesReturn {
            $return = $this->lockReturn($id);
            if (!in_array($return->return_status, ['pending_receipt', 'partial_received', 'received'], true)) {
                throw ValidationException::withMessages(['return_status' => '当前销售退货状态不能关闭未完成数量。']);
            }
            if ($return->receipts->contains(fn (SalesReturnReceipt $receipt) => $receipt->stock_post_status === 'pending')) {
                throw ValidationException::withMessages(['return_status' => '仍有退货到货单等待库存处理，请先完成或处理到货单后再关闭。']);
            }
            $from = $return->return_status;
            if ($return->items->contains(fn (SalesReturnItem $item) => (float) $item->pending_base_qty > 0)) {
                throw ValidationException::withMessages(['return_status' => '仍有待处理的退货数量，请先处置（转入库/报废/退回客户）后再关闭。']);
            }
            $return->update(['return_status' => 'closed', 'closed_by' => $operatorId, 'closed_at' => now()]);
            $this->settlements->refresh($return);
            $this->log($return, 'close', $from, 'closed', $operatorId, $operatorName, '关闭销售退货剩余未收数量');

            return $this->load($return);
        }, 5);
    }

    public function deleteDraft(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $salesReturn = $this->lockReturn($id);
            if ($salesReturn->return_status !== 'draft' || $salesReturn->confirmed_at !== null) {
                throw ValidationException::withMessages(['return_status' => '只有从未确认、未产生收货事实的销售退货草稿可以删除。']);
            }
            if ($salesReturn->receipts->isNotEmpty()
                || $salesReturn->items->contains(fn (SalesReturnItem $item) => (float) $item->received_base_qty > 0)) {
                throw ValidationException::withMessages(['return_status' => '该销售退货已产生收货或入库事实，不能删除。']);
            }

            // 原出库成本占用属于草稿级预留，随退货行级联删除后释放。
            $salesReturn->delete();
        }, 5);
    }

    private function availableSalesQuantity(SalesOrderLine $line, ?int $exceptReturnId): float
    {
        $reserved = SalesReturnItem::query()
            ->join('erp_sales_returns', 'erp_sales_returns.id', '=', 'erp_sales_return_items.sales_return_id')
            ->where('erp_sales_return_items.sales_order_line_id', $line->id)
            ->whereNotIn('erp_sales_returns.return_status', ['cancelled', 'closed'])
            ->when($exceptReturnId, fn ($query) => $query->where('erp_sales_returns.id', '<>', $exceptReturnId))
            ->orderBy('erp_sales_return_items.id')->lockForUpdate()->get(['erp_sales_return_items.requested_sales_qty'])
            ->sum('requested_sales_qty');

        return max(0, (float) $line->shipped_qty - $reserved);
    }

    private function fulfillmentFactor(SalesOrderLine $line): string
    {
        $factor = (string) ($line->fulfillment_factor_snapshot ?? '0');
        if (bccomp($factor, '0', 8) > 0) return $factor;
        if (bccomp((string) $line->order_qty, '0', 8) > 0 && bccomp((string) ($line->item_base_required_qty ?? '0'), '0', 8) > 0) {
            return bcadd(bcdiv((string) $line->item_base_required_qty, (string) $line->order_qty, 9), '0.000000005', 8);
        }

        return '1.00000000';
    }

    /**
     * Reserve return quantity against the original shipment inventory facts in
     * FIFO outbound order. A return may span multiple shipments/warehouse batches.
     */
    private function allocateOriginalOutboundCosts(SalesReturnItem $returnItem, SalesOrderLine $line): void
    {
        $outbounds = InventoryTransactionItem::query()
            ->select('erp_inventory_transaction_items.*')
            ->join('erp_inventory_transactions', 'erp_inventory_transactions.id', '=', 'erp_inventory_transaction_items.transaction_id')
            ->join('erp_sales_shipment_lines', function ($join): void {
                $join->on('erp_sales_shipment_lines.id', '=', 'erp_inventory_transaction_items.source_item_id')
                    ->where('erp_inventory_transaction_items.source_type', '=', 'sales_shipment');
            })
            ->where('erp_sales_shipment_lines.sales_order_line_id', $line->id)
            ->where('erp_sales_shipment_lines.item_id', $returnItem->item_id)
            ->where('erp_inventory_transactions.transaction_type', 'sales_shipment_outbound')
            ->where('erp_inventory_transactions.posting_status', 'posted')
            ->orderBy('erp_inventory_transactions.posted_at')
            ->orderBy('erp_inventory_transaction_items.id')
            ->lockForUpdate()
            ->get();

        $remaining = (float) $returnItem->requested_base_qty;
        foreach ($outbounds as $outbound) {
            if ($remaining <= 0.00000001) {
                break;
            }

            $alreadyAllocated = (float) SalesReturnCostAllocation::query()
                ->join('erp_sales_return_items', 'erp_sales_return_items.id', '=', 'erp_sales_return_cost_allocations.sales_return_item_id')
                ->join('erp_sales_returns', 'erp_sales_returns.id', '=', 'erp_sales_return_items.sales_return_id')
                ->where('erp_sales_return_cost_allocations.outbound_transaction_item_id', $outbound->id)
                ->whereNotIn('erp_sales_returns.return_status', ['cancelled', 'closed'])
                ->orderBy('erp_sales_return_cost_allocations.id')->lockForUpdate()->get(['erp_sales_return_cost_allocations.allocated_base_qty'])
                ->sum('allocated_base_qty');
            $available = max(0, abs((float) $outbound->change_qty) - $alreadyAllocated);
            if ($available <= 0.00000001) {
                continue;
            }

            $allocated = min($remaining, $available);
            $unitCost = abs((float) $outbound->unit_cost);
            $shipmentLineId = (int) $outbound->source_item_id;
            $shipmentId = (int) DB::table('erp_sales_shipment_lines')->where('id', $shipmentLineId)->lockForUpdate()->value('shipment_id');
            if (!$shipmentId) {
                continue;
            }
            SalesReturnCostAllocation::create([
                'sales_return_item_id' => $returnItem->id,
                'sales_shipment_id' => $shipmentId,
                'sales_shipment_line_id' => $shipmentLineId,
                'outbound_transaction_item_id' => $outbound->id,
                'allocated_base_qty' => $allocated,
                'unit_cost_snapshot' => $unitCost,
                'cost_amount_snapshot' => round($allocated * $unitCost, 4),
                'allocation_status' => 'reserved',
            ]);
            $remaining -= $allocated;
        }

        if ($remaining > 0.00000001) {
            throw ValidationException::withMessages([
                'items' => '该销售订单行缺少足额的原发运出库成本事实，不能按当前库存成本办理退货；请先补齐历史发运数据。',
            ]);
        }
    }

    private function refreshCompletion(SalesReturn $return): void
    {
        $return = $this->lockReturn((int) $return->id);
        $allReceived = $return->items->every(fn (SalesReturnItem $item) => (float) $item->received_base_qty >= (float) $item->requested_base_qty);
        $allPosted = $return->receipts->every(fn (SalesReturnReceipt $receipt) => in_array($receipt->stock_post_status, ['posted', 'not_required'], true));
        $noPending = $return->items->every(fn (SalesReturnItem $item) => (float) $item->pending_base_qty <= 0.00000001);
        if ($allReceived && $allPosted && $noPending && !in_array($return->return_status, ['cancelled', 'closed'], true)) {
            $return->update(['return_status' => 'completed']);
            $this->orderStates->handle(SalesOrderStateCoordinator::RETURN_CHANGED, (int) $return->sales_order_id);
        }
    }

    private function lockReturn(int $returnId): SalesReturn
    {
        // 归属 ID 创建后不变；仅用于找根锁，所有状态/数量随后用当前读重取。
        $orderId = SalesReturn::query()->whereKey($returnId)->value('sales_order_id');
        SalesOrder::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
        return SalesReturn::query()->with([
            'items' => fn ($query) => $query->orderBy('id')->lockForUpdate(),
            'items.orderLine' => fn ($query) => $query->lockForUpdate(),
            'receipts' => fn ($query) => $query->orderBy('id')->lockForUpdate(),
        ])->lockForUpdate()->findOrFail($returnId);
    }

    private function load(SalesReturn $return): SalesReturn
    {
        return $return->fresh([
            'order',
            'customer',
            'items.orderLine',
            'items.fulfillment',
            'items.item',
            'items.baseUnit',
            'receipts.items',
            'terms',
            'items.credit',
            'dispositions',
            'logs',
        ]);
    }

    private function log(
        SalesReturn $return,
        string $action,
        ?string $from,
        ?string $to,
        ?int $operatorId,
        ?string $operatorName,
        string $content,
    ): void {
        SalesReturnLog::create([
            'sales_return_id' => $return->id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'operator_id' => $operatorId,
            'operator_name' => $operatorName,
            'content' => $content,
        ]);
        // 退货确认会重新打开已完成订单；取消/关闭/完成后重新判定订单是否完结。
        $this->orderStates->handle(SalesOrderStateCoordinator::RETURN_CHANGED, (int) $return->sales_order_id);
    }
}
