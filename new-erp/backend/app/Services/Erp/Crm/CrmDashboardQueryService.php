<?php

namespace App\Services\Erp\Crm;

use App\Models\Erp\SalesCustomer;
use App\Services\Erp\ErpUserProjectionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** 当前结构、期间事实和订单金额分别取数，所有名单复用这里的查询。 */
final class CrmDashboardQueryService
{
    public const DIMENSIONS = ['industry' => '行业', 'purchase_scale' => '采购力', 'customer_level' => '客户等级',
        'region' => '地区', 'business_line' => '业务线', 'customer_pool' => '客户池', 'customer_tag' => '客户标签',
        'custom_tag' => '个人标签', 'first_source' => '首次来源', 'inquiry_source' => '本次咨询来源', 'entry_method' => '录入方式'];
    private const CUSTOMER_FIELDS = ['industry' => 'industry', 'purchase_scale' => 'purchasing_power',
        'customer_level' => 'customer_grade', 'region' => 'province', 'business_line' => 'business_lines', 'first_source' => 'first_source'];
    private const LEAD_FIELDS = ['customer_pool' => 'customer_pool', 'customer_tag' => 'customer_tags',
        'custom_tag' => 'custom_tags', 'inquiry_source' => 'source_channel', 'entry_method' => 'entry_method'];

    public function __construct(
        private readonly CrmDashboardScopeService $scope,
        private readonly CrmOrderAmountQueryService $orderAmounts,
        private readonly CrmCustomerAccessService $customers,
        private readonly CrmOpportunityApplicationService $opportunities,
        private readonly CrmFollowupApplicationService $followups,
        private readonly ErpUserProjectionService $users,
    ) {}

    public function metadata(object $actor, array $filters): array
    {
        $departmentIds = $this->scope->visibleDepartments($actor);
        $departments = DB::table('erp_departments')->whereIn('legacy_id', $departmentIds)->orderBy('sort')->orderBy('legacy_id')
            ->get(['legacy_id as id', 'parent_legacy_id as parent_id', 'name'])->all();
        $lines = $this->memberships($actor, $this->withoutAttribution($filters), 'business_line');
        $currencies = [];
        foreach ([$this->scope->orders($actor, []), $this->scope->opportunities($actor), $this->scope->leads($actor)] as $query) {
            if ($query) $currencies = array_merge($currencies, $query->distinct()->pluck('currency')->all());
        }
        return ['departments' => $departments, 'business_lines' => $lines ? $lines->whereNotNull('dimension_value')->distinct()->orderBy('dimension_value')->pluck('dimension_value')->all() : [],
            'currencies' => array_values(array_unique($currencies)), 'dimensions' => collect(self::DIMENSIONS)->map(fn ($label, $value) => compact('value', 'label'))->values()->all(),
            'defaults' => ['date_from' => $filters['date_from'], 'date_to' => $filters['date_to'], 'per_page' => 10],
            'abilities' => $this->scope->abilities($actor), 'metadata' => $this->basis()];
    }

