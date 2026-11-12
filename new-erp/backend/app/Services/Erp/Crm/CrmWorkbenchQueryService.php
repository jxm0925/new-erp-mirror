<?php

namespace App\Services\Erp\Crm;

use App\Models\Erp\CrmFollowup;
use App\Models\Erp\CrmLead;
use App\Models\Erp\CrmOpportunity;
use App\Models\Erp\SalesCustomer;
use App\Services\Erp\AuthContextService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/** 工作台只投影原对象的当前计划，办理仍走线索、商机和跟进正式服务。 */
final class CrmWorkbenchQueryService
{
    public const TASKS = ['all' => '全部待办', 'today' => '今日联系', 'overdue' => '逾期跟进', 'pending_receive' => '待接收',
        'pending_confirm' => '待基础确认', 'pending_assign' => '待分配', 'pending_verify' => '待核实'];

    public function __construct(
        private readonly CrmLeadApplicationService $leads,
        private readonly CrmOpportunityApplicationService $opportunities,
        private readonly CrmCustomerAccessService $access,
        private readonly CrmAssignmentAccessService $assignments,
        private readonly CrmSalesStaffService $staff,
        private readonly AuthContextService $auth,
    ) {}

    public function overview(object $actor): array
    {
        $summary = [];
        foreach (array_keys(self::TASKS) as $type) $summary[$type] = $this->query($actor, $type)->count();
        $canLead = $this->access->can($actor, 'crm.lead.view');
        $canContact = $canLead || $this->access->can($actor, 'crm.opportunity.view') || $this->access->can($actor, 'sales.customer');
        $grant = $this->assignments->activeGrant($actor);
        $manager = fn ($action) => $canLead && $this->assignments->activeAccount((int) $actor->legacy_id)
            && $this->assignments->hasPermission($actor, CrmAssignmentAccessService::PERMISSIONS[$action])
            && ($this->auth->isSuperAdmin($actor) || ($grant && in_array($action, $grant->actions, true)));
        $leadQuery = CrmLead::query();
        $this->leads->applyVisible($leadQuery, $actor);
        if (!$canLead) $leadQuery->whereRaw('1 = 0');
        $pools = (clone $leadQuery)->whereNotNull('customer_pool')->selectRaw('customer_pool, COUNT(*) AS total')->groupBy('customer_pool')->pluck('total', 'customer_pool');
        $statuses = (clone $leadQuery)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $department = $this->staff->departmentOf((int) $actor->legacy_id);
        return [
            'date' => today()->toDateString(),
            'current_user' => ['id' => (int) $actor->legacy_id, 'name' => ($actor->nickname ?? null) ?: $actor->username,
                'department_legacy_id' => $department, 'department_name' => $this->access->departmentName($department)],
            'summary' => $summary,
            'task_abilities' => ['all' => true, 'today' => $canContact, 'overdue' => $canContact,
                'pending_receive' => $canLead, 'pending_confirm' => $canLead,
                'pending_assign' => $manager('assign'), 'pending_verify' => $manager('verify')],
            'customer_pools' => array_map(fn ($pool) => ['name' => $pool, 'count' => (int) ($pools[$pool] ?? 0), 'query' => ['tab' => 'all', 'customer_pool' => $pool]], CrmLeadApplicationService::POOLS),
            'lead_statuses' => collect(CrmLeadApplicationService::STATUSES)->map(fn ($name, $status) => ['name' => $name, 'count' => (int) ($statuses[$status] ?? 0), 'query' => ['tab' => 'all', 'status' => $status]])->values()->all(),
            'customer_tags' => $this->tags(clone $leadQuery, 'customer_tags', 'customer_tag'),
            'custom_tags' => $this->tags((clone $leadQuery)->where('created_by_legacy_id', $actor->legacy_id), 'custom_tags', 'custom_tag'),
        ];
    }

    public function tasks(object $actor, array $filters)
    {
        $type = $filters['type'] ?? 'all';
        return $this->query($actor, $type)->orderBy('planned_at')->orderBy('target_type')->orderBy('target_id')
            ->paginate((int) ($filters['per_page'] ?? 10))->through(fn ($task) => $this->present($task, $actor, $type));
    }

