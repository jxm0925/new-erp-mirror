<?php

namespace App\Services\Erp\Crm;

use App\Models\Erp\CrmFollowup;
use App\Models\Erp\CrmLead;
use App\Models\Erp\CrmOpportunity;
use App\Models\Erp\SalesCustomer;
use App\Services\Erp\AuthContextService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** 工作台只投影原对象的当前计划，办理仍走线索、商机和跟进正式服务。 */
final class CrmWorkbenchQueryService
{
    public const TASKS = ['all' => '全部待办', 'today' => '今日联系', 'tomorrow' => '明日联系', 'overdue' => '逾期跟进', 'pending_receive' => '待接收',
        'pending_assign' => '待分配', 'pending_verify' => '待核实'];
    public const PRIORITIES = ['closing' => '预计成交', 'confirmation' => '待客户确认', 'stalled' => '停滞商机'];

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
        $pools = $this->poolCounts($actor, clone $leadQuery);
        $statuses = (clone $leadQuery)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $department = $this->staff->departmentOf((int) $actor->legacy_id);
        $isSales = $this->assignments->activeSales((int) $actor->legacy_id);
        return [
            'can_self_delegate' => $isSales,
            'delegation_departments' => $isSales ? DB::table('erp_departments')->whereNull('deleted_at')->where('status', 'normal')
                ->orderBy('sort')->orderBy('legacy_id')->get(['legacy_id as id', 'parent_legacy_id as parent_id', 'name']) : [],
            'date' => today()->toDateString(),
            'current_user' => ['id' => (int) $actor->legacy_id, 'name' => ($actor->nickname ?? null) ?: $actor->username,
                'department_legacy_id' => $department, 'department_name' => $this->access->departmentName($department)],
            'summary' => $summary,
            'task_abilities' => ['all' => true, 'today' => $canContact, 'tomorrow' => $canContact, 'overdue' => $canContact,
                'pending_receive' => $canLead,
                'pending_assign' => $manager('assign'), 'pending_verify' => $manager('verify')],
            'customer_pools' => $this->poolRadar($pools, $actor),
            'lead_statuses' => collect(CrmLeadApplicationService::STATUSES)->map(fn ($name, $status) => ['name' => $name, 'count' => (int) ($statuses[$status] ?? 0), 'query' => ['tab' => 'all', 'status' => $status]])->values()->all(),
            'customer_tags' => $this->tags(clone $leadQuery, 'customer_tags', 'customer_tag'),
            'custom_tags' => $this->tags((clone $leadQuery)->where('created_by_legacy_id', $actor->legacy_id), 'custom_tags', 'custom_tag'),
        ];
    }

    public function tasks(object $actor, array $filters)
    {
        $type = $filters['type'] ?? 'all';
        $page = $this->query($actor, $type)->orderBy('planned_at')->orderBy('target_type')->orderBy('target_id')
            ->paginate((int) ($filters['per_page'] ?? 10));
        $subjects = [];
        $targets = [];
        foreach (['lead' => CrmLead::query()->with('customer'), 'opportunity' => CrmOpportunity::query(), 'customer' => SalesCustomer::query()] as $kind => $query) {
            $subjects[$kind] = $page->getCollection()->where('target_type', $kind)->pluck('target_id')->all();
            if ($kind === 'lead') $this->leads->applyVisible($query, $actor);
            elseif ($kind === 'opportunity') $this->opportunities->applyVisible($query, $actor);
            else $this->access->applyVisible($query, $actor);
            $targets[$kind] = $subjects[$kind] ? $query->whereKey($subjects[$kind])->get()->keyBy('id') : collect();
        }
        $latest = $this->latestFollowups($subjects);
        $daily = collect();
        if ($this->includeDaily($actor, $type) && array_filter($subjects)) {
            $daily = app(CrmDailyFacts::class)->pendingActions($actor, $type)
                ->where(fn ($q) => $q->where(fn ($lead) => $lead->where('a.subject_type','lead')->whereIn('a.subject_id',$subjects['lead']))
                    ->orWhere(fn ($opp) => $opp->where('a.subject_type','opportunity')->whereIn('a.subject_id',$subjects['opportunity']))
                    ->orWhere(fn ($customer) => $customer->where('a.subject_type','customer')->whereIn('a.subject_id',$subjects['customer'])))
                ->orderBy('a.planned_at')->get(['a.*'])->groupBy(fn ($r) => $r->subject_type.':'.$r->subject_id);
        }
        return $page->through(function ($task) use ($actor, $type, $targets, $latest, $daily) {
            $target = $targets[$task->target_type]->get($task->target_id);
            abort_unless($target, 404, '待办对象已变化，请刷新后重试。');
            $key = $task->target_type.':'.$task->target_id;
            $actions = $daily->get($key);
            $row = $this->present($task, $actor, $type, $target, $latest->get($key), $actions?->first()?->planned_at);
            if ($actions) {
                $notes = $actions->map(fn ($a) => ($a->priority === 'primary' ? '主攻' : '补位').' · '.$a->action
                    .($a->expected_result ? '（'.$a->expected_result.'）' : ''))->implode(' / ');
                $row['next_plan'] = $row['next_plan'] ? $notes.' / 原计划：'.$row['next_plan'] : $notes;
                $row['task_labels'][] = ['key'=>'daily','label'=>'作战表已确认','type'=>'success'];
            }
            return $row;
        });
    }

    private function poolCounts(object $actor, Builder $leads): array
    {
        $queries = ['lead' => $leads->where('status', 'active')->selectRaw('customer_pool as pool, COUNT(*) as total')->groupBy('customer_pool')];
        if ($this->access->can($actor, 'crm.opportunity.view')) {
            $opps = CrmOpportunity::query()->where('status', 'open');
            $this->opportunities->applyVisible($opps, $actor);
            $queries['opportunity'] = $opps->selectRaw("COALESCE(NULLIF(customer_pool, ''), (SELECT l.customer_pool FROM erp_crm_leads l WHERE l.id = erp_crm_opportunities.source_lead_id)) as pool, COUNT(*) as total")->groupBy('pool');
        }
        if ($this->access->can($actor, 'sales.customer')) {
            $customers = SalesCustomer::query();
            $this->access->applyVisible($customers, $actor);
            $queries['customer'] = $customers->selectRaw('customer_pool as pool, COUNT(*) as total')->groupBy('customer_pool');
        }
        $counts = [];
        foreach ($queries as $kind => $query) foreach ($query->get() as $row) {
            $pool = app(CrmCustomerPoolService::class)->normalize($row->pool);
            if ($pool) $counts[$pool][$kind] = ($counts[$pool][$kind] ?? 0) + (int) $row->total;
        }
        return $counts;
    }

    private function poolRadar(array $pools, object $actor): array
    {
        $types = [
            'lead' => ['label' => '跟进中线索', 'path' => '/sales/leads', 'permission' => 'crm.lead.view', 'query' => ['tab' => 'all', 'status' => 'active']],
            'opportunity' => ['label' => '进行中商机', 'path' => '/sales/opportunities', 'permission' => 'crm.opportunity.view', 'query' => ['tab' => 'all', 'status' => 'open']],
            'customer' => ['label' => '维系客户', 'path' => '/sales/customers', 'permission' => 'sales.customer', 'query' => ['tab' => 'all']],
        ];
        $types = array_filter($types, fn ($type) => $this->access->can($actor, $type['permission']));
        $names = array_values(array_unique(array_merge(array_column(app(CrmCustomerPoolService::class)->options(), 'pool_name'), array_keys($pools))));
        $rows = [];
        foreach ($names as $pool) {
            $counts = array_replace(['lead' => 0, 'opportunity' => 0, 'customer' => 0], $pools[$pool] ?? []);
            $targets = [];
            foreach ($types as $kind => $type) $targets[] = ['type' => $kind, 'label' => $type['label'], 'count' => $counts[$kind],
                'path' => $type['path'], 'query' => $type['query'] + ['customer_pool' => $pool]];
            $rows[] = ['name' => $pool, 'count' => array_sum($counts), 'counts' => $counts, 'targets' => $targets];
        }
        return $rows;
    }

    public function priorities(object $actor, array $filters)
    {
        $type = $filters['type'] ?? 'closing';
        abort_unless(isset(self::PRIORITIES[$type]), 422, '请选择有效的重点推进分类。');
        $this->opportunities->permission($actor, 'crm.opportunity.view');
        $query = CrmOpportunity::query()->where('status', 'open')->where(fn ($assigned) => $assigned->where('owner_legacy_id', $actor->legacy_id)
            ->orWhere(fn ($delegated) => $this->opportunities->applyDelegated($delegated, $actor)));
        $this->opportunities->applyVisible($query, $actor);
        if ($type === 'closing') {
            // 原商机没有近期截止口径，按真实预计成交日优先展示，逾期也保留。
            $query->whereNotNull('expected_close_date')->orderBy('expected_close_date');
        } elseif ($type === 'confirmation') {
            $query->whereHas('files', fn ($files) => $files->where('status', 'pending'))->orderBy('last_contact_at');
        } else {
            // 与现有商机进度的30天停滞口径一致；未知或未来阶段起始时间不能当成停滞。
            $query->where('stage_entered_at', '<=', now()->subDays(30))->orderBy('stage_entered_at');
        }
        $page = $query->orderBy('id')->paginate((int) ($filters['per_page'] ?? 10));
        $latest = $this->latestFollowups(['opportunity' => $page->getCollection()->pluck('id')->all()]);
        return $page->through(function ($opportunity) use ($actor, $latest) {
            $followup = $latest->get('opportunity:'.$opportunity->id);
            return [
                'id' => (int) $opportunity->id, 'customer_id' => (int) $opportunity->customer_id,
                'customer_name' => $opportunity->customer_name, 'name' => $opportunity->name,
                'stage' => $opportunity->stage, 'stage_label' => CrmOpportunityApplicationService::STAGES[CrmNineStepCatalog::normalizeStage('opportunity', $opportunity->stage)] ?? $opportunity->stage,
                'expected_amount' => $opportunity->expected_amount, 'currency' => $opportunity->currency,
                'expected_close_date' => $opportunity->expected_close_date?->toDateString(),
                'is_close_overdue' => $opportunity->expected_close_date?->lt(today()) ?? false,
                'latest_progress' => $followup?->content,
                'stalled_days' => $opportunity->stage_entered_at && $opportunity->stage_entered_at->lte(now())
                    ? (int) $opportunity->stage_entered_at->diffInDays(now()) : null,
                'abilities' => $this->opportunities->abilities($opportunity, $actor),
                'delegated' => $this->opportunities->delegated($opportunity, $actor),
                'delegated_owner' => $this->opportunities->delegated($opportunity, $actor) ? ['id' => (int) $opportunity->owner_legacy_id, 'name' => $opportunity->owner_name] : null,
                'contact_name' => $followup?->contact_name ?: $opportunity->contact_name,
            ];
        });
    }

    private function latestFollowups(array $subjects): Collection
    {
        if (!array_filter($subjects)) return collect();
        return CrmFollowup::query()->where(function ($query) use ($subjects) {
            foreach ($subjects as $type => $ids) if ($ids) $query->orWhere(fn ($subject) => $subject->where('subject_type', $type)->whereIn('subject_id', $ids));
        })->whereNotExists(function ($newer) {
            $newer->from('erp_crm_followups as newer')->whereColumn('newer.subject_type', 'erp_crm_followups.subject_type')
                ->whereColumn('newer.subject_id', 'erp_crm_followups.subject_id')
                ->where(fn ($query) => $query->whereColumn('newer.contacted_at', '>', 'erp_crm_followups.contacted_at')
                    ->orWhere(fn ($tie) => $tie->whereColumn('newer.contacted_at', 'erp_crm_followups.contacted_at')->whereColumn('newer.id', '>', 'erp_crm_followups.id')));
        })->get()->keyBy(fn ($followup) => $followup->subject_type.':'.$followup->subject_id);
    }

    private function query(object $actor, string $type): QueryBuilder
    {
        $lead = CrmLead::query();
        $this->leads->applyVisible($lead, $actor);
        if (!$this->access->can($actor, 'crm.lead.view')) $lead->whereRaw('1 = 0');
        $types = $type === 'all' ? array_values(array_diff(array_keys(self::TASKS), ['all'])) : [$type];
        $handlingOwners = array_merge([(int) $actor->legacy_id], app(CrmSalesDelegationService::class)->activeOwnerIds($actor));
        // 一个线索可能同时待确认有效线索和到期；全部待办按对象只列一次，各卡片保留自己的办理范围。
        $lead->where(function ($matches) use ($actor, $types, $handlingOwners) {
            foreach ($types as $kind) $matches->orWhere(function ($q) use ($actor, $kind, $handlingOwners) {
                if (in_array($kind, ['today', 'tomorrow', 'overdue'], true)) {
                    $q->where('status', 'active')->whereIn('owner_legacy_id', $handlingOwners);
                    CrmContactPlanQueryService::applyDue($q, 'next_contact_at', $kind);
                } elseif ($kind === 'pending_receive') {
                    $q->where('status', 'pending_acceptance')->whereIn('pending_user_legacy_id', $handlingOwners);
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
        $opportunity = CrmOpportunity::query()->where('status', 'open')->where(fn ($assigned) => $assigned->where('owner_legacy_id', $actor->legacy_id)
            ->orWhere(fn ($delegated) => $this->opportunities->applyDelegated($delegated, $actor)));
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
        $customer->where(fn ($q) => $q->where('direct.followup_schedule_mode', 'scheduled')->orWhereNull('direct.followup_schedule_mode'));
        if (!$this->access->can($actor, 'sales.customer')) $customer->whereRaw('1 = 0');
        CrmContactPlanQueryService::applyDue($customer, 'direct.next_contact_at', $type);
        $customer->selectRaw("'customer' AS target_type, erp_sales_customers.id AS target_id, direct.next_contact_at AS planned_at");
        $tasks = $lead->toBase()->unionAll($opportunity->toBase())->unionAll($customer->toBase());
        if (!$this->includeDaily($actor, $type)) return DB::query()->fromSub($tasks, 'workbench_tasks');
        $daily = app(CrmDailyFacts::class)->pendingActions($actor, $type)
            ->selectRaw('a.subject_type as target_type, a.subject_id as target_id, a.planned_at');
        return DB::query()->fromSub(DB::query()->fromSub($tasks->unionAll($daily), 'combined_tasks')
            ->selectRaw('target_type,target_id,MIN(planned_at) as planned_at')->groupBy('target_type','target_id'), 'workbench_tasks');
    }

    private function includeDaily(object $actor, string $type): bool
    {
        return in_array($type, ['today','tomorrow','overdue','all'], true)
            && ($this->access->can($actor, 'crm.lead.view') || $this->access->can($actor, 'crm.opportunity.view') || $this->access->can($actor, 'sales.customer'));
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

    private function present(object $task, object $actor, string $type, CrmLead|CrmOpportunity|SalesCustomer $target, ?CrmFollowup $latest, ?string $dailyPlan): array
    {
        $kind = $type;
        $customerId = null;
        $contactName = null;
        $taskKinds = [];
        $delegated = false;
        $delegatedOwner = null;
        if ($task->target_type === 'lead') {
            $lead = $target;
            $pending = match ($lead->status) {
                'pending_acceptance' => 'pending_receive', 'pending_assignment' => 'pending_assign', 'pending_review' => 'pending_verify', default => null,
            };
            if ($pending) $taskKinds[] = $pending;
            $customerName = $lead->customer_name;
            $title = $lead->demand_summary ?: $lead->lead_no;
            $customerId = $lead->customer_id;
            $contactName = $lead->contact_name;
            $allowed = $this->leads->abilities($lead, $actor);
            $delegated = $this->leads->delegated($lead, $actor);
            if ($delegated) $delegatedOwner = ['id' => (int) ($lead->pending_user_legacy_id ?: $lead->owner_legacy_id), 'name' => $lead->pending_user_name ?: $lead->owner_name];
            if ($lead->customer_id) $allowed['transfer_customer'] = $lead->customer && $this->access->abilities($lead->customer, $actor)['transfer'];
            $review = $lead->status === 'pending_review' ? app(CrmDuplicateReviewApplicationService::class)->forLead($lead, $actor) : null;
            $allowed['verify'] = (bool) ($review['can_resolve'] ?? false);
            $allowed['review'] = $allowed['verify'];
            $contactMethod = $lead->mobile ? '电话' : ($lead->wechat ? '微信' : ($lead->email ? '邮件' : null));
            $canContact = $lead->status === 'active';
            $nextContact = $lead->next_contact_at;
            $nextPlan = $lead->next_plan;
        } elseif ($task->target_type === 'opportunity') {
            $opportunity = $target;
            $customerName = $opportunity->customer_name;
            $title = $opportunity->name;
            $customerId = $opportunity->customer_id;
            $allowed = $this->opportunities->abilities($opportunity, $actor);
            $delegated = $this->opportunities->delegated($opportunity, $actor);
            if ($delegated) $delegatedOwner = ['id' => (int) $opportunity->owner_legacy_id, 'name' => $opportunity->owner_name];
            $contactName = $opportunity->contact_name;
            $contactMethod = null;
            $canContact = $opportunity->status === 'open';
            $nextContact = $opportunity->next_contact_at;
            $nextPlan = $opportunity->next_plan;
        } else {
            $customer = $target;
            abort_unless($this->access->relation($customer, $actor) !== null, 403, '无权查看该客户。');
            $customerName = $customer->customer_name;
            $title = null;
            $customerId = $customer->id;
            $contactName = $customer->contact_name;
            $allowed = $this->access->abilities($customer, $actor);
            $allowed['follow'] = $allowed['follow'] && $this->access->can($actor, 'crm.lead.follow');
            $contactMethod = $customer->contact_phone ? '电话' : null;
            $canContact = !in_array($customer->status, ['disabled', 'blacklisted'], true);
            // 客户汇总计划可能来自线索或商机；当前待办只能展示直接客户跟进的计划。
            $nextContact = $latest?->next_contact_at;
            $nextPlan = $latest?->next_plan;
        }
        if ($dailyPlan) {
            $dailyTime = \Illuminate\Support\Carbon::parse($dailyPlan);
            if ($type !== 'all') $nextContact = \Illuminate\Support\Carbon::parse($task->planned_at);
            elseif (!$nextContact || $dailyTime->lt($nextContact)) $nextContact = $dailyTime;
        }
        $isOverdue = $canContact && $nextContact && $nextContact->lt(now());
        if ($isOverdue) $taskKinds[] = 'overdue';
        elseif ($canContact && $nextContact && $nextContact->gte(today()) && $nextContact->lt(today()->addDay())) $taskKinds[] = 'today';
        elseif ($canContact && $nextContact && $nextContact->gte(today()->addDay()) && $nextContact->lt(today()->addDays(2))) $taskKinds[] = 'tomorrow';
        if ($kind === 'all') $kind = $taskKinds[0] ?? 'today';
        $action = match ($kind) {
            'pending_receive' => 'receive', 'pending_assign' => 'assign', 'pending_verify' => 'verify', default => 'followup',
        };
        $ability = match ($action) { 'receive' => 'accept', 'followup' => 'follow', default => $action };
        if (empty($allowed[$ability])) $action = 'view';
        $actions = $action === 'view' ? [] : [$action];
        foreach (['followup' => 'follow', 'receive' => 'accept', 'assign' => 'assign', 'verify' => 'verify'] as $candidate => $ability) {
            if (!empty($allowed[$ability]) && ($candidate !== 'followup' || $canContact)) $actions[] = $candidate;
        }
        return ['id' => $task->target_type.':'.$task->target_id, 'target_type' => $task->target_type, 'target_id' => (int) $task->target_id,
            'customer_id' => $customerId, 'customer_name' => $customerName, 'title' => $title,
            'delegated' => $delegated, 'delegated_owner' => $delegatedOwner,
            'contact_name' => $latest?->contact_name ?: $contactName, 'planned_at' => $task->planned_at,
            'is_overdue' => (bool) $isOverdue,
            'contact_method_label' => $latest?->method ?: $contactMethod,
            'last_contact_content' => $latest?->content, 'next_plan' => $nextPlan,
            'task_type' => $kind, 'status_label' => in_array($kind, ['today', 'overdue'], true) ? ($kind === 'overdue' ? '逾期未联系' : '待联系') : self::TASKS[$kind],
            'task_labels' => array_map(fn ($key) => ['key' => $key, 'label' => $key === 'overdue' ? '逾期未联系' : self::TASKS[$key],
                'type' => $key === 'overdue' ? 'danger' : ($key === 'today' ? 'info' : 'warning')], $taskKinds),
            'actions' => $actions ? array_values(array_unique($actions)) : ['view'], 'action' => $action, 'allowed' => $allowed];
    }
}
