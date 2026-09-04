<?php

namespace App\Services\Erp;

use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventoryLocationBalance;
use App\Models\Erp\InventoryQualityEvent;
use App\Models\Erp\InventoryQualityEventLog;
use App\Models\Erp\InventorySerial;
use App\Models\Erp\InventorySerialEvent;
use App\Models\Erp\InventoryTransactionItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryQualityApplicationService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly PurchaseReturnApplicationService $returns,
        private readonly PurchaseExchangeApplicationService $exchanges,
        private readonly InventoryAvailabilityService $availability,
        private readonly InventoryAlertApplicationService $alerts,
    ) {
    }

    public function create(array $payload, ?int $operatorId, ?string $operatorName): InventoryQualityEvent
    {
        $createdEventId = null;
        try {
            return DB::transaction(function () use ($payload, $operatorId, $operatorName, &$createdEventId): InventoryQualityEvent {
            $balance = InventoryBalance::query()
                ->with(['item.unit', 'warehouse', 'location'])
                ->lockForUpdate()
                ->findOrFail((int) $payload['inventory_balance_id']);

            $quantity = round((float) $payload['issue_qty'], 8);
            $available = $this->availability->availableForOutbound($balance);
            if ($quantity <= 0 || $quantity > $available + 0.00000001) {
                throw ValidationException::withMessages([
                    'issue_qty' => "问题数量必须大于 0，且不能超过扣除订单、工单及其他质量占用后的当前可用库存 {$available}。",
                ]);
            }

            $serial = null;
            $serialNo = trim((string) ($payload['serial_no'] ?? ''));
            if ($serialNo !== '') {
                $serial = InventorySerial::query()
                    ->where('serial_no', $serialNo)
                    ->where('inventory_balance_id', $balance->id)
                    ->lockForUpdate()
                    ->first();
                if (!$serial) {
                    throw ValidationException::withMessages([
                        'serial_no' => '该序列号不属于当前 Item、仓库、库位和批次，请核对实物标签。',
                    ]);
                }
                if ($serial->serial_status !== 'available') {
                    throw ValidationException::withMessages([
                        'serial_no' => '该序列号已被占用、处理中或已出库，不能重复发起质量处理。',
                    ]);
                }
                if (abs($quantity - 1) > 0.00000001) {
                    throw ValidationException::withMessages([
                        'issue_qty' => '按序列号定位的实物一次只能处理 1 个基本单位。',
                    ]);
                }
                if (InventoryQualityEvent::query()
                    ->where('serial_no', $serialNo)
                    ->whereNotIn('event_status', ['completed', 'cancelled'])
                    ->lockForUpdate()
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'serial_no' => '该序列号已经存在未结束的质量事件。',
                    ]);
                }
            }

            $source = $this->sourceTransactionLine($balance, $serial);
            $receiptItem = $source?->purchaseReceiptItem;
            $receipt = $receiptItem?->receipt;
            $method = (string) $payload['handling_method'];
            if (!in_array($method, ['return_supplier', 'exchange'], true)) {
                throw ValidationException::withMessages([
                    'handling_method' => '已入库库存不适用让步接收；当前阶段只开放退供应商和换货，返修、报废将在正式单据接入后开放。',
                ]);
            }
            if (!$receiptItem || !$receipt || !$receipt->supplier_id) {
                throw ValidationException::withMessages([
                    'source' => '当前库存没有可追溯采购到货来源，不能发起退供应商或换货。',
                ]);
            }
            $status = $this->initialStatus($method);

            $event = InventoryQualityEvent::create([
                'event_no' => $this->nextQualityEventNo(),
                'inventory_balance_id' => $balance->id,
                'inventory_serial_id' => $serial?->id,
                'item_id' => $balance->item_id,
                'warehouse_id' => $balance->warehouse_id,
                'location_id' => $balance->location_id,
                'batch_no' => $balance->batch_no,
                'serial_no' => $serialNo ?: null,
                'source_receipt_id' => $receipt?->id,
                'source_receipt_item_id' => $receiptItem?->id,
                'source_order_id' => $receipt?->order_id,
                'supplier_id' => $receipt?->supplier_id,
                'unit_id' => $balance->unit_id ?: $balance->item?->unit_id,
                'unit_name_snapshot' => $balance->item?->unit?->unit_name,
                'issue_qty' => $quantity,
                'issue_category' => $payload['issue_category'],
                'issue_description' => $payload['issue_description'],
                'handling_method' => $method,
                'responsible_party' => $payload['responsible_party'],
                'event_status' => $status,
                'business_doc_type' => null,
                'business_doc_id' => null,
                'business_doc_no' => null,
                'attachments' => $payload['attachments'] ?? [],
                'remark' => $payload['remark'] ?? null,
                'created_by' => $operatorId,
                'created_by_name' => $operatorName,
            ]);
            $createdEventId = $event->id;

            if ($method === 'return_supplier') {
                $document = $this->returns->createFromInventoryQuality(
                    $event, $balance, $receiptItem, $operatorId, $operatorName,
                );
                $event->update([
                    'business_doc_type' => 'purchase_return',
                    'business_doc_id' => $document->id,
                    'business_doc_no' => $document->return_no,
                ]);
            } else {
                $document = $this->exchanges->createFromInventoryQuality(
                    $event, $balance, $receiptItem, $operatorName,
                );
                $event->update([
                    'business_doc_type' => 'purchase_exchange_order',
                    'business_doc_id' => $document->id,
                    'business_doc_no' => $document->exchange_no,
                ]);
            }

            $pending = (float) $balance->quantity_pending + $quantity;
            $balance->update([
                'quantity_pending' => $pending,
                'quantity_available' => $this->available($balance, $pending),
                'last_transaction_at' => now(),
            ]);
            $this->refreshAlert($balance, 'inventory_quality_hold');
            $this->changeLocationPending($balance, $quantity);

            if ($serial) {
                $serial->update(['serial_status' => 'quality_hold']);
                $this->serialEvent($serial, 'quality_hold', 'available', 'quality_hold', $event, $operatorId, $operatorName);
            }

            InventoryQualityEventLog::create([
                'quality_event_id' => $event->id,
                'action' => 'create_and_hold',
                'to_status' => $status,
                'operator_id' => $operatorId,
                'operator_name' => $operatorName,
                'content' => "创建库存质量事件并冻结 {$quantity} {$event->unit_name_snapshot} 可用库存；处理方式：{$method}",
            ]);

                return $this->load($event);
            }, 5);
        } catch (\Throwable $exception) {
            if ($createdEventId) {
                DB::transaction(function () use ($createdEventId, $operatorId, $operatorName): void {
                    $orphan = InventoryQualityEvent::query()
                        ->whereKey($createdEventId)
                        ->whereNull('business_doc_id')
                        ->whereNull('business_doc_no')
                        ->whereDoesntHave('logs')
                        ->lockForUpdate()
                        ->first();
                    if (!$orphan) return;

                    $from = $orphan->event_status;
                    $orphan->update([
                        'event_status' => 'cancelled',
                        'remark' => '正式处理单生成失败，质量事件自动作废；未冻结库存。',
                        'completed_at' => now(),
                    ]);
                    $this->log(
                        $orphan,
                        'auto_cancel_document_failure',
                        $from,
                        'cancelled',
                        $operatorId,
                        $operatorName,
                        '正式处理单生成失败，系统自动作废未生效质量事件。',
                    );
                }, 5);
            }
            throw $exception;
        }
    }

    public function markExternalOutbound(
        int $id,
        string $description,
        ?int $operatorId,
        ?string $operatorName,
    ): InventoryQualityEvent {
        return DB::transaction(function () use ($id, $description, $operatorId, $operatorName): InventoryQualityEvent {
            $event = InventoryQualityEvent::query()->lockForUpdate()->findOrFail($id);
            if ($event->event_status === 'processing_exchange') return $this->load($event);
            if ($event->event_status !== 'pending_exchange') {
                throw ValidationException::withMessages(['event_status' => '只有待换货的库存质量事件可以登记原品出库。']);
            }
            $this->releaseHold($event, $operatorId, $operatorName, 'quality_exchange_outbound');
            $event->update(['event_status' => 'processing_exchange', 'started_at' => now(), 'remark' => $description]);
            $this->log($event, 'external_outbound', 'pending_exchange', 'processing_exchange', $operatorId, $operatorName, $description);
            return $this->load($event);
        }, 5);
    }

    public function markExternalCompleted(
        int $id,
        string $description,
        ?int $operatorId,
        ?string $operatorName,
    ): InventoryQualityEvent {
        return DB::transaction(function () use ($id, $description, $operatorId, $operatorName): InventoryQualityEvent {
            $event = InventoryQualityEvent::query()->lockForUpdate()->findOrFail($id);
            if ($event->event_status === 'completed') return $this->load($event);
            if (!in_array($event->event_status, ['pending_supplier_return', 'processing_exchange'], true)) {
                throw ValidationException::withMessages(['event_status' => '当前质量事件状态不能由外部退货/换货单完成。']);
            }
            $from = $event->event_status;
            if ($from === 'pending_supplier_return') {
                $this->releaseHold($event, $operatorId, $operatorName, 'quality_supplier_returned');
            }
            $event->update([
                'event_status' => 'completed',
                'completed_at' => now(),
                'remark' => trim(collect([$event->remark, $description])->filter()->implode("\n")),
            ]);
            $this->log($event, 'external_completed', $from, 'completed', $operatorId, $operatorName, $description);
            return $this->load($event);
        }, 5);
    }

    /**
     * Cancelling the not-yet-posted supplier return releases only the hold that
     * this quality event created.  It never creates an inventory outbound record.
     */
    public function cancelPendingSupplierReturn(
        int $id,
        string $returnNo,
        ?int $operatorId,
        ?string $operatorName,
    ): InventoryQualityEvent {
        return DB::transaction(function () use ($id, $returnNo, $operatorId, $operatorName): InventoryQualityEvent {
            $event = InventoryQualityEvent::query()->lockForUpdate()->findOrFail($id);
            if ($event->event_status === 'cancelled') return $this->load($event);
            if ($event->event_status !== 'pending_supplier_return') {
                throw ValidationException::withMessages(['event_status' => '关联库存质量事件不处于待退供应商状态，不能取消采购退货单。']);
            }

            $balance = InventoryBalance::query()->lockForUpdate()->findOrFail($event->inventory_balance_id);
            if ((float) $balance->quantity_pending + 0.00000001 < (float) $event->issue_qty) {
                throw ValidationException::withMessages(['event_status' => '质量事件冻结数量不足，无法安全取消采购退货单。']);
            }

            $pending = max(0, (float) $balance->quantity_pending - (float) $event->issue_qty);
            $balance->update([
                'quantity_pending' => $pending,
                'quantity_available' => $this->available($balance, $pending),
                'last_transaction_at' => now(),
            ]);
            $this->refreshAlert($balance, 'inventory_quality_supplier_return_cancelled');
            $this->changeLocationPending($balance, -(float) $event->issue_qty);

            if ($event->inventory_serial_id) {
                $serial = InventorySerial::query()->whereKey($event->inventory_serial_id)->lockForUpdate()->firstOrFail();
                if ($serial->serial_status === 'quality_hold') {
                    $serial->update(['serial_status' => 'available']);
                    $this->serialEvent($serial, 'quality_supplier_return_cancelled', 'quality_hold', 'available', $event, $operatorId, $operatorName);
                }
            }

            $event->update([
                'event_status' => 'cancelled',
                'completed_at' => now(),
                'remark' => trim(collect([$event->remark, "关联采购退货单 {$returnNo} 已取消，库存冻结已解除。"])->filter()->implode("\n")),
            ]);
            $this->log($event, 'cancel_supplier_return', 'pending_supplier_return', 'cancelled', $operatorId, $operatorName,
                "关联采购退货单 {$returnNo} 已取消，未产生库存出库流水，冻结库存已恢复可用。");

            return $this->load($event);
        }, 5);
    }

    public function start(int $id, ?int $operatorId, ?string $operatorName): InventoryQualityEvent
    {
        return DB::transaction(function () use ($id, $operatorId, $operatorName): InventoryQualityEvent {
            $event = InventoryQualityEvent::query()->lockForUpdate()->findOrFail($id);
            if (!str_starts_with((string) $event->event_status, 'pending_')) {
                throw ValidationException::withMessages(['event_status' => '只有待执行的质量事件可以开始处理。']);
            }
            $from = $event->event_status;
            $to = 'processing_'.$event->handling_method;
            $event->update(['event_status' => $to, 'started_at' => now()]);
            $this->log($event, 'start', $from, $to, $operatorId, $operatorName, '开始执行质量处理，问题库存继续保持冻结。');
            return $this->load($event);
        }, 5);
    }

    public function complete(int $id, array $payload, ?int $operatorId, ?string $operatorName): InventoryQualityEvent
    {
        return DB::transaction(function () use ($id, $payload, $operatorId, $operatorName): InventoryQualityEvent {
            $event = InventoryQualityEvent::query()->lockForUpdate()->findOrFail($id);
            if (!str_starts_with((string) $event->event_status, 'processing_')) {
                throw ValidationException::withMessages(['event_status' => '质量事件必须先开始处理，才能登记完成结果。']);
            }
            if (!in_array($event->handling_method, ['repair', 'continue_use'], true)) {
                throw ValidationException::withMessages([
                    'handling_method' => '退供应商、换货和报废必须完成对应出入库/审批单据后才能结束，不能在质量事件中直接点完成。',
                ]);
            }

            $balance = InventoryBalance::query()->lockForUpdate()->findOrFail($event->inventory_balance_id);
            $pending = max(0, (float) $balance->quantity_pending - (float) $event->issue_qty);
            $balance->update([
                'quantity_pending' => $pending,
                'quantity_available' => $this->available($balance, $pending),
                'last_transaction_at' => now(),
            ]);
            $this->refreshAlert($balance, 'inventory_quality_release');
            $this->changeLocationPending($balance, -(float) $event->issue_qty);
            if ($event->inventory_serial_id) {
                $serial = InventorySerial::query()->whereKey($event->inventory_serial_id)->lockForUpdate()->firstOrFail();
                $serial->update(['serial_status' => 'available']);
                $this->serialEvent($serial, 'quality_released', 'quality_hold', 'available', $event, $operatorId, $operatorName);
            }

            $from = $event->event_status;
            $event->update([
                'event_status' => 'completed',
                'completed_at' => now(),
                'remark' => trim(collect([$event->remark, $payload['result_description'] ?? null])->filter()->implode("\n")),
            ]);
            $this->log($event, 'complete_and_release', $from, 'completed', $operatorId, $operatorName, '返修/让步审批完成并复检合格，释放对应待处理库存。');
            return $this->load($event);
        }, 5);
    }

    private function sourceTransactionLine(InventoryBalance $balance, ?InventorySerial $serial): ?InventoryTransactionItem
    {
        $query = InventoryTransactionItem::query()
            ->with(['purchaseReceiptItem.receipt.order', 'purchaseReceiptItem.receipt.supplier'])
            ->where('source_type', 'purchase_receipt')
            ->where('item_id', $balance->item_id)
            ->where('batch_no', $balance->batch_no)
            ->where('change_qty', '>', 0);

        if ($serial?->source_receipt_item_id) {
            $query->where('source_item_id', $serial->source_receipt_item_id);
        }

        $source = (clone $query)
            ->where('warehouse_id', $balance->warehouse_id)
            ->where('location_id', $balance->location_id)
            ->latest('id')->first();

        return $source ?? $query->latest('id')->first();
    }

    private function changeLocationPending(InventoryBalance $balance, float $change): void
    {
        $location = InventoryLocationBalance::query()
            ->where('item_id', $balance->item_id)
            ->where('warehouse_id', $balance->warehouse_id)
            ->where('location_id', $balance->location_id)
            ->lockForUpdate()
            ->first();
        if (!$location) return;

        $pending = max(0, (float) $location->quantity_pending + $change);
        $location->update([
            'quantity_pending' => $pending,
            'quantity_available' => $this->availability->calculate(
                (float) $location->quantity_on_hand,
                (float) $location->quantity_locked,
                (float) $location->quantity_defective,
                $pending,
            ),
            'last_transaction_at' => now(),
        ]);
    }

    private function releaseHold(
        InventoryQualityEvent $event,
        ?int $operatorId,
        ?string $operatorName,
        string $serialEventType,
    ): void {
        $balance = InventoryBalance::query()->lockForUpdate()->findOrFail($event->inventory_balance_id);
        $pending = max(0, (float) $balance->quantity_pending - (float) $event->issue_qty);
        $balance->update([
            'quantity_pending' => $pending,
            'quantity_available' => $this->available($balance, $pending),
            'last_transaction_at' => now(),
        ]);
        $this->refreshAlert($balance, 'inventory_quality_external_outbound');
        $this->changeLocationPending($balance, -(float) $event->issue_qty);
        if ($event->inventory_serial_id) {
            $serial = InventorySerial::query()->whereKey($event->inventory_serial_id)->lockForUpdate()->firstOrFail();
            if ($serial->serial_status === 'quality_hold') {
                $serial->update(['serial_status' => 'returned']);
                $this->serialEvent($serial, $serialEventType, 'quality_hold', 'returned', $event, $operatorId, $operatorName);
            }
        }
    }

    private function available(InventoryBalance $balance, float $pending): float
    {
        return $this->availability->calculate(
            (float) $balance->quantity_on_hand,
            (float) $balance->quantity_locked,
            (float) $balance->quantity_defective,
            $pending,
        );
    }

    private function refreshAlert(InventoryBalance $balance, string $reason): void
    {
        $this->alerts->recalculateForItemWarehouse((int) $balance->item_id, (int) $balance->warehouse_id, $reason);
    }

    private function initialStatus(string $method): string
    {
        return match ($method) {
            'repair' => 'pending_repair',
            'return_supplier' => 'pending_supplier_return',
            'exchange' => 'pending_exchange',
            'scrap' => 'pending_scrap_approval',
            'continue_use' => 'pending_concession_approval',
            default => 'pending_action',
        };
    }

    private function nextQualityEventNo(): string
    {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $number = $this->numbers->next('inventory_quality_event', 'IQE');
            if (!InventoryQualityEvent::query()->where('event_no', $number)->exists()) {
                return $number;
            }
        }

        throw ValidationException::withMessages([
            'event_no' => '库存质量事件编号连续发生冲突，请检查编号规则和历史单据。',
        ]);
    }

    private function businessDocumentType(string $method): string
    {
        return match ($method) {
            'repair' => 'inventory_repair_order',
            'return_supplier' => 'inventory_supplier_return_request',
            'exchange' => 'inventory_exchange_order',
            'scrap' => 'inventory_scrap_request',
            'continue_use' => 'inventory_concession_request',
            default => 'inventory_quality_action',
        };
    }

    private function businessPrefix(string $method): string
    {
        return match ($method) {
            'repair' => 'RPR',
            'return_supplier' => 'ISR',
            'exchange' => 'EXC',
            'scrap' => 'SCR',
            'continue_use' => 'CON',
            default => 'IQA',
        };
    }

    private function load(InventoryQualityEvent $event): InventoryQualityEvent
    {
        return $event->fresh([
            'item.unit', 'warehouse', 'location', 'serial', 'receipt.order', 'supplier', 'logs',
        ]);
    }

    private function log(InventoryQualityEvent $event, string $action, ?string $from, ?string $to, ?int $operatorId, ?string $operatorName, string $content): void
    {
        InventoryQualityEventLog::create([
            'quality_event_id' => $event->id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'operator_id' => $operatorId,
            'operator_name' => $operatorName,
            'content' => $content,
        ]);
    }

    private function serialEvent(InventorySerial $serial, string $type, string $from, string $to, InventoryQualityEvent $qualityEvent, ?int $operatorId, ?string $operatorName): void
    {
        InventorySerialEvent::create([
            'inventory_serial_id' => $serial->id,
            'event_type' => $type,
            'document_type' => 'inventory_quality_event',
            'document_id' => $qualityEvent->id,
            'document_no' => $qualityEvent->event_no,
            'from_status' => $from,
            'to_status' => $to,
            'warehouse_id' => $serial->warehouse_id,
            'location_id' => $serial->location_id,
            'batch_no' => $serial->batch_no,
            'operator_id' => $operatorId,
            'operator_name' => $operatorName,
            'event_payload' => [
                'handling_method' => $qualityEvent->handling_method,
                'business_doc_type' => $qualityEvent->business_doc_type,
                'business_doc_no' => $qualityEvent->business_doc_no,
            ],
            'occurred_at' => now(),
        ]);
    }
}
