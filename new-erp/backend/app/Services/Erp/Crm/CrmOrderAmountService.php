<?php

namespace App\Services\Erp\Crm;

use App\Domain\Finance\FinanceConstants as Finance;
use App\Domain\Finance\Money;
use App\Models\Erp\SalesOrder;
use App\Services\Erp\SalesFinanceSettlementService;
use App\Services\Erp\SalesOrderPlatformPaymentService;
use App\Services\Erp\SalesReturnSettlementService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** 只投影订单与收退款事实；调用方先落实订单和金额权限，不在读取时补写历史。 */
final class CrmOrderAmountService
{
    private const AMOUNTS = ['deal_amount', 'received_amount', 'outstanding_amount'];
    private const EFFECTIVE_STATUSES = ['confirmed', 'closed', 'cancelled'];

    public function __construct(private readonly SalesFinanceSettlementService $settlements) {}

    public function applyEffective(Builder $orders): void
    {
        $orders->whereIn($orders->qualifyColumn('order_status'), self::EFFECTIVE_STATUSES)
            ->where($orders->qualifyColumn('confirm_status'), 'confirmed')
            ->whereNotNull($orders->qualifyColumn('confirmed_at'));
    }

    /** 完整 SalesOrder 集合；结果按订单 ID 索引，每批仅查询两次关联事实。 */
    public function forOrders(Collection $orders): array
    {
        $orders = $orders->filter(fn (SalesOrder $order): bool => $order->confirmed_at !== null
            && $order->confirm_status === 'confirmed' && in_array($order->order_status, self::EFFECTIVE_STATUSES, true))->keyBy('id');
        if ($orders->isEmpty()) return [];

        $allocations = DB::table('erp_finance_allocations as a')
            ->join('erp_finance_cash_documents as cash', 'cash.id', '=', 'a.cash_document_id')
            ->whereIn('a.source_document_id', $orders->keys()->all())
            ->whereIn('a.source_business_type', [Finance::SOURCE_SALES_ORDER, Finance::SOURCE_SALES_ORDER_REFUND])
            ->where('a.status', Finance::ALLOCATION_ACTIVE)->where('cash.status', Finance::STATUS_CONFIRMED)
            ->get(['a.source_document_id', 'a.source_business_type', 'a.allocated_amount', 'a.currency', 'a.party_type', 'a.party_id',
                'cash.direction', 'cash.currency as cash_currency', 'cash.party_type as cash_party_type', 'cash.party_id as cash_party_id'])
            ->groupBy('source_document_id');
        // LEFT JOIN 保留无结算口径的旧退货，不能让缺失 terms 被 SUM 当成没有退货。
        $returns = DB::table('erp_sales_returns as r')
            ->leftJoin('erp_sales_return_terms as t', 't.sales_return_id', '=', 'r.id')
            ->whereIn('r.sales_order_id', $orders->keys()->all())->whereNotIn('r.return_status', ['draft', 'cancelled'])
            ->get(['r.sales_order_id', 't.return_type', 't.credit_amount'])->groupBy('sales_order_id');

        $result = [];
        foreach ($orders as $order) {
            $result[$order->id] = $this->amounts($order, $allocations->get($order->id, collect()), $returns->get($order->id, collect()));
        }
        return $result;
    }

