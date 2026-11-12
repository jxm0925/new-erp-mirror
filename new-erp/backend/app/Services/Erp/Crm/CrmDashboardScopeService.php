<?php

namespace App\Services\Erp\Crm;

use App\Models\Erp\CrmFollowup;
use App\Models\Erp\CrmLead;
use App\Models\Erp\CrmOpportunity;
use App\Models\Erp\SalesCustomer;
use App\Models\Erp\SalesOrder;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\SalesOrderVisibilityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** 看板只收窄原模块范围；当前客户、商机和历史订单各用自己的归属。 */
final class CrmDashboardScopeService
{
    private array $abilityCache = [];
    private array $departmentCache = [];
    public function __construct(
        private readonly CrmCustomerAccessService $access,
        private readonly CrmLeadApplicationService $leadAccess,
        private readonly CrmOpportunityApplicationService $opportunityAccess,
        private readonly SalesOrderVisibilityService $orderAccess,
        private readonly CrmOrderAmountService $amounts,
        private readonly AuthContextService $auth,
    ) {}

    public function abilities(object $actor): array
    {
        if (isset($this->abilityCache[$actor->legacy_id])) return $this->abilityCache[$actor->legacy_id];
        $orders = $this->access->can($actor, 'sales_order.view');
        return $this->abilityCache[$actor->legacy_id] = ['customers' => $this->access->can($actor, 'sales.customer'),
            'leads' => $this->access->can($actor, 'crm.lead.view'),
            'opportunities' => $this->access->can($actor, 'crm.opportunity.view'),
            'orders' => $orders, 'amounts' => $orders && $this->access->can($actor, 'sales_order.amount.view')];
    }

    public function customers(object $actor, array $filters = []): ?Builder
    {
        if (!$this->abilities($actor)['customers']) return null;
        $query = SalesCustomer::query();
        $this->access->applyVisible($query, $actor);
        $this->attribution($query, $filters, 'owner_legacy_id', 'owner_department_legacy_id');
        if (!empty($filters['business_line'])) $query->whereJsonContains('business_lines', $filters['business_line']);
        return $query;
    }

    public function leads(object $actor, array $filters = []): ?Builder
    {
        if (!$this->abilities($actor)['leads']) return null;
        $query = CrmLead::query();
        $this->leadAccess->applyVisible($query, $actor);
        $this->attribution($query, $filters, 'owner_legacy_id', 'department_legacy_id');
        if (!empty($filters['business_line'])) $query->whereJsonContains('business_lines', $filters['business_line']);
        if (!empty($filters['currency'])) $query->where('currency', $filters['currency']);
        return $query;
    }

    public function opportunities(object $actor, array $filters = []): ?Builder
    {
        if (!$this->abilities($actor)['opportunities']) return null;
        $query = CrmOpportunity::query();
        $this->opportunityAccess->applyVisible($query, $actor);
        $this->attribution($query, $filters, 'owner_legacy_id', 'department_legacy_id');
        if (!empty($filters['business_line'])) $query->where('business_line', $filters['business_line']);
        if (!empty($filters['currency'])) $query->where('currency', $filters['currency']);
        return $query;
    }

    public function orders(object $actor, array $filters = []): ?Builder
    {
        if (!$this->abilities($actor)['orders']) return null;
        $query = SalesOrder::query();
        $this->orderAccess->apply($query, $actor, true);
        $this->amounts->applyEffective($query);
        $this->attribution($query, $filters, 'sales_user_legacy_id', 'sales_department_id');
        if (!empty($filters['currency'])) $query->where('currency', $filters['currency']);
        if (!empty($filters['business_line'])) {
            // 订单没有业务线快照，只取原权限下可见的关联商机，不猜客户当前业务线。
            $opportunities = $this->opportunities($actor, ['business_line' => $filters['business_line']]);
            $opportunities ? $query->whereIn('id', DB::table('erp_crm_opportunity_orders')->select('order_id')
                ->whereIn('opportunity_id', $opportunities->select('erp_crm_opportunities.id'))) : $query->whereRaw('1 = 0');
        }
        return $this->period($query, $filters, 'order_date');
    }

