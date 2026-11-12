<?php

namespace App\Services\Erp\Crm;

use App\Models\Erp\CrmOpportunity;
use App\Models\Erp\SalesCustomer;
use App\Models\Erp\SalesOrder;
use App\Services\Erp\ErpUserProjectionService;
use App\Services\Erp\SalesOrderVisibilityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Domain\Finance\Money;

/** 金额和下钻共用原订单读取范围，不随客户当前负责人或协作权限扩大。 */
final class CrmOrderAmountQueryService
{
    public function __construct(
        private readonly CrmCustomerAccessService $customers,
        private readonly SalesOrderVisibilityService $visibility,
        private readonly CrmOrderAmountService $amounts,
        private readonly ErpUserProjectionService $users,
        private readonly CrmOpportunityApplicationService $opportunities,
    ) {}

    public function abilities(object $actor): array
    {
        $orders = $this->customers->can($actor, 'sales_order.view');
        return ['view_orders' => $orders, 'view_amounts' => $orders && $this->customers->can($actor, 'sales_order.amount.view')];
    }

    /** 调用方只传已按客户范围过滤的当前页，选择器不调用本方法。 */
    public function forCustomers(Collection $customers, object $actor): array
    {
        $abilities = $this->abilities($actor);
        $result = $customers->mapWithKeys(fn (SalesCustomer $customer) => [$customer->id => [
            'money_summary' => null, 'money_abilities' => $abilities,
        ]])->all();
        if (!$abilities['view_orders'] || $customers->isEmpty()) return $result;

        return DB::transaction(function () use ($customers, $actor, $abilities, $result): array {
            $query = $this->query([], $actor)->whereIn('customer_id', $customers->pluck('id'));
            $summaries = $abilities['view_amounts'] ? $this->groupedSummaries($query, 'customer_id') : [];
            $counts = $abilities['view_amounts'] ? [] : (clone $query)->select('customer_id')
                ->selectRaw('COUNT(*) as order_count')->groupBy('customer_id')->pluck('order_count', 'customer_id')->all();
            foreach ($result as $id => &$row) {
                $row['money_summary'] = $abilities['view_amounts']
                    ? $this->presentSummary($summaries[$id] ?? $this->amounts->summarize([]))
                    : ['currencies' => [], 'order_count' => (int) ($counts[$id] ?? 0)];
            }
            return $result;
        });
    }

    public function orders(array $filters, object $actor): array
    {
        $this->assertView($actor);
        return DB::transaction(fn () => $this->orderPage($this->query($filters, $actor), $filters, $actor));
    }

    /** 看板传入已与原订单权限相交的查询，继续复用同一金额投影及分页。 */
    public function scopedOrders(Builder $query, array $filters, object $actor): array
    {
        $this->assertView($actor);
        if (!empty($filters['outstanding_status'])) {
            abort_unless($this->abilities($actor)['view_amounts'], 403, '没有查看订单金额的权限。');
            return $this->filteredOrderPage($query, $filters, $actor);
        }
        return $this->orderPage($query, $filters, $actor);
    }

    public function summaryForQuery(Builder $query, object $actor): ?array
    {
        return $this->abilities($actor)['view_amounts'] ? $this->summary($query, true) : null;
    }

    public function customerOrders(int $customerId, array $filters, object $actor): array
    {
        $this->assertView($actor);
        return DB::transaction(function () use ($customerId, $filters, $actor): array {
            $customer = SalesCustomer::query()->findOrFail($customerId);
            $this->customers->assert($customer, $actor, 'view', '该客户不在你的可见范围内。');
            $query = $this->query($filters, $actor)->where('customer_id', $customerId);
            return $this->orderPage($query, $filters, $actor);
        });
    }