    private function query(object $actor, string $type): QueryBuilder
    {
        $lead = CrmLead::query();
        $this->leads->applyVisible($lead, $actor);
        if (!$this->access->can($actor, 'crm.lead.view')) $lead->whereRaw('1 = 0');
        $types = $type === 'all' ? array_values(array_diff(array_keys(self::TASKS), ['all'])) : [$type];
        // 一个线索可能同时待基础确认和到期；全部待办按对象只列一次，各卡片保留自己的办理范围。
        $lead->where(function ($matches) use ($actor, $types) {
            foreach ($types as $kind) $matches->orWhere(function ($q) use ($actor, $kind) {
                if (in_array($kind, ['today', 'overdue'], true)) {
                    $q->where('status', 'active')->where('owner_legacy_id', $actor->legacy_id);
                    CrmContactPlanQueryService::applyDue($q, 'next_contact_at', $kind);
                } elseif ($kind === 'pending_receive') {
                    $q->where('status', 'pending_acceptance')->where('pending_user_legacy_id', $actor->legacy_id);
                } elseif ($kind === 'pending_confirm') {
                    $q->where('status', 'active')->where('owner_legacy_id', $actor->legacy_id)->whereNull('confirmed_at');
                } elseif ($kind === 'pending_assign') {
                    $q->where('status', 'pending_assignment');
                    $this->managementRange($q, $actor, 'assign', 'department_legacy_id', 'source_channel');
                } elseif ($kind === 'pending_verify') {
                    $q->where('status', 'pending_review')->whereExists(function ($review) use ($actor) {
                        $review->from('erp_crm_duplicate_reviews as r')->where('r.subject_type', 'lead')->whereColumn('r.subject_id', 'erp_crm_leads.id')
                            ->where('r.status', 'pending')->where('r.input_snapshot->purpose', 'duplicate');
                        $this->managementRange($review, $actor, 'verify', 'r.review_department_legacy_id', 'r.source_channel');
                    });
                }
            });
        });
        $lead->selectRaw("'lead' AS target_type, id AS target_id, COALESCE(next_contact_at, created_at) AS planned_at");
        $opportunity = CrmOpportunity::query()->where('owner_legacy_id', $actor->legacy_id)->where('status', 'open');
        $this->opportunities->applyVisible($opportunity, $actor);
        if (!$this->access->can($actor, 'crm.opportunity.view')) $opportunity->whereRaw('1 = 0');
        CrmContactPlanQueryService::applyDue($opportunity, 'next_contact_at', $type);
        $opportunity->selectRaw("'opportunity' AS target_type, id AS target_id, next_contact_at AS planned_at");

        // 客户 next_contact_at 是各需求的汇总，只有最新的直接客户跟进计划可以另列，不能再计一次汇总值。
        $customer = SalesCustomer::query()->where('erp_sales_customers.owner_legacy_id', $actor->legacy_id)->whereNotIn('erp_sales_customers.status', ['disabled', 'blacklisted'])
            ->join('erp_crm_followups as direct', function ($join) {
                $join->on('direct.customer_id', '=', 'erp_sales_customers.id')->where('direct.subject_type', 'customer');
            })->whereNotExists(function ($newer) {
                $newer->from('erp_crm_followups as newer')->where('newer.subject_type', 'customer')->whereColumn('newer.customer_id', 'direct.customer_id')
                    ->where(fn ($q) => $q->whereColumn('newer.contacted_at', '>', 'direct.contacted_at')
                        ->orWhere(fn ($tie) => $tie->whereColumn('newer.contacted_at', 'direct.contacted_at')->whereColumn('newer.id', '>', 'direct.id')));
            });
        $this->access->applyVisible($customer, $actor);
        if (!$this->access->can($actor, 'sales.customer')) $customer->whereRaw('1 = 0');
        CrmContactPlanQueryService::applyDue($customer, 'direct.next_contact_at', $type);
        $customer->selectRaw("'customer' AS target_type, erp_sales_customers.id AS target_id, direct.next_contact_at AS planned_at");
        return DB::query()->fromSub($lead->toBase()->unionAll($opportunity->toBase())->unionAll($customer->toBase()), 'workbench_tasks');
    }

    private function managementRange(Builder|QueryBuilder $query, object $actor, string $action, string $department, string $channel): void
    {
        if (!$this->assignments->activeAccount((int) $actor->legacy_id) || !$this->assignments->hasPermission($actor, CrmAssignmentAccessService::PERMISSIONS[$action])) {
            $query->whereRaw('1 = 0'); return;
        }
        $all = $this->auth->isSuperAdmin($actor);
        $grant = $all ? null : $this->assignments->activeGrant($actor);
        if (!$all && (!$grant || !in_array($action, $grant->actions, true))) { $query->whereRaw('1 = 0'); return; }
        $departments = $all ? $this->assignments->managedDepartments($actor) : $this->assignments->expandDepartments($grant->department_ids);
        if ($all && $action === 'verify') {
            $query->where(fn ($q) => $q->whereNull($department)->orWhereIn($department, $departments));
            return;
        }
        $query->whereIn($department, $departments);
        if ($grant?->channels) $query->whereIn($channel, $grant->channels);
    }

