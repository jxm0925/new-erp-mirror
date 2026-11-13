<?php

namespace App\Services\Erp\Crm;

use App\Models\Erp\CrmLead;
use App\Models\Erp\CrmOpportunity;
use App\Models\Erp\SalesCustomer;
use App\Models\Erp\SalesOrder;
use App\Services\Erp\SalesOrderVisibilityService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/** 看板只读取真实阶段和计划事实；工作台的本人待办及正式办理入口保持独立。 */
final class CrmDashboardPipelineService
{
    public const FUNNEL = ['recorded' => '线索录入', 'confirmed' => '有效线索',
        'requirement' => '客户需求确认', 'solution' => '客户方案生成', 'product' => '产品方案确认',
        'price' => '订单价格确认', 'contract' => '订单合同确认', 'won' => '成交'];
    private const KINDS = ['pipeline_stage', 'pipeline_stalled', 'pipeline_overdue_followup', 'pipeline_overdue_close',
        'pipeline_active', 'pipeline_lost', 'pipeline_won', 'pipeline_unknown_stage_age', 'cohort_stage', 'cohort_conversion',
        'contact_due', 'contact_completed', 'contact_overdue', 'contact_due_soon', 'contact_pending_accept',
        'contact_uncontacted', 'contact_unverified', 'contact_unknown_history', 'contact_sales', 'contact_records'];

    public function __construct(
        private readonly CrmDashboardScopeService $scope,
        private readonly CrmLeadApplicationService $leads,
        private readonly CrmOpportunityApplicationService $opportunities,
        private readonly CrmCustomerAccessService $customers,
        private readonly SalesOrderVisibilityService $orderVisibility,
    ) {}

    public function handles(string $kind): bool { return in_array($kind, self::KINDS, true); }

