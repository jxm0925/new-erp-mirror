<?php

namespace App\Services\Erp;

use App\Domain\Finance\FinanceConstants;
use App\Domain\Finance\Money;
use App\Models\Erp\FinanceInvoice;
use App\Models\Erp\FinanceInvoiceAllocation;
use App\Models\Erp\FinanceAttachment;
use App\Models\Erp\FinanceOperationLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * 发票领域底座。本阶段不开放页面，只固化发票、来源分摊、超开防护和反向事实。
 */
class FinanceInvoiceApplicationService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly FinancePartyResolver $parties,
        private readonly FinanceBusinessSourceResolver $sources,
        private readonly PurchaseSettlementSourceApplicationService $purchaseSettlementSources,
    ) {}

    public function create(array $data, ?int $operatorId): FinanceInvoice
    {
        return DB::transaction(function () use ($data, $operatorId): FinanceInvoice {
            $direction = (string) $data['invoice_direction'];
            if (!in_array($direction, [FinanceConstants::INVOICE_SALES, FinanceConstants::INVOICE_PURCHASE], true)) {
                throw ValidationException::withMessages(['invoice_direction' => '发票方向无效。']);
            }
            $party = $this->parties->resolve((string) $data['party_type'], (int) $data['party_id']);
            if ($direction === FinanceConstants::INVOICE_PURCHASE && (($data['currency'] ?? 'CNY') !== 'CNY')) {
                throw ValidationException::withMessages(['currency' => '采购进项发票 V1 当前仅允许 CNY。']);
            }
            $facts = $this->invoiceTaxFacts($data);
            $excl = $facts['amount_excl_tax'];
            $tax = $facts['tax_amount'];
            $incl = $facts['amount_incl_tax'];
            $number = $this->numbers->reservedNumber($data['reservation_token'], 'finance_invoice', $operatorId, $data['creation_session_id'] ?? null);
            $invoice = FinanceInvoice::create([
                'invoice_direction' => $direction, 'document_no' => $number,
                'invoice_no' => $data['invoice_no'] ?? null, 'invoice_code' => $data['invoice_code'] ?? null,
                'invoice_type' => $data['invoice_type'] ?? null, ...$party,
                'invoice_date' => $data['invoice_date'] ?? null, 'received_date' => $data['received_date'] ?? null,
                'currency' => $data['currency'] ?? 'CNY',
                'amount_excl_tax' => $excl, 'tax_amount' => $tax, 'amount_incl_tax' => $incl,
                'tax_detail' => $facts['tax_detail'], 'remark' => $data['remark'] ?? null,
                'status' => FinanceConstants::STATUS_DRAFT, 'red_invoice_of_id' => $data['red_invoice_of_id'] ?? null,
                'created_by' => $operatorId,
            ]);
            $this->numbers->consume($data['reservation_token'], 'finance_invoice', $number, $operatorId, 'finance_invoice', $invoice->id);
            $this->log($invoice, 'create', null, FinanceConstants::STATUS_DRAFT, $operatorId, '登记发票草稿');
            return $invoice;
        }, 5);
    }

    /**
     * Create an immutable red-invoice fact.  The original blue invoice and
     * its positive allocation history are never rewritten: negative active
     * allocation facts make the current settlement-source coverage net of
     * the red invoice while preserving the original audit trail.
     */
    public function createRedInvoice(int $originalInvoiceId, array $data, ?int $operatorId): FinanceInvoice
    {
        return DB::transaction(function () use ($originalInvoiceId, $data, $operatorId): FinanceInvoice {
            $original = FinanceInvoice::query()->lockForUpdate()->findOrFail($originalInvoiceId);
            if ($original->invoice_direction !== FinanceConstants::INVOICE_PURCHASE
                || $original->currency !== 'CNY'
                || $original->status !== FinanceConstants::STATUS_CONFIRMED
                || $original->red_invoice_of_id !== null) {
                throw ValidationException::withMessages(['original_invoice_id' => '仅已确认的 CNY 采购蓝票可以开具红字发票。']);
            }

            $priorRedAmount = Money::normalize((string) FinanceInvoice::query()
                ->where('red_invoice_of_id', $original->id)
                ->where('status', FinanceConstants::STATUS_RED)
                ->lockForUpdate()
                ->sum('amount_incl_tax'));
            $remaining = Money::maxZero(Money::sub((string) $original->amount_incl_tax, $priorRedAmount));
            if (Money::compare($remaining, '0') <= 0) {
                throw ValidationException::withMessages(['original_invoice_id' => '该蓝票已完成全额红冲，不能再次开具红字发票。']);
            }

            $excl = Money::normalize((string) $data['amount_excl_tax']);
            $tax = Money::normalize((string) $data['tax_amount']);
            $incl = Money::normalize((string) $data['amount_incl_tax']);
            $scope = (string) $data['red_scope'];
            if (!in_array($scope, ['full', 'partial'], true)
                || Money::compare($excl, '0') < 0 || Money::compare($tax, '0') < 0
                || Money::compare($incl, '0') <= 0 || Money::compare(Money::add($excl, $tax), $incl) !== 0
                || Money::compare($incl, $remaining) > 0
                || ($scope === 'full' && Money::compare($incl, $remaining) !== 0)) {
                throw ValidationException::withMessages(['amount_incl_tax' => '红冲价税合计必须等于未税金额加税额；不能超过可红冲余额，全额红冲必须冲完当前余额。']);
            }
            if (trim((string) $data['red_reason']) === '') {
                throw ValidationException::withMessages(['red_reason' => '请填写红冲原因。']);
            }

            $number = $this->numbers->reservedNumber($data['reservation_token'], 'finance_invoice', $operatorId, $data['creation_session_id'] ?? null);
            $red = FinanceInvoice::create([
                'invoice_direction' => FinanceConstants::INVOICE_PURCHASE,
                'document_no' => $number,
                'invoice_no' => $data['invoice_no'] ?? null, 'invoice_code' => $data['invoice_code'] ?? null,
                'invoice_type' => $data['invoice_type'],
                'party_type' => $original->party_type, 'party_id' => $original->party_id,
                'party_name_snapshot' => $original->party_name_snapshot,
                'invoice_date' => $data['red_date'], 'received_date' => $data['red_date'], 'currency' => 'CNY',
                'amount_excl_tax' => $excl, 'tax_amount' => $tax, 'amount_incl_tax' => $incl,
                'tax_detail' => $data['tax_detail'] ?? null, 'remark' => $data['remark'] ?? null,
                'status' => FinanceConstants::STATUS_RED, 'red_invoice_of_id' => $original->id,
                'red_reason' => trim((string) $data['red_reason']), 'red_date' => $data['red_date'],
                'red_scope' => $scope, 'red_match_handling' => 'preserve_match_history',
                'created_by' => $operatorId, 'confirmed_by' => $operatorId, 'confirmed_at' => now(),
            ]);
            $this->numbers->consume($data['reservation_token'], 'finance_invoice', $number, $operatorId, 'finance_invoice', $red->id);

            $toReverse = $incl;
            $sourceIds = [];
            $allocations = FinanceInvoiceAllocation::query()
                ->where('invoice_id', $original->id)->where('status', FinanceConstants::ALLOCATION_ACTIVE)
                ->orderBy('id')->lockForUpdate()->get();
            foreach ($allocations as $allocation) {
                if (Money::compare($toReverse, '0') <= 0) break;
                $positive = Money::maxZero((string) $allocation->allocated_amount);
                if (Money::compare($positive, '0') <= 0) continue;
                $part = Money::compare($positive, $toReverse) > 0 ? $toReverse : $positive;
                FinanceInvoiceAllocation::create([
                    'invoice_id' => $red->id, 'source_business_type' => $allocation->source_business_type,
                    'source_document_id' => $allocation->source_document_id, 'source_document_no' => $allocation->source_document_no,
                    'source_amount_snapshot' => $allocation->source_amount_snapshot,
                    'allocated_amount' => Money::negate($part), 'status' => FinanceConstants::ALLOCATION_ACTIVE,
                    'idempotency_key' => 'INV-RED-'.$red->id.'-SRC-'.$allocation->id,
                ]);
                $toReverse = Money::sub($toReverse, $part);
                if ($allocation->source_business_type === FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE) {
                    $sourceIds[(int) $allocation->source_document_id] = true;
                }
            }
            foreach (array_keys($sourceIds) as $sourceId) $this->purchaseSettlementSources->refresh((int) $sourceId, $operatorId);

            $this->log($red, 'create_red_invoice', null, FinanceConstants::STATUS_RED, $operatorId, '开具红字发票，保留蓝票与原匹配历史，以独立负向匹配事实冲减当前覆盖金额。');
            $this->log($original, 'red_invoice_issued', FinanceConstants::STATUS_CONFIRMED, FinanceConstants::STATUS_CONFIRMED, $operatorId, '已关联红字发票 '.$red->document_no.'；原蓝票事实不可修改。');
            return $red->fresh();
        }, 5);
    }

    /** Persist an editable red-invoice draft without creating a financial effect. */
    public function createRedInvoiceDraft(int $originalInvoiceId, array $data, ?int $operatorId): FinanceInvoice
    {
        return DB::transaction(function () use ($originalInvoiceId, $data, $operatorId): FinanceInvoice {
            $original = FinanceInvoice::query()->lockForUpdate()->findOrFail($originalInvoiceId);
            if ($original->invoice_direction !== FinanceConstants::INVOICE_PURCHASE || $original->currency !== 'CNY'
                || $original->status !== FinanceConstants::STATUS_CONFIRMED || $original->red_invoice_of_id !== null) {
                throw ValidationException::withMessages(['original_invoice_id' => '仅已确认的 CNY 采购蓝票可以创建红冲草稿。']);
            }
            $this->validateRedAmounts($original, $data, true);
            $number = $this->numbers->reservedNumber($data['reservation_token'], 'finance_invoice', $operatorId, $data['creation_session_id'] ?? null);
            $draft = FinanceInvoice::create([
                'invoice_direction' => FinanceConstants::INVOICE_PURCHASE, 'document_no' => $number,
                'invoice_no' => $data['invoice_no'] ?? null, 'invoice_code' => $data['invoice_code'] ?? null, 'invoice_type' => $data['invoice_type'],
                'party_type' => $original->party_type, 'party_id' => $original->party_id, 'party_name_snapshot' => $original->party_name_snapshot,
                'invoice_date' => $data['red_date'], 'received_date' => $data['red_date'], 'currency' => 'CNY',
                'amount_excl_tax' => Money::normalize((string) $data['amount_excl_tax']), 'tax_amount' => Money::normalize((string) $data['tax_amount']), 'amount_incl_tax' => Money::normalize((string) $data['amount_incl_tax']),
                'tax_detail' => $data['tax_detail'] ?? null, 'remark' => $data['remark'] ?? null,
                'status' => FinanceConstants::STATUS_DRAFT, 'red_invoice_of_id' => $original->id,
                'red_reason' => trim((string) $data['red_reason']), 'red_date' => $data['red_date'], 'red_scope' => $data['red_scope'],
                'red_match_handling' => 'preserve_match_history', 'created_by' => $operatorId,
            ]);
            $this->numbers->consume($data['reservation_token'], 'finance_invoice', $number, $operatorId, 'finance_invoice', $draft->id);
            $this->log($draft, 'create_red_draft', null, FinanceConstants::STATUS_DRAFT, $operatorId, '保存红字发票草稿；尚未产生结算或会计影响。');
            return $draft;
        }, 5);
    }

    /** Confirm an existing draft in place so its number and history stay stable. */
    public function confirmRedInvoiceDraft(int $redInvoiceId, ?int $operatorId): FinanceInvoice
    {
        return DB::transaction(function () use ($redInvoiceId, $operatorId): FinanceInvoice {
            $red = FinanceInvoice::query()->lockForUpdate()->findOrFail($redInvoiceId);
            if ($red->status !== FinanceConstants::STATUS_DRAFT || ! $red->red_invoice_of_id) {
                throw ValidationException::withMessages(['status' => '仅红字发票草稿可以确认红冲。']);
            }
            $original = FinanceInvoice::query()->lockForUpdate()->findOrFail($red->red_invoice_of_id);
            if ($original->status !== FinanceConstants::STATUS_CONFIRMED) throw ValidationException::withMessages(['original_invoice_id' => '原蓝票状态已变化，不能确认红冲。']);
            $this->validateRedAmounts($original, [
                'red_scope' => $red->red_scope, 'red_reason' => $red->red_reason,
                'amount_excl_tax' => $red->amount_excl_tax, 'tax_amount' => $red->tax_amount, 'amount_incl_tax' => $red->amount_incl_tax,
            ], false, $red->id);
            $red->update(['status' => FinanceConstants::STATUS_RED, 'confirmed_by' => $operatorId, 'confirmed_at' => now()]);
            $this->applyRedMatchFacts($original, $red, $operatorId);
            $this->log($red, 'confirm_red_invoice', FinanceConstants::STATUS_DRAFT, FinanceConstants::STATUS_RED, $operatorId, '确认红字发票并完成结算来源净额重算。');
            $this->log($original, 'red_invoice_issued', FinanceConstants::STATUS_CONFIRMED, FinanceConstants::STATUS_CONFIRMED, $operatorId, '已关联红字发票 '.$red->document_no.'；原蓝票事实不可修改。');
            return $red->fresh();
        }, 5);
    }

    public function updateDraft(int $invoiceId, array $data, ?int $operatorId): FinanceInvoice
    {
        return DB::transaction(function () use ($invoiceId, $data, $operatorId): FinanceInvoice {
            $invoice = FinanceInvoice::query()->lockForUpdate()->findOrFail($invoiceId);
            if ($invoice->status !== FinanceConstants::STATUS_DRAFT) {
                throw ValidationException::withMessages(['status' => '仅草稿发票可以修改。']);
            }
            $direction = (string) ($data['invoice_direction'] ?? $invoice->invoice_direction);
            if ($direction === FinanceConstants::INVOICE_PURCHASE && (($data['currency'] ?? $invoice->currency) !== 'CNY')) {
                throw ValidationException::withMessages(['currency' => '采购进项发票 V1 当前仅允许 CNY。']);
            }
            $party = isset($data['party_id']) || isset($data['party_type'])
                ? $this->parties->resolve((string) ($data['party_type'] ?? $invoice->party_type), (int) ($data['party_id'] ?? $invoice->party_id))
                : [];
            $facts = $this->invoiceTaxFacts($data);
            $excl = $facts['amount_excl_tax'];
            $tax = $facts['tax_amount'];
            $incl = $facts['amount_incl_tax'];
            $invoice->fill([
                'invoice_direction' => $direction, 'invoice_no' => $data['invoice_no'] ?? $invoice->invoice_no,
                'invoice_code' => $data['invoice_code'] ?? $invoice->invoice_code,
                'invoice_type' => $data['invoice_type'] ?? $invoice->invoice_type,
                'invoice_date' => $data['invoice_date'] ?? $invoice->invoice_date,
                'received_date' => $data['received_date'] ?? $invoice->received_date,
                'amount_excl_tax' => $excl, 'tax_amount' => $tax, 'amount_incl_tax' => $incl,
                'tax_detail' => $facts['tax_detail'], 'remark' => $data['remark'] ?? $invoice->remark,
                ...$party,
            ]);
            $invoice->save();
            $this->log($invoice, 'update_draft', FinanceConstants::STATUS_DRAFT, FinanceConstants::STATUS_DRAFT, $operatorId, '修改发票草稿');
            return $invoice->fresh();
        }, 5);
    }

    public function allocate(int $invoiceId, array $rows, ?int $operatorId = null): array
    {
        try {
            return DB::transaction(function () use ($invoiceId, $rows, $operatorId): array {
                $invoice = FinanceInvoice::query()->lockForUpdate()->findOrFail($invoiceId);
                if ($invoice->status !== FinanceConstants::STATUS_DRAFT) {
                    throw ValidationException::withMessages(['status' => '只有草稿发票可以分摊业务来源。']);
                }
                $allocated = $this->activeTotalForInvoice($invoice->id);
                $remaining = Money::sub((string) $invoice->amount_incl_tax, $allocated);
                $created = [];
                foreach ($rows as $row) {
                    $source = $this->sources->resolve((string) $row['source_business_type'], (int) $row['source_document_id'], 'invoice');
                    $expectedType = $invoice->invoice_direction === FinanceConstants::INVOICE_SALES
                        ? FinanceConstants::SOURCE_SALES_ORDER : FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE;
                    if ($source['type'] !== $expectedType) throw ValidationException::withMessages(['source_business_type' => '发票方向与业务来源不一致。']);
                    if ($invoice->party_type !== $source['partyType'] || (int) $invoice->party_id !== $source['partyId'] || $invoice->currency !== $source['currency']) {
                        throw ValidationException::withMessages(['source_document_id' => '发票与业务来源的交易对手或币种不一致。']);
                    }
                    $amount = Money::normalize((string) $row['allocated_amount']);
                    $sourceRemaining = Money::sub($source['amount'], $this->activeTotalForSource($source['type'], $source['id']));
                    if (Money::compare($amount, '0') <= 0 || Money::compare($amount, $remaining) > 0 || Money::compare($amount, $sourceRemaining) > 0) {
                        throw ValidationException::withMessages(['allocated_amount' => '发票分摊金额无效或超过可开票余额。']);
                    }
                    $created[] = FinanceInvoiceAllocation::create([
                        'invoice_id' => $invoice->id, 'source_business_type' => $source['type'],
                        'source_document_id' => $source['id'], 'source_document_no' => $source['no'],
                        'source_amount_snapshot' => $source['amount'], 'allocated_amount' => $amount,
                        'status' => FinanceConstants::ALLOCATION_ACTIVE,
                        'idempotency_key' => $row['idempotency_key'] ?? ('INV-MATCH-'.$invoice->id.'-'.$source['id'].'-'.Str::uuid()),
                    ]);
                    $remaining = Money::sub($remaining, $amount);
                    if ($source['type'] === FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE) {
                        $this->purchaseSettlementSources->refresh((int) $source['id']);
                    }
                }
                $this->log($invoice, 'match', $invoice->status, $invoice->status, $operatorId, '完成发票匹配');
                return ['allocations' => $created, 'remaining_amount' => $remaining];
            }, 5);
        } catch (QueryException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'duplicate')) {
                throw ValidationException::withMessages(['idempotency_key' => '该发票分摊请求已经处理，不能重复提交。']);
            }
            throw $exception;
        }
    }

    /**
     * Append a new matching batch to a draft invoice.
     *
     * A saved match is an accounting trace, not a form snapshot: later matching
     * must never overwrite an earlier allocation. Reversal is handled by the
     * dedicated reversal action so the history remains auditable.
     */
    public function saveMatches(int $invoiceId, array $rows, ?int $operatorId): array
    {
        if ($rows === []) {
            throw ValidationException::withMessages(['items' => '请至少选择一条新的结算来源并填写匹配金额。']);
        }
        return $this->allocate($invoiceId, $rows, $operatorId);
    }

    public function confirm(int $invoiceId, ?int $operatorId): FinanceInvoice
    {
        return DB::transaction(function () use ($invoiceId, $operatorId): FinanceInvoice {
            $invoice = FinanceInvoice::query()->lockForUpdate()->findOrFail($invoiceId);
            if ($invoice->status !== FinanceConstants::STATUS_DRAFT) throw ValidationException::withMessages(['status' => '只有草稿发票可以确认。']);
            if (Money::compare($this->activeTotalForInvoice($invoice->id), (string) $invoice->amount_incl_tax) !== 0) {
                throw ValidationException::withMessages(['allocations' => '发票必须完整分摊到业务来源后才能确认。']);
            }
            if ($invoice->invoice_direction === FinanceConstants::INVOICE_PURCHASE && !FinanceAttachment::query()
                ->where('document_type', 'finance_invoice')->where('document_id', $invoice->id)
                ->where('attachment_type', 'invoice_scan')->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['attachments' => '采购进项发票确认前必须上传发票扫描件。']);
            }
            $invoice->update(['status' => FinanceConstants::STATUS_CONFIRMED, 'confirmed_by' => $operatorId, 'confirmed_at' => now()]);
            $sourceIds = FinanceInvoiceAllocation::query()
                ->where('invoice_id', $invoice->id)
                ->where('status', FinanceConstants::ALLOCATION_ACTIVE)
                ->where('source_business_type', FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE)
                ->lockForUpdate()
                ->pluck('source_document_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->all();
            foreach ($sourceIds as $sourceId) {
                $this->purchaseSettlementSources->refresh($sourceId, $operatorId);
            }
            $this->log($invoice, 'confirm', FinanceConstants::STATUS_DRAFT, FinanceConstants::STATUS_CONFIRMED, $operatorId, '确认发票');
            return $invoice->fresh();
        }, 5);
    }

    public function reverseAllocation(int $allocationId, string $reason, ?int $operatorId = null): FinanceInvoiceAllocation
    {
        return DB::transaction(function () use ($allocationId, $reason, $operatorId): FinanceInvoiceAllocation {
            $allocation = FinanceInvoiceAllocation::query()->lockForUpdate()->findOrFail($allocationId);
            $invoice = FinanceInvoice::query()->lockForUpdate()->findOrFail($allocation->invoice_id);
            if ($invoice->status !== FinanceConstants::STATUS_DRAFT || $allocation->status !== FinanceConstants::ALLOCATION_ACTIVE) {
                throw ValidationException::withMessages(['status' => '只有草稿发票的有效分摊可以撤销。']);
            }
            if (trim($reason) === '') throw ValidationException::withMessages(['reason' => '撤销原因必填。']);
            $allocation->update(['status' => FinanceConstants::ALLOCATION_REVERSED]);
            $reversal = FinanceInvoiceAllocation::create([
                'invoice_id' => $allocation->invoice_id, 'source_business_type' => $allocation->source_business_type,
                'source_document_id' => $allocation->source_document_id, 'source_document_no' => $allocation->source_document_no,
                'source_amount_snapshot' => $allocation->source_amount_snapshot,
                'allocated_amount' => Money::negate((string) $allocation->allocated_amount),
                'status' => FinanceConstants::ALLOCATION_REVERSAL, 'reversal_of_id' => $allocation->id,
                'idempotency_key' => 'INV-REV-'.$allocation->id,
            ]);
            if ($allocation->source_business_type === FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE) {
                $this->purchaseSettlementSources->refresh((int) $allocation->source_document_id);
            }
            $this->log($invoice, 'reverse_match', $invoice->status, $invoice->status, $operatorId, '撤销发票匹配：'.$reason);
            return $reversal;
        }, 5);
    }

    /**
     * Tax details are the source of truth for an invoice amount.  Header
     * figures are accepted only as a cross-check so callers cannot submit an
     * empty or unrelated tax-detail list.
     */
    private function invoiceTaxFacts(array $data): array
    {
        $rows = $data['tax_detail'] ?? [];
        if (!is_array($rows) || $rows === []) {
            throw ValidationException::withMessages(['tax_detail' => '请至少填写一条税率明细。']);
        }

        $excl = '0.0000';
        $tax = '0.0000';
        $normalizedRows = [];
        foreach ($rows as $index => $row) {
            $rate = (float) ($row['tax_rate'] ?? -1);
            $rawExcl = trim((string) ($row['amount_excl_tax'] ?? ''));
            $rawTax = trim((string) ($row['tax_amount'] ?? ''));
            if (!preg_match('/^\d+(\.\d{1,4})?$/', $rawExcl) || !preg_match('/^\d+(\.\d{1,4})?$/', $rawTax)) {
                throw ValidationException::withMessages(["tax_detail.$index" => '税率明细中的未税金额和税额必须为有效金额。']);
            }
            $lineExcl = Money::normalize($rawExcl);
            $lineTax = Money::normalize($rawTax);
            if ($rate < 0 || $rate > 100 || Money::compare($lineExcl, '0') < 0 || Money::compare($lineTax, '0') < 0) {
                throw ValidationException::withMessages(["tax_detail.$index" => '税率明细金额无效。']);
            }
            $calculatedTax = Money::normalize(number_format(round((float) $lineExcl * $rate / 100, 4), 4, '.', ''));
            if (Money::compare($lineTax, $calculatedTax) !== 0) {
                throw ValidationException::withMessages(["tax_detail.$index.tax_amount" => "税额必须按本行未税金额和税率自动计算：申报 {$lineTax}，应为 {$calculatedTax}。"]);
            }
            $excl = Money::add($excl, $lineExcl);
            $tax = Money::add($tax, $lineTax);
            $normalizedRows[] = ['tax_rate' => $rate, 'amount_excl_tax' => $lineExcl, 'tax_amount' => $lineTax];
        }

        $incl = Money::add($excl, $tax);
        foreach (['amount_excl_tax' => $excl, 'tax_amount' => $tax, 'amount_incl_tax' => $incl] as $key => $value) {
            if (array_key_exists($key, $data) && Money::compare(Money::normalize((string) $data[$key]), $value) !== 0) {
                throw ValidationException::withMessages([$key => '发票金额必须由税率明细汇总生成，不能与明细不一致。']);
            }
        }
        if (Money::compare($incl, '0') <= 0) {
            throw ValidationException::withMessages(['tax_detail' => '税率明细的价税合计必须大于 0。']);
        }

        return ['amount_excl_tax' => $excl, 'tax_amount' => $tax, 'amount_incl_tax' => $incl, 'tax_detail' => $normalizedRows];
    }

    private function activeTotalForInvoice(int $invoiceId): string
    {
        return Money::normalize((string) FinanceInvoiceAllocation::query()->where('invoice_id', $invoiceId)->where('status', FinanceConstants::ALLOCATION_ACTIVE)->sum('allocated_amount'));
    }

    private function validateRedAmounts(FinanceInvoice $original, array $data, bool $includeDrafts = false, ?int $excludeId = null): void
    {
        if (!in_array((string) ($data['red_scope'] ?? ''), ['full', 'partial'], true) || trim((string) ($data['red_reason'] ?? '')) === '') {
            throw ValidationException::withMessages(['red_scope' => '请选择红冲方式并填写红冲原因。']);
        }
        $redRows = FinanceInvoice::query()->where('red_invoice_of_id', $original->id)
            ->when(!$includeDrafts, fn ($q) => $q->where('status', FinanceConstants::STATUS_RED), fn ($q) => $q->whereIn('status', [FinanceConstants::STATUS_RED, FinanceConstants::STATUS_DRAFT]))
            ->when($excludeId, fn ($q) => $q->whereKeyNot($excludeId))->lockForUpdate();
        $remaining = Money::maxZero(Money::sub((string) $original->amount_incl_tax, (string) $redRows->sum('amount_incl_tax')));
        $excl = Money::normalize((string) $data['amount_excl_tax']); $tax = Money::normalize((string) $data['tax_amount']); $incl = Money::normalize((string) $data['amount_incl_tax']);
        if (Money::compare($remaining, '0') <= 0 || Money::compare($excl, '0') < 0 || Money::compare($tax, '0') < 0 || Money::compare($incl, '0') <= 0
            || Money::compare(Money::add($excl, $tax), $incl) !== 0 || Money::compare($incl, $remaining) > 0
            || ((string) $data['red_scope'] === 'full' && Money::compare($incl, $remaining) !== 0)) {
            throw ValidationException::withMessages(['amount_incl_tax' => '红冲金额无效、超出可红冲余额，或全额红冲未覆盖剩余金额。']);
        }
    }

    private function applyRedMatchFacts(FinanceInvoice $original, FinanceInvoice $red, ?int $operatorId): void
    {
        $toReverse = Money::normalize((string) $red->amount_incl_tax); $sourceIds = [];
        $allocations = FinanceInvoiceAllocation::query()->where('invoice_id', $original->id)->where('status', FinanceConstants::ALLOCATION_ACTIVE)->orderBy('id')->lockForUpdate()->get();
        foreach ($allocations as $allocation) {
            if (Money::compare($toReverse, '0') <= 0) break;
            $positive = Money::maxZero((string) $allocation->allocated_amount); if (Money::compare($positive, '0') <= 0) continue;
            $part = Money::compare($positive, $toReverse) > 0 ? $toReverse : $positive;
            FinanceInvoiceAllocation::create(['invoice_id' => $red->id, 'source_business_type' => $allocation->source_business_type, 'source_document_id' => $allocation->source_document_id, 'source_document_no' => $allocation->source_document_no, 'source_amount_snapshot' => $allocation->source_amount_snapshot, 'allocated_amount' => Money::negate($part), 'status' => FinanceConstants::ALLOCATION_ACTIVE, 'idempotency_key' => 'INV-RED-'.$red->id.'-SRC-'.$allocation->id]);
            $toReverse = Money::sub($toReverse, $part);
            if ($allocation->source_business_type === FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE) $sourceIds[(int) $allocation->source_document_id] = true;
        }
        foreach (array_keys($sourceIds) as $sourceId) $this->purchaseSettlementSources->refresh((int) $sourceId, $operatorId);
    }

    private function activeTotalForSource(string $type, int $id): string
    {
        return Money::normalize((string) FinanceInvoiceAllocation::query()->where('source_business_type', $type)->where('source_document_id', $id)
            ->where('status', FinanceConstants::ALLOCATION_ACTIVE)->whereHas('invoice', fn ($q) => $q->whereIn('status', [FinanceConstants::STATUS_DRAFT, FinanceConstants::STATUS_CONFIRMED, FinanceConstants::STATUS_RED]))->sum('allocated_amount'));
    }

    private function log(FinanceInvoice $invoice, string $action, ?string $from, ?string $to, ?int $operatorId, string $content): void
    {
        FinanceOperationLog::create([
            'document_type' => 'finance_invoice', 'document_id' => $invoice->id, 'action' => $action,
            'from_status' => $from, 'to_status' => $to, 'operator_id' => $operatorId,
            'operator_name' => $operatorId ? '操作人#'.$operatorId : '系统', 'content' => $content,
            'fact_snapshot' => ['document_no' => $invoice->document_no, 'amount_incl_tax' => $invoice->amount_incl_tax, 'currency' => $invoice->currency],
        ]);
    }
}
