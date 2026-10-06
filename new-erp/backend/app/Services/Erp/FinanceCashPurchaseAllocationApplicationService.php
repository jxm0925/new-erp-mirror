<?php

namespace App\Services\Erp;

use App\Domain\Finance\FinanceConstants;
use App\Domain\Finance\Money;
use App\Models\Erp\FinanceAllocation;
use App\Models\Erp\FinanceCashDocument;
use App\Models\Erp\FinanceCashPurchaseAllocation;
use App\Models\Erp\FinanceCashPurchaseRevision;
use App\Models\Erp\FinanceOperationLog;
use App\Models\Erp\PurchaseOrder;
use App\Models\Erp\PurchasePaymentPlanItem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Formal purchase-order purpose of cash; it never creates a payable or an allocation. */
class FinanceCashPurchaseAllocationApplicationService
{
    public function __construct(private readonly PurchasePaymentBalanceQueryService $balances) {}

    public static function rules(string $field = 'purchase_order_allocations', string $presence = 'sometimes|array|max:100'): array
    {
        return [
            $field => $presence,
            $field.'.*.purchase_order_id' => 'required|integer|min:1',
            $field.'.*.payment_plan_id' => 'nullable|integer|min:1',
            $field.'.*.amount' => ['required', 'regex:/^\d{1,14}(\.\d{1,4})?$/'],
        ];
    }

    public function normalize(mixed $items): array
    {
        Validator::make(['purchase_order_allocations' => $items], self::rules('purchase_order_allocations', 'present|array|max:100'))->validate();
        $seen = [];
        $rows = [];
        foreach ($items as $item) {
            $row = ['purchase_order_id' => (int) $item['purchase_order_id'],
                'payment_plan_id' => isset($item['payment_plan_id']) ? (int) $item['payment_plan_id'] : null,
                'amount' => Money::normalize((string) $item['amount'])];
            if (Money::compare($row['amount'], '0') <= 0) $this->fail('用途分配金额必须大于 0。');
            $key = $this->purposeKey($row);
            if (isset($seen[$key])) $this->fail('同一采购订单、同一期付款安排只能填写一行用途。');
            $seen[$key] = true;
            $rows[] = $row;
        }
        return $rows;
    }

    public function validateDraft(FinanceCashDocument $document, array $rows): void
    {
        $this->validateRows($document, $rows);
    }

    public function freezeForConfirmation(FinanceCashDocument $document, ?int $operatorId, ?string $operatorName): void
    {
        $rows = $this->normalize($document->purchase_order_allocations ?? []);
        if ($rows === []) return;
        $rows = $this->validateRows($document, $rows);
        $this->assertOverpaymentExplained($document, $rows, (string) $document->purchase_order_allocation_reason);
        $this->writeRevision($document, $rows, null, hash('sha256', json_encode($this->canonical($rows), JSON_THROW_ON_ERROR)),
            (string) $document->purchase_order_allocation_reason, $operatorId, $operatorName);
    }