    public function followups(object $actor, array $filters = []): ?Builder
    {
        $subjectFilters = $filters;
        if (!empty($filters['contact_operator'])) unset($subjectFilters['sales_user_legacy_id'], $subjectFilters['sales_user_unassigned']);
        $customers = $this->customers($actor, $subjectFilters);
        $leads = $this->leads($actor, $subjectFilters);
        $opportunities = $this->opportunities($actor, $subjectFilters);
        if (!$customers && !$leads && !$opportunities) return null;
        $query = CrmFollowup::query()->where(function ($visible) use ($customers, $leads, $opportunities): void {
            $visible->whereRaw('1 = 0');
            if ($customers) $visible->orWhere(fn ($q) => $q->where('subject_type', 'customer')->whereIn('subject_id', $customers->select('erp_sales_customers.id')));
            if ($leads) $visible->orWhere(fn ($q) => $q->where('subject_type', 'lead')->whereIn('subject_id', $leads->select('erp_crm_leads.id')));
            if ($opportunities) $visible->orWhere(fn ($q) => $q->where('subject_type', 'opportunity')->whereIn('subject_id', $opportunities->select('erp_crm_opportunities.id')));
        });
        if (!empty($filters['contact_operator'])) {
            if (!empty($filters['sales_user_unassigned'])) $query->whereNull('operator_legacy_id');
            elseif (!empty($filters['sales_user_legacy_id'])) $query->where('operator_legacy_id', $filters['sales_user_legacy_id']);
        }
        return $query;
    }

    public function period(Builder $query, array $filters, string $column): Builder
    {
        $query = clone $query;
        $column = $query->qualifyColumn($column);
        if (!empty($filters['date_from'])) $query->where($column, '>=', $filters['date_from']);
        if (!empty($filters['date_to'])) $query->where($column, '<', Carbon::parse($filters['date_to'])->addDay()->toDateString());
        return $query;
    }

    public function departmentIds(int $id): array
    {
        if (isset($this->departmentCache[$id])) return $this->departmentCache[$id];
        $rows = DB::table('erp_departments')->get(['legacy_id', 'parent_legacy_id']);
        $ids = [$id];
        do {
            $before = count($ids);
            foreach ($rows as $row) if (in_array((int) $row->parent_legacy_id, $ids, true)) $ids[] = (int) $row->legacy_id;
            $ids = array_values(array_unique($ids));
        } while (count($ids) !== $before);
        return $this->departmentCache[$id] = $ids;
    }

    /** 筛选项只来自原可见对象及原组织范围，未知或越权值不能退回全范围。 */
    public function assertFilters(object $actor, array $filters): void
    {
        foreach (['department_legacy_id', 'group_legacy_id'] as $field) {
            if (empty($filters[$field])) continue;
            $id = (int) $filters[$field];
            if (!DB::table('erp_departments')->where('legacy_id', $id)->exists()) throw ValidationException::withMessages([$field => '部门或小组不存在。']);
            if ($field === 'group_legacy_id' && !empty($filters['department_legacy_id'])
                && !in_array($id, $this->departmentIds((int) $filters['department_legacy_id']), true)) {
                throw ValidationException::withMessages([$field => '所选小组不属于该部门。']);
            }
            if ($this->auth->dataScope($actor) !== 'all') {
                $visible = $this->visibleDepartments($actor);
                abort_unless(in_array($id, $visible, true), 403, '所选部门或小组不在可见范围内。');
            }
        }
        if (!empty($filters['sales_user_legacy_id'])) {
            if (!empty($filters['sales_user_unassigned'])) throw ValidationException::withMessages(['sales_user_unassigned' => '不能同时选择销售人员和未归属销售。']);
            $id = (int) $filters['sales_user_legacy_id'];
            if (!DB::table('erp_legacy_admin_users')->where('legacy_id', $id)->exists()) throw ValidationException::withMessages(['sales_user_legacy_id' => '销售人员不存在。']);
            abort_unless($this->salesIds($actor)->where('sales_id', $id)->exists(), 403, '所选销售人员不在可见范围内。');
        }
        if (!empty($filters['business_line'])) {
            $line = $filters['business_line'];
            $customers = $this->customers($actor);
            $leads = $this->leads($actor);
            $opportunities = $this->opportunities($actor);
            $exists = ($customers && $customers->whereJsonContains('business_lines', $line)->exists())
                || ($leads && $leads->whereJsonContains('business_lines', $line)->exists())
                || ($opportunities && $opportunities->where('business_line', $line)->exists());
            if (!$exists) throw ValidationException::withMessages(['business_line' => '所选业务线不存在或已不在当前可见范围内。']);
        }
        if (!empty($filters['currency'])) {
            $exists = false;
            foreach ([$this->orders($actor), $this->opportunities($actor), $this->leads($actor)] as $query) {
                if ($query && $query->where('currency', $filters['currency'])->exists()) { $exists = true; break; }
            }
            if (!$exists) throw ValidationException::withMessages(['currency' => '所选币种不存在或已不在当前可见范围内。']);
        }
    }

