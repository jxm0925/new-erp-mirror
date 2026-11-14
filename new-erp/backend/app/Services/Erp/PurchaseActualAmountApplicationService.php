<?php

namespace App\Services\Erp;

use App\Domain\Finance\FinanceConstants;
use App\Domain\Finance\Money;
use App\Models\Erp\FinanceInvoiceAllocation;
use App\Models\Erp\PurchaseDefectHandling;
use App\Models\Erp\PurchaseExchangeOrder;
use App\Models\Erp\PurchaseLog;
use App\Models\Erp\PurchaseOrder;
use App\Models\Erp\PurchasePaymentPlanItem;
use App\Models\Erp\PurchaseReceipt;
use App\Models\Erp\PurchaseReceiptItem;
use App\Models\Erp\PurchaseReturnItem;
use App\Models\Erp\PurchaseSettlementSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** A final agreed amount for one receipt line; never a new payment or a partial-payment write-off. */
class PurchaseActualAmountApplicationService
{
    public const ACTION = 'finance_actual_amount_corrected';

    public function __construct(
        private readonly FinanceAllocationBalanceQueryService $balances,
        private readonly PurchaseReceiptSettlementService $settlements,
        private readonly PurchaseSettlementSourceApplicationService $sources,
    ) {}

    public static function rules(bool $applying = false): array
    {
        $rules = ['actual_amount' => [$applying ? 'required' : 'sometimes', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/D']];
        if ($applying) $rules += [
            'expected_fingerprint' => 'required|string|size:64|regex:/^[a-f0-9]{64}$/D',
            'reason' => 'required|string|max:1000',
            'idempotency_key' => 'required|string|max:100',
            'business_date' => 'sometimes|date_format:Y-m-d',
        ];
        return $rules;
    }

    public function preview(int $sourceId, array $data, object $actor): array
    {
        $this->authorize($actor, 'finance.payable.view');
        $data = Validator::make($data, self::rules())->validate();
        return DB::transaction(function () use ($sourceId, $data, $actor): array {
            [$source, $receipt, $line] = $this->lockContext($sourceId);
            return $this->previewLocked($source, $receipt, $line, $data['actual_amount'] ?? null, $actor);
        }, 5);
    }

    public function apply(int $sourceId, array $data, object $actor): array
    {
        $this->authorize($actor, 'finance.payable.view');
        $this->authorize($actor, 'finance.payment.confirm');
        $data = Validator::make($data, self::rules(true))->validate();
        $data['reason'] = trim($data['reason']);
        $data['idempotency_key'] = trim($data['idempotency_key']);
        if ($data['reason'] === '' || $data['idempotency_key'] === '') {
            throw ValidationException::withMessages(['reason' => '请填写实际金额修正原因和有效请求编号。']);
        }
        $data['actual_amount'] = Money::normalize((string) $data['actual_amount']);
        $requestHash = hash('sha256', json_encode(['source_id' => $sourceId, ...$data], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($sourceId, $data, $actor, $requestHash): array {
            [$source, $receipt, $line] = $this->lockContext($sourceId);
            // The receipt lock serializes this key and every affected line; a lost response must not post cost twice.
            $previous = PurchaseLog::query()->where('target_type', 'purchase_receipt')->where('target_id', $receipt->id)
                ->where('action', self::ACTION)->where('evidence->idempotency_key', $data['idempotency_key'])
                ->orderBy('id')->lockForUpdate()->first();
            if ($previous) {
                if (! hash_equals((string) ($previous->evidence['request_hash'] ?? ''), $requestHash)) {
                    throw ValidationException::withMessages(['idempotency_key' => '该请求编号已用于其他实际金额修正内容。']);
                }
                return [...$previous->evidence['result'], 'replayed' => true];
            }

            $preview = $this->previewLocked($source, $receipt, $line, $data['actual_amount'], $actor);
            if (! hash_equals($preview['expected_fingerprint'], $data['expected_fingerprint'])) {
                abort(409, '到货、核销、发票或库存状态已变化，请重新预览后确认实际金额。');
            }
            if ($preview['block_reasons']) {
                throw ValidationException::withMessages(['actual_amount' => array_column($preview['block_reasons'], 'message')]);
            }
            if (! $preview['has_amount_change'] && ! $preview['has_cost_change']) {
                throw ValidationException::withMessages(['actual_amount' => '实际金额和库存成本均未变化，无需重复修正。']);
            }

            $oldActual = $line->effectiveAmount();
            $oldOverride = $line->finance_actual_amount;
            $oldCost = Money::normalize((string) $line->inventory_cost_amount);
            $line->update(['finance_actual_amount' => $data['actual_amount']]);
            $this->settlements->refresh($receipt);
            $line->refresh()->setRelation('receipt', $receipt);
            $newCost = Money::normalize((string) $line->inventory_cost_amount);
            $operatorId = (int) $actor->legacy_id;
            $operatorName = (string) ($actor->nickname ?: $actor->username ?: '系统');
            $log = PurchaseLog::create([
                'target_type' => 'purchase_receipt', 'target_id' => $receipt->id, 'action' => self::ACTION,
                'operator' => $operatorName, 'content' => '财务核定本行最终实际金额：'.$data['reason'],
                'evidence' => [
                    'source_id' => $sourceId, 'receipt_item_id' => $line->id,
                    'original_amount' => $preview['original_amount'], 'old_actual_amount' => $oldActual,
                    'old_finance_actual_amount' => $oldOverride, 'new_finance_actual_amount' => $data['actual_amount'],
                    'old_inventory_cost_amount' => $oldCost, 'new_inventory_cost_amount' => $newCost,
                    'business_date' => $data['business_date'] ?? now()->toDateString(),
                    'reason' => $data['reason'], 'operator_id' => $operatorId, 'operator_name' => $operatorName,
                    'idempotency_key' => $data['idempotency_key'], 'request_hash' => $requestHash,
                    'expected_fingerprint' => $data['expected_fingerprint'],
                ],
            ]);
            $costTransaction = $preview['inventory_posted']
                ? app(PurchaseReceiptCostAdjustmentService::class)->apply($line, $oldCost, $newCost, $log->id, $operatorId)
                : null;
            $this->sources->syncReceipt($receipt->id, $operatorId, $operatorName);
            [$source, $receipt, $line] = $this->lockContext($sourceId);
            $result = [...$this->previewLocked($source, $receipt, $line, null, $actor),
                'log_id' => $log->id, 'cost_transaction_id' => $costTransaction?->id, 'replayed' => false];
            $log->refresh();
            $log->update(['evidence' => [...$log->evidence, 'cost_transaction_id' => $costTransaction?->id, 'result' => $result]]);
            return $result;
        }, 5);
    }

    public function history(int $sourceId, int $perPage, object $actor): array
    {
        $this->authorize($actor, 'finance.payable.view');
        $source = PurchaseSettlementSource::query()->findOrFail($sourceId);
        $page = PurchaseLog::query()->where('target_type', 'purchase_receipt')->where('target_id', $source->source_receipt_id)
            ->where('action', self::ACTION)->where('evidence->receipt_item_id', $source->source_line_id)
            ->latest('id')->paginate(min(100, max(1, $perPage)));
        $page->setCollection($page->getCollection()->map(function (PurchaseLog $log): array {
            $fact = $log->evidence ?: [];
            return ['id' => $log->id, 'receipt_item_id' => $fact['receipt_item_id'] ?? null,
                'original_amount' => $fact['original_amount'] ?? null, 'old_actual_amount' => $fact['old_actual_amount'] ?? null,
                'new_actual_amount' => $fact['new_finance_actual_amount'] ?? null,
                'old_inventory_cost_amount' => $fact['old_inventory_cost_amount'] ?? null,
                'new_inventory_cost_amount' => $fact['new_inventory_cost_amount'] ?? null,
                'cost_transaction_id' => $fact['cost_transaction_id'] ?? null,
                'business_date' => $fact['business_date'] ?? null, 'reason' => $fact['reason'] ?? '',
                'operator_id' => $fact['operator_id'] ?? null, 'operator_name' => $fact['operator_name'] ?? $log->operator,
                'created_at' => $log->created_at?->toISOString()];
        }));
        return $page->toArray();
    }

    private function lockContext(int $sourceId): array
    {
        $identity = PurchaseSettlementSource::query()->findOrFail($sourceId, ['id', 'source_receipt_id', 'purchase_order_id']);
        $order = $identity->purchase_order_id ? PurchaseOrder::query()->whereKey($identity->purchase_order_id)->lockForUpdate()->firstOrFail() : null;
        $receipt = PurchaseReceipt::query()->whereKey($identity->source_receipt_id)->lockForUpdate()->firstOrFail();
        $items = $receipt->items()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $source = PurchaseSettlementSource::query()->whereKey($sourceId)->lockForUpdate()->firstOrFail();
        $line = $items->get($source->source_line_id);
        abort_unless($line && (int) $source->source_receipt_id === (int) $receipt->id
            && (int) $source->purchase_order_id === (int) $receipt->order_id
            && (int) $source->source_document_id === (int) $receipt->id
            && $source->source_type === PurchaseSettlementSourceApplicationService::SOURCE_TYPE_RECEIPT_QUALIFIED
            && $source->source_document_type === 'purchase_receipt', 422, '该结算来源与原到货明细不一致。');
        $line->setRelation('receipt', $receipt);
        $receipt->setRelation('order', $order);
        $line->load('item');
        return [$source, $receipt, $line];
    }

    private function previewLocked(PurchaseSettlementSource $source, PurchaseReceipt $receipt, PurchaseReceiptItem $line, ?string $amount, object $actor): array
    {
        $target = $amount === null ? $line->effectiveAmount() : Money::normalize($amount);
        $current = $line->effectiveAmount();
        $reasons = [];
        if (! $this->can($actor, 'finance.payment.confirm')) $this->reason($reasons, 'permission_denied', '当前账号没有确认财务实际金额的权限。');
        if ($receipt->confirm_status !== 'confirmed' || $receipt->settlement_mode !== 'normal'
            || $receipt->finance_fact_status !== 'frozen' || $line->finance_fact_status !== 'frozen') {
            $this->reason($reasons, 'receipt_ineligible', '仅金额已冻结的普通已确认到货允许财务核定实际金额。');
        }
        if ($source->currency !== 'CNY' || $receipt->currency_snapshot !== 'CNY') $this->reason($reasons, 'currency_unsupported', '当前仅支持人民币到货实际金额修正。');
        $base = (string) ($line->original_received_base_qty ?: $line->actual_base_qty ?: $line->standard_base_qty ?: $line->receipt_qty);
        $stockable = (string) $line->final_stockable_base_qty;
        $defects = PurchaseDefectHandling::query()->where('receipt_item_id', $line->id)->orderBy('id')->lockForUpdate()
            ->get(['id', 'handling_status', 'handling_method', 'handling_qty']);
        $completed = $defects->where('handling_status', 'completed');
        $handled = fn (string $method): string => $completed->where('handling_method', $method)
            ->reduce(fn (string $sum, $row): string => bcadd($sum, (string) $row->handling_qty, 8), '0.00000000');
        $repaired = $handled('repair');
        $concession = $handled('concession');
        $accepted = bcadd(bcadd((string) ($line->original_qualified_base_qty ?? '0'), $repaired, 8), $concession, 8);
        if (bccomp($base, '0', 8) <= 0 || bccomp($accepted, $base, 8) !== 0
            || bccomp(bcadd((string) ($line->original_qualified_base_qty ?? '0'), (string) ($line->original_unqualified_base_qty ?? '0'), 8), $base, 8) !== 0
            || bccomp($repaired, (string) ($line->rework_qualified_base_qty ?? '0'), 8) !== 0
            || bccomp($concession, (string) ($line->concession_accepted_base_qty ?? '0'), 8) !== 0
            || bccomp(bcadd(bcadd($handled('return_supplier'), $handled('scrap'), 8), $handled('exchange'), 8), '0', 8) > 0
            || bccomp((string) ($line->rejected_base_qty ?? '0'), '0', 8) > 0
            || bccomp((string) ($line->scrapped_base_qty ?? '0'), '0', 8) > 0
            || ((bool) $line->is_stock_item_snapshot && bccomp($stockable, $base, 8) !== 0)) {
            $this->reason($reasons, 'quality_quantity_unsettled', '仅本行全部合格或已完成让步、返修接收且没有拒收报废的到货允许核定最终实际金额。');
        }
        if (Money::compare((string) $line->quality_hold_amount, '0') > 0
            || $defects->whereNotIn('handling_status', ['completed', 'cancelled'])->isNotEmpty()) {
            $this->reason($reasons, 'quality_pending', '该行仍有未决质量处理，请处理完成后再核定最终实际金额。');
        }
        $returns = PurchaseReturnItem::query()->join('erp_purchase_returns as actual_return', 'actual_return.id', '=', 'erp_purchase_return_items.return_id')
            ->where('source_receipt_item_id', $line->id)->where('actual_return.return_status', '!=', 'cancelled')
            ->orderBy('erp_purchase_return_items.id')->lockForUpdate()->get(['erp_purchase_return_items.id', 'actual_return.return_status']);
        $exchanges = PurchaseExchangeOrder::query()->where('source_receipt_item_id', $line->id)
            ->where('exchange_status', '!=', 'cancelled')->orderBy('id')->lockForUpdate()->get(['id', 'exchange_status']);
        if ($returns->isNotEmpty() || $exchanges->isNotEmpty()) {
            $this->reason($reasons, 'return_exchange_exists', '该行已有退货或换货事实，请先核对原结算，不能直接重算已有退换货金额。');
        }

        $paid = $this->balances->forSource(FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE, $source->id, FinanceConstants::DIRECTION_PAYMENT);
        $legacyPaid = $this->balances->forSource(FinanceConstants::SOURCE_PURCHASE_RECEIPT, $receipt->id, FinanceConstants::DIRECTION_PAYMENT);
        if (Money::compare($legacyPaid, '0') > 0) $this->reason($reasons, 'legacy_receipt_allocation', '原到货存在历史整单付款核销，请先在往来核销中更正到具体结算来源。');
        if (Money::compare($paid, $target) > 0) $this->reason($reasons, 'payment_exceeds_actual', '已核销付款超过本行最终金额，请先在往来核销中正式撤销或更正核销；原付款金额保留。');
        $invoices = FinanceInvoiceAllocation::query()->join('erp_finance_invoices as actual_invoice', 'actual_invoice.id', '=', 'erp_finance_invoice_allocations.invoice_id')
            ->where('erp_finance_invoice_allocations.status', FinanceConstants::ALLOCATION_ACTIVE)
            ->whereIn('actual_invoice.status', [FinanceConstants::STATUS_DRAFT, FinanceConstants::STATUS_CONFIRMED, FinanceConstants::STATUS_RED])
            ->where(function ($query) use ($source, $receipt): void {
                $query->where(fn ($q) => $q->where('source_business_type', FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE)->where('source_document_id', $source->id))
                    ->orWhere(fn ($q) => $q->where('source_business_type', FinanceConstants::SOURCE_PURCHASE_RECEIPT)->where('source_document_id', $receipt->id));
            })->orderBy('erp_finance_invoice_allocations.id')->lockForUpdate()->get([
                'erp_finance_invoice_allocations.id', 'source_business_type', 'allocated_amount', 'actual_invoice.status as invoice_status',
            ]);
        $matched = $reserved = '0.0000';
        foreach ($invoices as $invoice) {
            if ($invoice->source_business_type === FinanceConstants::SOURCE_PURCHASE_RECEIPT) {
                $this->reason($reasons, 'legacy_invoice_allocation', '原到货存在历史整单发票匹配，请先在发票管理中核对后再修正本行实际金额。');
            }
            if ($invoice->invoice_status === FinanceConstants::STATUS_DRAFT) $reserved = Money::add($reserved, (string) $invoice->allocated_amount);
            else $matched = Money::add($matched, (string) $invoice->allocated_amount);
        }
        if (Money::compare(Money::add($matched, $reserved), $target) > 0) {
            $this->reason($reasons, 'invoice_exceeds_actual', '已确认发票和草稿匹配合计超过最终金额，请先在发票管理中撤销草稿匹配或办理正式红冲。');
        }

        $order = $receipt->order;
        $plans = $order ? PurchasePaymentPlanItem::query()->where('purchase_order_id', $order->id)->where('status', 'active')
            ->orderBy('id')->lockForUpdate()->get(['id', 'amount', 'sequence_no']) : collect();
        $planned = $plans->reduce(fn (string $sum, $plan): string => Money::add($sum, (string) $plan->amount), '0.0000');
        $paymentAmount = $order ? FinanceCashPurchaseAllocationApplicationService::paymentAmount($order) : null;
        $targetPaymentAmount = $paymentAmount === null ? null : Money::add($paymentAmount, Money::sub($target, $current));
        if ($targetPaymentAmount !== null && Money::compare($planned, $targetPaymentAmount) > 0) {
            $this->reason($reasons, 'payment_plan_exceeds_actual', '现有付款安排合计超过修正后的订单付款总额，请先在原采购订单的付款安排中调整期次金额；不会自动减少分期尾款。');
        }

        $cost = (bool) $line->is_stock_item_snapshot
            ? app(PurchaseReceiptCostAdjustmentService::class)->preview($line)
            : ['supported' => true, 'reason' => null, 'posted' => false, 'version' => 'not_stock_managed'];
        if (! $cost['supported']) $this->reason($reasons, 'cost_adjustment_unavailable', $cost['reason'] ?: '当前库存成本无法安全追溯修正。');
        $targetCost = (bool) $line->is_stock_item_snapshot && bccomp($base, '0', 8) > 0 && bccomp($stockable, '0', 8) > 0
            && bccomp($stockable, $base, 8) <= 0
            ? CuttingDecimal::share($target, $base, $stockable) : '0.0000';
        if (bccomp($stockable, '0', 8) > 0 && bccomp(bcdiv($targetCost, $stockable, 8), '9999999999.99999999', 8) > 0) {
            $this->reason($reasons, 'unit_cost_out_of_range', '实际金额反算的库存单价超过系统允许范围，请核对金额和到货数量。');
        }
        $revision = PurchaseLog::query()->where('target_type', 'purchase_receipt')->where('target_id', $receipt->id)
            ->where('action', self::ACTION)->latest('id')->lockForUpdate()->first(['id']);
        $fingerprint = hash('sha256', json_encode([
            'target_actual_amount' => $target,
            'source' => $source->only(['id', 'source_line_id', 'source_version', 'eligible_amount', 'ap_offset_amount', 'status']),
            'receipt' => $receipt->only(['confirm_status', 'settlement_mode', 'stock_post_status', 'finance_fact_status', 'currency_snapshot']),
            'line' => $line->only(['amount_incl_tax', 'finance_actual_amount', 'settlement_amount', 'inventory_cost_amount',
                'quality_hold_amount', 'finance_fact_status', 'original_received_base_qty', 'actual_base_qty', 'final_stockable_base_qty']),
            'paid' => $paid, 'legacy_paid' => $legacyPaid, 'invoices' => $invoices->toArray(),
            'returns' => $returns->toArray(), 'exchanges' => $exchanges->toArray(), 'defects' => $defects->toArray(),
            'cost_version' => $cost['version'], 'revision_id' => $revision?->id,
            'order' => $order?->only(['id', 'amount_incl_tax', 'total_amount', 'payment_plan_version']),
            'order_payment_amount' => $paymentAmount, 'payment_plans' => $plans->toArray(),
        ], JSON_THROW_ON_ERROR));
        $hasAmountChange = Money::compare($target, $current) !== 0;
        $hasCostChange = Money::compare($targetCost, (string) $line->inventory_cost_amount) !== 0;
        return [
            'source_id' => $source->id, 'receipt_id' => $receipt->id, 'receipt_item_id' => $line->id, 'receipt_no' => $receipt->receipt_no,
            'source_type' => $source->source_type, 'source_document_type' => $source->source_document_type,
            'item_code' => $line->item?->item_code, 'item_name' => $line->item?->item_name,
            'spec_model' => $line->spec_model ?: ($line->item?->spec ?: $line->item?->model),
            'currency' => $source->currency, 'original_amount' => Money::normalize((string) ($line->amount_incl_tax ?? $line->receipt_cost ?? '0')),
            'current_actual_amount' => $current, 'finance_actual_amount' => $line->finance_actual_amount,
            'target_actual_amount' => $target, 'actual_amount_delta' => Money::sub($target, $current),
            'current_payable_amount' => Money::maxZero(Money::sub((string) $line->settlement_amount, (string) $source->ap_offset_amount)),
            'target_payable_amount' => Money::maxZero(Money::sub($target, (string) $source->ap_offset_amount)),
            'paid_amount' => $paid, 'invoice_matched_amount' => $matched, 'invoice_reserved_amount' => $reserved,
            'current_order_payment_amount' => $paymentAmount, 'target_order_payment_amount' => $targetPaymentAmount, 'planned_amount' => $planned,
            'old_inventory_cost' => Money::normalize((string) $line->inventory_cost_amount), 'target_inventory_cost' => $targetCost,
            'cost_adjustment_amount' => Money::sub($targetCost, (string) $line->inventory_cost_amount), 'inventory_posted' => (bool) $cost['posted'],
            'expected_fingerprint' => $fingerprint, 'has_amount_change' => $hasAmountChange, 'has_cost_change' => $hasCostChange,
            'can_apply' => $reasons === [] && ($hasAmountChange || $hasCostChange),
            'block_reasons' => array_values(array_unique($reasons, SORT_REGULAR)),
        ];
    }

    private function reason(array &$reasons, string $code, string $message): void
    {
        $reasons[] = compact('code', 'message');
    }

    private function can(object $actor, string $permission): bool
    {
        $auth = app(AuthContextService::class);
        return $auth->isSuperAdmin($actor) || in_array($permission, $auth->permissionCodes($actor), true);
    }

    private function authorize(object $actor, string $permission): void
    {
        abort_unless($this->can($actor, $permission), 403, '当前账号没有执行采购实际金额操作的财务权限。');
    }
}