    /** Explicit, versioned correction of a confirmed cash document's purpose only. */
    public function replaceConfirmed(int $cashId, int $version, array $items, string $reason, string $key, ?int $operatorId, ?string $operatorName): FinanceCashDocument
    {
        $rows = $this->normalize($items);
        $reason = trim($reason);
        Validator::make(compact('version', 'reason', 'key'), [
            'version' => 'required|integer|min:0', 'reason' => 'required|string|max:1000', 'key' => 'required|string|max:100',
        ])->validate();
        $hash = hash('sha256', json_encode(['cash_id' => $cashId, 'version' => $version, 'reason' => $reason, 'rows' => $this->canonical($rows)], JSON_THROW_ON_ERROR));
        try {
            return DB::transaction(function () use ($cashId, $version, $rows, $reason, $key, $hash, $operatorId, $operatorName): FinanceCashDocument {
                $document = FinanceCashDocument::query()->lockForUpdate()->findOrFail($cashId);
                $revision = FinanceCashPurchaseRevision::query()->where('idempotency_key', $key)->lockForUpdate()->first();
                if ($revision) {
                    if ((int) $revision->cash_document_id !== $cashId || ! hash_equals($revision->idempotency_key, $key) || ! hash_equals($revision->request_hash, $hash)) {
                        throw ValidationException::withMessages(['idempotency_key' => '该用途修改请求编号已经用于其他内容。']);
                    }
                    return $this->currentDocument($cashId);
                }
                if ($document->status !== FinanceConstants::STATUS_CONFIRMED) throw ValidationException::withMessages(['status' => '只有已确认资金单可以办理用途补充或更正。']);
                if ($document->party_type !== FinanceConstants::PARTY_SUPPLIER) $this->fail('采购订单用途只适用于供应商付款或供应商退款收款。');
                if ((int) $document->purchase_order_allocation_version !== $version) throw ValidationException::withMessages(['version' => '采购订单用途已被其他操作修改，请刷新后重试。']);
                $oldOrderIds = $this->activeFacts($cashId)->pluck('purchase_order_id')->all();
                $validated = $this->validateRows($document, $rows);
                $this->assertExistingAllocationsCovered($document, $validated);
                $this->assertOverpaymentExplained($document, $validated, $reason);
                $this->writeRevision($document, $validated, $key, $hash, $reason, $operatorId, $operatorName);
                // Moving or removing a purpose must not make an already refunded
                // deposit available to spend again, or erase its funding source.
                $this->assertNetAvailableForCash($cashId, $oldOrderIds);
                return $this->currentDocument($cashId);
            }, 5);
        } catch (QueryException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'duplicate')) {
                throw ValidationException::withMessages(['idempotency_key' => '该用途修改请求已处理，请刷新后重试。']);
            }
            throw $exception;
        }
    }

    public function assertCanAllocate(FinanceCashDocument $document, string $sourceType, int $sourceId, string $amount): void
    {
        $facts = $this->activeFacts($document->id);
        if ($facts->isEmpty()) return; // Explicit compatibility: never invent purposes for historical cash.
        $orderId = $this->balances->sourceOrderId($sourceType, $sourceId, true);
        if (! $orderId || ! $facts->contains('purchase_order_id', $orderId)) $this->fail('该资金单只可核销已关联采购订单的到货或退款来源。');
        $this->lockOrders([$orderId]);
        $budget = $facts->where('purchase_order_id', $orderId)->reduce(fn (string $sum, $row) => Money::add($sum, $row->amount), '0.0000');
        $allocated = $this->allocationAmounts($document->id)[$orderId] ?? '0.0000';
        if (Money::compare(Money::add($allocated, $amount), $budget) > 0) $this->fail('对该采购订单的核销金额超过本资金单分配给它的用途金额。');
        if ($document->direction === FinanceConstants::DIRECTION_PAYMENT) {
            $available = $this->balances->currentForOrders([$orderId])[$orderId]['prepaid_amount'];
            if (Money::compare($amount, $available) > 0) $this->fail('该采购订单的可用预付余额不足；已退订金不能继续核销，请核对付款、退款及其用途。');
        }
    }

    /** Also called after refund allocation reversal and cash voiding. */
    public function assertNetAvailableForCash(int $cashId, array $additionalOrderIds = []): void
    {
        $ids = array_values(array_unique(array_merge($additionalOrderIds, $this->activeFacts($cashId)->pluck('purchase_order_id')->all())));
        if ($ids === []) return;
        $this->lockOrders($ids);
        foreach ($this->balances->currentForOrders($ids) as $balance) {
            if (Money::compare($balance['prepaid_amount'], '0') < 0) {
                $this->fail('订单未核销退款超过可用预付款。退订金须有已关联付款余额；退货退款须同时核销正式采购退款来源，已使用的余额须先撤销相关核销。');
            }
        }
    }

    public function present(FinanceCashDocument $document): array
    {
        if ($document->status === FinanceConstants::STATUS_DRAFT) {
            $items = $this->normalize($document->purchase_order_allocations ?? []);
            $orders = PurchaseOrder::query()->whereIn('id', array_column($items, 'purchase_order_id'))->get()->keyBy('id');
            $plans = PurchasePaymentPlanItem::query()->whereIn('id', array_filter(array_column($items, 'payment_plan_id')))->get()->keyBy('id');
            return array_map(function (array $row) use ($orders, $plans): array {
                $plan = $row['payment_plan_id'] ? $plans->get($row['payment_plan_id']) : null;
                return [...$row, 'purchase_order_no' => $orders->get($row['purchase_order_id'])?->purchase_order_no,
                    'title' => $plan?->title, 'sequence_no' => $plan?->sequence_no, 'trigger_type' => $plan?->trigger_type];
            }, $items);
        }
        $query = $document->purchaseOrderAllocations()->orderBy('id');
        if (DB::transactionLevel() > 0) $query->lockForUpdate();
        return $query->get()->map(fn ($row): array => [
            'id' => $row->id, 'purchase_order_id' => (int) $row->purchase_order_id, 'payment_plan_id' => $row->payment_plan_id ? (int) $row->payment_plan_id : null,
            'purchase_order_no' => $row->purchase_order_no_snapshot, 'amount' => $row->amount,
            'title' => $row->plan_title_snapshot, 'sequence_no' => $row->sequence_no_snapshot, 'trigger_type' => $row->trigger_type_snapshot,
        ])->all();
    }

    public static function contractAmount(PurchaseOrder $order): string
    {
        return Money::normalize((string) ($order->amount_incl_tax ?? $order->total_amount ?? '0'));
    }

    private function validateRows(FinanceCashDocument $document, array $rows): array
    {
        if ($rows === []) return [];
        if ($document->party_type !== FinanceConstants::PARTY_SUPPLIER) $this->fail('采购订单用途只适用于供应商付款或供应商退款收款。');
        $orders = $this->lockOrders(array_column($rows, 'purchase_order_id'))->keyBy('id');
        $plans = PurchasePaymentPlanItem::query()->whereIn('id', array_filter(array_column($rows, 'payment_plan_id')))
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $total = '0.0000';
        foreach ($rows as &$row) {
            $order = $orders->get($row['purchase_order_id']);
            if (! $order || $order->audit_status !== 'approved' || $order->finance_fact_status !== 'frozen'
                || in_array($order->purchase_status, ['draft', 'submitted', 'cancelled'], true)) {
                $this->fail('只有正式审核通过并冻结合同金额的采购订单可以关联资金用途。');
            }
            if ((int) $order->supplier_id !== (int) $document->party_id) $this->fail('采购订单供应商与资金单交易对手不一致。');
            if (($order->currency ?: 'CNY') !== $document->currency) $this->fail('采购订单币种与资金单币种不一致。');
            $plan = $row['payment_plan_id'] ? $plans->get($row['payment_plan_id']) : null;
            if ($row['payment_plan_id'] && (! $plan || (int) $plan->purchase_order_id !== (int) $order->id || $plan->status !== 'active' || $plan->currency !== $document->currency)) {
                $this->fail('付款期次不存在、已停用或不属于该采购订单及币种。');
            }
            $row = [...$row, 'purchase_order_no' => $order->purchase_order_no,
                'title' => $plan?->title, 'sequence_no' => $plan?->sequence_no, 'trigger_type' => $plan?->trigger_type];
            $total = Money::add($total, $row['amount']);
        }
        if (Money::compare($total, (string) $document->amount) !== 0) $this->fail('采购订单用途金额合计必须等于资金单金额。');
        return $rows;
    }

    private function assertExistingAllocationsCovered(FinanceCashDocument $document, array $rows): void
    {
        $budgets = $this->amountsByOrder($rows);
        foreach ($this->allocationAmounts($document->id) as $orderId => $amount) {
            if (! $orderId || ! isset($budgets[$orderId]) || Money::compare($amount, $budgets[$orderId]) > 0) {
                $this->fail('资金单已有有效核销；修改后用途必须保留每个已核销采购订单及不少于已核销的金额，请先处理不一致的核销。');
            }
        }
    }

    private function assertOverpaymentExplained(FinanceCashDocument $document, array $rows, string $reason): void
    {
        if ($document->direction !== FinanceConstants::DIRECTION_PAYMENT || $rows === []) return;
        $amounts = $this->amountsByOrder($rows);
        $orders = $this->lockOrders(array_keys($amounts))->keyBy('id');
        $balances = $this->balances->currentForOrders(array_keys($amounts), $document->id);
        foreach ($amounts as $orderId => $amount) {
            if (Money::compare(Money::add($balances[$orderId]['net_paid_amount'], $amount), self::contractAmount($orders[$orderId])) > 0 && trim($reason) === '') {
                throw ValidationException::withMessages(['purchase_order_allocation_reason' => '该笔付款将使采购订单净付款超过合同金额，请填写真实超付原因。']);
            }
        }
    }

    private function writeRevision(FinanceCashDocument $document, array $rows, ?string $key, string $hash, string $reason, ?int $operatorId, ?string $operatorName): void
    {
        $before = $this->activeFacts($document->id);
        $version = (int) $document->purchase_order_allocation_version + 1;
        FinanceCashPurchaseAllocation::query()->whereIn('id', $before->pluck('id'))->update(['status' => 'superseded']);
        foreach ($rows as $row) {
            FinanceCashPurchaseAllocation::create([
                'cash_document_id' => $document->id, 'purchase_order_id' => $row['purchase_order_id'],
                'payment_plan_id' => $row['payment_plan_id'], 'allocation_version' => $version,
                'purpose_key' => $this->purposeKey($row), 'direction' => $document->direction,
                'currency' => $document->currency, 'amount' => $row['amount'], 'status' => 'active',
                'purchase_order_no_snapshot' => $row['purchase_order_no'], 'plan_title_snapshot' => $row['title'],
                'trigger_type_snapshot' => $row['trigger_type'], 'sequence_no_snapshot' => $row['sequence_no'], 'created_by' => $operatorId,
            ]);
        }
        $document->update(['purchase_order_allocations' => [], 'purchase_order_allocation_version' => $version,
            'purchase_order_allocation_reason' => $reason ?: null]);
        FinanceCashPurchaseRevision::create([
            'cash_document_id' => $document->id, 'version' => $version, 'idempotency_key' => $key, 'request_hash' => $hash,
            'reason' => $reason ?: null, 'before_snapshot' => $before->toArray(), 'after_snapshot' => $rows,
            'operator_id' => $operatorId, 'operator_name' => $operatorName,
        ]);
        FinanceOperationLog::create([
            'document_type' => 'cash_document', 'document_id' => $document->id,
            'action' => $key === null ? 'freeze_purchase_order_purposes' : 'revise_purchase_order_purposes',
            'from_status' => $document->status, 'to_status' => $document->status,
            'fact_snapshot' => ['purpose_version' => $version, 'purchase_order_allocations' => $rows],
            'operator_id' => $operatorId, 'operator_name' => $operatorName,
            'content' => $reason ?: '确认资金用途；不产生到货应付或核销。',
        ]);
    }

    private function allocationAmounts(int $cashId): array
    {
        $amounts = [];
        foreach (FinanceAllocation::query()->where('cash_document_id', $cashId)->where('status', FinanceConstants::ALLOCATION_ACTIVE)->orderBy('id')->lockForUpdate()->get() as $allocation) {
            $id = $this->balances->sourceOrderId($allocation->source_business_type, (int) $allocation->source_document_id, true) ?? 0;
            $amounts[$id] = Money::add($amounts[$id] ?? '0.0000', $allocation->allocated_amount);
        }
        return $amounts;
    }

    private function amountsByOrder(array $rows): array
    {
        $amounts = [];
        foreach ($rows as $row) $amounts[$row['purchase_order_id']] = Money::add($amounts[$row['purchase_order_id']] ?? '0.0000', $row['amount']);
        return $amounts;
    }

    private function activeFacts(int $cashId)
    {
        return FinanceCashPurchaseAllocation::query()->where('cash_document_id', $cashId)->where('status', 'active')->orderBy('id')->lockForUpdate()->get();
    }

    private function lockOrders(array $ids)
    {
        return PurchaseOrder::query()->whereIn('id', array_unique($ids))->orderBy('id')->lockForUpdate()->get();
    }

    private function currentDocument(int $id): FinanceCashDocument
    {
        return FinanceCashDocument::query()->with(['account', 'attachments',
            'allocations' => fn ($query) => $query->lockForUpdate(), 'logs' => fn ($query) => $query->lockForUpdate(),
        ])->lockForUpdate()->findOrFail($id);
    }

    private function purposeKey(array $row): string
    {
        return $row['purchase_order_id'].':'.($row['payment_plan_id'] ?? 0);
    }

    private function canonical(array $rows): array
    {
        $canonical = array_map(fn (array $row) => ['purchase_order_id' => $row['purchase_order_id'], 'payment_plan_id' => $row['payment_plan_id'], 'amount' => $row['amount']], $rows);
        usort($canonical, fn (array $a, array $b) => strcmp($this->purposeKey($a), $this->purposeKey($b)));
        return $canonical;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['purchase_order_allocations' => $message]);
    }
}