    public function salesIds(object $actor, array $filters = []): \Illuminate\Database\Query\Builder
    {
        $parts = [];
        foreach ([[$this->customers($actor, $filters), 'owner_legacy_id'], [$this->leads($actor, $filters), 'owner_legacy_id'],
            [$this->opportunities($actor, $filters), 'owner_legacy_id'], [$this->orders($actor, $filters), 'sales_user_legacy_id']] as [$query, $field]) {
            if ($query) $parts[] = $query->selectRaw($query->qualifyColumn($field).' AS sales_id')->toBase();
        }
        $users = DB::table('erp_legacy_admin_users as u')->selectRaw('u.legacy_id AS sales_id')
            ->whereNull('u.deleted_at')->whereIn(DB::raw('LOWER(TRIM(u.status))'), ['normal', 'active'])
            ->whereExists(fn ($staff) => $staff->from('erp_business_staff as s')->whereColumn('s.user_legacy_id', 'u.legacy_id')
                ->where('s.staff_type', 'sales')->where('s.status', 'enabled')
                ->where(fn ($q) => $q->whereNull('s.effective_from')->orWhere('s.effective_from', '<=', today()->toDateString()))
                ->where(fn ($q) => $q->whereNull('s.effective_to')->orWhere('s.effective_to', '>=', today()->toDateString())));
        if ($this->auth->dataScope($actor) !== 'all') $users->whereIn('u.legacy_id', $this->auth->dataScope($actor) === 'department'
            ? $this->auth->departmentUserIds($actor) : [(int) $actor->legacy_id]);
        if (!empty($filters['sales_user_legacy_id'])) $users->where('u.legacy_id', $filters['sales_user_legacy_id']);
        if (!empty($filters['sales_user_unassigned'])) $users->whereRaw('1 = 0');
        foreach (['department_legacy_id', 'group_legacy_id'] as $field) if (!empty($filters[$field])) {
            $ids = $this->departmentIds((int) $filters[$field]);
            $users->whereExists(fn ($s) => $s->from('erp_business_staff as filter_staff')->whereColumn('filter_staff.user_legacy_id', 'u.legacy_id')
                ->where('filter_staff.staff_type', 'sales')->whereIn('filter_staff.department_legacy_id', $ids));
        }
        $parts[] = $users;
        $union = array_shift($parts);
        foreach ($parts as $part) $union->union($part);
        return DB::query()->fromSub($union, 'dashboard_sales')->select('sales_id')->distinct();
    }

    public function visibleDepartments(object $actor): array
    {
        if ($this->auth->dataScope($actor) === 'all') return DB::table('erp_departments')->orderBy('legacy_id')->pluck('legacy_id')->map(fn ($id) => (int) $id)->all();
        $ids = [];
        foreach ([[$this->customers($actor), 'owner_department_legacy_id'], [$this->leads($actor), 'department_legacy_id'],
            [$this->opportunities($actor), 'department_legacy_id'], [$this->orders($actor), 'sales_department_id']] as [$query, $field]) {
            if ($query) $ids = array_merge($ids, $query->distinct()->pluck($field)->all());
        }
        $ids = array_merge($ids, DB::table('erp_department_users')->where('user_legacy_id', $actor->legacy_id)->pluck('department_legacy_id')->all());
        // 祖先只作为层级筛选入口，结果仍与每种对象的原权限相交。
        $rows = DB::table('erp_departments')->get(['legacy_id', 'parent_legacy_id']);
        do {
            $before = count($ids);
            foreach ($rows as $row) if (in_array((int) $row->legacy_id, array_map('intval', $ids), true) && $row->parent_legacy_id) $ids[] = (int) $row->parent_legacy_id;
            $ids = array_values(array_unique(array_map('intval', array_filter($ids))));
        } while (count($ids) !== $before);
        return $ids;
    }

    private function attribution(Builder $query, array $filters, string $owner, string $department): void
    {
        if (!empty($filters['sales_user_unassigned'])) $query->whereNull($owner);
        elseif (!empty($filters['sales_user_legacy_id'])) $query->where($owner, (int) $filters['sales_user_legacy_id']);
        foreach (['department_legacy_id', 'group_legacy_id'] as $field) if (!empty($filters[$field])) {
            $query->whereIn($department, $this->departmentIds((int) $filters[$field]));
        }
    }
}