    /** 任一订单某项未知时，该币种该项总额为 null；known_* 仅供核对已知部分。 */
    public function summarize(iterable $rows): array
    {
        $currencies = [];
        $orderCount = $verificationCount = 0;
        foreach ($rows as $row) {
            $currency = $row['currency'] ?? null;
            $key = $currency ?? '';
            $count = (int) ($row['order_count'] ?? 1);
            $verification = (int) ($row['verification_count'] ?? (!empty($row['needs_verification']) ? 1 : 0));
            if (!isset($currencies[$key])) {
                $currencies[$key] = ['currency' => $currency, 'order_count' => 0, 'verification_count' => 0, 'verification_reasons' => []];
                foreach (self::AMOUNTS as $field) $currencies[$key][$field] = $currencies[$key]['known_'.$field] = '0.0000';
            }
            $total = &$currencies[$key];
            $total['order_count'] += $count;
            $total['verification_count'] += $verification;
            $total['verification_reasons'] = array_values(array_unique(array_merge($total['verification_reasons'], $row['verification_reasons'] ?? [])));
            foreach (self::AMOUNTS as $field) {
                $amount = $row[$field] ?? null;
                $known = $row['known_'.$field] ?? $amount ?? '0.0000';
                $total['known_'.$field] = Money::add($total['known_'.$field], $known);
                $total[$field] = $total[$field] === null || $amount === null ? null : Money::add($total[$field], $amount);
            }
            unset($total);
            $orderCount += $count;
            $verificationCount += $verification;
        }
        ksort($currencies);
        return ['currencies' => array_values($currencies), 'order_count' => $orderCount, 'verification_count' => $verificationCount];
    }

    public function mergeSummaries(iterable $summaries): array
    {
        return $this->summarize((function () use ($summaries): \Generator {
            foreach ($summaries as $summary) foreach ($summary['currencies'] as $currency) yield $currency;
        })());
    }

    private function amounts(SalesOrder $order, Collection $allocations, Collection $returns): array
    {
        $reasons = [];
        $currency = trim((string) $order->currency) ?: null;
        $credit = $this->returnCredit($returns);
        $contract = $this->contractAmount($order);
        $legacy = $order->isLegacyHistory();
        $deal = $contract === null || $credit === null ? null : Money::maxZero(Money::sub($contract, $credit));
        if ($credit === null) $reasons[] = '退货缺少可靠的结算口径。';
        if ($contract === null) $reasons[] = '订单缺少可靠的当前应收金额。';
        if ($legacy) {
            $deal = null;
            $reasons[] = '旧系统订单当前应收和收退款事实尚未完整迁入。';
        }
        if ($order->order_status === 'cancelled') $deal = '0.0000';

        [$financeReceived, $refunded, $hasReceipt] = $this->financeAmounts($order, $allocations);
        $snapshot = SalesOrderPlatformPaymentService::receiptSnapshot($order);
        $platformAmount = null;
        $received = null;
        $source = 'unknown';
        if ($financeReceived === null || $refunded === null) {
            $reasons[] = '财务核销金额、币种、方向或客户与订单不一致。';
        } elseif ($legacy) {
            // 旧单缺少完整迁入凭据，不能把未查到本系统核销解释为未收款。
        } elseif ($hasReceipt) {
            $received = Money::sub($financeReceived, $refunded);
            $source = 'finance';
        } elseif ($snapshot !== null) {
            $platformAmount = $this->platformAmount($order, $snapshot, $refunded);
            $received = $platformAmount;
            if ($platformAmount === null) $reasons[] = '原平台核对的订单身份或金额依据已变化，须核实收款。';
            else $source = 'platform_verification';
        } elseif ($order->channel_type_snapshot === 'online_platform') {
            $reasons[] = '平台订单缺少已核对的实际付款金额。';
        } elseif ($order->channel_type_snapshot !== 'offline_direct') {
            $reasons[] = '订单缺少可靠的收款渠道口径。';
        } elseif (Money::compare($refunded, '0') > 0) {
            $reasons[] = '存在退款但缺少对应有效收款事实。';
        } else {
            $received = '0.0000';
            $source = 'finance';
        }
        if ($currency === null) {
            $deal = $received = null;
            $reasons[] = '订单未记录币种。';
        }
        $outstanding = $deal === null || $received === null ? null : Money::maxZero(Money::sub($deal, $received));
        return [
            'order_id' => (int) $order->id,
            'currency' => $currency,
            'deal_amount' => $deal,
            'received_amount' => $received,
            'outstanding_amount' => $outstanding,
            'needs_verification' => $reasons !== [],
            'verification_reasons' => array_values(array_unique($reasons)),
            'received_source' => $source,
            'finance_received_amount' => $financeReceived,
            'refunded_amount' => $refunded,
            'platform_payment_amount' => $platformAmount,
            'return_credit_amount' => $credit,
            'sales_user_legacy_id' => $order->sales_user_legacy_id ? (int) $order->sales_user_legacy_id : null,
            'sales_department_id' => $order->sales_department_id ? (int) $order->sales_department_id : null,
            'attribution_needs_verification' => !$order->sales_user_legacy_id || !$order->sales_department_id,
        ];
    }

