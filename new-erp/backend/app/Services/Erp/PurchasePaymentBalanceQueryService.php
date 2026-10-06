<?php

namespace App\Services\Erp;

use App\Domain\Finance\FinanceConstants;
use App\Domain\Finance\Money;
use App\Models\Erp\FinanceAllocation;
use App\Models\Erp\FinanceCashPurchaseAllocation;
use App\Models\Erp\PurchaseReceipt;
use App\Models\Erp\PurchaseReturn;
use App\Models\Erp\PurchaseSettlementSource;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Contract-purpose cash is separate from receipt payables and from inventory cost. */
class PurchasePaymentBalanceQueryService
{
    public const FIELDS = [
        'paid_amount', 'refund_amount', 'net_paid_amount', 'allocated_payment_amount',
        'allocated_refund_amount', 'unallocated_payment_amount', 'unallocated_refund_amount', 'prepaid_amount',
    ];

    public function zero(): array
    {
        return array_fill_keys(self::FIELDS, '0.0000');
    }

    /** SQL projection used by server pagination and summaries of the entire filtered set. */
    public function aggregateQuery(): Builder
    {
        $purpose = DB::table('erp_finance_cash_purchase_allocations as purpose')
            ->join('erp_finance_cash_documents as cash', 'cash.id', '=', 'purpose.cash_document_id')
            ->where('purpose.status', 'active')->where('cash.status', FinanceConstants::STATUS_CONFIRMED)
            ->selectRaw('purpose.purchase_order_id, purpose.cash_document_id, cash.direction, cash.currency, SUM(purpose.amount) AS amount')
            ->groupBy('purpose.purchase_order_id', 'purpose.cash_document_id', 'cash.direction', 'cash.currency');
        $orderExpression = 'COALESCE(source.purchase_order_id, receipt.order_id, refund_receipt.order_id)';
        $allocations = DB::table('erp_finance_allocations as allocation')
            ->leftJoin('erp_purchase_settlement_sources as source', function ($join): void {
                $join->on('source.id', '=', 'allocation.source_document_id')
                    ->where('allocation.source_business_type', FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE);
            })
            ->leftJoin('erp_purchase_receipts as receipt', function ($join): void {
                $join->on('receipt.id', '=', 'allocation.source_document_id')
                    ->where('allocation.source_business_type', FinanceConstants::SOURCE_PURCHASE_RECEIPT);
            })
            ->leftJoin('erp_purchase_returns as supplier_return', function ($join): void {
                $join->on('supplier_return.id', '=', 'allocation.source_document_id')
                    ->where('allocation.source_business_type', FinanceConstants::SOURCE_PURCHASE_RETURN_SUPPLIER_REFUND);
            })
            ->leftJoin('erp_purchase_receipts as refund_receipt', 'refund_receipt.id', '=', 'supplier_return.source_receipt_id')
            ->where('allocation.status', FinanceConstants::ALLOCATION_ACTIVE)
            ->selectRaw('allocation.cash_document_id, '.$orderExpression.' AS purchase_order_id, SUM(allocation.allocated_amount) AS amount')
            ->groupBy('allocation.cash_document_id')->groupByRaw($orderExpression);
        $totals = DB::query()->fromSub($purpose, 'purposes')
            ->leftJoinSub($allocations, 'settled', function ($join): void {
                $join->on('settled.cash_document_id', '=', 'purposes.cash_document_id')
                    ->on('settled.purchase_order_id', '=', 'purposes.purchase_order_id');
            })
            ->selectRaw('purposes.purchase_order_id, purposes.currency,
                SUM(CASE WHEN purposes.direction = ? THEN purposes.amount ELSE 0 END) AS paid_amount,
                SUM(CASE WHEN purposes.direction = ? THEN purposes.amount ELSE 0 END) AS refund_amount,
                SUM(CASE WHEN purposes.direction = ? THEN COALESCE(settled.amount, 0) ELSE 0 END) AS allocated_payment_amount,
                SUM(CASE WHEN purposes.direction = ? THEN COALESCE(settled.amount, 0) ELSE 0 END) AS allocated_refund_amount',
                ['payment', 'receipt', 'payment', 'receipt'])
            ->groupBy('purposes.purchase_order_id', 'purposes.currency');
        return DB::query()->fromSub($totals, 'cash_totals')->select('cash_totals.*')
            ->selectRaw('paid_amount - refund_amount AS net_paid_amount,
                paid_amount - allocated_payment_amount AS unallocated_payment_amount,
                refund_amount - allocated_refund_amount AS unallocated_refund_amount,
                paid_amount - allocated_payment_amount - refund_amount + allocated_refund_amount AS prepaid_amount');
    }

    /**
     * Business guards must not use the reporting SUM/subquery under an old RR
     * snapshot. The caller locks the purchase orders; then these joined rows
     * and allocation facts are read with FOR UPDATE before exact summation.
     */
    public function currentForOrders(array $orderIds, ?int $excludeCashId = null): array
    {
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));
        $totals = array_fill_keys($orderIds, $this->zero());
        if ($orderIds === []) return $totals;
        $query = FinanceCashPurchaseAllocation::query()
            ->join('erp_finance_cash_documents as purpose_cash', 'purpose_cash.id', '=', 'erp_finance_cash_purchase_allocations.cash_document_id')
            ->whereIn('erp_finance_cash_purchase_allocations.purchase_order_id', $orderIds)
            ->where('erp_finance_cash_purchase_allocations.status', 'active')
            ->where('purpose_cash.status', FinanceConstants::STATUS_CONFIRMED);
        if ($excludeCashId !== null) $query->where('purpose_cash.id', '<>', $excludeCashId);
        $purposes = $query->orderBy('erp_finance_cash_purchase_allocations.id')->lockForUpdate()->get([
            'erp_finance_cash_purchase_allocations.*', 'purpose_cash.direction as cash_direction',
        ]);
        $cashDirections = [];
        $cashOrders = [];
        foreach ($purposes as $purpose) {
            $id = (int) $purpose->purchase_order_id;
            $cashId = (int) $purpose->cash_document_id;
            $cashDirections[$cashId] = $purpose->cash_direction;
            $cashOrders[$cashId][$id] = true;
            $field = $purpose->cash_direction === 'payment' ? 'paid_amount' : 'refund_amount';
            $totals[$id][$field] = Money::add($totals[$id][$field], $purpose->amount);
        }
        if ($cashDirections !== []) {
            $allocations = FinanceAllocation::query()->whereIn('cash_document_id', array_keys($cashDirections))
                ->where('status', FinanceConstants::ALLOCATION_ACTIVE)->orderBy('id')->lockForUpdate()->get();
            foreach ($allocations as $allocation) {
                $id = $this->sourceOrderId($allocation->source_business_type, (int) $allocation->source_document_id, true);
                if (! $id || ! isset($cashOrders[(int) $allocation->cash_document_id][$id])) continue;
                $field = $cashDirections[(int) $allocation->cash_document_id] === 'payment' ? 'allocated_payment_amount' : 'allocated_refund_amount';
                $totals[$id][$field] = Money::add($totals[$id][$field], $allocation->allocated_amount);
            }
        }
        foreach ($totals as &$row) $row = $this->derive($row);
        return $totals;
    }

    public function sourceOrderId(string $type, int $id, bool $current = false): ?int
    {
        $query = match ($type) {
            FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE => PurchaseSettlementSource::query(),
            FinanceConstants::SOURCE_PURCHASE_RECEIPT => PurchaseReceipt::query(),
            FinanceConstants::SOURCE_PURCHASE_RETURN_SUPPLIER_REFUND => PurchaseReturn::query(),
            default => null,
        };
        if (! $query) return null;
        if ($current) $query->lockForUpdate();
        if ($type === FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE) return ($value = $query->whereKey($id)->value('purchase_order_id')) ? (int) $value : null;
        if ($type === FinanceConstants::SOURCE_PURCHASE_RECEIPT) return ($value = $query->whereKey($id)->value('order_id')) ? (int) $value : null;
        $receiptId = $query->whereKey($id)->value('source_receipt_id');
        if (! $receiptId) return null;
        $receipt = PurchaseReceipt::query()->whereKey($receiptId);
        if ($current) $receipt->lockForUpdate();
        return ($value = $receipt->value('order_id')) ? (int) $value : null;
    }

    public function derive(array $row): array
    {
        $row['net_paid_amount'] = Money::sub($row['paid_amount'], $row['refund_amount']);
        $row['unallocated_payment_amount'] = Money::sub($row['paid_amount'], $row['allocated_payment_amount']);
        $row['unallocated_refund_amount'] = Money::sub($row['refund_amount'], $row['allocated_refund_amount']);
        $row['prepaid_amount'] = Money::sub($row['unallocated_payment_amount'], $row['unallocated_refund_amount']);
        return $row;
    }
}
