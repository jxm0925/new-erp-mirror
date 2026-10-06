<?php

namespace App\Services\Erp;

use App\Domain\Finance\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;

/** Read-only supplier balances and separately dated financial facts. */
class SupplierFinanceQueryService
{
    private const AMOUNTS = [
        'current_payable_amount', 'quality_frozen_amount', 'paid_amount', 'unpaid_amount',
        'prepayment_balance_amount', 'pending_refund_amount', 'received_invoice_amount',
        'unreceived_invoice_amount', 'red_invoice_amount', 'period_receipt_amount',
        'period_payment_amount', 'period_refund_amount', 'period_offset_amount',
        'period_refund_due_amount', 'period_allocated_amount', 'period_reversed_allocation_amount',
        'period_refund_allocated_amount', 'period_refund_reversed_amount',
    ];

    public function paginate(array $filters, int $perPage = 20): array
    {
        $query = $this->supplierQuery($filters);
        // Aggregate the complete filtered result before pagination. Never add
        // different currencies or confuse a supplier page with a company total.
        $totals = DB::query()->fromSub(clone $query, 'filtered_suppliers')
            ->select('currency')->selectRaw('COUNT(*) AS supplier_count');
        foreach (self::AMOUNTS as $field) $totals->selectRaw("SUM({$field}) AS {$field}");
        $summary = $totals->groupBy('currency')->orderBy('currency')->get()
            ->map(fn (object $row) => $this->normalize($row))->all();
        $page = $query->orderBy('supplier_name')->orderBy('supplier_id')->orderBy('currency')
            ->paginate($perPage, ['*'], 'page', $this->page($filters));

        return [
            ...$page->toArray(),
            'data' => $page->getCollection()->map(fn (object $row) => $this->normalize($row))->all(),
            'summary_by_currency' => $summary,
            // Retained for old single-currency consumers; mixed-currency totals
            // deliberately have no numeric scalar representation.
            'summary' => count($summary) === 1 ? $summary[0] : null,
            'balance_as_of' => now()->toISOString(),
            'period' => $this->period($filters),
            'date_basis' => 'current_balances_and_period_flows',
        ];
    }

    /** Shared current supplier/currency facts for read-only chart aggregation. */
    public function currentBalanceQuery(string $currency): Builder
    {
        return $this->supplierQuery(['currency' => $currency]);
    }