    public function salesOptions(object $actor, array $filters): array
    {
        $query = DB::table('erp_legacy_admin_users as u')->whereIn('u.legacy_id', $this->scope->salesIds($actor, $filters)->whereNotNull('sales_id'));
        if (!empty($filters['ids'])) $query->whereIn('u.legacy_id', $filters['ids']);
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) $query->where(fn ($q) => $q->where('u.nickname', 'like', "%{$keyword}%")->orWhere('u.username', 'like', "%{$keyword}%"));
        return $query->orderBy('u.legacy_id')->paginate((int) ($filters['per_page'] ?? 10), ['u.legacy_id as id', DB::raw("COALESCE(NULLIF(u.nickname, ''), u.username) AS name")], 'page', (int) ($filters['page'] ?? 1))->toArray();
    }

    public function overview(object $actor, array $filters): array
    {
        $metrics = [];
        $drilldowns = [];
        foreach (['customers', 'new_customers', 'first_contacts', 'open_opportunities', 'won_customers', 'contacted_customers'] as $kind) {
            $query = $this->metricQuery($actor, $filters, $kind);
            $metrics[$kind] = $query?->count();
            $drilldowns[$kind] = ['kind' => $kind];
        }
        $orders = $this->scope->orders($actor, $filters);
        return ['metrics' => $metrics, 'metric_drilldowns' => $drilldowns,
            'expected_amounts' => $this->expected($this->scope->opportunities($actor, $filters)),
            'money_summary' => $orders ? $this->orderAmounts->summaryForQuery($orders, $actor) : null,
            'sales_ranking' => $this->salesPage($actor, array_replace($filters, ['per_page' => 5, 'page' => 1]), false, true)['data'],
            'abilities' => $this->scope->abilities($actor), 'metadata' => $this->basis()];
    }

    public function sales(object $actor, array $filters): array
    {
        return $this->salesPage($actor, $filters, true);
    }

    private function salesPage(object $actor, array $filters, bool $summary, bool $rank = false): array
    {
        $people = $this->scope->salesIds($actor, $filters)->select('dashboard_sales.sales_id');
        $customers = $this->scope->customers($actor, $filters);
        if ($rank && $customers) {
            $counts = $customers->selectRaw('owner_legacy_id AS sales_id, COUNT(*) AS customer_count')->groupBy('owner_legacy_id');
            $people->leftJoinSub($counts->toBase(), 'ranking', fn ($join) => $join->whereRaw('ranking.sales_id <=> dashboard_sales.sales_id'))
                ->addSelect('ranking.customer_count as ranking_customer_count')->orderByDesc('ranking_customer_count');
        }
        $page = $people->orderByRaw('dashboard_sales.sales_id IS NULL')->orderBy('dashboard_sales.sales_id')
            ->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
        $names = $this->users->many($page->getCollection()->pluck('sales_id')->all());
        $funnels = $rank ? [] : app(CrmDashboardPipelineService::class)->funnelsBySales($actor, $filters);
        $overdue = app(CrmDashboardPipelineService::class)->overdueBySales($actor, $filters);
        $abilities = $this->scope->abilities($actor);
        $canContact = $abilities['customers'] || $abilities['leads'] || $abilities['opportunities'];
        $page->through(function ($person) use ($actor, $filters, $names, $funnels, $overdue, $canContact): array {
            $id = $person->sales_id === null ? null : (int) $person->sales_id;
            $personal = $this->personFilters($filters, $id);
            $customers = $this->scope->customers($actor, $personal);
            $opportunities = $this->scope->opportunities($actor, $personal);
            $orders = $this->scope->orders($actor, $personal);
            $new = $customers ? $this->scope->period($customers, $personal, 'created_at')->count() : null;
            return ['sales_user_legacy_id' => $id, 'sales_user_name' => $names[$id]['display_name'] ?? null,
                'customer_count' => $customers ? (clone $customers)->count() : null, 'new_customer_count' => $new,
                'opportunity_count' => $opportunities ? (clone $opportunities)->count() : null,
                'open_opportunity_count' => $opportunities ? (clone $opportunities)->where('status', 'open')->count() : null,
                'expected_amounts' => $this->expected($opportunities),
                'money_summary' => $orders ? $this->orderAmounts->summaryForQuery($orders, $actor) : null,
                'funnel' => $funnels[$id ?? ''] ?? null,
                'overdue_followup_count' => $canContact ? (int) ($overdue[$id ?? ''] ?? 0) : null,
                'drilldown' => ['kind' => 'customers'] + ($id ? ['sales_user_legacy_id' => $id] : ['sales_user_unassigned' => true])];
        });
        $result = $page->toArray() + ['abilities' => $this->scope->abilities($actor), 'metadata' => $this->basis()];
        if ($summary) {
            $orders = $this->scope->orders($actor, $filters);
            $customers = $this->scope->customers($actor, $filters);
            $opportunities = $this->scope->opportunities($actor, $filters);
            $result['summary'] = ['customer_count' => $customers ? (clone $customers)->count() : null,
                'new_customer_count' => $customers ? $this->scope->period($customers, $filters, 'created_at')->count() : null,
                'open_opportunity_count' => $opportunities ? (clone $opportunities)->where('status', 'open')->count() : null,
                'expected_amounts' => $this->expected($opportunities), 'overdue_followup_count' => $canContact ? array_sum($overdue) : null,
                'money_summary' => $orders ? $this->orderAmounts->summaryForQuery($orders, $actor) : null];
        }
        return $result;
    }

    public function dimensions(object $actor, array $filters): array
    {
        if (!empty($filters['cross_sales'])) return $this->crossDimensions($actor, $filters);
        $dimension = $filters['dimension'] ?? 'industry';
        $members = $this->memberships($actor, $filters, $dimension);
        abort_unless($members, 403, '没有查看该客户统计的原模块权限。');
        $groups = (clone $members)->select('dimension_value');
        $groups->distinct();
        $orderMembers = $this->orderMemberships($actor, $filters, $dimension);
        if ($orderMembers) {
            $orderGroups = (clone $orderMembers)->select('dimension_value');
            $groups->union($orderGroups->distinct());
        }
        $opportunities = $this->scope->opportunities($actor, $filters);
        $opportunityMembers = $this->memberships($actor, $this->withoutAttribution($filters), $dimension);
        if ($opportunities && $opportunityMembers) {
            $opportunityGroups = DB::query()->fromSub($opportunities->where('status', 'open')->select('customer_id')->toBase(), 'opportunity_customers')
                ->joinSub($opportunityMembers, 'opportunity_dimensions', 'opportunity_dimensions.customer_id', '=', 'opportunity_customers.customer_id')
                ->select('dimension_value')->distinct();
            $groups->union($opportunityGroups);
        }
        $query = DB::query()->fromSub($groups, 'dimension_groups')->orderByRaw('dimension_value IS NULL')->orderBy('dimension_value');
        $page = $query->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
        $page->through(function ($group) use ($actor, $filters, $dimension, $members): array {
            $matched = $this->matchValue(clone $members, $group->dimension_value);
            $drilldown = ['kind' => 'dimension_customers', 'dimension' => $dimension, 'value' => $group->dimension_value,
                'value_is_null' => $group->dimension_value === null];
            $opportunities = $this->dimensionOpportunities($actor, $filters, $dimension, $group->dimension_value);
            $row = ['value' => $group->dimension_value, 'label' => $group->dimension_value === null ? '未填写' : $group->dimension_value,
                'customer_count' => $matched->distinct()->count('customer_id'), 'money_summary' => null,
                'open_opportunity_count' => $opportunities ? (clone $opportunities)->count() : null,
                'expected_amounts' => $this->expected($opportunities),
                'opportunity_drilldown' => array_replace($drilldown, ['kind' => 'dimension_opportunities'])];
            $orders = $this->dimensionOrders($actor, $filters, $dimension, $group->dimension_value);
            if ($orders) $row['money_summary'] = $this->orderAmounts->summaryForQuery($orders, $actor);
            return $row + ['drilldown' => $drilldown];
        });
        return $page->toArray() + ['summary' => $this->dimensionSummary($actor, $filters, $dimension, $members),
            'abilities' => $this->scope->abilities($actor), 'metadata' => $this->basis() + ['dimension_attribution' => 'current_customer_attributes', 'multi_value_groups_are_not_additive' => true]];
    }

    public function sources(object $actor, array $filters): array
    {
        return $this->dimensions($actor, array_replace($filters, ['dimension' => $filters['dimension'] ?? 'first_source']));
    }

    private function crossDimensions(object $actor, array $filters): array
    {
        $dimension = $filters['dimension'] ?? 'industry';
        $members = $this->memberships($actor, $filters, $dimension);
        abort_unless($members, 403, '没有查看该客户统计的原模块权限。');
        $columns = (clone $members)->select('dimension_value')->groupBy('dimension_value')->orderByRaw('dimension_value IS NULL')->orderBy('dimension_value')
            ->paginate((int) ($filters['dimension_per_page'] ?? 10), ['dimension_value'], 'dimension_page', (int) ($filters['dimension_page'] ?? 1));
        $page = $this->scope->salesIds($actor, $filters)->orderByRaw('sales_id IS NULL')->orderBy('sales_id')
            ->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
        $ids = $page->getCollection()->pluck('sales_id');
        $names = $this->users->many($ids->all());
        $pageMembers = (clone $members)->where(function ($q) use ($ids): void {
            $q->whereIn('sales_id', $ids->filter(fn ($id) => $id !== null)->all());
            if ($ids->contains(null)) $q->orWhereNull('sales_id');
        });
        $totals = (clone $pageMembers)->select('sales_id')->selectRaw('COUNT(DISTINCT customer_id) AS total')->groupBy('sales_id')->get()->keyBy(fn ($row) => $row->sales_id ?? '');
        $values = $columns->getCollection()->pluck('dimension_value');
        $cells = $pageMembers->where(function ($q) use ($values): void {
            $q->whereIn('dimension_value', $values->filter(fn ($value) => $value !== null)->all());
            if ($values->contains(null)) $q->orWhereNull('dimension_value');
        })->select(['sales_id', 'dimension_value'])->selectRaw('COUNT(DISTINCT customer_id) AS total')->groupBy('sales_id', 'dimension_value')->get();
        $counts = [];
        foreach ($cells as $cell) $counts[$cell->sales_id ?? ''][json_encode($cell->dimension_value)] = (int) $cell->total;
        $columnRows = $values->map(fn ($value) => ['value' => $value, 'label' => $value ?? '未填写'])->all();
        $page->through(function ($person) use ($dimension, $columnRows, $names, $totals, $counts): array {
            $id = $person->sales_id === null ? null : (int) $person->sales_id;
            $sales = $id === null ? ['sales_user_unassigned' => true] : ['sales_user_legacy_id' => $id];
            return ['sales_user_legacy_id' => $id, 'sales_user_name' => $names[$id]['display_name'] ?? null,
                'customer_count' => (int) ($totals[$id ?? '']->total ?? 0),
                'drilldown' => ['kind' => 'dimension_all_customers', 'dimension' => $dimension] + $sales,
                'cells' => array_map(fn ($column) => $column + ['customer_count' => $counts[$id ?? ''][json_encode($column['value'])] ?? 0,
                    'drilldown' => ['kind' => 'dimension_customers', 'dimension' => $dimension, 'value' => $column['value'],
                        'value_is_null' => $column['value'] === null, 'cross_sales' => true] + $sales], $columnRows)];
        });
        return $page->toArray() + ['columns' => $columnRows, 'dimension_pagination' => [
            'current_page' => $columns->currentPage(), 'per_page' => $columns->perPage(), 'total' => $columns->total(), 'last_page' => $columns->lastPage()],
            'summary' => $this->dimensionSummary($actor, $filters, $dimension, $members),
            'abilities' => $this->scope->abilities($actor), 'metadata' => $this->basis() + ['multi_value_groups_are_not_additive' => true]];
    }

    public function income(object $actor, array $filters): array
    {
        return $this->salesPage($actor, $filters, true);
    }

    public function orders(object $actor, array $filters): array
    {
        $query = $this->scope->orders($actor, $filters);
        abort_unless($query, 403, '没有查看销售订单的权限。');
        if (!empty($filters['customer_id'])) {
            $customers = $this->scope->customers($actor, $this->withoutAttribution($filters));
            abort_unless($customers && $customers->whereKey($filters['customer_id'])->exists(), 403, '没有查看该客户的权限。');
            $query->where('customer_id', $filters['customer_id']);
        }
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) $query->where(fn ($q) => $q->where('sales_order_no', 'like', "%{$keyword}%")->orWhere('customer_name', 'like', "%{$keyword}%"));
        return $this->orderAmounts->scopedOrders($query, $filters, $actor) + ['metadata' => $this->basis()];
    }

    public function drilldown(object $actor, array $filters): array
    {
        $kind = $filters['kind'];
        if (app(CrmDashboardPipelineService::class)->handles($kind)) {
            $page = app(CrmDashboardPipelineService::class)->drilldown($actor, $filters);
            return is_array($page) ? $page : $page->toArray();
        }
        if ($kind === 'orders') return $this->orders($actor, $filters);
        if (in_array($kind, ['dimension_all_customers', 'dimension_all_opportunities', 'dimension_all_orders'], true)) {
            abort_unless(isset($filters['dimension']), 422, '请选择有效的统计维度。');
            $members = $this->memberships($actor, $kind === 'dimension_all_customers' ? $filters : $this->withoutAttribution($filters), $filters['dimension']);
            abort_unless($members, 403, '没有查看该客户统计的原模块权限。');
            if ($kind === 'dimension_all_orders') {
                $query = $this->scope->orders($actor, $filters);
                abort_unless($query, 403, '没有查看销售订单的权限。');
                return $this->orderAmounts->scopedOrders($query->whereIn('customer_id', $members->select('customer_id')), $filters, $actor) + ['metadata' => $this->basis()];
            }
            $query = $kind === 'dimension_all_opportunities'
                ? $this->scope->opportunities($actor, $filters)?->where('status', 'open')->whereIn('customer_id', $members->select('customer_id'))
                : $this->scope->customers($actor, $filters)->whereIn('id', $members->select('customer_id'));
        } elseif (in_array($kind, ['dimension_customers', 'dimension_orders', 'dimension_opportunities'], true)) {
            abort_unless(isset($filters['dimension']) && (array_key_exists('value', $filters) || !empty($filters['value_is_null'])), 422, '请选择有效的统计分组。');
            $value = !empty($filters['value_is_null']) ? null : $filters['value'];
            $members = $this->memberships($actor, $filters, $filters['dimension']);
            abort_unless($members, 403, '没有查看该客户统计的原模块权限。');
            $matched = $this->matchValue($members, $value);
            $available = $this->memberships($actor, $this->withoutAttribution($filters), $filters['dimension']);
            if (!$available || !$this->matchValue($available, $value)->exists()) throw ValidationException::withMessages(['value' => '该分组不存在或已不在当前可见范围内。']);
            if ($kind === 'dimension_orders') {
                $query = $this->dimensionOrders($actor, $filters, $filters['dimension'], $value);
                abort_unless($query, 403, '没有查看销售订单的权限。');
                return $this->orderAmounts->scopedOrders($query, $filters, $actor) + ['metadata' => $this->basis()];
            }
            if ($kind === 'dimension_opportunities') {
                $query = $this->dimensionOpportunities($actor, $filters, $filters['dimension'], $value);
            } else {
                $query = $this->scope->customers($actor, $filters)->whereIn('id', $matched->select('customer_id'));
            }
        } elseif ($kind === 'followup_records') {
            $query = $this->scope->followups($actor, $filters);
            if ($query) $query = $this->scope->period($query, $filters, 'contacted_at');
        } else {
            abort_unless(in_array($kind, ['customers', 'new_customers', 'first_contacts', 'won_customers', 'contacted_customers', 'opportunities', 'open_opportunities'], true), 422, '统计名单类型无效。');
            $query = $this->metricQuery($actor, $filters, $kind);
        }
        abort_unless($query, 403, '没有查看该名单的原模块权限。');
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) {
            $field = $kind === 'followup_records' ? 'content' : ($query->getModel() instanceof SalesCustomer ? 'customer_name' : 'name');
            $query->where($field, 'like', "%{$keyword}%");
        }
        $page = $query->orderByDesc($query->qualifyColumn('id'))->paginate((int) ($filters['per_page'] ?? 10));
        $page->through(function ($item) use ($actor, $kind): array {
            if ($item instanceof SalesCustomer) return $item->only(['id', 'customer_code', 'customer_name', 'owner_name', 'owner_legacy_id', 'industry', 'purchasing_power', 'customer_grade', 'first_contact_at', 'last_contact_at', 'next_contact_at', 'province', 'city', 'district'])
                + ['target_type' => 'customer', 'target_id' => $item->id, 'allowed' => $this->customers->abilities($item, $actor)];
            if ($kind === 'followup_records') return $this->followups->present($item, $actor) + ['target_type' => $item->subject_type, 'target_id' => $item->subject_id, 'allowed' => ['view' => true]];
            return $this->opportunities->present($item, $actor) + ['target_type' => 'opportunity', 'target_id' => $item->id, 'allowed' => $this->opportunities->abilities($item, $actor)];
        });
        return $page->toArray() + ['metadata' => $this->basis()];
    }

    private function metricQuery(object $actor, array $filters, string $kind): ?Builder
    {
        if (in_array($kind, ['opportunities', 'open_opportunities'], true)) {
            $query = $this->scope->opportunities($actor, $filters);
            return $kind === 'open_opportunities' ? $query?->where('status', 'open') : $query;
        }
        $query = $this->scope->customers($actor, $filters);
        if (!$query) return null;
        if ($kind === 'new_customers') return $this->scope->period($query, $filters, 'created_at');
        if ($kind === 'first_contacts') return $this->scope->period($query, array_replace($filters,
            ['date_from' => today()->subDays(6)->toDateString(), 'date_to' => today()->toDateString()]), 'first_contact_at');
        if ($kind === 'won_customers') {
            $orders = $this->scope->orders($actor, $filters);
            return $orders ? $query->whereIn('id', $orders->select('customer_id')->whereNotNull('customer_id')) : null;
        }
        if ($kind === 'contacted_customers') {
            $followups = $this->scope->followups($actor, $filters);
            return $followups ? $query->whereIn('id', $this->scope->period($followups, $filters, 'contacted_at')->select('customer_id')->whereNotNull('customer_id')) : null;
        }
        return $query;
    }

    private function memberships(object $actor, array $filters, string $dimension): ?QueryBuilder
    {
        abort_unless(isset(self::DIMENSIONS[$dimension]), 422, '统计维度无效。');
        $customers = $this->scope->customers($actor, $filters);
        if (!$customers) return null;
        $field = self::CUSTOMER_FIELDS[$dimension] ?? null;
        $customerFields = ['id', 'owner_legacy_id'];
        if ($field) $customerFields[] = $field;
        $query = DB::query()->fromSub($customers->select($customerFields)->toBase(), 'c');
        $source = 'c';
        if (!$field) {
            $field = self::LEAD_FIELDS[$dimension];
            $leads = $this->scope->leads($actor, $this->withoutAttribution($filters));
            if (!$leads) return null;
            if ($dimension === 'custom_tag') $leads->where('created_by_legacy_id', $actor->legacy_id);
            $query->joinSub($leads->select(['customer_id', $field])->toBase(), 'l', 'l.customer_id', '=', 'c.id');
            $source = 'l';
        }
        if (in_array($dimension, ['business_line', 'customer_tag', 'custom_tag'], true)) {
            $column = "$source.$field";
            $json = "JSON_TABLE(IF(JSON_TYPE($column) = 'ARRAY', $column, JSON_ARRAY()), '$[*]' COLUMNS (dimension_value VARCHAR(250) PATH '$')) AS dimension_values";
            $query->leftJoin(DB::raw($json), DB::raw('1'), '=', DB::raw('1'));
            $value = 'dimension_values.dimension_value';
        } else $value = "$source.$field";
        return DB::query()->fromSub($query->selectRaw("c.id AS customer_id, c.owner_legacy_id AS sales_id, NULLIF($value, '') AS dimension_value")->distinct(), 'dimension_members');
    }

    private function orderMemberships(object $actor, array $filters, string $dimension): ?QueryBuilder
    {
        $orders = $this->scope->orders($actor, $filters);
        $members = $this->memberships($actor, $this->withoutAttribution($filters), $dimension);
        if (!$orders || !$members) return null;
        return DB::query()->fromSub($orders->select(['id', 'customer_id', 'sales_user_legacy_id'])->toBase(), 'o')
            ->joinSub($members, 'm', 'm.customer_id', '=', 'o.customer_id')
            ->selectRaw('o.id AS order_id, o.sales_user_legacy_id AS sales_id, m.dimension_value')->distinct();
    }

    private function dimensionOrders(object $actor, array $filters, string $dimension, mixed $value): ?Builder
    {
        $orders = $this->scope->orders($actor, $filters);
        $members = $this->memberships($actor, $this->withoutAttribution($filters), $dimension);
        return $orders && $members ? $orders->whereIn('customer_id', $this->matchValue($members, $value)->select('customer_id')) : null;
    }

    private function dimensionOpportunities(object $actor, array $filters, string $dimension, mixed $value): ?Builder
    {
        $opportunities = $this->scope->opportunities($actor, $filters);
        $members = $this->memberships($actor, $this->withoutAttribution($filters), $dimension);
        return $opportunities && $members ? $opportunities->where('status', 'open')->whereIn('customer_id', $this->matchValue($members, $value)->select('customer_id')) : null;
    }

    private function dimensionSummary(object $actor, array $filters, string $dimension, QueryBuilder $members): array
    {
        $eligible = $this->memberships($actor, $this->withoutAttribution($filters), $dimension);
        $orders = $this->scope->orders($actor, $filters);
        $opportunities = $this->scope->opportunities($actor, $filters);
        if ($orders && $eligible) $orders->whereIn('customer_id', (clone $eligible)->select('customer_id'));
        if ($opportunities && $eligible) $opportunities->whereIn('customer_id', (clone $eligible)->select('customer_id'));
        return ['customer_count' => (clone $members)->distinct()->count('customer_id'),
            'open_opportunity_count' => $opportunities ? (clone $opportunities)->where('status', 'open')->count() : null,
            'expected_amounts' => $this->expected($opportunities),
            'drilldowns' => ['customers' => ['kind' => 'dimension_all_customers', 'dimension' => $dimension],
                'opportunities' => ['kind' => 'dimension_all_opportunities', 'dimension' => $dimension],
                'orders' => ['kind' => 'dimension_all_orders', 'dimension' => $dimension]],
            'money_summary' => $orders ? $this->orderAmounts->summaryForQuery($orders, $actor) : null];
    }

    private function matchValue(QueryBuilder $query, mixed $value): QueryBuilder
    {
        return $value === null ? $query->whereNull('dimension_value') : $query->where('dimension_value', $value);
    }

    private function expected(?Builder $query): ?array
    {
        if (!$query) return null;
        return (clone $query)->where('status', 'open')->select('currency')
            ->selectRaw('CASE WHEN COUNT(*) = COUNT(expected_amount) THEN SUM(expected_amount) ELSE NULL END AS amount, SUM(expected_amount IS NULL) AS unknown_count')
            ->groupBy('currency')->orderBy('currency')->get()->map(fn ($row) => ['currency' => $row->currency, 'amount' => $row->amount, 'unknown_count' => (int) $row->unknown_count])->all();
    }

    private function personFilters(array $filters, ?int $id): array
    {
        unset($filters['sales_user_legacy_id'], $filters['sales_user_unassigned']);
        return $filters + ($id === null ? ['sales_user_unassigned' => true] : ['sales_user_legacy_id' => $id]);
    }

    private function withoutAttribution(array $filters): array
    {
        unset($filters['department_legacy_id'], $filters['group_legacy_id'], $filters['sales_user_legacy_id'], $filters['sales_user_unassigned']);
        return $filters;
    }

    private function basis(): array
    {
        return ['amount_basis' => 'current_facts_for_orders_selected_by_order_date', 'amount_as_of' => now()->toIso8601String(),
            'amount_observed_on' => today()->toDateString(),
            'historical_snapshot' => false, 'customer_attribution' => 'current_owner', 'opportunity_attribution' => 'current_owner',
            'order_attribution' => 'original_sales_user_and_department', 'business_line_basis' => 'orders_via_visible_linked_opportunities',
            'structure_basis' => 'current_facts', 'period_basis' => 'new_customers_first_contacts_actual_contacts_and_order_date'];
    }
}
