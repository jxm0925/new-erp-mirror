<?php

namespace App\Services\Erp;

use App\Models\Erp\PurchaseDefectHandling;
use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventoryQualityEvent;
use App\Models\Erp\PurchaseExchangeLog;
use App\Models\Erp\PurchaseExchangeOrder;
use App\Models\Erp\PurchaseExchangeSerialLink;
use App\Models\Erp\PurchaseReceipt;
use App\Models\Erp\PurchaseReceiptItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseExchangeApplicationService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly PurchaseReceiptSettlementService $settlements,
        private readonly PurchaseSettlementSourceApplicationService $settlementSources,
        private readonly InventoryService $inventory,
        private readonly PurchaseFinancialFactService $finance,
    ) {
    }

    public function createFromInventoryQuality(
        InventoryQualityEvent $event,
        InventoryBalance $balance,
        PurchaseReceiptItem $source,
        ?string $operatorName,
    ): PurchaseExchangeOrder {
        return DB::transaction(function () use ($event, $balance, $source, $operatorName): PurchaseExchangeOrder {
            $event = InventoryQualityEvent::query()->lockForUpdate()->findOrFail($event->id);
            $existing = PurchaseExchangeOrder::query()->where('inventory_quality_event_id', $event->id)->lockForUpdate()->first();
            if ($existing) {
                $this->ensureOriginalSerialLink($existing, $event);
                return $this->load($existing);
            }

            $source = PurchaseReceiptItem::query()->with(['receipt.order', 'item.unit'])->lockForUpdate()->findOrFail($source->id);
            $facts = $this->finance->proportionalFacts($source, (float) $event->issue_qty);
            $cost = (float) $facts['return_amount_excl_tax'];
            $order = PurchaseExchangeOrder::create([
                'exchange_no' => $this->nextExchangeNo(),
                'defect_handling_id' => null,
                'inventory_quality_event_id' => $event->id,
                'source_receipt_id' => $source->receipt_id,
                'source_receipt_item_id' => $source->id,
                'purchase_order_id' => $source->receipt?->order_id,
                'supplier_id' => $source->receipt?->supplier_id,
                'item_id' => $source->item_id,
                'exchange_base_qty' => $event->issue_qty,
                'base_unit_name_snapshot' => $event->unit_name_snapshot ?: $source->base_unit_name_snapshot,
                'original_contract_amount' => $facts['return_amount_incl_tax'],
                'original_payable_amount' => $facts['return_amount_incl_tax'],
                'exchange_additional_payable_amount' => 0,
                'replacement_payable_amount' => 0,
                'replacement_inventory_cost' => $cost,
                'currency_snapshot' => $facts['currency_snapshot'],
                'finance_fact_status' => $facts['finance_fact_status'],
                'exchange_status' => 'processing',
                'current_step' => 'pending_original_return',
                'source_scope' => 'inventory_quality',
                'return_warehouse_name' => $balance->warehouse?->warehouse_name,
                'return_location_name' => $balance->location?->location_name,
                'remark' => "来源库存质量事件 {$event->event_no}",
                'created_by' => $operatorName ?: '系统',
                'updated_by' => $operatorName ?: '系统',
            ]);
            $this->ensureOriginalSerialLink($order, $event);
            $this->log($order, 'create', null, 'pending_original_return', $operatorName,
                "由库存质量事件 {$event->event_no} 生成正式采购换货单。");
            return $this->load($order);
        }, 5);
    }

    public function createFromDefect(PurchaseDefectHandling $handling, ?string $operatorName): PurchaseExchangeOrder
    {
        if ($handling->handling_method !== 'exchange') {
            throw ValidationException::withMessages(['handling_method' => '只有换货处理可以生成采购换货单。']);
        }

        return DB::transaction(function () use ($handling, $operatorName): PurchaseExchangeOrder {
            $handling = PurchaseDefectHandling::query()
                ->with(['receipt.order', 'receiptItem.item'])
                ->lockForUpdate()
                ->findOrFail($handling->id);
            $existing = PurchaseExchangeOrder::where('defect_handling_id', $handling->id)->lockForUpdate()->first();
            if ($existing) return $this->load($existing);

            $line = $handling->receiptItem;
            $facts = $this->finance->proportionalFacts($line, (float) $handling->handling_qty);
            $cost = (float) $facts['return_amount_excl_tax'];
            $exchangeNo = $this->nextExchangeNo($handling->business_doc_no);
            $order = PurchaseExchangeOrder::create([
                'exchange_no' => $exchangeNo,
                'defect_handling_id' => $handling->id,
                'source_receipt_id' => $handling->receipt_id,
                'source_receipt_item_id' => $handling->receipt_item_id,
                'purchase_order_id' => $handling->receipt?->order_id,
                'supplier_id' => $handling->supplier_id,
                'item_id' => $handling->item_id,
                'exchange_base_qty' => $handling->handling_qty,
                'base_unit_name_snapshot' => $line->base_unit_name_snapshot ?: $line->item?->unit?->unit_name,
                'original_contract_amount' => $facts['return_amount_incl_tax'],
                'original_payable_amount' => $facts['return_amount_incl_tax'],
                'exchange_additional_payable_amount' => 0,
                'replacement_payable_amount' => 0,
                'replacement_inventory_cost' => $cost,
                'currency_snapshot' => $facts['currency_snapshot'],
                'finance_fact_status' => $facts['finance_fact_status'],
                'exchange_status' => 'processing',
                'current_step' => 'pending_original_return',
                'source_scope' => 'receipt_inspection',
                'return_warehouse_name' => null,
                'return_location_name' => null,
                'created_by' => $operatorName ?: '系统',
                'updated_by' => $operatorName ?: '系统',
            ]);
            $handling->update([
                'business_doc_type' => 'purchase_exchange_order',
                'business_doc_no' => $exchangeNo,
                'current_step' => 'exchange_pending_original_return',
            ]);
            $this->log($order, 'create', null, 'pending_original_return', $operatorName, '由不合格品处理生成正式采购换货单。');
            return $this->load($order);
        }, 5);
    }

    public function action(int $id, string $action, array $payload, ?string $operatorName): PurchaseExchangeOrder
    {
        return DB::transaction(function () use ($id, $action, $payload, $operatorName): PurchaseExchangeOrder {
            $order = PurchaseExchangeOrder::query()
                ->with(['handling', 'inventoryQualityEvent', 'sourceReceiptItem.item', 'replacementReceipt.items.item'])
                ->lockForUpdate()
                ->findOrFail($id);
            $from = $order->current_step;

            match ($action) {
                'register_original_return' => $this->registerOriginalReturn($order, $payload, $operatorName),
                'confirm_supplier_receipt' => $this->confirmSupplierReceipt($order, $payload, $operatorName),
                'confirm_replacement_shipment' => $this->confirmReplacementShipment($order, $payload, $operatorName),
                'confirm_replacement_acceptance' => $this->confirmReplacementAcceptance($order, $payload, $operatorName),
                default => throw ValidationException::withMessages(['action' => '未知或当前阶段未开放的换货动作。']),
            };

            $order->refresh();
            $this->log($order, $action, $from, $order->current_step, $operatorName,
                $payload['result_description'] ?? $this->actionContent($action), $payload);
            return $this->load($order);
        }, 5);
    }

    public function syncReplacementReceiptConfirmed(PurchaseReceipt $receipt, ?string $operatorName): void
    {
        DB::transaction(function () use ($receipt, $operatorName): void {
            $order = PurchaseExchangeOrder::where('replacement_receipt_id', $receipt->id)->lockForUpdate()->first();
            if (!$order || $order->current_step !== 'replacement_in_transit') return;
            $from = $order->current_step;
            $order->update(['current_step' => 'pending_replacement_acceptance', 'updated_by' => $operatorName]);
            $this->log($order, 'replacement_arrived', $from, 'pending_replacement_acceptance', $operatorName,
                '替换品到货单已确认，等待换货单最终验收。');
        }, 5);
    }

    public function normalizeReplacementReceiptUnit(int $exchangeOrderId): PurchaseReceipt
    {
        return DB::transaction(function () use ($exchangeOrderId): PurchaseReceipt {
            $order = PurchaseExchangeOrder::query()
                ->with(['sourceReceiptItem.item.unit'])
                ->lockForUpdate()
                ->findOrFail($exchangeOrderId);
            if (!$order->replacement_receipt_id) {
                throw ValidationException::withMessages(['replacement_receipt_id' => '采购换货单尚未生成替换到货单。']);
            }
            $receipt = PurchaseReceipt::query()->with('items')->lockForUpdate()->findOrFail($order->replacement_receipt_id);
            if ($receipt->settlement_mode !== 'replacement_no_charge' || $receipt->confirm_status !== 'draft') {
                throw ValidationException::withMessages(['replacement_receipt_id' => '只有尚未确认的零应付替换到货单允许修正单位口径。']);
            }
            $source = $order->sourceReceiptItem;
            $baseUnitId = $source?->base_unit_id ?: $source?->item?->unit_id;
            $baseUnitName = $source?->base_unit_name_snapshot ?: $source?->item?->unit?->unit_name;
            $quantity = round((float) $order->exchange_base_qty, 8);
            $unitPrice = $quantity > 0 ? round((float) $order->replacement_inventory_cost / $quantity, 8) : 0;
            foreach ($receipt->items as $line) {
                $line->update([
                    'purchase_unit_id' => $baseUnitId,
                    'purchase_unit_name_snapshot' => $baseUnitName,
                    'conversion_factor_snapshot' => 1,
                    'base_unit_id' => $baseUnitId,
                    'base_unit_name_snapshot' => $baseUnitName,
                    'receipt_qty' => $quantity,
                    'standard_base_qty' => $quantity,
                    'actual_base_qty' => $quantity,
                    'allow_actual_conversion' => false,
                    'unit_price' => $unitPrice,
                    'receipt_cost' => $order->replacement_inventory_cost,
                ]);
            }
            $receipt->update([
                'total_receipt_qty' => $quantity,
                'total_amount' => $order->replacement_inventory_cost,
            ]);
            $this->settlements->refresh($receipt);

            return $receipt->fresh(['items.item.unit']);
        }, 5);
    }

    private function registerOriginalReturn(PurchaseExchangeOrder $order, array $payload, ?string $operatorName): void
    {
        $this->assertStep($order, 'pending_original_return');
        $qty = (float) ($payload['returned_base_qty'] ?? 0);
        if (abs($qty - (float) $order->exchange_base_qty) > 0.00000001) {
            throw ValidationException::withMessages(['returned_base_qty' => '原货退回数量必须等于换货数量。']);
        }
        $company = trim((string) ($payload['return_logistics_company'] ?? ''));
        $tracking = trim((string) ($payload['return_tracking_no'] ?? ''));
        if ($company === '' || $tracking === '') {
            throw ValidationException::withMessages(['return_tracking_no' => '必须完整填写原货退回物流公司和运单号。']);
        }
        if ($order->source_scope === 'inventory_quality') {
            if (!$order->return_warehouse_name || !$order->return_location_name) {
                throw ValidationException::withMessages(['return_warehouse_name' => '库存质量换货必须引用原库存仓库和库位。']);
            }
            $serials = collect($payload['original_serial_numbers'] ?? [])
                ->map(fn ($v) => trim((string) $v))->filter()->unique()->values();
            $event = $order->inventoryQualityEvent;
            if (!$event) {
                throw ValidationException::withMessages(['inventory_quality_event_id' => '库存质量换货单缺少来源质量事件。']);
            }
            if ($serials->isEmpty() && $event->serial_no) {
                $serials = collect([$event->serial_no]);
            }
            if ($event->serial_no && ($serials->count() !== 1 || $serials->first() !== $event->serial_no)) {
                throw ValidationException::withMessages([
                    'original_serial_numbers' => '原品序列号必须使用库存质量事件已经锁定的实物编号，不能替换为其他编号。',
                ]);
            }
            if ($order->sourceReceiptItem?->item?->serialTrackingMode() === 'required'
                && $serials->count() !== (int) round($order->exchange_base_qty)) {
                throw ValidationException::withMessages(['original_serial_numbers' => '库存质量换货必须引用已入库的原品序列号。']);
            }
            foreach ($serials as $serial) {
                PurchaseExchangeSerialLink::updateOrCreate(
                    [
                        'exchange_order_id' => $order->id,
                        'original_serial_no' => $serial,
                    ],
                    ['original_return_status' => 'returned'],
                );
            }
            $event = $order->inventoryQualityEvent;
            if (!$event) {
                throw ValidationException::withMessages(['inventory_quality_event_id' => '库存质量换货单缺少来源质量事件。']);
            }
            $this->inventory->postInventoryQualityOutbound($event, $order->exchange_no, $operatorName);
            app(InventoryQualityApplicationService::class)->markExternalOutbound(
                $event->id,
                '换货原品已退回供应商，等待替换品到货。',
                null,
                $operatorName,
            );
        }
        $order->update([
            'returned_base_qty' => $qty,
            'return_logistics_company' => $company,
            'return_tracking_no' => $tracking,
            'returned_at' => now(),
            'current_step' => 'supplier_receipt_pending',
            'updated_by' => $operatorName,
        ]);
    }

    private function ensureOriginalSerialLink(PurchaseExchangeOrder $order, InventoryQualityEvent $event): void
    {
        if (!$event->serial_no) return;

        PurchaseExchangeSerialLink::firstOrCreate(
            [
                'exchange_order_id' => $order->id,
                'original_serial_no' => $event->serial_no,
            ],
            ['original_return_status' => 'pending'],
        );
    }

    private function confirmSupplierReceipt(PurchaseExchangeOrder $order, array $payload, ?string $operatorName): void
    {
        $this->assertStep($order, 'supplier_receipt_pending');
        $receiver = trim((string) ($payload['supplier_receiver'] ?? ''));
        if ($receiver === '') throw ValidationException::withMessages(['supplier_receiver' => '必须填写供应商收货确认人。']);
        $order->serialLinks()->update(['original_return_status' => 'supplier_received']);
        $order->update([
            'supplier_receiver' => $receiver,
            'supplier_received_at' => now(),
            'current_step' => 'pending_replacement_shipment',
            'updated_by' => $operatorName,
        ]);
    }

    private function confirmReplacementShipment(PurchaseExchangeOrder $order, array $payload, ?string $operatorName): void
    {
        $this->assertStep($order, 'pending_replacement_shipment');
        foreach (['replacement_shipped_date', 'replacement_logistics_company', 'replacement_tracking_no'] as $field) {
            if (trim((string) ($payload[$field] ?? '')) === '') {
                throw ValidationException::withMessages([$field => '供应商补发日期、物流公司和运单号必须完整。']);
            }
        }
        $receipt = $this->createReplacementReceipt($order);
        $order->update([
            'replacement_receipt_id' => $receipt->id,
            'replacement_shipped_date' => $payload['replacement_shipped_date'],
            'replacement_logistics_company' => $payload['replacement_logistics_company'],
            'replacement_tracking_no' => $payload['replacement_tracking_no'],
            'replacement_expected_date' => $payload['replacement_expected_date'] ?? null,
            'replacement_shipped_at' => now(),
            'current_step' => 'replacement_in_transit',
            'updated_by' => $operatorName,
        ]);
        $order->handling?->update([
            'replacement_receipt_id' => $receipt->id,
            'current_step' => 'exchange_pending_replacement_receipt',
            'started_at' => now(),
            'updated_by' => $operatorName,
        ]);
    }

    private function confirmReplacementAcceptance(PurchaseExchangeOrder $order, array $payload, ?string $operatorName): void
    {
        $this->assertStep($order, 'pending_replacement_acceptance');
        $receipt = $order->replacementReceipt;
        if (!$receipt || $receipt->confirm_status !== 'confirmed') {
            throw ValidationException::withMessages(['replacement_receipt_id' => '替换品到货单尚未验收确认。']);
        }
        if ($receipt->stock_post_status !== 'posted') {
            throw ValidationException::withMessages(['replacement_receipt_id' => '替换品到货单尚未完成库存过账，不能完成换货。']);
        }
        $qualified = (float) $receipt->items()->sum('qualified_base_qty');
        if ($qualified + 0.00000001 < (float) $order->exchange_base_qty) {
            throw ValidationException::withMessages(['replacement_receipt_id' => '替换品合格数量不足，不能完成换货。']);
        }
        $replacementSerials = collect($receipt->items)->flatMap(function ($line) {
            return collect($line->serial_entries ?: [])->pluck('serial_no')->filter();
        })->values();
        $links = $order->serialLinks()->orderBy('id')->get();
        if ($order->sourceReceiptItem?->item?->serialTrackingMode() === 'required'
            && $replacementSerials->count() !== (int) round($order->exchange_base_qty)) {
            throw ValidationException::withMessages(['replacement_serial_numbers' => '替换品必须逐件完成序列号登记。']);
        }
        foreach ($replacementSerials as $index => $serial) {
            $link = $links->get($index) ?: new PurchaseExchangeSerialLink(['exchange_order_id' => $order->id]);
            $link->fill([
                'replacement_serial_no' => $serial,
                'replacement_receipt_status' => 'accepted',
                'linked_at' => now(),
            ])->save();
        }
        $order->update([
            'exchange_status' => 'completed',
            'current_step' => 'completed',
            'replacement_received_base_qty' => (float) $order->exchange_base_qty,
            'contract_fulfilled_base_qty' => (float) $order->exchange_base_qty,
            'replacement_payable_amount' => 0,
            'finance_fact_status' => 'frozen',
            'replacement_accepted_at' => now(),
            'completed_at' => now(),
            'updated_by' => $operatorName,
        ]);
        $order->handling?->update([
            'handling_status' => 'completed',
            'current_step' => 'exchange_completed',
            'result_description' => trim((string) ($payload['result_description'] ?? '替换品已验收合格，换货完成。')),
            'handled_at' => now(),
            'completed_at' => now(),
            'updated_by' => $operatorName,
        ]);
        if ($order->inventory_quality_event_id) {
            app(InventoryQualityApplicationService::class)->markExternalCompleted(
                (int) $order->inventory_quality_event_id,
                trim((string) ($payload['result_description'] ?? '替换品已验收合格，库存质量换货完成。')),
                null,
                $operatorName,
            );
        }
        $this->settlements->refresh($order->source_receipt_id);
        $this->settlementSources->syncReceipt($order->source_receipt_id, null, $operatorName);
    }

    private function createReplacementReceipt(PurchaseExchangeOrder $order): PurchaseReceipt
    {
        if ($order->replacement_receipt_id) return PurchaseReceipt::findOrFail($order->replacement_receipt_id);
        $source = $order->sourceReceiptItem;
        $purchaseQty = round((float) $order->exchange_base_qty, 8);
        $baseUnitId = $source->base_unit_id ?: $source->item?->unit_id;
        $baseUnitName = $source->base_unit_name_snapshot ?: $source->item?->unit?->unit_name;
        $receipt = PurchaseReceipt::create([
            'receipt_no' => $this->numbers->next('purchase_receipt', 'PRC'),
            'order_id' => $order->purchase_order_id,
            'supplier_id' => $order->supplier_id,
            'receipt_date' => now()->toDateString(),
            'receipt_status' => 'draft', 'confirm_status' => 'draft', 'stock_post_status' => 'pending',
            'total_receipt_qty' => $purchaseQty, 'total_qualified_qty' => 0, 'total_unqualified_qty' => 0,
            'total_amount' => $order->replacement_inventory_cost,
            'settlement_mode' => 'replacement_no_charge',
            'currency_snapshot' => $order->currency_snapshot ?: $source->currency_snapshot ?: 'CNY',
            'tax_mode_snapshot' => $source->tax_mode_snapshot ?: 'tax_included',
            'replacement_received_base_qty' => $purchaseQty,
            'contract_fulfilled_base_qty' => 0,
            'finance_fact_status' => 'pending',
            'remark' => "采购换货单 {$order->exchange_no} 的替换品到货单",
            'data_source' => 'system',
        ]);
        PurchaseReceiptItem::create([
            'receipt_id' => $receipt->id, 'order_item_id' => $source->order_item_id, 'item_id' => $source->item_id,
            'purchase_unit_id' => $baseUnitId, 'purchase_unit_name_snapshot' => $baseUnitName,
            'conversion_factor_snapshot' => 1, 'base_unit_id' => $baseUnitId,
            'base_unit_name_snapshot' => $baseUnitName, 'warehouse_id' => $source->warehouse_id,
            'location_id' => $source->location_id, 'receipt_qty' => $purchaseQty, 'qualified_qty' => 0, 'unqualified_qty' => 0,
            'standard_base_qty' => $order->exchange_base_qty, 'actual_base_qty' => $order->exchange_base_qty,
            'qualified_base_qty' => 0, 'unqualified_base_qty' => 0, 'difference_qty' => 0,
            'allow_actual_conversion' => false, 'inventory_posting_status' => 'pending',
            'unit_price' => $purchaseQty > 0 ? $order->replacement_inventory_cost / $purchaseQty : 0,
            'tax_rate' => $source->tax_rate, 'receipt_cost' => $order->replacement_inventory_cost,
            'is_stock_item_snapshot' => (bool) $source->is_stock_item_snapshot,
            'physical_received_base_qty' => $purchaseQty,
            'contract_fulfilled_base_qty' => 0,
            'replacement_received_base_qty' => $purchaseQty,
            'currency_snapshot' => $order->currency_snapshot ?: $source->currency_snapshot ?: 'CNY',
            'tax_mode_snapshot' => $source->tax_mode_snapshot ?: 'tax_included',
            'finance_fact_status' => 'pending',
            'batch_no' => $this->numbers->next('inventory_batch', 'BAT'), 'serial_number_source' => $source->serial_number_source,
            'remark' => "来源采购换货单 {$order->exchange_no}", 'data_source' => 'system',
        ]);
        $this->settlements->refresh($receipt);
        return $receipt->fresh(['items.item']);
    }

    private function assertStep(PurchaseExchangeOrder $order, string $step): void
    {
        if ($order->current_step !== $step) {
            throw ValidationException::withMessages(['action' => '当前换货状态不允许执行该操作，请刷新后重试。']);
        }
    }

    private function nextExchangeNo(?string $preferred = null): string
    {
        $preferred = trim((string) $preferred);
        if ($preferred !== '' && !PurchaseExchangeOrder::query()->where('exchange_no', $preferred)->exists()) {
            return $preferred;
        }

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $number = $this->numbers->next('purchase_exchange_order', 'PEX');
            if (!PurchaseExchangeOrder::query()->where('exchange_no', $number)->exists()) {
                return $number;
            }
        }

        throw ValidationException::withMessages([
            'exchange_no' => '采购换货单号连续发生冲突，请检查编号规则和历史单据。',
        ]);
    }

    private function actionContent(string $action): string
    {
        return [
            'register_original_return' => '原不合格品已发回供应商。',
            'confirm_supplier_receipt' => '供应商已确认收到原货。',
            'confirm_replacement_shipment' => '供应商已补发，系统生成零应付替换到货单。',
            'confirm_replacement_acceptance' => '替换品验收完成，换货单已关闭。',
        ][$action] ?? '换货状态已更新。';
    }

    private function log(PurchaseExchangeOrder $order, string $action, ?string $from, ?string $to, ?string $operatorName, ?string $content, array $payload = []): void
    {
        PurchaseExchangeLog::create([
            'exchange_order_id' => $order->id, 'action' => $action, 'from_step' => $from, 'to_step' => $to,
            'operator_name' => $operatorName ?: '系统', 'content' => $content, 'payload' => $payload ?: null,
        ]);
    }

    public function load(PurchaseExchangeOrder $order): PurchaseExchangeOrder
    {
        return $order->fresh([
            'handling', 'inventoryQualityEvent', 'sourceReceipt.order', 'sourceReceiptItem.item.unit', 'sourceReceiptItem.warehouse',
            'sourceReceiptItem.location', 'purchaseOrder', 'supplier', 'item.unit', 'replacementReceipt.items.item',
            'serialLinks', 'logs', 'attachments',
        ]);
    }
}