    public function people(array $filters, object $actor): array
    {
        $this->assertView($actor);
        return DB::transaction(function () use ($filters, $actor): array {
            $query = $this->query($filters, $actor);
            $abilities = $this->abilities($actor);
            $page = (clone $query)->select('sales_user_legacy_id')->selectRaw('COUNT(*) as order_count')
                ->groupBy('sales_user_legacy_id')->orderByRaw('sales_user_legacy_id IS NULL')
                ->orderBy('sales_user_legacy_id')->paginate((int) ($filters['per_page'] ?? 10));
            $ids = $page->getCollection()->pluck('sales_user_legacy_id');
            $pageOrders = (clone $query)->where(function ($scoped) use ($ids): void {
                $scoped->whereIn('sales_user_legacy_id', $ids->filter(fn ($id) => $id !== null)->all());
                if ($ids->contains(null)) $scoped->orWhereNull('sales_user_legacy_id');
            });
            $names = $this->users->many($ids->all());
            $summaries = $abilities['view_amounts'] ? $this->groupedSummaries($pageOrders, 'sales_user_legacy_id') : [];
            $departments = (clone $pageOrders)->select('sales_user_legacy_id', 'sales_department_id')->distinct()->get()->groupBy('sales_user_legacy_id');
            $page->through(function (SalesOrder $person) use ($names, $summaries, $departments, $abilities): array {
                $id = $person->sales_user_legacy_id;
                $departmentIds = $departments->get($id ?? '', collect())->pluck('sales_department_id')
                    ->map(fn ($department) => $department ? (int) $department : null)->values()->all();
                $summary = $abilities['view_amounts'] ? $this->presentSummary($summaries[$id ?? ''] ?? $this->amounts->summarize([]))
                    : ['currencies' => [], 'order_count' => (int) $person->order_count];
                return [
                    'sales_user_legacy_id' => $id === null ? null : (int) $id,
                    'sales_user_name' => $names[$id]['display_name'] ?? null,
                    'sales_department_ids' => $departmentIds,
                    'attribution_needs_verification' => !$id || in_array(null, $departmentIds, true),
                ] + $summary;
            });
            return $page->toArray() + ['summary' => $this->summary($query, $abilities['view_amounts']), 'abilities' => $abilities];
        });
    }

