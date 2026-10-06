<?php

namespace App\Services\Erp;

use App\Domain\Finance\Money;
use App\Models\Erp\FinanceCurrency;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Read-only chart projections: dated cash flows and separately current balances. */
class FinanceDashboardQueryService
{
    private const PAYABLE_FIELDS = ['current_payable_amount', 'unpaid_amount', 'quality_frozen_amount', 'paid_amount'];

    public function __construct(private readonly SupplierFinanceQueryService $suppliers) {}

    public function dashboard(array $filters, array $permissions): array
    {
        // The exchange-rate service locks base-currency rows for posting. This
        // report deliberately performs a plain read and no valuation conversion.
        $base = FinanceCurrency::query()->where('status', 'enabled')->where('is_base', true)->pluck('currency_code');
        if ($base->count() !== 1) throw ValidationException::withMessages(['base_currency' => '系统必须且只能维护一个启用的本位币。']);
        $currency = $filters['currency'] ?? $base[0];
        // Disabled currencies still own historical cash and ledger facts.
        // Only the default base-currency choice requires an enabled record.
        if (! FinanceCurrency::query()->where('currency_code', $currency)->exists()) {
            throw ValidationException::withMessages(['currency' => '请选择已有币种。']);
        }
        $end = CarbonImmutable::parse($filters['period_end'] ?? today()->toDateString())->startOfDay();
        $start = CarbonImmutable::parse($filters['period_start'] ?? $end->subDays(29)->toDateString())->startOfDay();
        if ($start->greaterThan($end)) throw ValidationException::withMessages(['period_end' => '结束日期不能早于开始日期。']);
        $days = (int) $start->diffInDays($end) + 1;
        if ($days > 366) throw ValidationException::withMessages(['period_end' => '统计期间不能超过 366 天。']);
        $previousEnd = $start->subDay();
        $previousStart = $start->subDays($days);
        $permissions = ['payables' => (bool) ($permissions['payables'] ?? false), 'suppliers' => (bool) ($permissions['suppliers'] ?? false)];
        [$payables, $suppliers] = $this->supplierBalances($currency, $permissions);

        return [
            'currency' => $currency, 'base_currency' => $base[0],
            'period' => ['start' => $start->toDateString(), 'end' => $end->toDateString(), 'days' => $days],
            'comparison_period' => ['start' => $previousStart->toDateString(), 'end' => $previousEnd->toDateString()],
            'as_of' => now()->toISOString(), 'timezone' => config('app.timezone', 'UTC'), 'permissions' => $permissions,
            'cash' => $this->cash($currency, $start, $end, $previousStart, $previousEnd),
            'accounts' => $this->accounts($currency), 'payables' => $payables, 'suppliers' => $suppliers,
        ];
    }