    private function supplierQuery(array $filters): Builder
    {
        $cashAllocations = DB::table('erp_finance_allocations')->where('status', 'active')
            ->select('cash_document_id')
            ->selectRaw('SUM(CASE WHEN cash_allocated_amount <> 0 THEN cash_allocated_amount ELSE allocated_amount END) AS allocated_amount')
            ->groupBy('cash_document_id');
        $sourceAllocations = DB::table('erp_finance_allocations as a')
            ->join('erp_finance_cash_documents as c', 'c.id', '=', 'a.cash_document_id')
            ->where('a.status', 'active')->where('c.status', 'confirmed')->where('c.direction', 'payment')
            ->where('a.source_business_type', 'purchase_settlement_source')->select('a.source_document_id')
            ->selectRaw('SUM(CASE WHEN a.business_allocated_amount <> 0 THEN a.business_allocated_amount ELSE a.allocated_amount END) AS allocated_amount')
            ->groupBy('a.source_document_id');
        $refundAllocations = DB::table('erp_finance_allocations as a')
            ->join('erp_finance_cash_documents as c', 'c.id', '=', 'a.cash_document_id')
            ->where('a.status', 'active')->where('c.status', 'confirmed')->where('c.direction', 'receipt')
            ->where('a.source_business_type', 'purchase_return_supplier_refund')->select('a.source_document_id')
            ->selectRaw('SUM(CASE WHEN a.business_allocated_amount <> 0 THEN a.business_allocated_amount ELSE a.allocated_amount END) AS allocated_amount')
            ->groupBy('a.source_document_id');

        $payable = 'GREATEST(s.eligible_amount - s.ap_offset_amount, 0)';
        $paid = 'COALESCE(a.allocated_amount, 0)';
        $sources = DB::table('erp_purchase_settlement_sources as s')
            ->leftJoinSub($sourceAllocations, 'a', 'a.source_document_id', '=', 's.id');
        $this->supplierScope($sources, $filters, 's.supplier_id', 's.currency');
        $union = $this->metrics($sources, 's.supplier_id', 's.currency', 's.supplier_name_snapshot', [
            'source_count' => '1', 'current_payable_amount' => $payable,
            'quality_frozen_amount' => 's.frozen_amount', 'paid_amount' => $paid,
            'unpaid_amount' => "GREATEST({$payable} - {$paid}, 0)",
            'received_invoice_amount' => 's.invoice_matched_amount',
            'unreceived_invoice_amount' => "GREATEST({$payable} - s.invoice_matched_amount, 0)",
            'period_receipt_amount' => $this->periodAmount('s.business_date', 's.original_amount', $filters),
        ]);

        $cash = DB::table('erp_finance_cash_documents as c')
            ->leftJoinSub($cashAllocations, 'a', 'a.cash_document_id', '=', 'c.id')
            ->where('c.party_type', 'supplier')->whereIn('c.status', ['confirmed', 'voided']);
        $this->supplierScope($cash, $filters, 'c.party_id', 'c.currency');
        $union->unionAll($this->metrics($cash, 'c.party_id', 'c.currency', 'c.party_name_snapshot', [
            // Returned deposits reduce available prepayment. A refund already
            // allocated to a formal return obligation must not reduce it again.
            // Net all confirmed cash in the supplier/currency group before
            // clamping; report periods and AP filters never change this balance.
            'prepayment_balance_amount' => "CASE WHEN c.status = 'confirmed' THEN (CASE WHEN c.direction = 'payment' THEN 1 ELSE -1 END) * GREATEST(c.amount - COALESCE(a.allocated_amount, 0), 0) ELSE 0 END",
            'period_payment_amount' => $this->periodAmount('c.business_date', "CASE WHEN c.direction = 'payment' AND c.status = 'confirmed' THEN c.amount ELSE 0 END", $filters),
            'period_refund_amount' => $this->periodAmount('c.business_date', "CASE WHEN c.direction = 'receipt' AND c.status = 'confirmed' THEN c.amount ELSE 0 END", $filters),
        ]));

        $returns = DB::table('erp_purchase_returns as r')
            ->leftJoinSub($refundAllocations, 'a', 'a.source_document_id', '=', 'r.id')
            ->where('r.return_status', 'completed')->whereIn('r.settlement_effect_type', ['AP_OFFSET', 'SUPPLIER_REFUND']);
        $returnCurrency = "COALESCE(NULLIF(r.currency_snapshot, ''), 'CNY')";
        $this->supplierScope($returns, $filters, 'r.supplier_id', $returnCurrency);
        $union->unionAll($this->metrics($returns, 'r.supplier_id', $returnCurrency, "''", [
            'pending_refund_amount' => "CASE WHEN r.settlement_effect_type = 'SUPPLIER_REFUND' THEN GREATEST(r.settlement_amount - COALESCE(a.allocated_amount, 0), 0) ELSE 0 END",
            'period_offset_amount' => $this->periodAmount('r.return_date', "CASE WHEN r.settlement_effect_type = 'AP_OFFSET' THEN r.settlement_amount ELSE 0 END", $filters),
            'period_refund_due_amount' => $this->periodAmount('r.return_date', "CASE WHEN r.settlement_effect_type = 'SUPPLIER_REFUND' THEN r.settlement_amount ELSE 0 END", $filters),
        ]));

        $invoices = DB::table('erp_finance_invoices as i')->where('i.party_type', 'supplier')
            ->where('i.invoice_direction', 'purchase')->where('i.status', 'red');
        $this->supplierScope($invoices, $filters, 'i.party_id', 'i.currency');
        $union->unionAll($this->metrics($invoices, 'i.party_id', 'i.currency', 'i.party_name_snapshot', [
            'red_invoice_amount' => 'i.amount_incl_tax',
        ]));

        $allocations = DB::table('erp_finance_allocations as a')
            ->join('erp_finance_cash_documents as c', 'c.id', '=', 'a.cash_document_id')
            ->where('c.party_type', 'supplier')->whereIn('c.status', ['confirmed', 'voided'])
            ->whereIn('a.status', ['active', 'reversed', 'reversal']);
        $this->supplierScope($allocations, $filters, 'c.party_id', 'c.currency');
        $allocationAmount = 'ABS(CASE WHEN a.cash_allocated_amount <> 0 THEN a.cash_allocated_amount ELSE a.allocated_amount END)';
        $flowFields = [];
        foreach ([
            'period_allocated_amount' => ['payment', "a.status IN ('active', 'reversed')"],
            'period_reversed_allocation_amount' => ['payment', "a.status = 'reversal'"],
            'period_refund_allocated_amount' => ['receipt', "a.status IN ('active', 'reversed')"],
            'period_refund_reversed_amount' => ['receipt', "a.status = 'reversal'"],
        ] as $field => [$direction, $status]) {
            $flowFields[$field] = $this->periodAmount('DATE(a.allocated_at)', "CASE WHEN c.direction = '{$direction}' AND {$status} THEN {$allocationAmount} ELSE 0 END", $filters);
        }
        $union->unionAll($this->metrics($allocations, 'c.party_id', 'c.currency', 'c.party_name_snapshot', $flowFields));

        // Supplier identity and currency, never a historical name, define a row.
        // A supplier with cash only is included by the UNION above.
        $grouped = DB::query()->fromSub($union, 'facts')->select('supplier_id', 'currency')
            ->selectRaw("MAX(NULLIF(supplier_name_snapshot, '')) AS fallback_name, SUM(source_count) AS source_count");
        foreach (self::AMOUNTS as $field) {
            $amount = $field === 'prepayment_balance_amount' ? "GREATEST(SUM({$field}), 0)" : "SUM({$field})";
            $grouped->selectRaw("{$amount} AS {$field}");
        }
        $grouped->groupBy('supplier_id', 'currency');

        $identified = DB::query()->fromSub($grouped, 'g')
            ->leftJoin('erp_suppliers as supplier', 'supplier.id', '=', 'g.supplier_id')
            ->select('g.*', 'supplier.supplier_code')
            ->selectRaw("COALESCE(supplier.supplier_name, g.fallback_name, CONCAT('供应商#', g.supplier_id)) AS supplier_name")
            ->selectRaw("CASE WHEN quality_frozen_amount > 0 AND current_payable_amount <= 0 THEN 'frozen' WHEN current_payable_amount <= 0 THEN 'settled' WHEN paid_amount <= 0 THEN 'unpaid' WHEN unpaid_amount <= 0 THEN 'paid' ELSE 'partial' END AS payment_status")
            ->selectRaw("CASE WHEN current_payable_amount <= 0 THEN 'not_required' WHEN received_invoice_amount <= 0 THEN 'unreceived' WHEN unreceived_invoice_amount <= 0 THEN 'received' ELSE 'partial' END AS invoice_status")
            ->selectRaw("CASE WHEN quality_frozen_amount > 0 THEN 'quality_frozen' WHEN pending_refund_amount > 0 THEN 'pending_refund' WHEN unpaid_amount <= 0 AND unreceived_invoice_amount <= 0 AND prepayment_balance_amount <= 0 THEN 'settled' ELSE 'unclosed' END AS finance_status");
        $query = DB::query()->fromSub($identified, 'supplier_finance');
        if ($keyword = trim((string) ($filters['supplier_keyword'] ?? ''))) {
            $query->where(fn (Builder $q) => $q->where('supplier_name', 'like', "%{$keyword}%")->orWhere('supplier_code', 'like', "%{$keyword}%"));
        }
        foreach (['payment_status', 'invoice_status'] as $field) {
            if (! empty($filters[$field])) $query->where($field, $filters[$field]);
        }
        if (($filters['has_balance'] ?? '') === 'yes') $query->whereRaw('(unpaid_amount > 0 OR prepayment_balance_amount > 0 OR pending_refund_amount > 0)');
        if (($filters['has_balance'] ?? '') === 'no') $query->whereRaw('(unpaid_amount <= 0 AND prepayment_balance_amount <= 0 AND pending_refund_amount <= 0)');
        if (! empty($filters['only_prepayment'])) $query->where('source_count', 0)->where('prepayment_balance_amount', '>', 0);
        return $query;
    }

