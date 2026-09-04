<?php

namespace App\Services\Erp;

use App\Models\Erp\PurchaseDefectHandling;
use App\Models\Erp\PurchaseDefectHandlingLog;
use App\Models\Erp\PurchaseReceipt;
use App\Models\Erp\PurchaseReceiptItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseDefectApplicationService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly PurchaseReturnApplicationService $returns,
        private readonly PurchaseReceiptSettlementService $settlements,
        private readonly PurchaseSettlementSourceApplicationService $settlementSources,
        private readonly PurchaseExchangeApplicationService $exchanges,
        private readonly PurchaseFinancialFactService $finance,
    ) {
    }

    public function create(array $data, ?int $operatorId, ?string $operatorName): PurchaseDefectHandling
    {
        return DB::transaction(function () use ($data, $operatorId, $operatorName): PurchaseDefectHandling {
            $line = PurchaseReceiptItem::query()
                ->with(['receipt', 'item', 'defectHandlings'])
                ->lockForUpdate()
                ->findOrFail($data['receipt_item_id']);
            $receipt = $line->receipt;
            if (!$receipt || $receipt->confirm_status !== 'confirmed') {
                throw ValidationException::withMessages([
                    'receipt_item_id' => '到货单尚未完成验收确认，不能发起不合格品处理。',
                ]);
            }
            if ($receipt->stock_post_status !== 'pending') {
                throw ValidationException::withMessages([
                    'receipt_item_id' => '该到货单已完成库存过账，后续质量问题必须从库存余额发起库存质量处理。',
                ]);
            }
            $remaining = $this->remainingQuantity($line);
            $quantity = (float) $data['handling_qty'];
            if ($quantity > $remaining + 0.00000001) {
                throw ValidationException::withMessages(['handling_qty' => "处理数量不能超过剩余不合格/待处理数量 {$remaining}。"]);
            }
            if (in_array($data['handling_method'], ['concession', 'repair'], true)
                && $receipt->stock_post_status !== 'pending') {
                throw ValidationException::withMessages(['handling_method' => '已库存过账的质量问题必须从库存质量处理发起。']);
            }

            [$status, $step, $docType, $prefix] = $this->initialState($data['handling_method']);
            $amountFacts = $this->finance->proportionalFacts($line, $quantity);
            $handling = PurchaseDefectHandling::create([
                'handling_no' => $this->numbers->next('purchase_defect_handling', 'PDH'),
                'receipt_id' => $receipt->id,
                'receipt_item_id' => $line->id,
                'item_id' => $line->item_id,
                'supplier_id' => $receipt->supplier_id,
                'handling_method' => $data['handling_method'],
                'handling_qty' => $quantity,
                'amount_excl_tax' => $amountFacts['return_amount_excl_tax'],
                'tax_amount' => $amountFacts['return_tax_amount'],
                'amount_incl_tax' => $amountFacts['return_amount_incl_tax'],
                'settlement_effect_type' => in_array($data['handling_method'], ['return_supplier', 'scrap'], true)
                    ? 'REFUSE_PAY'
                    : 'PENDING',
                'finance_fact_status' => $amountFacts['finance_fact_status'],
                'handling_status' => $status,
                'current_step' => $step,
                'business_doc_type' => $docType,
                'business_doc_no' => $prefix ? $this->numbers->next('purchase_defect_'.$data['handling_method'], $prefix) : null,
                'defect_reason' => $data['defect_reason'],
                'defect_description' => $data['defect_description'] ?? null,
                'responsible_party' => $data['responsible_party'],
                'remark' => $data['remark'] ?? null,
                'created_by' => $operatorName ?: '系统',
                'updated_by' => $operatorName ?: '系统',
            ]);

            $this->log($handling, 'create', null, $step, $operatorName, '创建不合格品处理单，等待后续业务动作。', $data);

            if ($handling->handling_method === 'return_supplier') {
                $purchaseReturn = $this->returns->createRejectedFromDefect($line, $handling, $quantity, $operatorId, $operatorName);
                $handling->update([
                    'business_doc_type' => 'purchase_return',
                    'business_doc_no' => $purchaseReturn->return_no,
                    'current_step' => 'return_pending_approval',
                ]);
                $this->log($handling, 'create_return', $step, 'return_pending_approval', $operatorName, '已生成采购退货单，等待审核。', ['purchase_return_id' => $purchaseReturn->id]);
            }

            if ($handling->handling_method === 'exchange') {
                $exchange = $this->exchanges->createFromDefect($handling, $operatorName);
                $handling = $handling->fresh();
                $this->log($handling, 'create_exchange_order', $step, 'exchange_pending_original_return', $operatorName,
                    '已生成正式采购换货单，等待登记原货退回。', ['purchase_exchange_order_id' => $exchange->id]);
            }

            return $this->load($handling);
        }, 5);
    }

    public function action(int $id, string $action, array $payload, ?string $operatorName): PurchaseDefectHandling
    {
        return DB::transaction(function () use ($id, $action, $payload, $operatorName): PurchaseDefectHandling {
            $handling = PurchaseDefectHandling::query()
                ->with(['receiptItem.receipt', 'replacementReceipt.items'])
                ->lockForUpdate()
                ->findOrFail($id);
            $from = (string) $handling->current_step;

            match ($action) {
                'approve_concession' => $this->approveConcession($handling, $payload, $operatorName),
                'start_repair' => $this->startRepair($handling, $operatorName),
                'complete_repair' => $this->completeRepair($handling, $payload, $operatorName),
                'approve_scrap' => $this->approveScrap($handling, $operatorName),
                'confirm_scrap' => $this->confirmScrap($handling, $payload, $operatorName),
                default => throw ValidationException::withMessages(['action' => '未知或当前阶段未开放的处理动作。']),
            };

            $handling->refresh();
            $this->log(
                $handling,
                $action,
                $from,
                $handling->current_step,
                $operatorName,
                $payload['result_description'] ?? $this->actionContent($action),
                $payload,
            );
            $this->settlements->refresh($handling->receipt_id);
            $this->settlementSources->syncReceipt($handling->receipt_id, null, $operatorName);

            return $this->load($handling);
        }, 5);
    }

    private function approveConcession(PurchaseDefectHandling $handling, array $payload, ?string $operatorName): void
    {
        $this->assertStep($handling, 'concession', 'concession_pending_approval');
        $this->acceptIntoQualified($handling->receiptItem, (float) $handling->handling_qty);
        $this->complete($handling, 'concession_completed', $payload['result_description'] ?? '让步接收审批通过，数量转入合格待过账。', $operatorName);
        $handling->approved_at = now();
        $handling->save();
    }

    private function startRepair(PurchaseDefectHandling $handling, ?string $operatorName): void
    {
        $this->assertStep($handling, 'repair', 'repair_pending_start');
        $handling->update(['current_step' => 'repair_in_progress', 'started_at' => now(), 'updated_by' => $operatorName]);
    }

    private function completeRepair(PurchaseDefectHandling $handling, array $payload, ?string $operatorName): void
    {
        $this->assertStep($handling, 'repair', 'repair_in_progress');
        $result = trim((string) ($payload['result_description'] ?? ''));
        if ($result === '') throw ValidationException::withMessages(['result_description' => '返修完成必须填写返修结果和复检结论。']);
        $this->acceptIntoQualified($handling->receiptItem, (float) $handling->handling_qty);
        $this->complete($handling, 'repair_completed', $result, $operatorName);
    }

    private function approveScrap(PurchaseDefectHandling $handling, ?string $operatorName): void
    {
        $this->assertStep($handling, 'scrap', 'scrap_pending_approval');
        $handling->update(['current_step' => 'scrap_pending_confirmation', 'approved_at' => now(), 'updated_by' => $operatorName]);
    }

    private function confirmScrap(PurchaseDefectHandling $handling, array $payload, ?string $operatorName): void
    {
        $this->assertStep($handling, 'scrap', 'scrap_pending_confirmation');
        $result = trim((string) ($payload['result_description'] ?? ''));
        if ($result === '') throw ValidationException::withMessages(['result_description' => '报废执行完成必须填写实物处置结果。']);
        $this->complete($handling, 'scrap_completed', $result, $operatorName);
    }

    private function confirmExchangeReturn(PurchaseDefectHandling $handling, array $payload, ?string $operatorName): void
    {
        $this->assertStep($handling, 'exchange', 'exchange_pending_original_return');
        $receipt = $this->createReplacementReceipt($handling);
        $handling->update([
            'current_step' => 'exchange_pending_replacement_receipt',
            'replacement_receipt_id' => $receipt->id,
            'started_at' => now(),
            'result_description' => $payload['result_description'] ?? '原不合格品已退回，等待替换品到货验收。',
            'updated_by' => $operatorName,
        ]);
    }

    private function completeExchange(PurchaseDefectHandling $handling, array $payload, ?string $operatorName): void
    {
        $this->assertStep($handling, 'exchange', 'exchange_pending_replacement_receipt');
        $receipt = $handling->replacementReceipt;
        if (!$receipt || $receipt->confirm_status !== 'confirmed') {
            throw ValidationException::withMessages(['replacement_receipt_id' => '替换品到货单尚未完成验收确认，不能完成换货。']);
        }
        $qualified = (float) $receipt->items()->sum('qualified_base_qty');
        if ($qualified + 0.00000001 < (float) $handling->handling_qty) {
            throw ValidationException::withMessages(['replacement_receipt_id' => '替换品验收合格数量不足，不能完成换货。']);
        }
        $this->complete($handling, 'exchange_completed', $payload['result_description'] ?? '替换品已到货并验收合格。', $operatorName);
    }

    private function createReplacementReceipt(PurchaseDefectHandling $handling): PurchaseReceipt
    {
        $source = $handling->receiptItem()->with(['receipt', 'item'])->firstOrFail();
        $baseQuantity = (float) $handling->handling_qty;
        $actualBase = (float) ($source->actual_base_qty ?: $source->standard_base_qty ?: $source->receipt_qty);
        $purchaseQuantity = $actualBase > 0
            ? round($baseQuantity * (float) $source->receipt_qty / $actualBase, 8)
            : $baseQuantity;

        $receipt = PurchaseReceipt::create([
            'receipt_no' => $this->numbers->next('purchase_receipt', 'PRC'),
            'order_id' => $source->receipt->order_id,
            'supplier_id' => $source->receipt->supplier_id,
            'receipt_date' => now()->toDateString(),
            'receipt_status' => 'draft',
            'confirm_status' => 'draft',
            'stock_post_status' => 'pending',
            'total_receipt_qty' => $purchaseQuantity,
            'total_qualified_qty' => 0,
            'total_unqualified_qty' => 0,
            'total_amount' => $purchaseQuantity * (float) $source->unit_price,
            'settlement_mode' => 'replacement_no_charge',
            'remark' => "换货处理 {$handling->handling_no} 的替换品到货单",
            'data_source' => 'system',
        ]);

        PurchaseReceiptItem::create([
            'receipt_id' => $receipt->id,
            'order_item_id' => $source->order_item_id,
            'item_id' => $source->item_id,
            'purchase_unit_id' => $source->purchase_unit_id,
            'purchase_unit_name_snapshot' => $source->purchase_unit_name_snapshot,
            'conversion_factor_snapshot' => $source->conversion_factor_snapshot,
            'base_unit_id' => $source->base_unit_id,
            'base_unit_name_snapshot' => $source->base_unit_name_snapshot,
            'warehouse_id' => $source->warehouse_id,
            'location_id' => $source->location_id,
            'receipt_qty' => $purchaseQuantity,
            'qualified_qty' => 0,
            'unqualified_qty' => 0,
            'standard_base_qty' => $baseQuantity,
            'actual_base_qty' => $baseQuantity,
            'qualified_base_qty' => 0,
            'unqualified_base_qty' => 0,
            'difference_qty' => 0,
            'allow_actual_conversion' => $source->allow_actual_conversion,
            'inventory_posting_status' => 'pending',
            'unit_price' => $source->unit_price,
            'tax_rate' => $source->tax_rate,
            'receipt_cost' => $purchaseQuantity * (float) $source->unit_price,
            'batch_no' => $this->numbers->next('inventory_batch', 'BAT'),
            'serial_number_source' => $source->serial_number_source,
            'remark' => "来源不合格品换货处理 {$handling->handling_no}",
            'data_source' => 'system',
        ]);

        return $receipt->fresh(['items.item']);
    }

    private function acceptIntoQualified(PurchaseReceiptItem $line, float $baseQuantity): void
    {
        $line = PurchaseReceiptItem::query()->with('receipt')->lockForUpdate()->findOrFail($line->id);
        $actualBase = (float) ($line->actual_base_qty ?: $line->standard_base_qty ?: $line->receipt_qty);
        $factor = (float) ($line->conversion_factor_snapshot ?: 1);
        $purchaseQuantity = $actualBase > 0 && (float) $line->receipt_qty > 0
            ? $baseQuantity * (float) $line->receipt_qty / $actualBase
            : $baseQuantity / $factor;
        $line->update([
            'qualified_qty' => (float) $line->qualified_qty + $purchaseQuantity,
            'unqualified_qty' => max(0, (float) $line->unqualified_qty - $purchaseQuantity),
            'qualified_base_qty' => (float) $line->qualified_base_qty + $baseQuantity,
            'unqualified_base_qty' => max(0, (float) $line->unqualified_base_qty - $baseQuantity),
        ]);
        $line->receipt->update([
            'total_qualified_qty' => $line->receipt->items()->sum('qualified_qty'),
            'total_unqualified_qty' => $line->receipt->items()->sum('unqualified_qty'),
        ]);
    }

    private function complete(PurchaseDefectHandling $handling, string $step, string $result, ?string $operatorName): void
    {
        $handling->update([
            'handling_status' => 'completed',
            'current_step' => $step,
            'result_description' => $result,
            'handled_at' => now(),
            'completed_at' => now(),
            'updated_by' => $operatorName,
        ]);
    }

    private function remainingQuantity(PurchaseReceiptItem $line): float
    {
        $receiptQuantity = (float) ($line->actual_base_qty ?: $line->standard_base_qty ?: $line->receipt_qty);
        $qualified = (float) ($line->qualified_base_qty ?: $line->qualified_qty);
        $unqualified = (float) ($line->unqualified_base_qty ?: $line->unqualified_qty);
        $pending = max(0, $receiptQuantity - $qualified - $unqualified);
        $reserved = (float) $line->defectHandlings
            ->filter(fn (PurchaseDefectHandling $row) => $row->handling_status !== 'cancelled'
                && $row->handling_method !== 'pending')
            ->sum('handling_qty');
        return max(0, $unqualified + $pending - $reserved);
    }

    private function initialState(string $method): array
    {
        return match ($method) {
            'return_supplier' => ['processing', 'return_creating_order', 'purchase_return', null],
            'exchange' => ['processing', 'exchange_pending_original_return', 'exchange_order', 'PEX'],
            'concession' => ['processing', 'concession_pending_approval', 'concession_acceptance', 'PAC'],
            'repair' => ['processing', 'repair_pending_start', 'repair_order', 'PRR'],
            'scrap' => ['processing', 'scrap_pending_approval', 'scrap_order', 'PSC'],
            default => ['pending', 'pending_decision', 'pending_decision', null],
        };
    }

    private function assertStep(PurchaseDefectHandling $handling, string $method, string $step): void
    {
        if ($handling->handling_method !== $method || $handling->current_step !== $step) {
            throw ValidationException::withMessages(['action' => '当前处理方式或状态不允许执行该操作，请刷新后重试。']);
        }
    }

    private function actionContent(string $action): string
    {
        return [
            'approve_concession' => '让步接收审批通过。',
            'start_repair' => '返修开始执行。',
            'approve_scrap' => '报废审批通过。',
            'confirm_exchange_return' => '原不合格品已退回，已生成替换品到货单。',
        ][$action] ?? '处理状态已更新。';
    }

    private function log(PurchaseDefectHandling $handling, string $action, ?string $from, ?string $to, ?string $operatorName, ?string $content, array $payload = []): void
    {
        PurchaseDefectHandlingLog::create([
            'handling_id' => $handling->id,
            'action' => $action,
            'from_step' => $from,
            'to_step' => $to,
            'operator_name' => $operatorName,
            'content' => $content,
            'payload' => $payload ?: null,
        ]);
    }

    private function load(PurchaseDefectHandling $handling): PurchaseDefectHandling
    {
        return $handling->fresh(['receipt', 'receiptItem.item', 'item', 'supplier', 'replacementReceipt.items', 'logs']);
    }
}