    private function tags(Builder $query, string $field, string $filter): array
    {
        $counts = [];
        foreach ($query->select(['id', $field])->whereNotNull($field)->cursor() as $lead) {
            foreach (array_unique($lead->{$field} ?? []) as $tag) if ($tag !== '') $counts[$tag] = ($counts[$tag] ?? 0) + 1;
        }
        ksort($counts, SORT_NATURAL);
        $rows = [];
        foreach ($counts as $tag => $count) $rows[] = ['name' => (string) $tag, 'count' => $count, 'query' => ['tab' => 'all', $filter => (string) $tag]];
        return $rows;
    }

    private function present(object $task, object $actor, string $type): array
    {
        $kind = $type;
        $customerId = null;
        $contactName = null;
        if ($task->target_type === 'lead') {
            $lead = $this->leads->visible((int) $task->target_id, $actor);
            $row = $this->leads->present($lead, $actor);
            if ($type === 'all') $kind = match ($lead->status) {
                'pending_acceptance' => 'pending_receive', 'pending_assignment' => 'pending_assign', 'pending_review' => 'pending_verify',
                default => !$lead->confirmed_at ? 'pending_confirm' : ($lead->next_contact_at?->isPast() ? 'overdue' : 'today'),
            };
            $customerName = $lead->customer_name;
            $title = $lead->demand_summary ?: $lead->lead_no;
            $customerId = $lead->customer_id;
            $contactName = $row['contact_name'];
            $allowed = $row['abilities'];
            $allowed['verify'] = (bool) ($row['review']['can_resolve'] ?? false);
            $allowed['review'] = $allowed['verify'];
            $contactMethod = $lead->mobile ? '电话' : ($lead->wechat ? '微信' : ($lead->email ? '邮件' : null));
        } elseif ($task->target_type === 'opportunity') {
            $opportunity = $this->opportunities->visible((int) $task->target_id, $actor);
            $customerName = $opportunity->customer_name;
            $title = $opportunity->name;
            $customerId = $opportunity->customer_id;
            $allowed = $this->opportunities->abilities($opportunity, $actor);
            $contactName = null;
            $contactMethod = null;
        } else {
            $customer = SalesCustomer::query()->findOrFail($task->target_id);
            abort_unless($this->access->relation($customer, $actor) !== null, 403, '无权查看该客户。');
            $customerName = $customer->customer_name;
            $title = null;
            $customerId = $customer->id;
            $contactName = $customer->contact_name;
            $allowed = $this->access->abilities($customer, $actor);
            $allowed['follow'] = $allowed['follow'] && $this->access->can($actor, 'crm.lead.follow');
            $contactMethod = $customer->contact_phone ? '电话' : null;
        }
        if ($kind === 'all') $kind = $task->planned_at < now()->toDateTimeString() ? 'overdue' : 'today';
        $latest = CrmFollowup::query()->where('subject_type', $task->target_type)->where('subject_id', $task->target_id)->orderByDesc('contacted_at')->orderByDesc('id')->first();
        $action = match ($kind) {
            'pending_receive' => 'receive', 'pending_confirm' => 'confirm', 'pending_assign' => 'assign', 'pending_verify' => 'verify', default => 'followup',
        };
        $ability = match ($action) { 'receive' => 'accept', 'followup' => 'follow', default => $action };
        if (empty($allowed[$ability])) $action = 'view';
        return ['id' => $task->target_type.':'.$task->target_id, 'target_type' => $task->target_type, 'target_id' => (int) $task->target_id,
            'customer_id' => $customerId, 'customer_name' => $customerName, 'title' => $title,
            'contact_name' => $latest?->contact_name ?: $contactName, 'planned_at' => $task->planned_at,
            'is_overdue' => in_array($kind, ['today', 'overdue'], true) && $task->planned_at < now()->toDateTimeString(),
            'contact_method_label' => $latest?->method ?: $contactMethod,
            'task_type' => $kind, 'status_label' => in_array($kind, ['today', 'overdue'], true) ? ($kind === 'overdue' ? '逾期未联系' : '待联系') : self::TASKS[$kind],
            'action' => $action, 'allowed' => $allowed];
    }
}