    /** Every branch has one row shape; SQL performs the complete aggregation. */
    private function metrics(Builder $query, string $supplier, string $currency, string $name, array $values): Builder
    {
        $query->selectRaw("{$supplier} AS supplier_id, {$currency} AS currency, {$name} AS supplier_name_snapshot");
        foreach (['source_count', ...self::AMOUNTS] as $field) {
            $value = $values[$field] ?? '0';
            [$sql, $bindings] = is_array($value) ? $value : [$value, []];
            $query->selectRaw("{$sql} AS {$field}", $bindings);
        }
        return $query;
    }

    private function supplierScope(Builder $query, array $filters, string $supplier, string $currency): void
    {
        if (! empty($filters['supplier_id'])) $query->where($supplier, (int) $filters['supplier_id']);
        if (! empty($filters['currency'])) $query->whereRaw("{$currency} = ?", [$filters['currency']]);
    }

    private function periodAmount(string $date, string $amount, array $filters): array
    {
        $period = $this->period($filters);
        $conditions = []; $bindings = [];
        if ($period['start']) { $conditions[] = "{$date} >= ?"; $bindings[] = $period['start']; }
        if ($period['end']) { $conditions[] = "{$date} <= ?"; $bindings[] = $period['end']; }
        return $conditions === [] ? [$amount, []] : ['CASE WHEN '.implode(' AND ', $conditions)." THEN ({$amount}) ELSE 0 END", $bindings];
    }

