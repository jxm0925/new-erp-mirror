<?php

namespace App\Services\Erp\Crm;

use App\Models\Erp\CrmFollowup;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\CrmAnalysisProvider;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class CrmDailyFacts
{
    public function __construct(
        private readonly CrmDashboardScopeService $scope,
        private readonly CrmCustomerAccessService $access,
        private readonly CrmAssignmentAccessService $teams,
        private readonly AuthContextService $auth,
        private readonly CrmSalesStaffService $salesStaff,
    ) {}

    public function departments(object $actor): array
    {
        if (!$this->access->can($actor, 'crm.team.view')) return [];
        $ids = $this->auth->isSuperAdmin($actor) || $this->auth->dataScope($actor) === 'all'
            ? $this->teams->managedDepartments($actor)
            : $this->teams->expandDepartments(DB::table('erp_department_users')->where('user_legacy_id', $actor->legacy_id)
                ->where('is_principal', true)->pluck('department_legacy_id')->all());
        return DB::table('erp_departments')->whereIn('legacy_id', $ids ?: [0])->where('status', 'normal')->whereNull('deleted_at')
            ->orderBy('sort')->orderBy('legacy_id')->get(['legacy_id as id', 'parent_legacy_id as parent_id', 'name'])
            ->map(fn ($row) => (array) $row)->all();
    }

    public function salesStaffForActor(object $actor): array
    {
        $selfId = (int) $actor->legacy_id;
        $selfName = $this->salesStaff->name($selfId) ?: ($actor->nickname ?: $actor->username ?: '当前用户');
        $selfDept = $this->salesStaff->departmentOf($selfId);
        $selfRow = [
            'id' => $selfId,
            'name' => $selfName,
            'department_id' => $selfDept,
            'department_name' => $selfDept ? (string) DB::table('erp_departments')->where('legacy_id', $selfDept)->value('name') : '',
            'is_self' => true,
        ];

        $isAll = $this->auth->isSuperAdmin($actor) || $this->auth->dataScope($actor) === 'all';
        if (!$isAll && !$this->access->can($actor, 'crm.team.view')) {
            return [$selfRow];
        }

        $deptIds = $isAll ? null : $this->teams->managedDepartments($actor);
        if ($deptIds !== null && !$deptIds) {
            return [$selfRow];
        }

        $today = today()->toDateString();
        $rows = DB::table('erp_business_staff as s')
            ->join('erp_legacy_admin_users as u', 'u.legacy_id', '=', 's.user_legacy_id')
            ->leftJoin('erp_departments as d', 'd.legacy_id', '=', 's.department_legacy_id')
            ->where('s.staff_type', 'sales')
            ->where('s.status', 'enabled')
            ->whereNull('u.deleted_at')
            ->whereIn(DB::raw('LOWER(TRIM(u.status))'), ['normal', 'active'])
            ->where(fn ($q) => $q->whereNull('s.effective_from')->orWhere('s.effective_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('s.effective_to')->orWhere('s.effective_to', '>=', $today))
            ->when($deptIds !== null, fn ($q) => $q->whereIn('s.department_legacy_id', $deptIds))
            ->orderBy('d.name')
            ->orderBy('u.legacy_id')
            ->get(['u.legacy_id as id', 'u.nickname', 'u.username', 's.department_legacy_id as department_id', 'd.name as department_name']);

        $result = [];
        $seen = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            if (isset($seen[$id])) continue;
            $seen[$id] = true;
            $result[] = [
                'id' => $id,
                'name' => $row->nickname ?: $row->username,
                'department_id' => $row->department_id ? (int) $row->department_id : null,
                'department_name' => (string) ($row->department_name ?? ''),
                'is_self' => $id === $selfId,
            ];
        }
        if (!isset($seen[$selfId])) {
            array_unshift($result, $selfRow);
        }
        return $result;
    }

    public function canInspectSales(object $actor, int $salesId): bool
    {
        if ($salesId <= 0) return false;
        if ($salesId === (int) $actor->legacy_id) return true;
        $userExists = DB::table('erp_legacy_admin_users')->where('legacy_id', $salesId)->whereNull('deleted_at')->exists();
        if (!$userExists) return false;
        if ($this->auth->isSuperAdmin($actor) || $this->auth->dataScope($actor) === 'all') return true;
        if (!$this->access->can($actor, 'crm.team.view')) return false;
        $managed = $this->teams->managedDepartments($actor);
        if (!$managed) return false;
        $dept = $this->salesStaff->departmentOf($salesId);
        if ($dept && in_array($dept, $managed, true)) return true;
        return DB::table('erp_department_users')->where('user_legacy_id', $salesId)
            ->whereIn('department_legacy_id', $managed)->exists();
    }

    public function subjects(object $actor, ?int $owner, bool $active = true): Builder
    {
        $filters = $owner ? ['sales_user_legacy_id' => $owner] : [];
        $leads = $this->scope->leads($actor, $filters);
        $opps = $this->scope->opportunities($actor, $filters);
        $customers = $this->scope->customers($actor, $filters);
        $amountColumn = $this->scope->abilities($actor)['amounts'] ? 'expected_amount' : 'NULL as expected_amount';
        $queries = [];
        if ($leads) {
            if ($active) $leads->where('status', 'active')->whereNotNull('customer_id');
            $queries[] = $leads->selectRaw("'lead' as kind, id, lead_no as number, customer_id, customer_name, demand_summary as project,
                nine_step_stage as stage, customer_pool as pool, {$amountColumn}, currency, next_contact_at, next_plan, last_contact_at,
                NULL as close_date, NULL as source_lead_id, owner_legacy_id as owner_id, version as subject_version, created_at, updated_at")->toBase();
        }
        if ($opps) {
            if ($active) $opps->where('status', 'open');
            $queries[] = $opps->selectRaw("'opportunity' as kind, id, opportunity_no as number, customer_id, customer_name, name as project,
                stage, COALESCE(NULLIF(customer_pool, ''), (SELECT l.customer_pool FROM erp_crm_leads l WHERE l.id = erp_crm_opportunities.source_lead_id LIMIT 1)) as pool,
                {$amountColumn}, currency, next_contact_at, next_plan, last_contact_at, expected_close_date as close_date, source_lead_id,
                owner_legacy_id as owner_id, version as subject_version, created_at, updated_at")->toBase();
        }
        if ($customers) {
            if ($active) $customers->whereNotIn('status', ['disabled', 'blacklisted']);
            $queries[] = $customers->selectRaw("'customer' as kind, id, customer_code as number, id as customer_id, customer_name,
                NULL as project, lifecycle_stage as stage, customer_pool as pool, NULL as expected_amount, 'CNY' as currency,
                next_contact_at, next_plan, last_contact_at, NULL as close_date, NULL as source_lead_id,
                owner_legacy_id as owner_id, crm_version as subject_version, created_at, updated_at")->toBase();
        }
        abort_if(!$queries, 403, '没有线索或商机查看权限。');
        $query = array_shift($queries);
        foreach ($queries as $other) $query->unionAll($other);
        return DB::query()->fromSub($query, 'subjects');
    }

    public function candidates(object $actor, string $day, string $keyword, int $page, ?int $ownerId = null): array
    {
        $owner = $ownerId ?: (int) $actor->legacy_id;
        $query = $this->subjects($actor, $owner);
        if ($keyword !== '') $query->where(fn ($q) => $q->where('customer_name', 'like', "%$keyword%")
            ->orWhere('number', 'like', "%$keyword%")->orWhere('project', 'like', "%$keyword%"));
        $query->orderByRaw('CASE WHEN next_contact_at < ? THEN 0 WHEN DATE(last_contact_at) = ? THEN 1 WHEN close_date <= ? THEN 2 ELSE 3 END',
            [Carbon::parse($day)->addDays(2)->startOfDay(), $day, Carbon::parse($day)->addDay()->toDateString()])
            ->orderByDesc('last_contact_at')->orderBy('kind')->orderBy('id');
        $rows = $query->paginate(10, ['*'], 'page', $page);
        return $rows->through(fn ($row) => $this->describe($row))->toArray();
    }

    public function byKeys(object $actor, ?int $owner, array $keys, bool $active = true): array
    {
        if (!$keys) return [];
        // 历史日报按实际操作者保留事实，不能再按现任负责人截掉已转交的对象。
        $query = $this->subjects($actor, $active ? $owner : null, $active);
        $query->where(function ($q) use ($keys) {
            foreach (['lead','opportunity','customer'] as $kind) {
                $ids = [];
                foreach ($keys as $key) if (str_starts_with($key, $kind.':')) $ids[] = (int) substr($key, strlen($kind) + 1);
                $q->orWhere(fn ($part) => $part->where('kind', $kind)->whereIn('id', $ids));
            }
        });
        $rows = $query->get()->map(fn ($row) => $this->describe($row))->keyBy('key')->all();
        if (!$active && $owner) {
            $history = $this->followupQuery($actor, $owner)->where(function ($q) use ($keys) {
                foreach (['lead', 'opportunity', 'customer'] as $kind) {
                    $ids = array_map(fn ($key) => (int) substr($key, strlen($kind) + 1), array_filter($keys, fn ($key) => str_starts_with($key, $kind.':')));
                    if ($ids) $q->orWhere(fn ($s) => $s->where('subject_type', $kind)->whereIn('subject_id', $ids));
                }
            })->whereNotExists(function ($q) use ($owner) {
                $q->from('erp_crm_followups as newer')->where('newer.operator_legacy_id', $owner)
                    ->whereColumn('newer.subject_type', 'erp_crm_followups.subject_type')->whereColumn('newer.subject_id', 'erp_crm_followups.subject_id')
                    ->where(fn ($n) => $n->whereColumn('newer.contacted_at', '>', 'erp_crm_followups.contacted_at')
                        ->orWhere(fn ($tie) => $tie->whereColumn('newer.contacted_at', 'erp_crm_followups.contacted_at')->whereColumn('newer.id', '>', 'erp_crm_followups.id')));
            });
            foreach ($history->get() as $followup) {
                $key = $followup->subject_type.':'.$followup->subject_id;
                if (!in_array($key, $keys, true) || isset($rows[$key])) continue;
                $snapshot = $followup->combat_snapshot ?? [];
                $rows[$key] = ['key' => $key, 'kind' => $followup->subject_type, 'id' => (int) $followup->subject_id,
                    'customer_id' => (int) $followup->customer_id, 'customer_name' => $snapshot['customer_name'] ?? '',
                    'project' => $snapshot['project'] ?? '', 'number' => $snapshot['number'] ?? '',
                    'stage' => $snapshot['stage_after'] ?? '', 'stage_label' => $snapshot['stage_after'] ?? '',
                    'expected_amount' => null, 'currency' => 'CNY', 'historical_only' => true];
            }
        }
        return $rows;
    }

    private function describe(object $row): array
    {
        $stage = CrmNineStepCatalog::normalizeStage($row->kind, $row->stage);
        $labels = collect(CrmNineStepCatalog::stages($row->kind))->pluck('label', 'value');
        return ['key' => $row->kind.':'.$row->id, 'stage' => $stage, 'stage_label' => $labels[$stage] ?? $stage,
            'pool' => app(CrmCustomerPoolService::class)->normalize($row->pool)] + (array) $row;
    }

    public function evidence(object $actor, array $subject, string $day): array
    {
        // 项目自己的记录与关联线索都可作为依据；客户级记录单独标注，避免串到其他商机。
        $query = $this->scope->followups($actor);
        if (!$query) return [];
        return $query->where('contacted_at', '<', Carbon::parse($day)->addDay()->startOfDay())
            ->where(function ($q) use ($subject) {
                $q->where(fn ($s) => $s->where('subject_type', $subject['kind'])->where('subject_id', $subject['id']));
                if ($subject['source_lead_id']) $q->orWhere(fn ($s) => $s->where('subject_type', 'lead')->where('subject_id', $subject['source_lead_id']));
                if ($subject['customer_id']) $q->orWhere(fn ($s) => $s->where('subject_type', 'customer')->where('subject_id', $subject['customer_id']));
            })->orderByRaw("CASE WHEN subject_type = ? THEN 0 WHEN subject_type = 'customer' THEN 2 ELSE 1 END", [$subject['kind']])
            ->orderByDesc('contacted_at')->orderByDesc('id')->limit(5)
            ->get(['id','subject_type','contacted_at','content','result','next_plan'])->map(fn ($f) => [
                'id' => $f->id, 'scope' => $f->subject_type, 'at' => $f->contacted_at->toDateTimeString(),
                'content' => mb_substr((string) $f->content, 0, 500), 'result' => mb_substr((string) $f->result, 0, 250),
                'plan' => mb_substr((string) $f->next_plan, 0, 200),
            ])->all();
    }

    public function coversSalesOrderOwner(object $actor, int $owner): bool
    {
        if ($owner === (int) $actor->legacy_id || $this->auth->isSuperAdmin($actor)) return true;
        $scope = $this->auth->dataScope($actor);
        return $scope === 'all' || ($scope === 'department' && in_array($owner, $this->auth->departmentUserIds($actor), true));
    }

    public function amounts(object $actor, string $day, array $filters): ?array
    {
        $start = Carbon::parse($day)->startOfDay();
        return $this->sumAmountsByRange($actor, $start, $start->copy()->addDay(), $filters);
    }

    public function monthlyAmounts(object $actor, string $day, array $filters): ?array
    {
        $end = Carbon::parse($day)->startOfDay()->addDay();
        $start = Carbon::parse($day)->startOfMonth()->startOfDay();
        return $this->sumAmountsByRange($actor, $start, $end, $filters);
    }

    private function sumAmountsByRange(object $actor, Carbon $start, Carbon $end, array $filters): ?array
    {
        if (!$this->scope->abilities($actor)['amounts']) return null;
        $orders = $this->scope->orders($actor, $filters);
        if (!$orders) return null;
        $closed = (clone $orders)->where('confirmed_at', '>=', $start)->where('confirmed_at', '<', $end)
            ->selectRaw('currency, SUM(total_amount) as amount')->groupBy('currency')->pluck('amount', 'currency');
        $shipped = DB::table('erp_sales_shipment_lines as s')->join('erp_sales_order_lines as l', 'l.id', '=', 's.sales_order_line_id')
            ->joinSub((clone $orders)->select(['id', 'currency'])->toBase(), 'o', 'o.id', '=', 'l.sales_order_id')
            ->where('s.line_status', 'shipped')->where('s.dispatched_at', '>=', $start)->where('s.dispatched_at', '<', $end)
            ->selectRaw('o.currency, ROUND(SUM(l.amount_incl_tax * s.sales_qty / NULLIF(l.order_qty,0)),2) as amount')
            ->groupBy('o.currency')->pluck('amount', 'currency');
        return ['closed' => $closed->all(), 'shipped' => $shipped->all()];
    }

    public function cockpitAmounts(object $actor, string $day, int $department): ?array
    {
        if (!$this->scope->abilities($actor)['amounts']) return null;
        $orders = $this->scope->orders($actor, ['department_legacy_id' => $department]);
        if (!$orders) return null;
        $end = Carbon::parse($day)->startOfDay()->addDay();
        $week = (clone $orders)->where('confirmed_at', '>=', $end->copy()->subDays(7))->where('confirmed_at', '<', $end)
            ->selectRaw('currency, ROUND(SUM(total_amount) / 7, 2) AS average')->groupBy('currency')->pluck('average', 'currency');
        $orders->where('confirmed_at', '<', $end)
            ->where(fn ($q) => $q->whereNull('cancelled_at')->orWhere('cancelled_at', '>=', $end))
            ->where(fn ($q) => $q->whereNull('short_closed_at')->orWhere('short_closed_at', '>=', $end));
        $lines = DB::table('erp_sales_order_lines as l')->joinSub($orders->select(['id', 'currency'])->toBase(), 'o', 'o.id', '=', 'l.sales_order_id')
            ->where('l.line_type', 'physical')->where('l.order_qty', '>', 0);
        $remaining = $lines->leftJoin('erp_sales_shipment_lines as s', function ($join) use ($end) {
            $join->on('s.sales_order_line_id', '=', 'l.id')->where('s.line_status', 'shipped')->where('s.dispatched_at', '<', $end);
        })->groupBy('o.currency', 'l.id', 'l.amount_incl_tax', 'l.order_qty')
            ->selectRaw('o.currency, l.amount_incl_tax * GREATEST(l.order_qty - COALESCE(SUM(s.sales_qty),0),0) / l.order_qty AS remaining');
        $unshipped = DB::query()->fromSub($remaining, 'line_amounts')->groupBy('currency')
            ->selectRaw('currency, ROUND(SUM(remaining),2) AS amount')->pluck('amount', 'currency');
        return ['seven_day_average' => $week->all(), 'unshipped' => $unshipped->all()];
    }

    public function confirmedOrders(object $actor, string $day, int $ownerId): array
    {
        $orders = $this->scope->orders($actor, ['sales_user_legacy_id' => $ownerId]);
        if (!$orders) return [];
        $showAmount = (bool) $this->scope->abilities($actor)['amounts'];
        $start = Carbon::parse($day)->startOfDay();
        $end = $start->copy()->addDay();
        $rows = (clone $orders)
            ->where('confirmed_at', '>=', $start)
            ->where('confirmed_at', '<', $end)
            ->orderBy('confirmed_at')
            ->limit(100)
            ->get([
                'id', 'sales_order_no', 'customer_name', 'customer_name_snapshot',
                'total_amount', 'currency', 'order_source', 'sales_channel_name_snapshot',
                'legacy_clue_source',
            ]);
        if ($rows->isEmpty()) return [];

        $linesByOrder = DB::table('erp_sales_order_lines')
            ->whereIn('sales_order_id', $rows->pluck('id')->all())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['sales_order_id', 'product_name', 'sku_name', 'item_name', 'order_qty'])
            ->groupBy('sales_order_id');

        $out = [];
        foreach ($rows as $o) {
            $parts = [];
            foreach ($linesByOrder->get($o->id, collect()) as $line) {
                $name = trim((string) ($line->product_name ?: $line->item_name ?: ''));
                $sku = trim((string) ($line->sku_name ?? ''));
                $qty = rtrim(rtrim((string) $line->order_qty, '0'), '.');
                if ($name === '' && $sku === '') continue;
                $label = $sku !== '' && $sku !== $name ? "{$name}({$sku})" : $name;
                $parts[] = $qty !== '' ? "{$label}×{$qty}" : $label;
            }
            $out[] = [
                'order_id' => (int) $o->id,
                'order_no' => (string) $o->sales_order_no,
                'customer_name' => (string) ($o->customer_name ?: $o->customer_name_snapshot ?: ''),
                'amount' => $showAmount ? round((float) $o->total_amount, 2) : null,
                'currency' => (string) ($o->currency ?: 'CNY'),
                'source' => (string) ($o->sales_channel_name_snapshot ?: $o->legacy_clue_source ?: $o->order_source ?: ''),
                'product' => mb_substr(implode('；', array_slice($parts, 0, 5)), 0, 300),
            ];
        }
        return $out;
    }

    /** 本人当日实际跟进按对象合并，不受对象后来关闭或转交影响。 */
    public function poolOthers(object $actor, string $day, int $ownerId, string $salesName): array
    {
        $start = Carbon::parse($day)->startOfDay();
        $end = $start->copy()->addDay();
        $followups = $this->followupQuery($actor, $ownerId)->where('contacted_at', '<', $end)
            ->where(fn ($q) => $q->whereBetween('contacted_at', [$start, $end->copy()->subSecond()])
                ->orWhereBetween('created_at', [$start, $end->copy()->subSecond()])
                ->orWhereBetween('updated_at', [$start, $end->copy()->subSecond()]))
            ->orderBy('contacted_at')->orderBy('id')->get();
        $keys = $followups->map(fn ($f) => $f->subject_type.':'.$f->subject_id)->unique()->all();
        $subjects = $this->byKeys($actor, $ownerId, $keys, false);
        $rows = [];
        foreach ($followups->groupBy(fn ($f) => $f->subject_type.':'.$f->subject_id) as $key => $actions) {
            $last = $actions->last();
            $snapshot = $last->combat_snapshot ?? [];
            $subject = $subjects[$key] ?? [];
            $stage = $snapshot['stage_after'] ?? $subject['stage'] ?? '';
            $labels = collect(CrmNineStepCatalog::stages($last->subject_type))->pluck('label', 'value');
            $scheduled = ($last->followup_schedule_mode ?? ($last->next_contact_at ? 'scheduled' : 'nurture')) === 'scheduled';
            $rows[] = [
                'key' => $key, 'row_ref' => 'followup:'.$key, 'source' => 'followup',
                'kind' => $last->subject_type, 'id' => (int) $last->subject_id,
                'subject_version' => $subject['subject_version'] ?? null,
                'number' => $snapshot['number'] ?? $subject['number'] ?? '',
                'pool' => app(CrmCustomerPoolService::class)->normalize($snapshot['pool_after'] ?? $subject['pool'] ?? null) ?? '',
                'customer_name' => $snapshot['customer_name'] ?? $subject['customer_name'] ?? '',
                'project' => $snapshot['project'] ?? $subject['project'] ?? '',
                'expected_amount' => $subject['expected_amount'] ?? null, 'currency' => $subject['currency'] ?? 'CNY',
                'stage' => $stage, 'stage_label' => $labels[$stage] ?? $stage, 'nine_step' => $labels[$stage] ?? $stage,
                'probability' => $snapshot['probability'] ?? '',
                'today_action' => $actions->map(fn ($f) => $f->contacted_at->format('H:i').' '.$f->method.'：'.$f->content)->implode('；'),
                'today_result' => $actions->pluck('result')->filter()->implode('；'),
                'action_type' => $last->method, 'promise' => $snapshot['customer_promise'] ?? '',
                'max_blocker' => $snapshot['blocker'] ?? '', 'expected_pay_time' => $snapshot['expected_pay_date'] ?? '',
                'tmr_must_action' => $scheduled ? (string) $last->next_plan : '',
                'contact_time' => $scheduled && $last->next_contact_at ? $last->next_contact_at->toDateTimeString() : '',
                'followup_schedule_mode' => $scheduled ? 'scheduled' : ($last->followup_schedule_mode ?? 'nurture'),
                'followup_ids' => $actions->pluck('id')->map(fn ($id) => (int) $id)->all(),
                'followup_fingerprint' => hash('sha256', json_encode($actions->map(fn ($f) => [$f->id, $f->version, $f->contacted_at->toDateTimeString()])->all())),
                'owner' => $salesName, 'status' => $scheduled ? '待推进' : '暂不排期',
            ];
            $lastIndex = count($rows) - 1;
            $rows[$lastIndex]['auto_snapshot'] = array_intersect_key($rows[$lastIndex], array_flip(['pool', 'nine_step', 'stage', 'stage_label', 'probability', 'max_blocker', 'promise']));
        }
        return $rows;
    }

    private function followupQuery(object $actor, int $operator): \Illuminate\Database\Eloquent\Builder
    {
        abort_unless($this->canInspectSales($actor, $operator), 403, '无权读取该销售的跟进事实。');
        // 自己填写的历史记录属于个人日报事实；主管仍受原对象可见范围约束。
        $query = $operator === (int) $actor->legacy_id ? CrmFollowup::query()
            : ($this->scope->followups($actor) ?? CrmFollowup::query()->whereRaw('1 = 0'));
        return $query->where('operator_legacy_id', $operator);
    }

    public function leadResponseStats(object $actor, string $day, int $ownerId): array
    {
        $leads = $this->scope->leads($actor, ['sales_user_legacy_id' => $ownerId]);
        if (!$leads) return ['total_recent' => 0, 'slow_count' => 0, 'slow_leads' => []];
        $since = Carbon::parse($day)->subDays(2)->startOfDay();
        $until = Carbon::parse($day)->addDay()->startOfDay();
        $rows = (clone $leads)
            ->whereIn('status', ['active', 'converted', 'pending_acceptance'])
            ->whereRaw('COALESCE(assigned_at, created_at) >= ?', [$since])
            ->whereRaw('COALESCE(assigned_at, created_at) < ?', [$until])
            ->orderByDesc('id')
            ->limit(60)
            ->get(['lead_no', 'customer_name', 'assigned_at', 'created_at', 'first_contact_at']);

        $slow = [];
        $nowBound = now()->lt($until) ? now() : $until;
        foreach ($rows as $row) {
            $baseTime = $row->assigned_at ? Carbon::parse($row->assigned_at) : Carbon::parse($row->created_at);
            $compareTime = $row->first_contact_at ? Carbon::parse($row->first_contact_at) : $nowBound;
            $hours = $baseTime->diffInMinutes($compareTime, false) / 60;
            if ($hours > 4) {
                $slow[] = [
                    'lead_no' => (string) $row->lead_no,
                    'customer_name' => (string) ($row->customer_name ?: ''),
                    'hours_elapsed' => round($hours, 1),
                    'contacted' => (bool) $row->first_contact_at,
                ];
            }
        }
        return [
            'total_recent' => $rows->count(),
            'slow_count' => count($slow),
            'slow_leads' => array_slice($slow, 0, 15),
        ];
    }

    public function aiProviders(CrmAnalysisProvider $provider): array
    {
        $settings = $provider->settings();
        $out = [];
        foreach ($settings['providers'] ?? [] as $item) {
            if (empty($item['enabled']) || empty($item['has_key'])) continue;
            $models = array_values(array_filter(array_unique(array_merge(
                !empty($item['model']) ? [(string) $item['model']] : [],
                is_array($item['models'] ?? null) ? $item['models'] : []
            ))));
            $out[] = [
                'service_code' => (string) $item['service_code'],
                'vendor_name' => (string) $item['vendor_name'],
                'provider' => (string) $item['provider'],
                'model' => (string) $item['model'],
                'models' => $models,
                'is_default' => (bool) ($item['is_default'] ?? false),
            ];
        }
        return $out;
    }

    /** 每个正式跟进只完成该项目、该负责人已到期的计划。查询随跟进更正同步。 */
    public function pendingActions(object $actor, string $period): Builder
    {
        $q = DB::table('erp_crm_daily_actions as a')->joinSub($this->subjects($actor, (int) $actor->legacy_id), 's',
            fn ($join) => $join->on('s.id','=','a.subject_id')->on('s.kind','=','a.subject_type'))
            ->where('a.owner_id', $actor->legacy_id)->whereIn('a.priority', ['primary','backup'])
            ->whereNotExists(fn ($f) => $f->selectRaw('1')->from('erp_crm_followups as f')->whereColumn('f.subject_type','a.subject_type')
                ->whereColumn('f.subject_id','a.subject_id')
                ->where(fn ($closed) => $closed->where(fn ($actual) => $actual->whereRaw('f.contacted_at >= DATE(a.planned_at)')->whereColumn('f.created_at','>=','a.created_at'))
                    ->orWhere(fn ($cancelled) => $cancelled->whereIn('f.followup_schedule_mode', ['nurture', 'completed'])->whereColumn('f.updated_at','>=','a.created_at')
                        ->whereNotExists(fn ($newer) => $newer->from('erp_crm_followups as newer')->whereColumn('newer.subject_type', 'f.subject_type')
                            ->whereColumn('newer.subject_id', 'f.subject_id')->where(fn ($n) => $n->whereColumn('newer.contacted_at', '>', 'f.contacted_at')
                                ->orWhere(fn ($tie) => $tie->whereColumn('newer.contacted_at', 'f.contacted_at')->whereColumn('newer.id', '>', 'f.id')))))));
        if ($period === 'tomorrow') $q->where('a.planned_at','>=',today()->addDay())->where('a.planned_at','<',today()->addDays(2));
        elseif ($period === 'today') $q->where('a.planned_at','>=',today())->where('a.planned_at','<',today()->addDay());
        elseif ($period === 'overdue') $q->where('a.planned_at','<',now());
        else $q->where('a.planned_at','<',today()->addDays(2));
        return $q;
    }
}
