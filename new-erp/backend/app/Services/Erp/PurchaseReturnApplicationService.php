<?php

namespace App\Services\Erp;

use App\Models\Erp\InventoryTransactionItem;
use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventoryQualityEvent;
use App\Models\Erp\InventorySerial;
use App\Models\Erp\PurchaseDefectHandling;
use App\Models\Erp\PurchaseReceiptItem;
use App\Models\Erp\PurchaseReturn;
use App\Models\Erp\PurchaseReturnItem;
use App\Models\Erp\PurchaseReturnLog;
use App\Models\Erp\PurchaseReturnItemSerial;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseReturnApplicationService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly InventoryService $inventory,
        private readonly SupplierPerformanceService $supplierPerformance,
        private readonly InventoryAvailabilityService $availability,
        private readonly PurchaseFinancialFactService $finance,
        private readonly PurchaseReceiptSettlementService $settlements,
        private readonly PurchaseSettlementSourceApplicationService $settlementSources,
    ) {
    }

    public function create(array $payload, ?int $operatorId, ?string $operatorName): PurchaseReturn
    {
        return DB::transaction(function () use ($payload, $operatorId, $operatorName): PurchaseReturn {
            $receiptItemIds = collect($payload['items'])->pluck('source_receipt_item_id')->unique()->values();
            $sourceLines = PurchaseReceiptItem::query()
                ->with(['receipt', 'item.unit', 'purchaseUnit', 'baseUnit'])
                ->whereIn('id', $receiptItemIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($sourceLines->count() !== $receiptItemIds->count()) {
                throw ValidationException::withMessages(['items' => '退货来源到货明细不存在，请刷新后重新选择。']);
            }

            $receiptIds = $sourceLines->pluck('receipt_id')->unique();
            if ($receiptIds->count() !== 1 || (int) $receiptIds->first() !== (int) $payload['source_receipt_id']) {
                throw ValidationException::withMessages(['items' => '一张采购退货单只能来源于同一张采购到货单。']);
            }

            $supplierIds = $sourceLines->map(fn (PurchaseReceiptItem $line) => (int) $line->receipt->supplier_id)->unique();
            if ($supplierIds->count() !== 1 || (int) $supplierIds->first() !== (int) $payload['supplier_id']) {
                throw ValidationException::withMessages(['supplier_id' => '退货供应商必须与原采购到货单一致。']);
            }

            $scope = $payload['return_scope'];
            if ($scope !== 'posted_inventory') {
                throw ValidationException::withMessages(['return_scope' => '人工新增采购退货只允许选择已正式入库的采购批次；未入库拒收请从不合格品处理发起。']);
            }

            $returnNo = !empty($payload['reservation_token'])
                ? $this->numbers->reservedNumber(
                    $payload['reservation_token'],
                    'purchase_return',
                    $operatorId,
                    $payload['creation_session_id'] ?? null,
                )
                : $this->nextReturnNo();

            $purchaseReturn = PurchaseReturn::create([
                'return_no' => $returnNo,
                'return_scope' => $scope,
                'source_receipt_id' => $payload['source_receipt_id'],
                'source_order_id' => $sourceLines->first()?->receipt?->order_id,
                'supplier_id' => $payload['supplier_id'],
                'currency_snapshot' => $sourceLines->first()?->currency_snapshot ?: 'CNY',
                'settlement_effect_type' => 'PENDING',
                'return_date' => $payload['return_date'] ?? now()->toDateString(),
                'return_status' => 'draft',
                'audit_status' => 'pending',
                'stock_post_status' => 'pending',
                'return_reason' => $payload['return_reason'],
                'remark' => $payload['remark'] ?? null,
                'created_by' => $operatorId,
            ]);

            foreach ($payload['items'] as $row) {
                $source = $sourceLines->get((int) $row['source_receipt_item_id']);
                $row = $this->normalizeReturnQuantity($source, $row);
                $this->assertPostedReturnAvailability($source, $row, null);
                $selectedSerials = $this->validatedSerials($source, $row, null);
                $returnItem = PurchaseReturnItem::create([
                    'return_id' => $purchaseReturn->id,
                    'source_receipt_item_id' => $source->id,
                    'item_id' => $source->item_id,
                    'warehouse_id' => $row['warehouse_id'],
                    'location_id' => $row['location_id'],
                    'batch_no' => $row['batch_no'],
                    'base_unit_id' => $source->base_unit_id ?: $source->item?->unit_id,
                    'return_unit_id' => $row['return_unit_id'],
                    'return_unit_name_snapshot' => $row['return_unit_name_snapshot'],
                    'return_conversion_factor_snapshot' => $row['return_conversion_factor_snapshot'],
                    'requested_return_qty' => $row['requested_return_qty'],
                    'requested_base_qty' => $row['requested_base_qty'],
                    'approved_base_qty' => 0,
                    'posted_base_qty' => 0,
                    'unit_cost_snapshot' => $this->baseUnitCost($source),
                    'original_inventory_transaction_id' => $this->originalInventoryTransactionId($source),
                    ...$this->finance->proportionalFacts($source, (float) $row['requested_base_qty']),
                    'inventory_cost_amount' => round((float) $row['requested_base_qty'] * $this->baseUnitCost($source), 4),
                    'remark' => $row['remark'] ?? null,
                ]);
                foreach ($selectedSerials as $serial) {
                    PurchaseReturnItemSerial::create([
                        'purchase_return_item_id' => $returnItem->id,
                        'inventory_serial_id' => $serial->id,
                        'serial_no' => $serial->serial_no,
                    ]);
                }
            }

            $this->refreshFinancialTotals($purchaseReturn);

            if (!empty($payload['reservation_token'])) {
                $this->numbers->consume(
                    $payload['reservation_token'],
                    'purchase_return',
                    $returnNo,
                    $operatorId,
                    'purchase_return',
                    $purchaseReturn->id,
                );
            }

            $this->log($purchaseReturn, 'create', null, 'draft', $operatorId, $operatorName, '新增采购退货单');

            return $this->load($purchaseReturn);
        }, 5);
    }

    public function createRejectedFromDefect(
        PurchaseReceiptItem $source,
        PurchaseDefectHandling $handling,
        float $baseQuantity,
        ?int $operatorId,
        ?string $operatorName,
    ): PurchaseReturn {
        return DB::transaction(function () use ($source, $handling, $baseQuantity, $operatorId, $operatorName): PurchaseReturn {
            $source = PurchaseReceiptItem::query()->with(['receipt', 'item'])->lockForUpdate()->findOrFail($source->id);

            $activeRejected = (float) PurchaseReturnItem::query()
                ->join('erp_purchase_returns', 'erp_purchase_returns.id', '=', 'erp_purchase_return_items.return_id')
                ->where('erp_purchase_return_items.source_receipt_item_id', $source->id)
                ->where('erp_purchase_returns.return_scope', 'rejected_before_posting')
                ->whereNotIn('erp_purchase_returns.return_status', ['cancelled', 'closed'])
                ->sum('erp_purchase_return_items.requested_base_qty');

            $availableBase = max(0, (float) $source->unqualified_base_qty - $activeRejected);
            if ($baseQuantity > $availableBase + 0.00000001) {
                throw ValidationException::withMessages(['handling_qty' => '退供应商数量超过该到货明细剩余未入库不合格数量。']);
            }

            $purchaseReturn = PurchaseReturn::create([
                'return_no' => $this->nextReturnNo(),
                'return_scope' => 'rejected_before_posting',
                'source_receipt_id' => $source->receipt_id,
                'source_order_id' => $source->receipt->order_id,
                'supplier_id' => $source->receipt->supplier_id,
                'currency_snapshot' => $source->currency_snapshot ?: 'CNY',
                'settlement_effect_type' => 'PENDING',
                'return_date' => now()->toDateString(),
                'return_status' => 'submitted',
                'audit_status' => 'pending',
                'stock_post_status' => 'not_required',
                'return_reason' => $handling->defect_reason ?: '到货检验不合格，未入库退回供应商',
                'remark' => $handling->remark,
                'created_by' => $operatorId,
                'submitted_by' => $operatorId,
                'submitted_at' => now(),
            ]);

            $returnItem = PurchaseReturnItem::create([
                'return_id' => $purchaseReturn->id,
                'source_receipt_item_id' => $source->id,
                'source_defect_handling_id' => $handling->id,
                'item_id' => $source->item_id,
                'base_unit_id' => $source->base_unit_id ?: $source->item?->unit_id,
                'return_unit_id' => $source->base_unit_id ?: $source->item?->unit_id,
                'return_unit_name_snapshot' => $source->base_unit_name_snapshot ?: $source->item?->unit?->unit_name,
                'return_conversion_factor_snapshot' => 1,
                'requested_return_qty' => $baseQuantity,
                'requested_base_qty' => $baseQuantity,
                'approved_base_qty' => 0,
                'posted_base_qty' => 0,
                'unit_cost_snapshot' => $this->baseUnitCost($source),
                ...$this->finance->proportionalFacts($source, $baseQuantity),
                'inventory_cost_amount' => 0,
                'remark' => '未正式入库拒收，不产生采购入库或采购退货出库流水',
            ]);

            $this->refreshFinancialTotals($purchaseReturn);

            $handling->update([
                'business_doc_type' => 'purchase_return',
                'business_doc_no' => $purchaseReturn->return_no,
            ]);
            $this->log($purchaseReturn, 'create', null, 'submitted', $operatorId, $operatorName, '由不合格品处理生成采购退货单并提交审核');

            return $this->load($purchaseReturn);
        }, 5);
    }

    public function createFromInventoryQuality(
        InventoryQualityEvent $event,
        InventoryBalance $balance,
        PurchaseReceiptItem $source,
        ?int $operatorId,
        ?string $operatorName,
    ): PurchaseReturn {
        return DB::transaction(function () use ($event, $balance, $source, $operatorId, $operatorName): PurchaseReturn {
            $event = InventoryQualityEvent::query()->lockForUpdate()->findOrFail($event->id);
            $existing = PurchaseReturnItem::query()
                ->where('source_inventory_quality_event_id', $event->id)
                ->with('purchaseReturn')
                ->lockForUpdate()->first();
            if ($existing) return $this->load($existing->purchaseReturn);

            $source = PurchaseReceiptItem::query()->with(['receipt', 'item'])->lockForUpdate()->findOrFail($source->id);
            $row = [
                'warehouse_id' => $balance->warehouse_id,
                'location_id' => $balance->location_id,
                'batch_no' => $balance->batch_no,
                'requested_base_qty' => (float) $event->issue_qty,
            ];
            $this->assertPostedReturnAvailability($source, $row, null);

            $purchaseReturn = PurchaseReturn::create([
                'return_no' => $this->nextReturnNo(),
                'return_scope' => 'posted_inventory',
                'source_receipt_id' => $source->receipt_id,
                'source_order_id' => $source->receipt->order_id,
                'supplier_id' => $source->receipt->supplier_id,
                'currency_snapshot' => $source->currency_snapshot ?: 'CNY',
                'settlement_effect_type' => 'PENDING',
                'return_date' => now()->toDateString(),
                'return_status' => 'submitted',
                'audit_status' => 'pending',
                'stock_post_status' => 'pending',
                'return_reason' => mb_substr($event->issue_description, 0, 160),
                'remark' => "来源库存质量事件 {$event->event_no}",
                'created_by' => $operatorId,
                'submitted_by' => $operatorId,
                'submitted_at' => now(),
            ]);
            $returnItem = PurchaseReturnItem::create([
                'return_id' => $purchaseReturn->id,
                'source_receipt_item_id' => $source->id,
                'source_inventory_quality_event_id' => $event->id,
                'item_id' => $source->item_id,
                'warehouse_id' => $balance->warehouse_id,
                'location_id' => $balance->location_id,
                'batch_no' => $balance->batch_no,
                'base_unit_id' => $source->base_unit_id ?: $source->item?->unit_id,
                'return_unit_id' => $source->base_unit_id ?: $source->item?->unit_id,
                'return_unit_name_snapshot' => $source->base_unit_name_snapshot ?: $source->item?->unit?->unit_name,
                'return_conversion_factor_snapshot' => 1,
                'requested_return_qty' => $event->issue_qty,
                'requested_base_qty' => $event->issue_qty,
                'approved_base_qty' => 0,
                'posted_base_qty' => 0,
                'unit_cost_snapshot' => $balance->average_unit_cost,
                'original_inventory_transaction_id' => $this->originalInventoryTransactionId($source),
                ...$this->finance->proportionalFacts($source, (float) $event->issue_qty),
                'inventory_cost_amount' => round((float) $event->issue_qty * (float) $balance->average_unit_cost, 4),
                'remark' => '入库后质量问题退供应商，实物出库时冲减原批次库存',
            ]);
            if ($event->inventory_serial_id) {
                $serial = InventorySerial::query()->findOrFail($event->inventory_serial_id);
                PurchaseReturnItemSerial::create([
                    'purchase_return_item_id' => $returnItem->id,
                    'inventory_serial_id' => $serial->id,
                    'serial_no' => $serial->serial_no,
                ]);
            }
            $this->refreshFinancialTotals($purchaseReturn);
            $this->log($purchaseReturn, 'create_from_inventory_quality', null, 'submitted', $operatorId, $operatorName,
                "由库存质量事件 {$event->event_no} 生成并提交采购退货审批");
            return $this->load($purchaseReturn);
        }, 5);
    }

    public function submit(int $id, ?int $operatorId, ?string $operatorName): PurchaseReturn
    {
        return $this->transition($id, ['draft'], 'submitted', function (PurchaseReturn $return) use ($operatorId): void {
            foreach ($return->items as $item) {
                $this->assertReturnAvailability($item, $return->id);
                $this->assertReturnItemSerials($item, $return->id);
            }
            $return->update(['audit_status' => 'pending', 'submitted_by' => $operatorId, 'submitted_at' => now()]);
        }, 'submit', $operatorId, $operatorName, '提交采购退货审批');
    }

    public function approve(int $id, ?int $operatorId, ?string $operatorName): PurchaseReturn
    {
        $scope = PurchaseReturn::query()->whereKey($id)->value('return_scope');
        $approvalContent = $scope === 'rejected_before_posting'
            ? '采购退货已审核，等待退回供应商'
            : '采购退货已审核，等待仓库出库';

        return $this->transition($id, ['submitted'], 'pending_outbound', function (PurchaseReturn $return) use ($operatorId): void {
            if ($return->audit_status !== 'pending') {
                throw ValidationException::withMessages(['audit_status' => '只有待审核采购退货单可以审核通过。']);
            }
            foreach ($return->items as $item) {
                if ($return->return_scope === 'posted_inventory') {
                    $this->assertReturnAvailability($item, $return->id);
                    $this->assertReturnItemSerials($item, $return->id);
                }
                $item->update(['approved_base_qty' => $item->requested_base_qty]);
            }
            $return->update(['audit_status' => 'approved', 'approved_by' => $operatorId, 'approved_at' => now()]);
        }, 'approve', $operatorId, $operatorName, $approvalContent);
    }

    public function post(int $id, ?int $operatorId, ?string $operatorName): PurchaseReturn
    {
        $scope = PurchaseReturn::query()->whereKey($id)->value('return_scope');
        if ($scope === 'rejected_before_posting') {
            $purchaseReturn = DB::transaction(function () use ($id, $operatorId, $operatorName): PurchaseReturn {
                $purchaseReturn = PurchaseReturn::with('items.sourceDefectHandling')->lockForUpdate()->findOrFail($id);
                if ($purchaseReturn->return_status !== 'pending_outbound' || $purchaseReturn->audit_status !== 'approved') {
                    throw ValidationException::withMessages(['return_status' => '只有已审核、待退回的不合格品退货单可以确认退回供应商。']);
                }
                $purchaseReturn->update([
                    'return_status' => 'completed',
                    'stock_post_status' => 'not_required',
                    'posted_by' => $operatorId,
                    'posted_at' => now(),
                ]);
                foreach ($purchaseReturn->items as $item) {
                    $item->sourceDefectHandling?->update([
                        'handling_status' => 'completed',
                        'current_step' => 'return_completed',
                        'result_description' => '不合格实物已退回供应商，退货流程完成。',
                        'handled_at' => now(),
                        'completed_at' => now(),
                        'updated_by' => $operatorName,
                    ]);
                    $this->settlements->refresh($item->sourceReceiptItem->receipt_id);
                    $this->settlementSources->syncReceipt($item->sourceReceiptItem->receipt_id, $operatorId, $operatorName);
                }
                $this->log($purchaseReturn, 'confirm_supplier_return', 'pending_outbound', 'completed', $operatorId, $operatorName, '确认不合格实物已退回供应商；未入库，不产生库存出库流水');
                return $this->load($purchaseReturn);
            }, 5);
            foreach ($purchaseReturn->items()->select('item_id')->distinct()->pluck('item_id') as $itemId) {
                $this->supplierPerformance->refresh((int) $purchaseReturn->supplier_id, (int) $itemId);
            }
            return $purchaseReturn;
        }

        $purchaseReturn = DB::transaction(function () use ($id, $operatorId, $operatorName): PurchaseReturn {
            $qualityEventIds = PurchaseReturnItem::query()->where('return_id', $id)
                ->whereNotNull('source_inventory_quality_event_id')->pluck('source_inventory_quality_event_id');
            $this->inventory->postPurchaseReturn($id, $operatorId);
            $purchaseReturn = PurchaseReturn::findOrFail($id);
            $this->settlements->refresh($purchaseReturn->source_receipt_id);
            $this->settlementSources->syncReceipt($purchaseReturn->source_receipt_id, $operatorId, $operatorName);
            foreach ($qualityEventIds as $qualityEventId) {
                app(InventoryQualityApplicationService::class)->markExternalCompleted(
                    (int) $qualityEventId,
                    '采购退货已完成库存出库，质量事件关闭。',
                    $operatorId,
                    $operatorName,
                );
            }
            $this->log($purchaseReturn, 'post', 'pending_outbound', 'completed', $operatorId, $operatorName, '采购退货出库完成');
            return $purchaseReturn;
        }, 5);
        foreach ($purchaseReturn->items()->select('item_id')->distinct()->pluck('item_id') as $itemId) {
            $this->supplierPerformance->refresh((int) $purchaseReturn->supplier_id, (int) $itemId);
        }

        return $this->load($purchaseReturn);
    }

    public function cancel(int $id, ?int $operatorId, ?string $operatorName): PurchaseReturn
    {
        return $this->transition($id, ['draft', 'submitted'], 'cancelled', function (PurchaseReturn $return) use ($operatorId, $operatorName): void {
            if ($return->stock_post_status === 'posted') {
                throw ValidationException::withMessages(['return_status' => '已完成库存出库的采购退货单不能取消。']);
            }
            $return->update(['cancelled_by' => $operatorId, 'cancelled_at' => now()]);
            foreach ($return->items->whereNotNull('source_inventory_quality_event_id') as $item) {
                app(InventoryQualityApplicationService::class)->cancelPendingSupplierReturn(
                    (int) $item->source_inventory_quality_event_id,
                    $return->return_no,
                    $operatorId,
                    $operatorName,
                );
            }
            if ($return->return_scope === 'rejected_before_posting') {
                PurchaseDefectHandling::query()
                    ->whereIn('id', $return->items->pluck('source_defect_handling_id')->filter())
                    ->update(['handling_status' => 'cancelled', 'current_step' => 'return_cancelled', 'updated_by' => $operatorName]);
            }
        }, 'cancel', $operatorId, $operatorName, '取消采购退货单');
    }

    public function close(int $id, ?int $operatorId, ?string $operatorName): PurchaseReturn
    {
        return $this->transition($id, ['pending_outbound'], 'closed', function (PurchaseReturn $return) use ($operatorId, $operatorName): void {
            if ($return->stock_post_status === 'posted') {
                throw ValidationException::withMessages(['return_status' => '已完成库存出库的采购退货单不能关闭。']);
            }
            if ($return->items->contains(fn (PurchaseReturnItem $item) => $item->source_inventory_quality_event_id)) {
                throw ValidationException::withMessages(['return_status' => '库存质量事件生成的退货单不能单独关闭，请继续执行退货出库。']);
            }
            $return->update(['closed_by' => $operatorId, 'closed_at' => now()]);
            if ($return->return_scope === 'rejected_before_posting') {
                PurchaseDefectHandling::query()
                    ->whereIn('id', $return->items->pluck('source_defect_handling_id')->filter())
                    ->update(['handling_status' => 'cancelled', 'current_step' => 'return_closed', 'updated_by' => $operatorName]);
            }
        }, 'close', $operatorId, $operatorName, '关闭未执行的采购退货单');
    }

    private function transition(
        int $id,
        array $allowedFrom,
        string $to,
        callable $beforeUpdate,
        string $action,
        ?int $operatorId,
        ?string $operatorName,
        string $content,
    ): PurchaseReturn {
        return DB::transaction(function () use ($id, $allowedFrom, $to, $beforeUpdate, $action, $operatorId, $operatorName, $content): PurchaseReturn {
            $return = PurchaseReturn::with(['items.sourceReceiptItem.item'])->lockForUpdate()->findOrFail($id);
            if (!in_array($return->return_status, $allowedFrom, true)) {
                throw ValidationException::withMessages(['return_status' => '当前状态不允许执行该操作，请刷新页面查看最新状态。']);
            }
            $from = $return->return_status;
            $beforeUpdate($return);
            $return->update(['return_status' => $to]);
            $this->log($return, $action, $from, $to, $operatorId, $operatorName, $content);

            return $this->load($return);
        }, 5);
    }

    private function assertPostedReturnAvailability(PurchaseReceiptItem $source, array $row, ?int $exceptReturnId): void
    {
        $warehouseId = (int) $row['warehouse_id'];
        $locationId = (int) $row['location_id'];
        $batchNo = (string) $row['batch_no'];
        $requested = (float) ($row['requested_base_qty'] ?? 0);
        $posted = (float) InventoryTransactionItem::query()
            ->where('source_type', 'purchase_receipt')
            ->where('source_id', $source->receipt_id)
            ->where('source_item_id', $source->id)
            ->where('warehouse_id', $warehouseId)
            ->where('location_id', $locationId)
            ->where('batch_no', $batchNo)
            ->where('change_qty', '>', 0)
            ->sum('change_qty');

        $reserved = (float) PurchaseReturnItem::query()
            ->join('erp_purchase_returns', 'erp_purchase_returns.id', '=', 'erp_purchase_return_items.return_id')
            ->where('erp_purchase_return_items.source_receipt_item_id', $source->id)
            ->where('erp_purchase_return_items.warehouse_id', $warehouseId)
            ->where('erp_purchase_return_items.location_id', $locationId)
            ->where('erp_purchase_return_items.batch_no', $batchNo)
            ->whereNotIn('erp_purchase_returns.return_status', ['cancelled', 'closed'])
            ->when($exceptReturnId, fn ($query) => $query->where('erp_purchase_returns.id', '<>', $exceptReturnId))
            ->sum('erp_purchase_return_items.requested_base_qty');

        $balance = InventoryBalance::query()
            ->where('item_id', $source->item_id)
            ->where('warehouse_id', $warehouseId)
            ->where('location_id', $locationId)
            ->where('batch_no', $batchNo)
            ->lockForUpdate()
            ->first();
        $currentAvailable = $balance ? $this->availability->availableForOutbound($balance) : 0.0;
        $available = min(max(0, $posted - $reserved), max(0, $currentAvailable));
        if ($posted <= 0) {
            throw ValidationException::withMessages(['items' => '所选物料批次不是该采购到货单形成的正式入库批次。']);
        }
        if ($requested <= 0 || $requested > $available + 0.00000001) {
            throw ValidationException::withMessages(['items' => "采购退货数量超过可退数量，当前最多可退 {$available} 个基本单位。"]);
        }
    }

    /**
     * A return generated by an inventory quality event has already frozen its own
     * source quantity. It must validate that exact freeze instead of asking the
     * generic available-stock rule to see stock that is deliberately unavailable.
     */
    private function assertReturnAvailability(PurchaseReturnItem $item, ?int $exceptReturnId): void
    {
        $item->loadMissing(['sourceReceiptItem', 'sourceInventoryQualityEvent']);
        if (!$item->source_inventory_quality_event_id) {
            $this->assertPostedReturnAvailability($item->sourceReceiptItem, $item->toArray(), $exceptReturnId);
            return;
        }

        $event = InventoryQualityEvent::query()->lockForUpdate()->find($item->source_inventory_quality_event_id);
        if (!$event || $event->event_status !== 'pending_supplier_return') {
            throw ValidationException::withMessages(['return_status' => '关联库存质量事件不处于待退供应商状态，不能继续该采购退货单。']);
        }

        $balance = InventoryBalance::query()->lockForUpdate()->find($event->inventory_balance_id);
        $requested = (float) $item->requested_base_qty;
        if (!$balance
            || (int) $event->item_id !== (int) $item->item_id
            || (int) $balance->warehouse_id !== (int) $item->warehouse_id
            || (int) $balance->location_id !== (int) $item->location_id
            || (string) $balance->batch_no !== (string) $item->batch_no
            || $requested <= 0
            || (float) $event->issue_qty + 0.00000001 < $requested
            || (float) $balance->quantity_on_hand + 0.00000001 < $requested
            || (float) $balance->quantity_pending + 0.00000001 < $requested) {
            throw ValidationException::withMessages(['return_status' => '质量退货单的冻结库存与原 Item、仓库、库位、批次或数量不一致，不能继续处理。']);
        }
    }

    private function assertReturnItemSerials(PurchaseReturnItem $item, ?int $exceptReturnId): void
    {
        $item->loadMissing(['sourceReceiptItem.item', 'serialLinks', 'sourceInventoryQualityEvent']);
        if ($item->sourceInventoryQualityEvent?->inventory_serial_id) {
            if ($item->serialLinks->count() !== 1
                || (int) $item->serialLinks->first()->inventory_serial_id !== (int) $item->sourceInventoryQualityEvent->inventory_serial_id) {
                throw ValidationException::withMessages(['serial_ids' => '库存质量事件退货必须沿用该事件锁定的设备编号。']);
            }
            return;
        }
        $this->validatedSerials($item->sourceReceiptItem, [
            'warehouse_id' => $item->warehouse_id,
            'location_id' => $item->location_id,
            'batch_no' => $item->batch_no,
            'requested_base_qty' => (float) $item->requested_base_qty,
            'serial_ids' => $item->serialLinks->pluck('inventory_serial_id')->all(),
        ], $exceptReturnId);
    }

    private function validatedSerials(PurchaseReceiptItem $source, array $row, ?int $exceptReturnId)
    {
        $mode = $source->item?->serialTrackingMode() ?? 'none';
        $serialIds = collect($row['serial_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values();
        $quantity = (float) ($row['requested_base_qty'] ?? 0);
        $rounded = (int) round($quantity);

        if ($mode === 'none') {
            if ($serialIds->isNotEmpty()) {
                throw ValidationException::withMessages(['serial_ids' => "物料 {$source->item?->item_code} 未启用单件编号，不允许选择设备编号。"]);
            }
            return collect();
        }

        if ($mode === 'required' && abs($quantity - $rounded) > 0.00000001) {
            throw ValidationException::withMessages(['serial_ids' => "物料 {$source->item?->item_code} 必须逐件退货，退货基本数量必须是整数。"]);
        }
        if ($mode === 'required' && $serialIds->count() !== $rounded) {
            throw ValidationException::withMessages(['serial_ids' => "物料 {$source->item?->item_code} 退货 {$rounded} 件，必须选择 {$rounded} 个具体设备编号。"]);
        }
        if ($mode === 'optional' && $serialIds->count() > $quantity + 0.00000001) {
            throw ValidationException::withMessages(['serial_ids' => "所选设备编号数量不能超过物料 {$source->item?->item_code} 的退货数量。"]);
        }
        if ($serialIds->isEmpty()) return collect();

        $serials = InventorySerial::query()
            ->whereIn('id', $serialIds)
            ->where('source_receipt_item_id', $source->id)
            ->where('item_id', $source->item_id)
            ->where('warehouse_id', (int) $row['warehouse_id'])
            ->where('location_id', (int) $row['location_id'])
            ->where('batch_no', (string) $row['batch_no'])
            ->where('serial_status', 'available')
            ->whereDoesntHave('purchaseReturnLinks', function ($links) use ($exceptReturnId): void {
                $links->whereHas('returnItem.purchaseReturn', function ($returns) use ($exceptReturnId): void {
                    $returns->whereNotIn('return_status', ['cancelled', 'closed'])
                        ->when($exceptReturnId, fn ($query) => $query->where('id', '<>', $exceptReturnId));
                });
            })
            ->lockForUpdate()
            ->get();

        if ($serials->count() !== $serialIds->count()) {
            throw ValidationException::withMessages([
                'serial_ids' => '所选设备编号中存在不属于当前到货批次、已出库或已被其他退货单占用的编号，请刷新后重新选择。',
            ]);
        }

        return $serials;
    }

    /**
     * Convert the operator-facing return quantity into the inventory base quantity.
     * Only the historical purchase-unit snapshot and the base unit are valid here.
     */
    private function normalizeReturnQuantity(PurchaseReceiptItem $source, array $row): array
    {
        $baseUnitId = (int) ($source->base_unit_id ?: $source->item?->unit_id);
        $baseUnitName = $source->base_unit_name_snapshot ?: $source->baseUnit?->unit_name ?: $source->item?->unit?->unit_name;
        $purchaseUnitId = (int) ($source->purchase_unit_id ?: $baseUnitId);
        $purchaseUnitName = $source->purchase_unit_name_snapshot ?: $source->purchaseUnit?->unit_name ?: $baseUnitName;
        $purchaseFactor = max(0.00000001, (float) ($source->conversion_factor_snapshot ?: 1));

        // Internal defect flows created before the unit-aware form already pass a base quantity.
        if (!isset($row['requested_return_qty'], $row['return_unit_id'])) {
            $row['requested_return_qty'] = (float) ($row['requested_base_qty'] ?? 0);
            $row['return_unit_id'] = $baseUnitId;
        }

        $returnUnitId = (int) $row['return_unit_id'];
        if ($returnUnitId === $baseUnitId) {
            $factor = 1.0;
            $unitName = $baseUnitName;
        } elseif ($returnUnitId === $purchaseUnitId) {
            $factor = $purchaseFactor;
            $unitName = $purchaseUnitName;
        } else {
            throw ValidationException::withMessages([
                'return_unit_id' => '退货单位只能选择原到货采购单位或物料基本单位。',
            ]);
        }

        $returnQuantity = (float) $row['requested_return_qty'];
        if ($returnQuantity <= 0) {
            throw ValidationException::withMessages(['requested_return_qty' => '退货数量必须大于 0。']);
        }

        $row['return_unit_name_snapshot'] = $unitName;
        $row['return_conversion_factor_snapshot'] = $factor;
        $row['requested_base_qty'] = round($returnQuantity * $factor, 8);

        return $row;
    }

    private function purchaseQuantityToRejectedBase(PurchaseReceiptItem $source, float $purchaseQuantity): float
    {
        $unqualifiedPurchase = (float) $source->unqualified_qty;
        $unqualifiedBase = (float) $source->unqualified_base_qty;
        if ($unqualifiedPurchase <= 0 || $unqualifiedBase <= 0) {
            throw ValidationException::withMessages(['handling_qty' => '该到货明细没有可退回供应商的不合格基本数量。']);
        }

        return round($purchaseQuantity * $unqualifiedBase / $unqualifiedPurchase, 8);
    }

    private function baseUnitCost(PurchaseReceiptItem $source): float
    {
        $baseQuantity = (float) ($source->final_stockable_base_qty
            ?: $source->original_qualified_base_qty
            ?: $source->actual_base_qty
            ?: $source->standard_base_qty);
        $cost = (float) ($source->inventory_cost_amount ?: $source->amount_excl_tax ?: $source->receipt_cost);

        return $baseQuantity > 0 ? round($cost / $baseQuantity, 8) : 0;
    }

    private function originalInventoryTransactionId(PurchaseReceiptItem $source): ?int
    {
        $id = InventoryTransactionItem::query()
            ->where('source_type', 'purchase_receipt')
            ->where('source_id', $source->receipt_id)
            ->where('source_item_id', $source->id)
            ->where('change_qty', '>', 0)
            ->value('transaction_id');

        return $id ? (int) $id : null;
    }

    private function refreshFinancialTotals(PurchaseReturn $purchaseReturn): void
    {
        $purchaseReturn->update([
            'amount_excl_tax' => round((float) $purchaseReturn->items()->sum('return_amount_excl_tax'), 4),
            'tax_amount' => round((float) $purchaseReturn->items()->sum('return_tax_amount'), 4),
            'amount_incl_tax' => round((float) $purchaseReturn->items()->sum('return_amount_incl_tax'), 4),
            'settlement_amount' => round((float) $purchaseReturn->items()->sum('settlement_amount'), 4),
            'cost_amount' => round((float) $purchaseReturn->items()->sum('inventory_cost_amount'), 4),
            'finance_fact_status' => $purchaseReturn->items()
                ->where('finance_fact_status', '!=', 'frozen')->exists() ? 'legacy_unknown' : 'frozen',
        ]);
    }

    private function nextReturnNo(?string $preferred = null): string
    {
        $preferred = trim((string) $preferred);
        if ($preferred !== '' && !PurchaseReturn::query()->where('return_no', $preferred)->exists()) {
            return $preferred;
        }

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $number = $this->numbers->next('purchase_return', 'PRT');
            if (!PurchaseReturn::query()->where('return_no', $number)->exists()) {
                return $number;
            }
        }

        throw ValidationException::withMessages([
            'return_no' => '采购退货单号连续发生冲突，请检查编号规则和历史单据。',
        ]);
    }

    private function load(PurchaseReturn $return): PurchaseReturn
    {
        return $return->fresh([
            'supplier',
            'receipt.order',
            'items.sourceReceiptItem',
            'items.sourceDefectHandling',
            'items.sourceInventoryQualityEvent',
            'items.item',
            'items.warehouse',
            'items.location',
            'items.baseUnit',
            'items.returnUnit',
            'items.serialLinks.inventorySerial',
            'logs',
        ]);
    }

    private function log(
        PurchaseReturn $return,
        string $action,
        ?string $from,
        ?string $to,
        ?int $operatorId,
        ?string $operatorName,
        string $content,
    ): void {
        PurchaseReturnLog::create([
            'return_id' => $return->id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'operator_id' => $operatorId,
            'operator_name' => $operatorName,
            'content' => $content,
        ]);
    }
}
