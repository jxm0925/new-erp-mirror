<?php

namespace App\Services\Erp;

use App\Domain\Finance\Money;
use App\Models\Erp\PurchaseLog;
use App\Models\Erp\PurchaseOrder;
use App\Models\Erp\PurchasePaymentPlanItem;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class PurchasePaymentPlanQueryService
{
    public const SUMMARY_FIELDS = [
        'contract_amount', 'planned_amount', 'unplanned_amount', 'paid_amount', 'refund_amount', 'net_paid_amount',
        'contract_unpaid_amount', 'overpaid_amount', 'allocated_payment_amount', 'allocated_refund_amount',
        'unallocated_payment_amount', 'unallocated_refund_amount', 'prepaid_amount', 'current_payable_amount', 'unpaid_payable_amount',
    ];

    public function __construct(private readonly PurchasePaymentBalanceQueryService $balances) {}

    public function forOrder(int $id): array
    {
        $order = PurchaseOrder::query()->with('supplier')->findOrFail($id);
        $row = $this->project((array) $this->ordersQuery([], false)->where('purchase_order_id', $id)->first());
        $paid = $this->planTotalsQuery()->where('purpose.purchase_order_id', $id)->get()->keyBy('payment_plan_id');
        $items = PurchasePaymentPlanItem::query()->where('purchase_order_id', $id)->where('status', 'active')->orderBy('sequence_no')->orderBy('id')->get();
        $data = [...$row, 'version' => (int) $order->payment_plan_version, 'purchase_status' => $order->purchase_status, 'audit_status' => $order->audit_status,
            'finance_fact_status' => $order->finance_fact_status,
            'items' => $items->map(function ($item) use ($paid): array {
                $fact = $paid->get($item->id);
                $paidAmount = Money::normalize((string) ($fact->paid_amount ?? '0'));
                $refundAmount = Money::normalize((string) ($fact->refund_amount ?? '0'));
                $net = Money::sub($paidAmount, $refundAmount);
                return ['id' => $item->id, 'purchase_order_id' => (int) $item->purchase_order_id, 'sequence_no' => $item->sequence_no,
                    'title' => $item->title, 'trigger_type' => $item->trigger_type, 'amount' => $item->amount, 'currency' => $item->currency,
                    'due_date' => $item->due_date?->toDateString(), 'remark' => $item->remark,
                    'paid_amount' => $paidAmount, 'refund_amount' => $refundAmount, 'net_paid_amount' => $net,
                    'remaining_amount' => Money::maxZero(Money::sub($item->amount, $net)),
                    'overpaid_amount' => Money::maxZero(Money::sub($net, $item->amount)),
                    'payment_status' => self::paymentStatus($net, $item->amount),
                    'overdue' => $item->due_date !== null && $item->due_date->toDateString() < now()->toDateString() && Money::compare($net, $item->amount) < 0];
            })->all(),
            'logs' => PurchaseLog::query()->where('target_type', 'purchase_order')->where('target_id', $id)
                ->where('action', 'update_payment_plan')->latest('id')->limit(50)->get()->toArray(),
        ];
        return $data;
    }

    public function listing(array $filters, int $perPage, bool $withSummary): array
    {
        $query = $this->ordersQuery($filters);
        $summary = $withSummary ? DB::query()->fromSub(clone $query, 'filtered_orders')->select('currency')
            ->selectRaw('COUNT(*) AS order_count, '.implode(', ', array_map(fn (string $field) => 'SUM('.$field.') AS '.$field, self::SUMMARY_FIELDS)))
            ->groupBy('currency')->orderBy('currency')->get()->map(function ($row): array {
                $row = (array) $row;
                $row['order_count'] = (int) $row['order_count'];
                foreach (self::SUMMARY_FIELDS as $field) $row[$field] = Money::normalize((string) $row[$field]);
                return $row;
            })->all() : null;
        $page = $query->orderByDesc('order_date')->orderByDesc('purchase_order_id')->paginate($perPage);
        $page->setCollection($page->getCollection()->map(fn ($row) => $this->project((array) $row)));
        $result = $page->toArray();
        if ($withSummary) $result['summary_by_currency'] = $summary;
        return $result;
    }

    public static function paymentStatus(string $net, string $amount): string
    {
        if (Money::compare($net, $amount) > 0) return 'overpaid';
        if (Money::compare($net, '0') <= 0) return 'unpaid';
        return Money::compare($net, $amount) >= 0 ? 'paid' : 'partial';
    }

    private function ordersQuery(array $filters, bool $approvedOnly = true): Builder
    {
        $planTotals = $this->planTotalsQuery();
        $plans = DB::table('erp_purchase_payment_plan_items as plan')
            ->leftJoinSub($planTotals, 'plan_paid', 'plan_paid.payment_plan_id', '=', 'plan.id')
            ->where('plan.status', 'active')->selectRaw('plan.purchase_order_id, SUM(plan.amount) AS planned_amount,
                MIN(CASE WHEN plan.amount > COALESCE(plan_paid.paid_amount, 0) - COALESCE(plan_paid.refund_amount, 0) THEN plan.due_date ELSE NULL END) AS next_due_date,
                GROUP_CONCAT(DISTINCT plan.trigger_type ORDER BY plan.trigger_type SEPARATOR ",") AS trigger_types')
            ->groupBy('plan.purchase_order_id');
        $payables = DB::table('erp_purchase_settlement_sources')->select('purchase_order_id')->selectRaw('
            SUM(GREATEST(eligible_amount - ap_offset_amount, 0)) AS current_payable_amount,
            SUM(GREATEST(eligible_amount - ap_offset_amount - allocated_amount, 0)) AS unpaid_payable_amount')
            ->groupBy('purchase_order_id');
        $contract = 'COALESCE(orders.amount_incl_tax, orders.total_amount, 0)';
        $net = 'COALESCE(cash.net_paid_amount, 0)';
        $query = DB::table('erp_purchase_orders as orders')
            ->leftJoin('erp_suppliers as supplier', 'supplier.id', '=', 'orders.supplier_id')
            ->leftJoinSub($this->balances->aggregateQuery(), 'cash', function ($join): void {
                $join->on('cash.purchase_order_id', '=', 'orders.id')->on('cash.currency', '=', 'orders.currency');
            })
            ->leftJoinSub($plans, 'plans', 'plans.purchase_order_id', '=', 'orders.id')
            ->leftJoinSub($payables, 'payables', 'payables.purchase_order_id', '=', 'orders.id')
            ->select(['orders.id', 'orders.id as purchase_order_id', 'orders.purchase_order_no', 'orders.supplier_id',
                'supplier.supplier_name', 'orders.order_date', 'orders.currency', 'orders.purchase_status', 'orders.audit_status',
                'orders.payment_plan_version', 'plans.next_due_date', 'plans.trigger_types'])
            ->selectRaw($contract.' AS contract_amount, COALESCE(plans.planned_amount, 0) AS planned_amount,
                GREATEST('.$contract.' - COALESCE(plans.planned_amount, 0), 0) AS unplanned_amount,
                GREATEST('.$contract.' - '.$net.', 0) AS contract_unpaid_amount,
                GREATEST('.$net.' - '.$contract.', 0) AS overpaid_amount,
                COALESCE(payables.current_payable_amount, 0) AS current_payable_amount,
                COALESCE(payables.unpaid_payable_amount, 0) AS unpaid_payable_amount,
                CASE WHEN '.$net.' > '.$contract.' THEN "overpaid" WHEN '.$net.' <= 0 THEN "unpaid" WHEN '.$net.' >= '.$contract.' THEN "paid" ELSE "partial" END AS payment_status');
        foreach (PurchasePaymentBalanceQueryService::FIELDS as $field) $query->selectRaw('COALESCE(cash.'.$field.', 0) AS '.$field);
        if ($approvedOnly) $query->where('orders.audit_status', 'approved')->where('orders.finance_fact_status', 'frozen')->whereNotIn('orders.purchase_status', ['draft', 'submitted', 'cancelled']);
        if (! empty($filters['supplier_id'])) $query->where('orders.supplier_id', $filters['supplier_id']);
        if (! empty($filters['currency'])) $query->where('orders.currency', $filters['currency']);
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) $query->where(fn ($q) => $q->where('orders.purchase_order_no', 'like', '%'.$keyword.'%')->orWhere('supplier.supplier_name', 'like', '%'.$keyword.'%')->orWhere('supplier.supplier_code', 'like', '%'.$keyword.'%'));
        foreach (['order_date_start' => '>=', 'order_date_end' => '<='] as $field => $operator) if (! empty($filters[$field])) $query->whereDate('orders.order_date', $operator, $filters[$field]);
        if (! empty($filters['trigger_type']) || ! empty($filters['due_date_start']) || ! empty($filters['due_date_end'])) {
            // These dates select orders with a matching arrangement. Monetary
            // columns intentionally remain lifetime totals for the selected orders.
            $query->whereExists(function ($due) use ($filters): void {
                $due->selectRaw('1')->from('erp_purchase_payment_plan_items as due_plan')
                    ->whereColumn('due_plan.purchase_order_id', 'orders.id')->where('due_plan.status', 'active');
                if (! empty($filters['trigger_type'])) $due->where('due_plan.trigger_type', $filters['trigger_type']);
                if (! empty($filters['due_date_start'])) $due->whereDate('due_plan.due_date', '>=', $filters['due_date_start']);
                if (! empty($filters['due_date_end'])) $due->whereDate('due_plan.due_date', '<=', $filters['due_date_end']);
            });
        }
        $result = DB::query()->fromSub($query, 'payment_orders')->select('payment_orders.*');
        if (! empty($filters['payment_status'])) $result->where('payment_status', $filters['payment_status']);
        return $result;
    }

    private function planTotalsQuery(): Builder
    {
        return DB::table('erp_finance_cash_purchase_allocations as purpose')
            ->join('erp_finance_cash_documents as cash', 'cash.id', '=', 'purpose.cash_document_id')
            ->where('purpose.status', 'active')->where('cash.status', 'confirmed')->whereNotNull('purpose.payment_plan_id')
            ->select('purpose.payment_plan_id')
            ->selectRaw('SUM(CASE WHEN cash.direction = "payment" THEN purpose.amount ELSE 0 END) AS paid_amount,
                SUM(CASE WHEN cash.direction = "receipt" THEN purpose.amount ELSE 0 END) AS refund_amount')
            ->groupBy('purpose.payment_plan_id');
    }

    private function project(array $row): array
    {
        foreach (self::SUMMARY_FIELDS as $field) $row[$field] = Money::normalize((string) ($row[$field] ?? '0'));
        foreach (['id', 'purchase_order_id', 'supplier_id', 'payment_plan_version'] as $field) if (isset($row[$field])) $row[$field] = (int) $row[$field];
        $row['trigger_types'] = empty($row['trigger_types']) ? [] : explode(',', $row['trigger_types']);
        return $row;
    }
}