    private function contractAmount(SalesOrder $order): ?string
    {
        $basis = data_get($order->funding_policy_snapshot, 'receivable_amount') ?? $order->final_receivable_amount ?? $order->total_amount;
        if ($this->decimal($basis) === null) return null;
        try {
            $amount = $this->settlements->receivableAmount($order);
            return Money::compare($amount, '0') < 0 ? null : $amount;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function returnCredit(Collection $returns): ?string
    {
        $total = '0.0000';
        foreach ($returns as $return) {
            if (!in_array($return->return_type, SalesReturnSettlementService::TYPES, true)) return null;
            if ($return->return_type === SalesReturnSettlementService::TYPE_EXCHANGE) continue;
            $amount = $this->decimal($return->credit_amount);
            if ($amount === null) return null;
            $total = Money::add($total, $amount);
        }
        return $total;
    }

    private function financeAmounts(SalesOrder $order, Collection $allocations): array
    {
        $received = $refunded = '0.0000';
        $hasReceipt = false;
        foreach ($allocations as $allocation) {
            $receipt = $allocation->source_business_type === Finance::SOURCE_SALES_ORDER;
            $hasReceipt = $hasReceipt || $receipt;
            $amount = $this->decimal($allocation->allocated_amount);
            if ($amount === null || $allocation->currency !== $order->currency || $allocation->cash_currency !== $order->currency
                || $allocation->direction !== ($receipt ? Finance::DIRECTION_RECEIPT : Finance::DIRECTION_PAYMENT)
                || $allocation->party_type !== Finance::PARTY_CUSTOMER || $allocation->cash_party_type !== Finance::PARTY_CUSTOMER
                || (int) $allocation->party_id !== (int) $order->customer_id || (int) $allocation->cash_party_id !== (int) $order->customer_id) {
                return [null, null, $hasReceipt];
            }
            if ($receipt) $received = Money::add($received, $amount);
            else $refunded = Money::add($refunded, $amount);
        }
        return [$received, $refunded, $hasReceipt];
    }

    private function platformAmount(SalesOrder $order, array $snapshot, string $refunded): ?string
    {
        $basis = $snapshot['basis'];
        if (!is_array($basis) || (int) $order->created_by_legacy_id <= 0
            || (int) ($snapshot['confirmed_by_legacy_id'] ?? 0) !== (int) $order->created_by_legacy_id
            || !isset($snapshot['confirmed_by'], $snapshot['confirmed_at'])) return null;
        $identity = [
            'sales_channel_id' => (int) $order->sales_channel_id,
            'platform' => (string) $order->platform,
            'platform2' => (string) $order->platform2,
            'platform_order_no' => SalesOrderPlatformPaymentService::orderNumber($order),
            'customer_id' => (int) $order->customer_id,
            'currency' => (string) $order->currency,
        ];
        foreach ($identity as $field => $value) {
            if (!array_key_exists($field, $basis) || $basis[$field] !== $value) return null;
        }
        $amount = $this->decimal($basis['net_receivable_amount'] ?? null);
        $previousRefund = $this->decimal($basis['refunded_amount'] ?? null);
        if ($amount === null || $previousRefund === null) return null;
        // 核对金额已经是当时净应收，只扣核对之后新增的净退款；财务收款出现后整单改用财务数。
        return Money::sub(Money::add($amount, $previousRefund), $refunded);
    }

    private function decimal(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) return null;
        try {
            $amount = Money::normalize($value);
            return Money::compare($amount, '0') < 0 ? null : $amount;
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