    private function orderPage(Builder $query, array $filters, object $actor, ?LengthAwarePaginator $selectedPage = null, ?array $selectedSummary = null): array
    {
        $abilities = $this->abilities($actor);
        $page = $selectedPage ?? (clone $query)->orderByDesc('order_date')->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 10));
        $orders = $page->getCollection();
        $names = $this->users->many($orders->pluck('sales_user_legacy_id')->all());
        $opportunityNames = $this->opportunityNames($orders, $actor);
        $amounts = $abilities['view_amounts'] ? $this->amounts->forOrders($orders) : [];
        $delivery = app(\App\Services\Erp\Manufacturing\WorkOrderReadAccessService::class)->canView($actor);
        $page->through(function (SalesOrder $order) use ($names, $opportunityNames, $amounts, $abilities, $delivery): array {
            $row = [
                'id' => $order->id, 'sales_order_no' => $order->sales_order_no,
                'target_type' => 'order', 'target_id' => $order->id, 'allowed' => ['view' => true],
                'customer_name' => $order->customer_name_snapshot ?: $order->customer_name,
                'order_date' => $order->order_date?->format('Y-m-d'),
                'sales_user_legacy_id' => $order->sales_user_legacy_id ? (int) $order->sales_user_legacy_id : null,
                'sales_user_name' => $names[$order->sales_user_legacy_id]['display_name'] ?? null,
                'opportunity_name' => $opportunityNames[$order->id] ?? null,
                'order_status' => $order->order_status, 'fulfillment_status' => $order->fulfillment_status,
                'currency' => $order->currency, 'can_view' => true,
                'can_view_delivery' => $delivery,
            ];
            if ($abilities['view_amounts']) $row += array_intersect_key($amounts[$order->id], array_flip([
                'deal_amount', 'received_amount', 'outstanding_amount', 'needs_verification', 'verification_reasons',
            ]));
            if ($abilities['view_amounts']) $row['currency'] = $amounts[$order->id]['currency'];
            return $row;
        });
        return $page->toArray() + ['summary' => $selectedSummary ?? $this->summary($query, $abilities['view_amounts']), 'abilities' => $abilities];
    }

    /** 金额状态依原事实逐单计算；只保留当前页，不能先分页再筛选导致总数错误。 */
    private function filteredOrderPage(Builder $query, array $filters, object $actor): array
    {
        $perPage = (int) ($filters['per_page'] ?? 10);
        $pageNumber = (int) ($filters['page'] ?? 1);
        $offset = ($pageNumber - 1) * $perPage;
        $selected = collect();
        $total = 0;
        $summary = $this->amounts->summarize([]);
        (clone $query)->orderByDesc('order_date')->orderByDesc('id')->chunk(200, function (Collection $orders) use ($filters, $offset, $perPage, &$selected, &$total, &$summary): void {
            $amounts = $this->amounts->forOrders($orders);
            $matched = [];
            foreach ($orders as $order) {
                $row = $amounts[$order->id];
                $outstanding = $row['outstanding_amount'];
                $matches = match ($filters['outstanding_status']) {
                    'verification' => $row['needs_verification'],
                    'outstanding' => $outstanding !== null && Money::compare($outstanding, '0') > 0,
                    'settled' => $outstanding !== null && Money::compare($outstanding, '0') === 0,
                    default => false,
                };
                if (!$matches) continue;
                if ($total >= $offset && $selected->count() < $perPage) $selected->push($order);
                $total++;
                $matched[] = $row;
            }
            $summary = $this->amounts->mergeSummaries([$summary, $this->amounts->summarize($matched)]);
        });
        $page = new LengthAwarePaginator($selected, $total, $perPage, $pageNumber, ['path' => LengthAwarePaginator::resolveCurrentPath()]);
        return $this->orderPage($query, $filters, $actor, $page, $this->presentSummary($summary));
    }

    private function query(array $filters, object $actor): Builder
    {
        $query = SalesOrder::query();
        $this->visibility->apply($query, $actor, true);
        $this->amounts->applyEffective($query);
        foreach (['sales_user_legacy_id', 'sales_department_id', 'customer_id'] as $field) {
            if (!empty($filters[$field])) $query->where($field, (int) $filters[$field]);
        }
        if (!empty($filters['date_from'])) $query->where('order_date', '>=', $filters['date_from']);
        if (!empty($filters['date_to'])) $query->where('order_date', '<=', $filters['date_to']);
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) {
            $query->where(fn ($match) => $match->where('sales_order_no', 'like', "%{$keyword}%")
                ->orWhere('legacy_order_no', 'like', "%{$keyword}%")->orWhere('customer_name', 'like', "%{$keyword}%")
                ->orWhere('customer_name_snapshot', 'like', "%{$keyword}%"));
        }
        return $query;
    }

    private function summary(Builder $query, bool $amounts): array
    {
        return $amounts ? $this->presentSummary($this->amounts->summarize($this->amountRows($query)))
            : ['currencies' => [], 'order_count' => (clone $query)->count()];
    }

    private function amountRows(Builder $query): \Generator
    {
        foreach ((clone $query)->lazyById(200)->chunk(200) as $orders) {
            foreach ($this->amounts->forOrders($orders->collect()) as $row) yield $row;
        }
    }

    public function groupedSummaries(Builder $query, string $field): array
    {
        $summaries = [];
        (clone $query)->chunkById(200, function (Collection $orders) use ($field, &$summaries): void {
            $amounts = $this->amounts->forOrders($orders);
            foreach ($orders->groupBy($field) as $id => $group) {
                $rows = $group->map(fn (SalesOrder $order) => $amounts[$order->id]);
                $summary = $this->amounts->summarize($rows);
                $summaries[$id] = isset($summaries[$id]) ? $this->amounts->mergeSummaries([$summaries[$id], $summary]) : $summary;
            }
        });
        return $summaries;
    }

    private function presentSummary(array $summary): array
    {
        return [
            'currencies' => array_map(fn (array $currency) => array_intersect_key($currency, array_flip([
                'currency', 'deal_amount', 'received_amount', 'outstanding_amount', 'order_count', 'verification_count',
            ])), $summary['currencies']),
            'order_count' => $summary['order_count'], 'verification_count' => $summary['verification_count'],
        ];
    }

    private function opportunityNames(Collection $orders, object $actor): array
    {
        if ($orders->isEmpty() || !$this->customers->can($actor, 'crm.opportunity.view')) return [];
        $query = CrmOpportunity::query()->join('erp_crm_opportunity_orders as amount_links', 'amount_links.opportunity_id', '=', 'erp_crm_opportunities.id')
            ->whereIn('amount_links.order_id', $orders->pluck('id'));
        $this->opportunities->applyVisible($query, $actor);
        return $query->pluck('erp_crm_opportunities.name', 'amount_links.order_id')->all();
    }

    private function assertView(object $actor): void
    {
        abort_unless($this->abilities($actor)['view_orders'], 403, '没有查看销售订单的权限。');
    }
}