    private function period(array $filters): array
    {
        return ['start' => $filters['period_start'] ?? $filters['business_date_start'] ?? null, 'end' => $filters['period_end'] ?? $filters['business_date_end'] ?? null];
    }

    private function normalize(object $row): array
    {
        $data = (array) $row;
        foreach (self::AMOUNTS as $field) if (array_key_exists($field, $data)) $data[$field] = Money::normalize((string) $data[$field]);
        foreach (['supplier_id', 'supplier_count', 'source_count'] as $field) if (isset($data[$field])) $data[$field] = (int) $data[$field];
        unset($data['fallback_name']);
        return $data;
    }

    private function page(array $filters): int
    {
        return max(1, (int) ($filters['page'] ?? Paginator::resolveCurrentPage()));
    }

    public function entries(int $supplierId, array $filters, int $perPage, bool $includeCash): array
    {
        $sources = DB::table('erp_purchase_settlement_sources as s')->where('s.supplier_id', $supplierId)
            ->selectRaw("CONCAT('source-', s.id) AS event_key, 'receipt' AS event_family, '到货结算来源' AS event_label, s.business_date, s.created_at AS occurred_at, s.currency, s.original_amount AS amount, '到货原额' AS amount_meaning, s.status, 'purchase_settlement_source' AS document_type, s.id AS document_id, s.source_document_no AS document_no, 'purchase_order' AS related_document_type, s.purchase_order_id AS related_document_id, s.purchase_order_no_snapshot AS related_document_no, '' AS cash_direction, GREATEST(s.eligible_amount - s.ap_offset_amount, 0) AS current_payable_amount, s.frozen_amount AS quality_frozen_amount");
        $returns = DB::table('erp_purchase_returns as r')->where('r.supplier_id', $supplierId)
            ->whereNotIn('r.return_status', ['draft', 'submitted'])->whereIn('r.settlement_effect_type', ['AP_OFFSET', 'SUPPLIER_REFUND'])
            ->selectRaw("CONCAT('return-', r.id) AS event_key, 'return' AS event_family, CASE WHEN r.settlement_effect_type = 'AP_OFFSET' THEN '采购退货抵扣' ELSE '供应商退款义务' END AS event_label, r.return_date AS business_date, COALESCE(r.posted_at, r.updated_at, r.created_at) AS occurred_at, COALESCE(NULLIF(r.currency_snapshot, ''), 'CNY') AS currency, r.settlement_amount AS amount, CASE WHEN r.settlement_effect_type = 'AP_OFFSET' THEN '减少应付' ELSE '应收退款' END AS amount_meaning, r.return_status AS status, 'purchase_return' AS document_type, r.id AS document_id, r.return_no AS document_no, 'purchase_receipt' AS related_document_type, r.source_receipt_id AS related_document_id, NULL AS related_document_no, '' AS cash_direction, NULL AS current_payable_amount, NULL AS quality_frozen_amount");
        $union = $sources->unionAll($returns);

        // Supplier-ledger permission grants aggregate balances, not the cash
        // documents protected by finance.view. Omit these branches server-side.
        if ($includeCash) {
            $cash = DB::table('erp_finance_cash_documents as c')->where('c.party_type', 'supplier')->where('c.party_id', $supplierId)
                ->whereIn('c.status', ['confirmed', 'voided'])
                ->selectRaw("CONCAT('cash-', c.id) AS event_key, 'cash' AS event_family, CASE WHEN c.direction = 'payment' THEN '实际付款' ELSE '实际收退款' END AS event_label, c.business_date, COALESCE(c.confirmed_at, c.created_at) AS occurred_at, c.currency, c.amount, CASE WHEN c.direction = 'payment' THEN '资金流出' ELSE '资金流入' END AS amount_meaning, c.status, 'cash_document' AS document_type, c.id AS document_id, c.document_no, NULL AS related_document_type, NULL AS related_document_id, NULL AS related_document_no, c.direction AS cash_direction, NULL AS current_payable_amount, NULL AS quality_frozen_amount");
            $voids = DB::table('erp_finance_cash_documents as c')->where('c.party_type', 'supplier')->where('c.party_id', $supplierId)
                ->where('c.status', 'voided')->whereNotNull('c.voided_at')
                ->selectRaw("CONCAT('void-', c.id) AS event_key, 'void' AS event_family, CASE WHEN c.direction = 'payment' THEN '付款作废' ELSE '收退款作废' END AS event_label, DATE(c.voided_at) AS business_date, c.voided_at AS occurred_at, c.currency, c.amount, '撤销资金事实' AS amount_meaning, c.status, 'cash_document' AS document_type, c.id AS document_id, c.document_no, NULL AS related_document_type, NULL AS related_document_id, NULL AS related_document_no, c.direction AS cash_direction, NULL AS current_payable_amount, NULL AS quality_frozen_amount");
            $allocations = DB::table('erp_finance_allocations as a')->join('erp_finance_cash_documents as c', 'c.id', '=', 'a.cash_document_id')
                ->where('c.party_type', 'supplier')->where('c.party_id', $supplierId)->whereIn('a.status', ['active', 'reversed', 'reversal'])
                ->whereIn('c.status', ['confirmed', 'voided'])
                ->selectRaw("CONCAT('allocation-', a.id) AS event_key, 'allocation' AS event_family, CASE WHEN a.status = 'reversal' THEN '撤销核销' ELSE '核销记录' END AS event_label, DATE(a.allocated_at) AS business_date, a.allocated_at AS occurred_at, c.currency, ABS(CASE WHEN a.cash_allocated_amount <> 0 THEN a.cash_allocated_amount ELSE a.allocated_amount END) AS amount, CASE WHEN a.status = 'reversal' THEN '撤销关联，不是退款' ELSE '关联货款，不是再次收付' END AS amount_meaning, a.status, 'cash_document' AS document_type, c.id AS document_id, c.document_no, a.source_business_type AS related_document_type, a.source_document_id AS related_document_id, a.source_document_no AS related_document_no, c.direction AS cash_direction, NULL AS current_payable_amount, NULL AS quality_frozen_amount");
            $union->unionAll($cash)->unionAll($voids)->unionAll($allocations);
        }
        $query = DB::query()->fromSub($union, 'supplier_entries');
        $period = $this->period($filters);
        if ($period['start']) $query->whereDate('business_date', '>=', $period['start']);
        if ($period['end']) $query->whereDate('business_date', '<=', $period['end']);
        if (! empty($filters['currency'])) $query->where('currency', $filters['currency']);
        if (! empty($filters['event_family'])) $query->where('event_family', $filters['event_family']);
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) $query->where(fn (Builder $q) => $q->where('document_no', 'like', "%{$keyword}%")->orWhere('related_document_no', 'like', "%{$keyword}%"));
        $page = $query->orderByDesc('business_date')->orderByDesc('occurred_at')->orderByDesc('event_key')
            ->paginate($perPage, ['*'], 'page', $this->page($filters));
        return [
            ...$page->toArray(),
            'data' => $page->getCollection()->map(function (object $row): array {
                $data = (array) $row;
                foreach (['amount', 'current_payable_amount', 'quality_frozen_amount'] as $field) if ($data[$field] !== null) $data[$field] = Money::normalize((string) $data[$field]);
                return $data;
            })->all(),
            'cash_details_visible' => $includeCash,
            'period' => $period,
        ];
    }
}