    private function cash(string $currency, CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $previousStart, CarbonImmutable $previousEnd): array
    {
        $query = DB::table('erp_finance_cash_documents')->where('currency', $currency)->where('status', 'confirmed');
        $current = (clone $query)->whereBetween('business_date', [$start->toDateString(), $end->toDateString()]);
        $summary = $this->cashSummary(clone $current);
        $previous = $this->cashSummary((clone $query)->whereBetween('business_date', [$previousStart->toDateString(), $previousEnd->toDateString()]));
        $daily = (clone $current)->select('business_date')
            ->selectRaw("SUM(CASE WHEN direction = 'receipt' THEN amount ELSE 0 END) AS receipt_amount")
            ->selectRaw("SUM(CASE WHEN direction = 'payment' THEN amount ELSE 0 END) AS payment_amount")
            ->groupBy('business_date')->get()->keyBy('business_date');
        $trend = [];
        for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
            $day = $date->toDateString();
            $receipt = Money::normalize((string) ($daily->get($day)?->receipt_amount ?? '0'));
            $payment = Money::normalize((string) ($daily->get($day)?->payment_amount ?? '0'));
            $trend[] = ['date' => $day, 'receipt_amount' => $receipt, 'payment_amount' => $payment, 'net_amount' => Money::sub($receipt, $payment)];
        }
        $groups = (clone $current)->select('direction')->selectRaw("CASE WHEN party_type IN ('supplier', 'customer') THEN party_type ELSE 'other' END AS party_group")
            ->selectRaw('SUM(amount) AS amount, COUNT(*) AS document_count')->groupBy('direction', 'party_group')->get()
            ->keyBy(fn (object $row) => $row->direction.':'.$row->party_group);
        $composition = [];
        foreach ([
            ['customer_receipt', '客户收款', 'receipt', 'customer'], ['supplier_refund', '供应商退款', 'receipt', 'supplier'],
            ['other_receipt', '其他收款', 'receipt', 'other'], ['supplier_payment', '供应商付款', 'payment', 'supplier'],
            ['customer_refund', '客户退款', 'payment', 'customer'], ['other_payment', '其他付款', 'payment', 'other'],
        ] as [$key, $label, $direction, $party]) {
            $group = $groups->get($direction.':'.$party);
            $composition[] = ['key' => $key, 'label' => $label, 'direction' => $direction, 'party_type' => $party,
                'amount' => Money::normalize((string) ($group?->amount ?? '0')), 'count' => (int) ($group?->document_count ?? 0)];
        }
        $changes = [];
        foreach (['receipt_amount', 'payment_amount', 'net_amount'] as $field) {
            $difference = Money::sub($summary[$field], $previous[$field]);
            $changes[$field] = ['amount' => $difference, 'percent' => Money::compare($previous[$field], '0') > 0
                ? bcdiv(bcmul($difference, '100', 6), $previous[$field], 2) : null];
        }
        return ['status' => 'available', 'summary' => $summary, 'previous_summary' => $previous,
            'changes' => $changes, 'trend' => $trend, 'composition' => $composition];
    }

    private function cashSummary(Builder $query): array
    {
        $row = $query->selectRaw("COALESCE(SUM(CASE WHEN direction = 'receipt' THEN amount ELSE 0 END), 0) AS receipt_amount")
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'payment' THEN amount ELSE 0 END), 0) AS payment_amount")
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'receipt' THEN 1 ELSE 0 END), 0) AS receipt_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'payment' THEN 1 ELSE 0 END), 0) AS payment_count")->first();
        $receipt = Money::normalize((string) $row->receipt_amount);
        $payment = Money::normalize((string) $row->payment_amount);
        return ['receipt_amount' => $receipt, 'payment_amount' => $payment, 'net_amount' => Money::sub($receipt, $payment),
            'receipt_count' => (int) $row->receipt_count, 'payment_count' => (int) $row->payment_count];
    }

    private function accounts(string $currency): array
    {
        $ledger = DB::table('erp_finance_account_movements')->where('status', 'confirmed')->where('currency', $currency)
            ->select('finance_account_id')->selectRaw("SUM(CASE WHEN direction = 'in' THEN original_amount ELSE -original_amount END) AS balance_amount")
            ->groupBy('finance_account_id');
        $items = DB::table('erp_finance_accounts as accounts')->where('accounts.currency', $currency)
            ->leftJoinSub($ledger, 'ledger', 'ledger.finance_account_id', '=', 'accounts.id')
            ->select('accounts.id', 'accounts.account_no', 'accounts.account_name', 'accounts.account_type', 'accounts.status')
            ->selectRaw('COALESCE(ledger.balance_amount, 0) AS balance_amount')->orderByDesc('balance_amount')->orderBy('accounts.id')->get()
            ->map(fn (object $row) => [...(array) $row, 'id' => (int) $row->id, 'balance_amount' => Money::normalize((string) $row->balance_amount)])->all();
        return ['status' => 'available', 'balance_amount' => array_reduce($items, fn (string $sum, array $row) => Money::add($sum, $row['balance_amount']), '0.0000'), 'items' => $items];
    }

    private function supplierBalances(string $currency, array $permissions): array
    {
        $payables = ['status' => 'forbidden', 'summary' => null];
        $suppliers = ['status' => 'forbidden', 'summary' => null, 'payable_ranking' => [], 'other_unpaid_amount' => null, 'total_unpaid_amount' => null];
        if (! $permissions['payables'] && ! $permissions['suppliers']) return [$payables, $suppliers];
        $query = $this->suppliers->currentBalanceQuery($currency);
        $totals = DB::query()->fromSub(clone $query, 'supplier_balances')->selectRaw('COUNT(*) AS supplier_count');
        foreach ([...self::PAYABLE_FIELDS, 'prepayment_balance_amount', 'pending_refund_amount'] as $field) $totals->selectRaw("COALESCE(SUM({$field}), 0) AS {$field}");
        $row = $totals->first();
        if ($permissions['payables']) {
            $summary = [];
            foreach (self::PAYABLE_FIELDS as $field) $summary[$field] = Money::normalize((string) $row->{$field});
            $payables = ['status' => 'available', 'summary' => $summary];
        }
        if ($permissions['suppliers']) {
            $ranking = (clone $query)->where('unpaid_amount', '>', 0)->orderByDesc('unpaid_amount')->orderBy('supplier_id')->limit(10)
                ->get(['supplier_id', 'supplier_code', 'supplier_name', 'unpaid_amount'])
                ->map(fn (object $supplier) => ['supplier_id' => (int) $supplier->supplier_id, 'supplier_code' => $supplier->supplier_code,
                    'supplier_name' => $supplier->supplier_name, 'unpaid_amount' => Money::normalize((string) $supplier->unpaid_amount)])->all();
            $rankedTotal = array_reduce($ranking, fn (string $sum, array $supplier) => Money::add($sum, $supplier['unpaid_amount']), '0.0000');
            $suppliers = ['status' => 'available', 'summary' => ['prepayment_balance_amount' => Money::normalize((string) $row->prepayment_balance_amount),
                'pending_refund_amount' => Money::normalize((string) $row->pending_refund_amount), 'supplier_count' => (int) $row->supplier_count],
                'payable_ranking' => $ranking, 'other_unpaid_amount' => Money::maxZero(Money::sub((string) $row->unpaid_amount, $rankedTotal)),
                'total_unpaid_amount' => Money::normalize((string) $row->unpaid_amount)];
        }
        return [$payables, $suppliers];
    }
}