    public function pipeline(object $actor, array $filters): array
    {
        $base = $this->scope->opportunities($actor, $filters);
        $stages = [];
        $dwell = [];
        $stageNames = CrmOpportunityApplicationService::STAGES;
        if ($base) foreach ((clone $base)->where('status', 'open')->whereNotIn('stage', array_keys($stageNames))->distinct()->pluck('stage') as $unknown) {
            $stageNames[$unknown] = $unknown === '' ? '阶段未填写' : '未知阶段（'.$unknown.'）';
        }
        foreach ($stageNames as $key => $name) {
            $query = $base ? (clone $base)->where('status', 'open')->where('stage', $key) : null;
            $stages[] = ['key' => $key, 'name' => $name, 'can' => $base !== null,
                'count' => $query?->count(), 'amounts' => $query ? $this->expectedAmounts($query) : [],
                'query' => ['kind' => 'pipeline_stage', 'stage' => $key]];
            $ages = $query ? (clone $query)->selectRaw('COUNT(*) AS total, SUM(stage_entered_at <= ?) AS known_count,
                AVG(IF(stage_entered_at <= ?, TIMESTAMPDIFF(DAY, stage_entered_at, ?), NULL)) AS average_days,
                MAX(IF(stage_entered_at <= ?, TIMESTAMPDIFF(DAY, stage_entered_at, ?), NULL)) AS max_days', [now(), now(), now(), now(), now()])->first() : null;
            $dwell[] = ['key' => $key, 'name' => $name, 'count' => $ages ? (int) $ages->total : null,
                'known_age_count' => $ages ? (int) $ages->known_count : null,
                'unknown_age_count' => $ages ? (int) $ages->total - (int) $ages->known_count : null,
                'average_days' => $ages?->average_days === null ? null : round((float) $ages->average_days, 1),
                'max_days' => $ages?->max_days === null ? null : (int) $ages->max_days,
                'query' => ['kind' => 'pipeline_stage', 'stage' => $key]];
        }
        $summary = [];
        foreach (['active', 'stalled', 'overdue_followup', 'overdue_close', 'lost', 'won', 'unknown_stage_age'] as $key) {
            $kind = 'pipeline_'.$key;
            $summary[$key] = $this->metric($base ? $this->opportunityQuery($base, $kind, $filters)->count() : null, $kind);
        }
        $lost = $base ? $this->opportunityQuery($base, 'pipeline_lost', $filters)->selectRaw("NULLIF(TRIM(lost_reason), '') AS reason, COUNT(*) AS total")
            ->groupByRaw("NULLIF(TRIM(lost_reason), '')")->orderByDesc('total')->get()->map(fn ($row) => [
                'key' => $row->reason, 'name' => $row->reason ?? '未填写', 'count' => (int) $row->total,
                'query' => ['kind' => 'pipeline_lost'] + ($row->reason === null ? ['dimension_empty' => true] : ['lost_reason' => $row->reason]),
            ])->all() : [];
        return ['can' => $base !== null, 'current_stages' => $stages, 'stage_dwell' => $dwell,
            'summary' => $summary, 'lost_reasons' => $lost, 'stalled_days' => (int) ($filters['stalled_days'] ?? 30),
            'funnel' => $this->funnel($actor, $filters), 'sales_funnels' => $this->salesFunnels($actor, $filters)->toArray(),
            'conversion' => $this->conversion($actor, $filters)];
    }

    public function followups(object $actor, array $filters): array
    {
        $can = $this->canContact($actor, $filters);
        $summary = [];
        foreach (['due', 'completed', 'overdue', 'due_soon', 'pending_accept', 'uncontacted', 'unverified', 'unknown_history'] as $key) {
            $kind = 'contact_'.$key;
            $queueCan = in_array($key, ['pending_accept', 'uncontacted', 'unverified'], true)
                ? $this->scope->leads($actor, $filters) !== null : $can;
            $summary[$key] = $this->metric($queueCan ? $this->contactQuery($actor, $filters, $kind)->count() : null, $kind);
        }
        $due = $this->contactQuery($actor, $filters, 'contact_due');
        $trend = (clone $due)->selectRaw('DATE(planned_at) AS day, COUNT(*) AS due_count, SUM(completed_at IS NOT NULL) AS completed_count')
            ->groupByRaw('DATE(planned_at)')->orderBy('day')->get()->map(fn ($row) => [
                'date' => $row->day, 'due_count' => (int) $row->due_count, 'completed_count' => (int) $row->completed_count,
            ])->all();
        $operators = $this->contactQuery($actor, $filters, 'contact_completed')
            ->selectRaw('completed_by_legacy_id, completed_by_name, COUNT(*) AS total')
            ->groupBy('completed_by_legacy_id', 'completed_by_name')->orderByDesc('total')->get()->map(fn ($row) => [
                'operator_legacy_id' => $row->completed_by_legacy_id === null ? null : (int) $row->completed_by_legacy_id,
                'operator_name' => $row->completed_by_name, 'count' => (int) $row->total,
            ])->all();
        return ['can' => $can, 'summary' => $summary, 'trend' => $trend,
            'sales' => $this->contactSales($actor, $filters)->toArray(), 'completed_by_operator' => $operators,
            'observed_on' => $this->observedOn($filters)->toDateString(),
            'completion_attribution' => '计划按对象负责销售归属，完成按后续实际联系记录的操作者标注'];
    }

    public function drilldown(object $actor, array $filters): LengthAwarePaginator
    {
        $kind = $filters['kind'] ?? '';
        abort_unless($this->handles($kind), 422, '请选择有效的经营统计名单。');
        if ($kind === 'contact_sales') return $this->contactSales($actor, $filters);
        if (in_array($kind, ['cohort_stage', 'cohort_conversion'], true)) {
            abort_unless($this->scope->leads($actor, $filters), 403, '没有查看线索的权限。');
            $stage = $filters['stage'] ?? 'recorded';
            abort_unless(isset(self::FUNNEL[$stage]), 422, '请选择有效的推进阶段。');
            $facts = $kind === 'cohort_stage' ? $this->cohortFacts($actor, $filters)->where('reached_'.$stage, 1)
                : $this->conversionQuery($actor, $filters, $filters['conversion_bucket'] ?? 'total');
            return $facts->orderBy('target_id')->paginate($this->perPage($filters))
                ->through(fn ($row) => $this->presentTarget($row, $actor, $kind));
        }
        if (str_starts_with($kind, 'pipeline_')) {
            $base = $this->scope->opportunities($actor, $filters);
            abort_unless($base, 403, '没有查看商机的权限。');
            return $this->opportunityQuery($base, $kind, $filters)->orderBy('stage_entered_at')->orderBy('id')
                ->paginate($this->perPage($filters))->through(fn ($row) => $this->presentOpportunity($row, $actor));
        }
        abort_unless($this->canContact($actor, $filters), 403, '没有查看跟进对象的权限。');
        if (in_array($kind, ['contact_pending_accept', 'contact_uncontacted', 'contact_unverified'], true)) {
            abort_unless($this->scope->leads($actor, $filters), 403, '没有查看线索的权限。');
        }
        $query = $this->contactQuery($actor, $filters, $kind);
        return $query->orderBy('planned_at')->orderBy('target_type')->orderBy('target_id')->orderBy('plan_id')
            ->paginate($this->perPage($filters))->through(fn ($row) => $this->presentTarget($row, $actor, $kind));
    }

    /** 一次按同一批线索分组，销售列表不能逐人重扫历史表。 */
    public function funnelsBySales(object $actor, array $filters): array
    {
        return $this->funnelGroups($actor, $filters)->get()->mapWithKeys(fn ($row) => [
            $row->owner_legacy_id ?? '' => $this->presentFunnelGroup($row, $filters),
        ])->all();
    }

    public function overdueBySales(object $actor, array $filters): array
    {
        if (!$this->canContact($actor, $filters)) return [];
        return $this->contactQuery($actor, $filters, 'contact_overdue')->selectRaw('owner_legacy_id, COUNT(*) AS total')
            ->groupBy('owner_legacy_id')->get()->mapWithKeys(fn ($row) => [$row->owner_legacy_id ?? '' => (int) $row->total])->all();
    }

    private function salesFunnels(object $actor, array $filters): LengthAwarePaginator
    {
        return $this->funnelGroups($actor, $filters)->orderByRaw('owner_legacy_id IS NULL')->orderBy('owner_legacy_id')
            ->paginate($this->perPage($filters))->through(fn ($row) => $this->presentFunnelGroup($row, $filters));
    }

    private function funnelGroups(object $actor, array $filters): QueryBuilder
    {
        $query = $this->cohortFacts($actor, $filters)->selectRaw('owner_legacy_id, MAX(owner_name) AS owner_name, COUNT(*) AS cohort_count');
        foreach (array_keys(self::FUNNEL) as $stage) $query->selectRaw("SUM(reached_{$stage}) AS reached_{$stage}, SUM(unknown_{$stage}) AS unknown_{$stage}");
        return $query->groupBy('owner_legacy_id');
    }

    private function presentFunnelGroup(object $row, array $filters): array
    {
        return ['can' => true, 'cohort_from' => $filters['cohort_from'] ?? $filters['date_from'] ?? null,
            'cohort_to' => $filters['cohort_to'] ?? $filters['date_to'] ?? null, 'observed_on' => $this->observedOn($filters)->toDateString(),
            'sales_user_legacy_id' => $row->owner_legacy_id === null ? null : (int) $row->owner_legacy_id,
            'sales_user_name' => $row->owner_name, 'cohort_count' => (int) $row->cohort_count,
            'stages' => $this->funnelStages($row), 'query' => ['kind' => 'cohort_stage'] + ($row->owner_legacy_id === null
                ? ['sales_user_unassigned' => true] : ['sales_user_legacy_id' => (int) $row->owner_legacy_id])];
    }

    private function funnel(object $actor, array $filters): array
    {
        $can = $this->scope->leads($actor, $filters) !== null;
        $query = $this->cohortFacts($actor, $filters)->selectRaw('COUNT(*) AS cohort_count');
        foreach (array_keys(self::FUNNEL) as $stage) $query->selectRaw("SUM(reached_{$stage}) AS reached_{$stage}, SUM(unknown_{$stage}) AS unknown_{$stage}");
        $row = $can ? $query->first() : null;
        return ['can' => $can, 'cohort_from' => $filters['cohort_from'] ?? $filters['date_from'] ?? null,
            'cohort_to' => $filters['cohort_to'] ?? $filters['date_to'] ?? null, 'observed_on' => $this->observedOn($filters)->toDateString(),
            'cohort_count' => $row ? (int) $row->cohort_count : null, 'stages' => $this->funnelStages($row),
            'evidence' => '录入、有效线索、商机创建、实际阶段日志及首次成交；缺少完整历史的未到达项保留未知'];
    }

    private function funnelStages(?object $row): array
    {
        $stages = [];
        foreach (self::FUNNEL as $key => $name) $stages[] = ['key' => $key, 'name' => $name,
            'count' => $row ? (int) ($row->{'reached_'.$key} ?? 0) : null,
            'unknown_count' => $row ? (int) ($row->{'unknown_'.$key} ?? 0) : null,
            'query' => ['kind' => 'cohort_stage', 'stage' => $key]];
        return $stages;
    }

    private function cohortFacts(object $actor, array $filters): QueryBuilder
    {
        $cutoff = $this->cutoff($filters);
        $leads = $this->scope->leads($actor, $filters) ?? CrmLead::query()->whereRaw('1 = 0');
        $leads = $this->scope->period($leads, ['date_from' => $filters['cohort_from'] ?? $filters['date_from'] ?? null,
            'date_to' => $filters['cohort_to'] ?? $filters['date_to'] ?? null], 'created_at')
            ->where('status', '!=', 'draft')->where('created_at', '<', $cutoff);
        // 批次归属只在线索上筛选；转商机后的负责人变化不能删掉该批线索的真实推进。
        $opportunities = $this->scope->opportunities($actor, []) ?? CrmOpportunity::query()->whereRaw('1 = 0');
        $opportunities->whereIn('source_lead_id', (clone $leads)->select('erp_crm_leads.id'))->where('created_at', '<', $cutoff);
        $oppIds = (clone $opportunities)->select('id')->toBase();
        $opps = (clone $opportunities)->select(['id', 'source_lead_id', 'created_at', 'won_at'])->toBase();
        $events = DB::query()->fromSub(clone $opps, 'o')->selectRaw("source_lead_id AS lead_id, 'requirement' AS stage, created_at AS arrived_at");
        $logs = DB::table('erp_crm_opportunity_stage_logs as h')->joinSub(clone $opps, 'o', 'o.id', '=', 'h.opportunity_id')
            ->whereIn('h.action', ['create', 'stage_forward', 'stage_backward'])->where('h.created_at', '<', $cutoff);
        $events->unionAll((clone $logs)->selectRaw('o.source_lead_id AS lead_id, h.to_stage AS stage, h.created_at AS arrived_at'));
        $events->unionAll((clone $logs)->whereNotNull('h.from_stage')->selectRaw('o.source_lead_id AS lead_id, h.from_stage AS stage, h.created_at AS arrived_at'));
        $events->unionAll(DB::query()->fromSub(clone $opps, 'o')->whereNotNull('won_at')->where('won_at', '<', $cutoff)
            ->selectRaw("source_lead_id AS lead_id, 'won' AS stage, won_at AS arrived_at"));
        if ($this->scope->abilities($actor)['orders']) {
            // 首次生效是历史到达，取消订单不能抹掉；这里只复用订单原读取权限，不套当前有效金额范围。
            $orders = SalesOrder::query();
            $this->orderVisibility->apply($orders, $actor, true);
            $events->unionAll(DB::table('erp_crm_opportunity_orders as link')
            ->joinSub(clone $opps, 'o', 'o.id', '=', 'link.opportunity_id')->whereIn('link.order_id', $orders->select('erp_sales_orders.id')->toBase())
            ->whereNotNull('link.first_effective_at')->where('link.first_effective_at', '<', $cutoff)
            ->selectRaw("o.source_lead_id AS lead_id, 'won' AS stage, link.first_effective_at AS arrived_at"));
        }
        $arrivals = DB::query()->fromSub($events, 'e')->select('lead_id');
        foreach (array_keys(CrmOpportunityApplicationService::STAGES) as $stage) $arrivals->selectRaw("MAX(stage = ?) AS reached_{$stage}", [$stage]);
        $arrivals->selectRaw("MAX(stage = 'won') AS reached_won, MIN(IF(stage = 'won', arrived_at, NULL)) AS first_won_at")->groupBy('lead_id');
        $complete = DB::table('erp_crm_opportunity_stage_logs')->whereIn('opportunity_id', $oppIds)
            ->where('action', 'create')->where('to_stage', 'requirement')->where('created_at', '<', $cutoff)
            ->selectRaw('opportunity_id, 1 AS complete')->groupBy('opportunity_id');
        $history = DB::query()->fromSub(clone $opps, 'o')->leftJoinSub($complete, 'h', 'h.opportunity_id', '=', 'o.id')
            ->selectRaw('source_lead_id, MAX(COALESCE(h.complete, 0)) AS complete')->groupBy('source_lead_id');
        $facts = DB::query()->fromSub($leads->select(['id', 'owner_legacy_id', 'owner_name', 'status', 'legacy_id', 'confirmed_at', 'created_at', 'is_new_inquiry'])->toBase(), 'l')
            ->leftJoinSub($arrivals, 'a', 'a.lead_id', '=', 'l.id')->leftJoinSub($history, 'h', 'h.source_lead_id', '=', 'l.id')
            ->selectRaw("'lead' AS target_type, l.id AS target_id, l.owner_legacy_id, l.owner_name, l.created_at AS recorded_at,
                l.is_new_inquiry, a.first_won_at, 1 AS reached_recorded, 0 AS unknown_recorded")
            ->selectRaw('IF(l.confirmed_at < ?, 1, 0) AS reached_confirmed, IF(l.legacy_id IS NOT NULL AND l.confirmed_at IS NULL, 1, 0) AS unknown_confirmed', [$cutoff]);
        // 缺失日志或不可见的后续商机只能标未知；不能用当前阶段补造过去到达。
        foreach (array_merge(array_keys(CrmOpportunityApplicationService::STAGES), ['won']) as $stage) {
            $facts->selectRaw("COALESCE(a.reached_{$stage}, 0) AS reached_{$stage},
                IF(COALESCE(a.reached_{$stage}, 0) = 0 AND (l.legacy_id IS NOT NULL OR (l.status = 'converted' AND h.source_lead_id IS NULL) OR h.complete = 0), 1, 0) AS unknown_{$stage}");
        }
        return DB::query()->fromSub($facts, 'cohort_facts');
    }

    private function conversion(object $actor, array $filters): array
    {
        $can = $this->scope->leads($actor, $filters) !== null;
        $rows = [];
        foreach (['total' => '累计成交线索', 'within_30' => '30天内成交', 'over_30' => '超过30天成交',
            'new' => '新咨询成交', 'old' => '老咨询成交', 'source_unknown' => '咨询类型未填写'] as $key => $name) {
            $rows[] = ['key' => $key, 'name' => $name, 'count' => $can ? $this->conversionQuery($actor, $filters, $key)->count() : null,
                'query' => ['kind' => 'cohort_conversion', 'conversion_bucket' => $key]];
        }
        return ['can' => $can, 'groups' => $rows, 'source_basis' => 'is_new_inquiry',
            'duration_basis' => '线索录入到首次实际成交的时间，30天内包含第30天，不表示首次购买',
            'amount_basis' => '成交、已收、尾款仅使用统一订单金额统计，不与线索预计金额相加'];
    }

    private function conversionQuery(object $actor, array $filters, string $bucket): QueryBuilder
    {
        abort_unless(in_array($bucket, ['total', 'within_30', 'over_30', 'new', 'old', 'source_unknown'], true), 422, '请选择有效的转化分类。');
        $query = $this->cohortFacts($actor, $filters)->where('reached_won', 1);
        if ($bucket === 'within_30') $query->whereRaw('first_won_at >= recorded_at AND first_won_at <= DATE_ADD(recorded_at, INTERVAL 30 DAY)');
        elseif ($bucket === 'over_30') $query->whereRaw('first_won_at > DATE_ADD(recorded_at, INTERVAL 30 DAY)');
        elseif ($bucket === 'new') $query->where('is_new_inquiry', 1);
        elseif ($bucket === 'old') $query->where('is_new_inquiry', 0);
        elseif ($bucket === 'source_unknown') $query->whereNull('is_new_inquiry');
        return $query;
    }

    private function opportunityQuery(Builder $base, string $kind, array $filters): Builder
    {
        $query = clone $base;
        if ($kind === 'pipeline_lost') {
            $query = $this->scope->period($query, $filters, 'lost_at')->where('status', 'lost')->whereNotNull('lost_at')->where('lost_at', '<=', now());
            if (!empty($filters['dimension_empty'])) $query->where(fn ($q) => $q->whereNull('lost_reason')->orWhereRaw("TRIM(lost_reason) = ''"));
            elseif (array_key_exists('lost_reason', $filters)) $query->whereRaw('TRIM(lost_reason) = ?', [$filters['lost_reason']]);
            return $query;
        }
        if ($kind === 'pipeline_won') return $this->scope->period($query, $filters, 'won_at')->whereNotNull('won_at')->where('won_at', '<=', now());
        $query->where('status', 'open');
        if ($kind === 'pipeline_stage') $query->where('stage', $filters['stage'] ?? 'requirement');
        elseif ($kind === 'pipeline_stalled') $query->where('stage_entered_at', '<=', now()->subDays((int) ($filters['stalled_days'] ?? 30)));
        elseif ($kind === 'pipeline_overdue_followup') $query->whereNotNull('next_contact_at')->where('next_contact_at', '<', now());
        elseif ($kind === 'pipeline_overdue_close') $query->whereNotNull('expected_close_date')->where('expected_close_date', '<', today()->toDateString());
        elseif ($kind === 'pipeline_unknown_stage_age') $query->where(fn ($q) => $q->whereNull('stage_entered_at')->orWhere('stage_entered_at', '>', now()));
        return $query;
    }

    private function expectedAmounts(Builder $query): array
    {
        return (clone $query)->selectRaw('currency, SUM(expected_amount) AS known_amount, COUNT(expected_amount) AS known_count, SUM(expected_amount IS NULL) AS unknown_count')
            ->groupBy('currency')->orderBy('currency')->get()->map(fn ($row) => [
                'currency' => $row->currency, 'known_amount' => $row->known_amount, 'known_count' => (int) $row->known_count,
                'unknown_count' => (int) $row->unknown_count,
            ])->all();
    }

    private function canContact(object $actor, array $filters): bool
    {
        return $this->scope->leads($actor, $filters) !== null || $this->scope->opportunities($actor, $filters) !== null || $this->scope->customers($actor, $filters) !== null;
    }

    /** 归属来自原对象；直接客户计划不能再读客户的多需求汇总 next_contact_at。 */
    private function subjects(object $actor, array $filters): QueryBuilder
    {
        $parts = [];
        foreach (['lead' => $this->scope->leads($actor, $filters), 'opportunity' => $this->scope->opportunities($actor, $filters),
            'customer' => $this->scope->customers($actor, $filters)] as $type => $query) {
            if (!$query) continue;
            $table = $query->getModel()->getTable();
            $id = $table.'.id';
            $customerId = $type === 'customer' ? $id : $table.'.customer_id';
            $title = match ($type) { 'lead' => 'demand_summary', 'opportunity' => 'name', default => 'customer_name' };
            $active = match ($type) { 'lead' => "status = 'active'", 'opportunity' => "status = 'open'", default => "status NOT IN ('disabled', 'blacklisted')" };
            if ($type === 'lead') $query->where('status', '!=', 'draft');
            $parts[] = $query->selectRaw("? AS target_type, {$id} AS target_id, {$customerId} AS customer_id,
                {$table}.customer_name, {$table}.{$title} AS title, {$table}.owner_legacy_id, {$table}.owner_name,
                {$table}.".($type === 'customer' ? 'owner_department_legacy_id' : 'department_legacy_id')." AS department_legacy_id, {$table}.created_at AS target_created_at, {$table}.status,
                ".($type === 'customer' ? 'NULL' : $table.'.next_plan')." AS next_plan,
                IF({$active}, 1, 0) AS is_active, ".($type === 'customer' ? 'NULL' : $table.'.next_contact_at')." AS current_planned_at", [$type])->toBase();
        }
        if (!$parts) return DB::query()->from('erp_crm_leads')->whereRaw('1 = 0')->selectRaw("'lead' AS target_type, id AS target_id, customer_id, customer_name, demand_summary AS title,
            owner_legacy_id, owner_name, department_legacy_id, created_at AS target_created_at, status, next_plan, 0 AS is_active, next_contact_at AS current_planned_at");
        $union = array_shift($parts);
        foreach ($parts as $part) $union->unionAll($part);
        return DB::query()->fromSub($union, 'visible_subjects');
    }

    private function plans(object $actor, array $filters): QueryBuilder
    {
        $subjects = $this->subjects($actor, $filters);
        $cutoff = $this->cutoff($filters);
        $history = DB::query()->fromSub(clone $subjects, 's')->join('erp_crm_followups as p', function ($join) {
            $join->on('p.subject_type', '=', 's.target_type')->on('p.subject_id', '=', 's.target_id');
        })->whereNotNull('p.next_contact_at')->where('p.contacted_at', '<', $cutoff)
            ->selectRaw("s.*, p.id AS plan_id, p.next_contact_at AS planned_at, p.contacted_at AS planned_from_at,
                p.next_plan AS planned_action, IF(EXISTS(SELECT 1 FROM erp_crm_followups AS revised
                    WHERE revised.subject_type = p.subject_type AND revised.subject_id = p.subject_id
                    AND revised.version > 1 AND revised.updated_at >= ?), 'history_changed', 'history') AS evidence", [$cutoff]);
        $successor = DB::table('erp_crm_followups as done')->whereColumn('done.subject_type', 'p.subject_type')->whereColumn('done.subject_id', 'p.subject_id')
            ->where('done.contacted_at', '<', $cutoff)->where(fn ($q) => $q->whereColumn('done.contacted_at', '>', 'p.contacted_at')
                ->orWhere(fn ($tie) => $tie->whereColumn('done.contacted_at', 'p.contacted_at')->whereColumn('done.id', '>', 'p.id')))
            ->orderBy('done.contacted_at')->orderBy('done.id')->limit(1);
        $history->selectSub((clone $successor)->select('done.contacted_at'), 'completed_at')
            ->selectSub((clone $successor)->select('done.operator_legacy_id'), 'completed_by_legacy_id')
            ->selectSub((clone $successor)->select('done.operator_name'), 'completed_by_name');
        // shortcut: 主对象编辑的旧计划缺少独立计划版本，当前字段仅作当前队列，补齐计划事件后才能还原历史。
        $current = DB::query()->fromSub(clone $subjects, 's')->where('s.is_active', 1)->whereNotNull('s.current_planned_at')
            ->where('s.target_created_at', '<', $cutoff)->whereNotExists(function ($q) {
                $q->from('erp_crm_followups as latest')->whereColumn('latest.subject_type', 's.target_type')->whereColumn('latest.subject_id', 's.target_id')
                    ->whereColumn('latest.next_contact_at', 's.current_planned_at')->whereNotExists(function ($newer) {
                        $newer->from('erp_crm_followups as newer')->whereColumn('newer.subject_type', 'latest.subject_type')->whereColumn('newer.subject_id', 'latest.subject_id')
                            ->where(fn ($match) => $match->whereColumn('newer.contacted_at', '>', 'latest.contacted_at')
                                ->orWhere(fn ($tie) => $tie->whereColumn('newer.contacted_at', 'latest.contacted_at')->whereColumn('newer.id', '>', 'latest.id')));
                    });
            })->selectRaw("s.*, 0 AS plan_id, s.current_planned_at AS planned_at, NULL AS planned_from_at, s.next_plan AS planned_action, 'current_only' AS evidence,
                NULL AS completed_at, NULL AS completed_by_legacy_id, NULL AS completed_by_name");
        return DB::query()->fromSub($history->unionAll($current), 'contact_plans');
    }

    private function contactQuery(object $actor, array $filters, string $kind): QueryBuilder
    {
        if (in_array($kind, ['contact_pending_accept', 'contact_uncontacted', 'contact_unverified'], true)) {
            $leads = $this->scope->leads($actor, $filters) ?? CrmLead::query()->whereRaw('1 = 0');
            if ($kind === 'contact_pending_accept') $leads->where('status', 'pending_acceptance');
            elseif ($kind === 'contact_unverified') $leads->where('status', 'pending_review');
            else $leads->where('status', 'active')->whereNull('first_contact_at');
            return DB::query()->fromSub($leads->selectRaw("'lead' AS target_type, id AS target_id, customer_id, customer_name, demand_summary AS title,
                owner_legacy_id, owner_name, next_contact_at AS planned_at, next_plan, 0 AS plan_id")->toBase(), 'lead_queue');
        }
        if ($kind === 'contact_records') {
            $records = DB::query()->fromSub($this->subjects($actor, $filters), 's')->join('erp_crm_followups as f', function ($join) {
                $join->on('f.subject_type', '=', 's.target_type')->on('f.subject_id', '=', 's.target_id');
            })->where('f.contacted_at', '<', $this->cutoff($filters));
            $this->period($records, $filters, 'f.contacted_at');
            return DB::query()->fromSub($records->selectRaw('s.*, f.id AS plan_id, f.contacted_at AS planned_at,
                f.contacted_at AS contacted_at, f.operator_legacy_id, f.operator_name, f.method, f.result, f.content'), 'actual_contacts');
        }
        $planFilters = $filters;
        if (in_array($kind, ['contact_overdue', 'contact_due_soon', 'contact_unknown_history'], true)) unset($planFilters['observed_on']);
        $plans = $this->plans($actor, $planFilters);
        if (in_array($kind, ['contact_due', 'contact_completed'], true)) {
            $plans->where('evidence', 'history')->where('planned_at', '<', $this->cutoff($filters));
            $this->period($plans, $filters, 'planned_at');
            if ($kind === 'contact_completed') $plans->whereNotNull('completed_at');
        } elseif ($kind === 'contact_unknown_history') $plans->where('evidence', '!=', 'history');
        elseif (in_array($kind, ['contact_overdue', 'contact_due_soon'], true)) {
            // 主对象编辑可以改期或清除计划，旧跟进中的计划仍留历史，不能继续冒充当前待办。
            $plans->where('is_active', 1)->whereNull('completed_at')
                ->where(fn ($q) => $q->where('target_type', 'customer')->orWhereColumn('current_planned_at', 'planned_at'));
            if ($kind === 'contact_overdue') $plans->where('planned_at', '<', now());
            else $plans->where('planned_at', '>=', now())->where('planned_at', '<', now()->addDays(7));
        }
        return $plans;
    }

    private function contactSales(object $actor, array $filters): LengthAwarePaginator
    {
        $due = $this->contactQuery($actor, $filters, 'contact_due')->selectRaw('owner_legacy_id, owner_name,
            1 AS due_count, IF(completed_at IS NOT NULL, 1, 0) AS completed_count, 0 AS overdue_count');
        $overdue = $this->contactQuery($actor, $filters, 'contact_overdue')->selectRaw('owner_legacy_id, owner_name,
            0 AS due_count, 0 AS completed_count, 1 AS overdue_count');
        return DB::query()->fromSub($due->unionAll($overdue), 'sales_plan_facts')
            ->selectRaw('owner_legacy_id, MAX(owner_name) AS owner_name, SUM(due_count) AS due_count,
                SUM(completed_count) AS completed_count, SUM(overdue_count) AS overdue_count')
            ->groupBy('owner_legacy_id')->orderByRaw('owner_legacy_id IS NULL')->orderBy('owner_legacy_id')->paginate($this->perPage($filters))
            ->through(fn ($row) => ['sales_user_legacy_id' => $row->owner_legacy_id === null ? null : (int) $row->owner_legacy_id,
                'sales_user_name' => $row->owner_name, 'due_count' => (int) $row->due_count, 'completed_count' => (int) $row->completed_count,
                'overdue_count' => (int) $row->overdue_count, 'query' => ['kind' => 'contact_due'] + ($row->owner_legacy_id === null
                    ? ['sales_user_unassigned' => true] : ['sales_user_legacy_id' => (int) $row->owner_legacy_id])]);
    }

    private function presentOpportunity(CrmOpportunity $opportunity, object $actor): array
    {
        $allowed = $this->opportunities->abilities($opportunity, $actor);
        $stageEnd = $opportunity->lost_at ?? $opportunity->won_at ?? now();
        return $opportunity->only(['opportunity_no', 'stage', 'status', 'stage_entered_at', 'expected_amount', 'currency', 'expected_close_date', 'last_contact_at', 'next_contact_at', 'next_plan', 'lost_reason', 'won_at']) + [
            'target_type' => 'opportunity', 'target_id' => (int) $opportunity->id, 'customer_id' => (int) $opportunity->customer_id,
            'customer_name' => $opportunity->customer_name, 'title' => $opportunity->name, 'owner_legacy_id' => (int) $opportunity->owner_legacy_id,
            'owner_name' => $opportunity->owner_name, 'stage_label' => CrmOpportunityApplicationService::STAGES[$opportunity->stage] ?? $opportunity->stage,
            'stage_days' => $opportunity->stage_entered_at && $opportunity->stage_entered_at->lte($stageEnd) ? (int) $opportunity->stage_entered_at->diffInDays($stageEnd) : null,
            'allowed' => $allowed, 'action' => !empty($allowed['follow']) ? 'followup' : 'view']
            + $this->overdueFields($opportunity->next_contact_at, $opportunity->status === 'open');
    }

    private function presentTarget(object $row, object $actor, string $kind): array
    {
        $data = (array) $row;
        $type = $row->target_type;
        $id = (int) $row->target_id;
        if ($type === 'lead') {
            $target = $this->leads->visible($id, $actor);
            $allowed = $this->leads->abilities($target, $actor);
            $allowed['view'] = true;
            if ($kind === 'contact_unverified') $allowed['verify'] = (bool) (app(CrmDuplicateReviewApplicationService::class)->forLead($target, $actor)['can_resolve'] ?? false);
            if (isset($allowed['verify'])) $allowed['review'] = $allowed['verify'];
            $data += $target->only(['customer_id', 'customer_name', 'owner_legacy_id', 'owner_name', 'lead_no', 'status', 'next_plan']);
            $data['title'] = $data['title'] ?? $target->demand_summary ?? $target->lead_no;
        } elseif ($type === 'opportunity') {
            $target = $this->opportunities->visible($id, $actor);
            $allowed = $this->opportunities->abilities($target, $actor);
        } else {
            $target = SalesCustomer::query()->findOrFail($id);
            abort_unless($this->customers->relation($target, $actor) !== null, 403, '无权查看该客户。');
            $allowed = $this->customers->abilities($target, $actor);
            $allowed['follow'] = $allowed['follow'] && $this->customers->can($actor, 'crm.lead.follow');
        }
        $action = match ($kind) { 'contact_pending_accept' => 'receive', 'contact_unverified' => 'verify', 'contact_records' => 'view', default => 'followup' };
        $ability = $action === 'receive' ? 'accept' : ($action === 'followup' ? 'follow' : $action);
        $data['target_id'] = $id;
        if ($kind === 'contact_records') $data['followup_id'] = (int) $row->plan_id;
        if (property_exists($row, 'planned_action')) {
            $data['next_plan'] = $row->planned_action;
            $plannedAt = $row->planned_at ? Carbon::parse($row->planned_at) : null;
            $currentPlan = $type === 'customer' || ($row->current_planned_at ?? null) === $row->planned_at;
            $data += $this->overdueFields($plannedAt, !$row->completed_at && (bool) ($row->is_active ?? true) && $currentPlan);
        }
        $data['allowed'] = $allowed;
        $data['action'] = !empty($allowed[$ability]) ? $action : 'view';
        return $data;
    }

    private function overdueFields(?Carbon $plannedAt, bool $pending): array
    {
        $overdue = $pending && $plannedAt && $plannedAt->isPast();
        $days = $overdue ? max(0, (int) $plannedAt->diffInDays(now())) : 0;
        return ['is_overdue' => $overdue, 'overdue_days' => $overdue ? $days : null,
            'overdue_less_than_day' => $overdue && $days === 0,
            'overdue_label' => $overdue ? ($days === 0 ? '不足1天' : $days.'天') : null];
    }

    private function metric(?int $count, string $kind): array { return ['count' => $count, 'can' => $count !== null, 'query' => ['kind' => $kind]]; }
    private function perPage(array $filters): int { return max(1, min(100, (int) ($filters['per_page'] ?? 10))); }
    private function observedOn(array $filters): Carbon { return isset($filters['observed_on']) ? Carbon::parse($filters['observed_on'])->startOfDay()->min(today()) : today(); }
    private function cutoff(array $filters): string { return $this->observedOn($filters)->isToday() ? now()->toDateTimeString() : $this->observedOn($filters)->addDay()->toDateTimeString(); }
    private function period(QueryBuilder $query, array $filters, string $column): void
    {
        if (!empty($filters['date_from'])) $query->where($column, '>=', $filters['date_from'].' 00:00:00');
        if (!empty($filters['date_to'])) $query->where($column, '<', Carbon::parse($filters['date_to'])->addDay()->toDateTimeString());
    }
}
