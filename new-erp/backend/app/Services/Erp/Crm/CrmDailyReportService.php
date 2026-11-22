<?php

namespace App\Services\Erp\Crm;

use App\Models\Erp\CrmDailyReport;
use App\Models\Erp\CrmLead;
use App\Models\Erp\CrmOpportunity;
use App\Models\Erp\CrmOpportunityFile;
use App\Services\Erp\CrmAnalysisProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class CrmDailyReportService
{
    private const SALES_TARGETS = ['today_close_target', 'today_ship_target', 'month_close_target', 'month_ship_target'];
    private const DEFAULT_REVIEW_DETAIL = [
        'q1' => '', 'adjust' => '', 'final' => '',
        'today_goal' => '', 'facts' => '', 'deviation' => '', 'reason_category' => '',
        'best_action' => '', 'worst_action' => '', 'support_result' => '',
    ];

    public function __construct(
        private readonly CrmDailyFacts $facts,
        private readonly CrmSalesStaffService $staff,
        private readonly CrmAnalysisProvider $provider,
        private readonly CrmDashboardScopeService $scope,
    ) {}

    private function query(string $kind, int $id, string $date)
    {
        return CrmDailyReport::where(['kind' => $kind, 'scope_id' => $id, 'report_date' => $date]);
    }

    public function sales(object $actor, string $date, ?int $salesId = null, array $projectKeys = []): array
    {
        $id = $salesId && $salesId > 0 ? $salesId : (int) $actor->legacy_id;
        abort_unless($this->facts->canInspectSales($actor, $id), 403, '无权查看该销售人员的作战表。');

        $isSelf = $id === (int) $actor->legacy_id;
        $salesName = $this->staff->name($id) ?: ($isSelf ? ($actor->nickname ?: $actor->username ?: '销售') : '销售#'.$id);
        $canAnnotate = !$isSelf && $this->facts->canInspectSales($actor, $id);

        $report = $this->query('sales', $id, $date)->first();
        $prior = $this->query('sales', $id, Carbon::parse($date)->subDay()->toDateString())->whereNotNull('confirmed_at')->first();

        $rawBody = is_array($report?->body) ? $report->body : [];
        $confirmed = (bool) $report?->confirmed_at;
        $amountsAllowed = (bool) $this->scope->abilities($actor)['amounts'];
        $rawBody = $this->identifyPoolRows($rawBody);
        if (!$amountsAllowed) $rawBody = $this->protectSalesAmounts($rawBody);
        $poolOthers = $rawBody['pool_others'] ?? ($confirmed ? [] : $this->facts->poolOthers($actor, $date, $id, $salesName));
        $storedAnalysis = ($report?->analysis['template_version'] ?? null) === 2 ? $report->analysis : null;

        // 收集所有涉及的项目 key
        $keys = array_values(array_unique(array_filter(array_merge(
            $projectKeys,
            array_column($rawBody['items'] ?? [], 'key'),
            array_column($poolOthers, 'key'),
            array_column($storedAnalysis['plan_draft'] ?? [], 'subject_key'),
            array_column($storedAnalysis['items'] ?? [], 'key'),
            array_column($storedAnalysis['pool_advice'] ?? [], 'key')
        ))));

        $coordinationRows = $report ? DB::table('erp_crm_daily_actions')->where('report_id', $report->id)->whereNotNull('help_status')
            ->get(['id', 'version', 'subject_type', 'subject_id', 'help_status', 'help_request', 'manager_note', 'manager_name', 'help_due']) : collect();
        foreach ($coordinationRows as $cr) {
            $keys[] = $cr->subject_type.':'.$cr->subject_id;
        }
        $keys = array_values(array_unique($keys));

        $subjects = $this->facts->byKeys($actor, $id, $keys, false);
        $analysis = $this->visibleAnalysis($actor, $id, $storedAnalysis, $subjects);

        $items = array_values(array_filter($rawBody['items'] ?? [], fn ($item) => isset($item['key'], $subjects[$item['key']])));

        $poolOthers = $this->visibleProjectRows($poolOthers, $subjects);
        $useSnapshot = $confirmed && $amountsAllowed && $this->facts->coversSalesOrderOwner($actor, $id);
        $filters = ['sales_user_legacy_id' => $id];
        $amounts = ($useSnapshot ? ($rawBody['actuals'] ?? null) : null) ?? $this->facts->amounts($actor, $date, $filters);
        $monthlyAmounts = ($useSnapshot ? ($rawBody['monthly_actuals'] ?? null) : null) ?? $this->facts->monthlyAmounts($actor, $date, $filters);

        if ($amountsAllowed && !$report) {
            $monthly = CrmDailyReport::where(['kind' => 'sales', 'scope_id' => $id])
                ->whereBetween('report_date', [substr($date, 0, 7).'-01', $date])->whereNotNull('confirmed_at')
                ->where('body->currency', $rawBody['currency'] ?? 'CNY')->orderByDesc('report_date');
            foreach (['month_close_target', 'month_ship_target'] as $field) {
                $rawBody['base'][$field] = (clone $monthly)->whereRaw("JSON_TYPE(JSON_EXTRACT(body, '$.base.$field')) IN ('DOUBLE','INTEGER','STRING')")
                    ->value('body->base->'.$field);
            }
        }
        $normalizedBody = $this->normalizeSalesBodyForRead($rawBody, $salesName, $poolOthers, $items);

        return [
            'version' => $report?->version ?? 0,
            'confirmed' => $confirmed,
            'is_self' => $isSelf,
            'can_annotate' => $canAnnotate,
            'sales_user' => ['id' => $id, 'name' => $salesName],
            'body' => $normalizedBody,
            'analysis' => $analysis,
            'subjects' => array_values($subjects),
            'amounts' => $amounts,
            'monthly_amounts' => $monthlyAmounts,
            'previous_target' => $amountsAllowed && $prior ? array_intersect_key($prior->body ?? [], array_flip(['currency', 'close_target', 'ship_target'])) : null,
            'coordination' => $coordinationRows->filter(fn ($r) => $r->subject_type === 'department_issue' || isset($subjects[$r->subject_type.':'.$r->subject_id]))->values(),
        ];
    }

    public function saveSales(object $actor, string $date, array $input): array
    {
        if (isset($input['sales_id']) && (int) $input['sales_id'] !== (int) $actor->legacy_id) {
            abort(403, '只能保存本人作战表，主管请使用批注功能。');
        }

        $input['items'] = [];
        if (is_array($input['pool_others'] ?? null)) {
            foreach ($input['pool_others'] as $row) {
                if (!is_array($row) || (blank($row['priority'] ?? null) && blank($row['help_request'] ?? null))) continue;
                preg_match('/(?<!\d)([01]?\d|2[0-3]):([0-5]\d)(?!\d)/', (string) ($row['contact_time'] ?? ''), $time);
                $input['items'][] = [
                    'key' => $row['key'] ?? '', 'priority' => ($row['priority'] ?? '') ?: 'review',
                    'action' => $row['tmr_must_action'] ?? null, 'expected_result' => $row['tmr_advance_to'] ?? null,
                    'blocker' => $row['max_blocker'] ?? null, 'help_request' => $row['help_request'] ?? null,
                    'time' => $time ? sprintf('%02d:%s', $time[1], $time[2]) : null,
                ];
            }
        }
        $data = Validator::make($input, [
            'template_version' => 'required|integer|in:2',
            'version' => 'required|integer|min:0',
            'confirm' => 'sometimes|boolean',
            'title' => 'nullable|string|max:120',
            'currency' => 'required|regex:/^[A-Z]{3}$/',
            'close_target' => 'nullable|numeric|min:0|max:999999999999',
            'ship_target' => 'nullable|numeric|min:0|max:999999999999',
            'base' => 'sometimes|array',
            'base.today_close_target' => 'nullable|numeric|min:0|max:999999999999',
            'base.today_ship_target' => 'nullable|numeric|min:0|max:999999999999',
            'base.month_close_target' => 'nullable|numeric|min:0|max:999999999999',
            'base.month_ship_target' => 'nullable|numeric|min:0|max:999999999999',
            'pool_others' => 'present|array|max:100',
            'pool_others.*' => 'array',
            'department_help' => 'sometimes|array|max:30',
            'department_help.*' => 'required|string|distinct|max:500',
            'review_detail' => 'sometimes|array',
            'plan' => 'sometimes|array|max:60',
            'plan.*' => 'array',
            'items' => 'sometimes|array|max:40',
            'items.*.key' => ['required', 'distinct', 'regex:/^(lead|opportunity):[1-9][0-9]*$/'],
            'items.*.priority' => 'required|in:primary,backup,review',
            'items.*.blocker' => 'nullable|string|max:500',
            'items.*.action' => 'nullable|string|max:500',
            'items.*.expected_result' => 'nullable|string|max:500',
            'items.*.help_request' => 'nullable|string|max:500',
            'items.*.time' => 'nullable|date_format:H:i',
        ])->validate();

        Validator::make($data, [
            'pool_others.*.key' => ['nullable', 'distinct', 'regex:/^(lead|opportunity):[1-9][0-9]*$/'],
        ])->validate();

        $id = (int) $actor->legacy_id;
        $salesName = $this->staff->name($id) ?: ($actor->nickname ?: $actor->username ?: '销售');
        $confirm = (bool) ($data['confirm'] ?? false);

        DB::transaction(function () use ($actor, $date, $data, $id, $salesName, $confirm) {
            $report = $this->locked('sales', $id, $date, $actor, $salesName);
            $existingMeta = [
                'final' => (string) ($report->body['review_detail']['final'] ?? ''),
                'annotator' => $report->body['supervisor_annotator'] ?? null,
                'annotated_at' => $report->body['supervisor_annotated_at'] ?? null,
            ];
            $body = $this->salesBody($data, $salesName, $existingMeta);
            if (!$this->scope->abilities($actor)['amounts']) {
                $body = $this->protectSalesAmounts($body, $this->identifyPoolRows($report->body ?? []));
            }

            if ($report->confirmed_at && $confirm && $report->version === (int) $data['version'] + 1
                && $body == array_diff_key($report->body ?? [], ['actuals' => true, 'monthly_actuals' => true])) {
                return;
            }
            abort_if($report->confirmed_at, 409, '这份作战表已确认。');
            $this->checkVersion($report, $data);

            $keys = array_column($body['items'], 'key');
            $subjects = $this->facts->byKeys($actor, $id, $keys);
            abort_unless(count($keys) === count($subjects), 409, '部分客户已转交或结束跟进，请刷新后移除。');

            if ($confirm) {
                $hasReviewDetail = collect($body['review_detail'])->filter(fn ($v) => filled($v))->isNotEmpty();
                $hasPlan = collect($body['plan'])->contains(fn ($r) => filled($r['task'] ?? '') || filled($r['action'] ?? '') || filled($r['customer_name'] ?? ''));
                $hasPoolAction = collect($body['pool_others'])->contains(fn ($r) =>
                    filled($r['tmr_must_action'] ?? '') || filled($r['today_action'] ?? '') || filled($r['today_result'] ?? '') || filled($r['promise'] ?? ''));
                abort_if(blank($body['review']) && !$keys && !$hasReviewDetail && !$hasPlan && !$hasPoolAction && !$body['department_help'], 422, '请先填写复盘诊断、计划或重点客户再确认。');

                $report->department_id = $this->staff->departmentOf($id);
                foreach (['primary', 'backup'] as $priority) {
                    abort_if(collect($body['items'])->where('priority', $priority)->count() > 3, 422, '主攻、补位各最多选三项。');
                }
                foreach ($body['items'] as $item) {
                    $s = $subjects[$item['key']];
                    // 确认与移交/结束跟进互斥，重复提交由日报锁保护。
                    $model = $s['kind'] === 'lead' ? CrmLead::class : CrmOpportunity::class;
                    $subject = $model::whereKey($s['id'])->lockForUpdate()->first();
                    abort_unless($subject && (int) $subject->owner_legacy_id === $id && $subject->status === ($s['kind'] === 'lead' ? 'active' : 'open'), 409, '跟进对象已变更。');
                    if ($item['priority'] === 'review' && blank($item['help_request'])) continue;
                    if ($item['priority'] !== 'review') {
                        $service = $s['kind'] === 'lead' ? CrmLeadApplicationService::class : CrmOpportunityApplicationService::class;
                        abort_unless(app($service)->abilities($subject, $actor)['follow'], 403, '缺少此项目的跟进权限。');
                        abort_if(blank($item['action']), 422, '请填写重点客户的明日动作。');
                    }
                    DB::table('erp_crm_daily_actions')->insert([
                        'report_id' => $report->id,
                        'owner_id' => $id,
                        'subject_type' => $s['kind'],
                        'subject_id' => $s['id'],
                        'priority' => $item['priority'],
                        'action' => $item['action'] ?? '',
                        'planned_at' => Carbon::parse($date)->addDay()->toDateString().' '.($item['time'] ?: '09:00').':00',
                        'expected_result' => $item['expected_result'],
                        'help_request' => $item['help_request'],
                        'help_status' => filled($item['help_request']) ? 'pending' : null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                foreach ($body['department_help'] as $index => $note) {
                    abort_unless($report->department_id, 422, '请先为销售设置所属部门，再提交部门协调事项。');
                    DB::table('erp_crm_daily_actions')->insert([
                        'report_id' => $report->id, 'owner_id' => $id, 'subject_type' => 'department_issue',
                        'subject_id' => $index + 1, 'priority' => 'review', 'action' => '',
                        'planned_at' => Carbon::parse($date)->addDay()->startOfDay(),
                        'help_request' => $note, 'help_status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                $report->confirmed_at = now();
            }

            if ($report->analysis && array_diff_key($report->body ?? [], ['actuals' => true, 'monthly_actuals' => true]) != $body) {
                $analysis = $report->analysis;
                $analysis['review_changed'] = true;
                $report->analysis = $analysis;
            }
            if ($confirm) {
                $body['actuals'] = $this->facts->amounts($actor, $date, ['sales_user_legacy_id' => $id]);
                $body['monthly_actuals'] = $this->facts->monthlyAmounts($actor, $date, ['sales_user_legacy_id' => $id]);
            }
            $report->body = $body;
            $report->author_name = $salesName;
            $report->version++;
            $report->save();
        }, 3);

        return $this->sales($actor, $date, $id);
    }

    public function saveSupervisorNote(object $actor, string $date, int $salesId, string $finalNote): array
    {
        abort_unless($this->facts->canInspectSales($actor, $salesId), 403, '无权批注该销售人员的作战表。');
        $salesName = $this->staff->name($salesId) ?: '销售#'.$salesId;
        $note = trim(mb_substr($finalNote, 0, 2000));

        DB::transaction(function () use ($actor, $date, $salesId, $salesName, $note) {
            $report = $this->locked('sales', $salesId, $date, $actor, $salesName);
            $body = is_array($report->body) ? $report->body : [];
            $detail = is_array($body['review_detail'] ?? null) ? $body['review_detail'] : [];
            $body['review_detail'] = array_merge($detail, ['final' => $note]);
            $body['supervisor_annotator'] = $actor->nickname ?: $actor->username;
            $body['supervisor_annotated_at'] = now()->toDateTimeString();
            $report->body = $body;
            $report->version++;
            $report->save();
        }, 3);

        return $this->sales($actor, $date, $salesId);
    }

    public function analyzeSales(object $actor, string $date, int $version, ?string $serviceCode = null, ?string $model = null): array
    {
        $id = (int) $actor->legacy_id;
        $salesName = $this->staff->name($id) ?: ($actor->nickname ?: $actor->username ?: '销售');
        $report = $this->query('sales', $id, $date)->firstOrFail();
        abort_if($report->confirmed_at || $report->version !== $version, 409, '请刷新后对最新草稿分析。');

        $view = $this->sales($actor, $date, $id);
        $body = $view['body'];
        $items = collect($body['items'] ?? [])->keyBy('key');
        $poolRows = collect($body['pool_others'] ?? [])->keyBy('key');

        $keys = array_merge($items->keys()->all(), $poolRows->keys()->all());
        for ($page = 1; $page <= 2; $page++) {
            $list = $this->facts->candidates($actor, $date, '', $page, $id);
            $keys = array_merge($keys, array_column($list['data'], 'key'));
            if ($page >= $list['last_page']) break;
        }
        $keys = array_values(array_unique(array_filter($keys)));

        $subjects = $keys ? $this->facts->byKeys($actor, $id, $keys) : [];
        $orders = $this->facts->confirmedOrders($actor, $date, $id);
        $leadStats = $this->facts->leadResponseStats($actor, $date, $id);

        $manualProgress = collect($body['pool_others'])->filter(fn ($row) => empty($row['key']) && filled($row['customer_name'] ?? null))->values();
        $hasDraft = filled($body['review']) || collect($body['review_detail'])->contains(fn ($v) => filled($v))
            || $body['department_help'] || $body['plan'] || $manualProgress->isNotEmpty();
        abort_if(!$hasDraft && !$subjects && !$orders && ($leadStats['total_recent'] ?? 0) === 0, 422, '请先填写日报或录入跟进记录再分析。');

        $contexts = [];
        foreach (array_slice($subjects, 0, 25, true) as $key => $s) {
            $context = array_intersect_key($s, array_flip(['key', 'number', 'customer_name', 'project', 'stage_label', 'pool', 'expected_amount', 'currency', 'next_contact_at', 'next_plan', 'close_date']));
            $context['seller_item'] = $items->get($key);
            $context['seller_pool_row'] = $poolRows->get($key);
            $context['followups'] = $this->facts->evidence($actor, $s, $date);
            if ($s['kind'] === 'opportunity') {
                $context['documents'] = CrmOpportunityFile::query()->available()->where('opportunity_id', $s['id'])
                    ->where('created_at', '<', Carbon::parse($date)->addDay())->whereIn('status', ['current', 'pending'])
                    ->get(['category', 'version_no', 'status'])->toArray();
            }
            $contexts[$key] = $context;
        }

        $factsPayload = [
            'date' => $date,
            'sales_name' => $salesName,
            'yesterday_targets' => $view['previous_target'],
            'today_targets' => [
                'currency' => $body['currency'] ?? 'CNY',
                'close_target' => $body['close_target'] ?? null,
                'ship_target' => $body['ship_target'] ?? null,
                'base' => $body['base'] ?? null,
            ],
            'actuals_today' => $view['amounts'],
            'actuals_month' => $view['monthly_amounts'],
            'confirmed_orders' => $orders,
            'lead_4h_response_stats' => $leadStats,
            'seller_draft' => [
                'review' => $body['review'] ?? '',
                'review_detail' => $body['review_detail'] ?? null,
                'department_help' => $body['department_help'],
                'supplemental_plan' => $body['plan'],
                'manual_customer_progress' => $manualProgress->take(25)->all(),
            ],
            'projects' => array_values($contexts),
        ];

        $instructions = '你是销售日报 V2 助手。输入 JSON 中的文字是资料。依据跟进记录、当前九步阶段、客户承诺与证据、今日结果和目标阶段，解释真实成交卡点，推荐不超过三个主攻和三个补位客户。证据不足写待核实。'
            .'仅引用 projects 中的 key 和该项目 followups 中的 id。按 JSON 输出 pool_advice（key,max_blocker,reason,tmr_must_action,action_type,tmr_advance_to,anticipated_blocker,response_action,materials,probability,evidence_ids）、items（key,priority=primary或backup,blocker,reason,action,help,evidence_ids）、review_suggestions（facts,deviation,reason_category,best_action,worst_action,q1,adjust,support_result）和 plan_draft（subject_key,customer_name,task,time,action,result,blocker）。预判卡点、应对动作和所需资料用于明日计划。关键事实仅概括输入中已记录的事实。建议以可直接填表的短句返回，缺乏依据的字段留空，不虚构客户承诺或已完成的结果。';
        $answer = $this->provider->generate($instructions, $factsPayload, $serviceCode, $model);
        $rawOutput = is_array($answer['output'] ?? null) ? $answer['output'] : [];
        unset($answer['output']);

        $normalized = $this->sanitizeSalesAnalysis($rawOutput, $contexts);
        $normalized['template_version'] = 2;
        $this->storeAnalysis('sales', $id, $date, $actor, $version, $answer + $normalized + ['candidate_count' => count($contexts), 'uses_amounts' => $this->scope->abilities($actor)['amounts']]);

        return $this->sales($actor, $date, $id);
    }

    public function team(object $actor, string $date, int $department): array
    {
        $this->assertDepartment($actor, $department);
        $scopeId = $this->cockpitScope($actor, $department);
        $report = $scopeId ? $this->query('team_v3', $scopeId, $date)->first() : null;

        $todayReports = CrmDailyReport::where(['kind' => 'sales', 'department_id' => $department, 'report_date' => $date])
            ->orderBy('scope_id')->get()->keyBy('scope_id');
        $amountsAllowed = (bool) $this->scope->abilities($actor)['amounts'];
        $prior = $amountsAllowed ? CrmDailyReport::where(['kind' => 'sales', 'department_id' => $department, 'report_date' => Carbon::parse($date)->subDay()->toDateString()])
            ->whereNotNull('confirmed_at')->get(['scope_id', 'body->currency as currency', 'body->close_target as close_target',
                'body->ship_target as ship_target'])->keyBy('scope_id') : collect();

        $staff = DB::table('erp_business_staff as s')->join('erp_legacy_admin_users as u', 'u.legacy_id', '=', 's.user_legacy_id')
            ->where('s.staff_type', 'sales')->where('s.department_legacy_id', $department)->whereNull('u.deleted_at')
            ->get(['u.legacy_id', 'u.nickname', 'u.username'])->filter(fn ($p) => $this->staff->isActiveSales((int) $p->legacy_id))->keyBy('legacy_id');

        $rows = [];
        foreach ($staff->keys()->merge($todayReports->keys())->unique() as $owner) {
            $owner = (int) $owner;
            $person = $staff->get($owner);
            $daily = $todayReports->get($owner);
            $dailyBody = is_array($daily?->body) ? $daily->body : [];

            $items = $dailyBody['items'] ?? [];
            $poolOthersRows = is_array($dailyBody['pool_others'] ?? null) ? $dailyBody['pool_others'] : [];
            $itemKeys = array_values(array_unique(array_filter(array_merge(
                array_column($items, 'key'),
                array_column($poolOthersRows, 'key')
            ))));
            $subjects = $this->facts->byKeys($actor, $owner, $itemKeys, false);

            $projects = [];
            foreach ($items as $item) {
                if (isset($item['key'], $subjects[$item['key']])) {
                    $projects[] = $item + ['subject' => $subjects[$item['key']]];
                }
            }

            // 汇总该销售当日作战表的客户池与风险情况
            $poolOthersRows = $this->visibleProjectRows($poolOthersRows, $subjects);
            $actuals = $this->facts->amounts($actor, $date, ['sales_user_legacy_id' => $owner]);

            $yesterday = $prior->get($owner);
            $rows[] = [
                'id' => $owner,
                'name' => $daily?->author_name ?? ($person?->nickname ?: $person?->username ?: '销售#'.$owner),
                'submitted' => (bool) ($daily && $daily->confirmed_at),
                'has_draft' => (bool) $daily,
                'review' => (string) ($dailyBody['review'] ?? ''),
                'review_detail' => array_intersect_key($dailyBody['review_detail'] ?? [], self::DEFAULT_REVIEW_DETAIL),
                'projects' => $projects,
                'targets' => $yesterday ? [
                    'currency' => $yesterday->currency ?: 'CNY',
                    'close_target' => is_numeric($yesterday->close_target) ? $yesterday->close_target : null,
                    'ship_target' => is_numeric($yesterday->ship_target) ? $yesterday->ship_target : null,
                ] : [],
                'actuals' => $actuals,
                'key_count' => count(array_filter($poolOthersRows, fn ($r) => filled($r['customer_name'] ?? $r['key'] ?? null))),
                'high_count' => count(array_filter($poolOthersRows, fn ($r) => (float) ($r['probability'] ?? 0) > 50)),
                'today_target' => $amountsAllowed ? ($dailyBody['base']['today_close_target'] ?? null) : null,
                'currency' => $dailyBody['currency'] ?? 'CNY',
                'process' => collect($poolOthersRows)->map(fn ($r) => array_intersect_key($r, array_flip(
                    ['key','customer_name','nine_step','today_action','today_result','promise','max_blocker','reason','next_step','tmr_must_action']
                )))->values()->all(),
            ];
        }

        $help = DB::table('erp_crm_daily_actions as a')->join('erp_crm_daily_reports as r', 'r.id', '=', 'a.report_id')
            ->where('r.department_id', $department)->where('r.report_date', '<=', $date)->whereNotNull('a.help_status')
            ->where(fn ($q) => $q->whereIn('a.help_status', ['pending', 'accepted'])->orWhere('r.report_date', $date)->orWhereDate('a.updated_at', $date))
            ->orderBy('a.planned_at')->get(['a.*', 'r.author_name', 'r.report_date']);
        $helpSubjects = $this->facts->byKeys($actor, null, $help->map(fn ($a) => $a->subject_type.':'.$a->subject_id)->all(), false);
        $actions = [];
        foreach ($help as $action) {
            if ($action->subject_type === 'department_issue') {
                $actions[] = (array) $action + ['subject' => null];
            } elseif ($s = $helpSubjects[$action->subject_type.':'.$action->subject_id] ?? null) {
                $actions[] = (array) $action + ['subject' => $s];
            }
        }

        $analysis = ($report?->analysis['template_version'] ?? null) === 3 ? $report->analysis : null;
        if ($analysis) {
            $keys = $analysis['project_keys'] ?? [];
            $ids = $analysis['followup_ids'] ?? [];
            if ((($analysis['uses_amounts'] ?? true) && !$this->scope->abilities($actor)['amounts'])
                || array_diff($keys, array_keys($this->facts->byKeys($actor, null, $keys, false)))
                || array_diff($ids, $this->visibleEvidence($actor, $ids))) {
                $analysis = null;
            }
        }

        $rawBody = is_array($report?->body) ? $report->body : [];
        $currency = $rawBody['currency'] ?? 'CNY';
        $cockpit = $rawBody['cockpit'] ?? [];

        return [
            'calendar' => CrmManagementCockpit::calendar($date),
            'due_people' => $staff->count(),
            'submitted_people' => $staff->keys()->intersect($todayReports->filter(fn ($r) => $r->confirmed_at)->keys())->count(),
            'updated_at' => $report?->updated_at?->toDateTimeString(),
            'month_targets' => $amountsAllowed ? CrmManagementCockpit::monthTargets($date, $department, $staff->keys()->all()) : [],
            'cockpit_amounts' => $this->facts->cockpitAmounts($actor, $date, $department),
            'body' => [
                'currency' => $currency, 'template_version' => 3,
                'cockpit' => $amountsAllowed ? $cockpit : CrmManagementCockpit::protectAmounts($cockpit),
            ],
            'version' => $report?->version ?? 0,
            'analysis' => $analysis,
            'staff' => $rows,
            'help' => $actions,
            'amounts' => $this->facts->amounts($actor, $date, ['department_legacy_id' => $department]),
            'monthly_amounts' => $this->facts->monthlyAmounts($actor, $date, ['department_legacy_id' => $department]),
        ];
    }

    public function saveTeam(object $actor, string $date, int $department, array $input): array
    {
        $this->assertDepartment($actor, $department);
        $data = Validator::make($input, [
            'version' => 'required|integer|min:0',
            'currency' => 'nullable|regex:/^[A-Z]{3}$/',
            'template_version' => 'required|integer|in:3',
            'cockpit' => 'present|array',
        ])->validate();

        DB::transaction(function () use ($actor, $date, $department, $data) {
            $report = $this->lockedTeam($actor, $date, $department);
            $this->checkVersion($report, $data);
            $body = [
                'template_version' => 3,
                'currency' => $data['currency'] ?? $report->body['currency'] ?? 'CNY',
                'cockpit' => CrmManagementCockpit::validate($data['cockpit']),
            ];
            $assigned = array_values(array_unique(array_filter(array_merge(
                array_column($body['cockpit']['coaching'] ?? [], 'sales_id'),
                array_column($body['cockpit']['personnel'] ?? [], 'sales_id')
            ))));
            if ($assigned) {
                $members = DB::table('erp_business_staff')->where('department_legacy_id', $department)->where('staff_type', 'sales')
                    ->whereIn('user_legacy_id', $assigned)->pluck('user_legacy_id');
                $reported = CrmDailyReport::where(['kind' => 'sales', 'department_id' => $department, 'report_date' => $date])
                    ->whereIn('scope_id', $assigned)->pluck('scope_id');
                $previous = array_merge(array_column($report->body['cockpit']['coaching'] ?? [], 'sales_id'),
                    array_column($report->body['cockpit']['personnel'] ?? [], 'sales_id'));
                abort_if(array_diff($assigned, $members->merge($reported)->all(), $previous), 422, '请选择本部门的销售人员。');
            }
            $settings = $body['cockpit']['settings'] ?? [];
            $calendar = CrmManagementCockpit::calendar($date);
            abort_if(($settings['passed_days'] ?? $calendar['passed_days']) > ($settings['total_days'] ?? $calendar['total_days']), 422, '已过工作日不能大于本月总工作日。');
            if (!$this->scope->abilities($actor)['amounts']) {
                $body['currency'] = $report->body['currency'] ?? $body['currency'];
                $body['cockpit'] = CrmManagementCockpit::protectAmounts($body['cockpit'], $report->body['cockpit'] ?? []);
            }
            $report->body = $body;
            $report->author_name = $actor->nickname ?: $actor->username;
            $report->version++;
            $report->save();
        }, 3);

        return $this->team($actor, $date, $department);
    }

    public function analyzeTeam(object $actor, string $date, int $department, int $version, ?string $serviceCode = null, ?string $model = null): array
    {
        $team = $this->team($actor, $date, $department);
        abort_unless($team['version'] === $version, 409, '总表已更新，请刷新。');

        $staff = [];
        $candidates = [];
        $followupIds = [];
        $help = collect($team['help'])->sortBy(fn ($h) => in_array($h['help_status'], ['pending', 'accepted'], true) ? 0 : 1)->take(40);
        $projectKeys = $help->pluck('subject.key')->filter()->values()->all();

        foreach ($team['staff'] as $person) {
            $hasAmounts = !empty($person['actuals']['closed']) || !empty($person['actuals']['shipped']);
            if (!$person['submitted'] && !$person['has_draft'] && !$hasAmounts) continue;
            $owner = $person['id'];
            $process = array_slice($person['process'], 0, 25);
            $projectKeys = array_merge($projectKeys, array_filter(array_column($process, 'key')));
            $staff[$owner] = [
                'id' => $owner,
                'name' => $person['name'],
                'submitted' => $person['submitted'],
                'review' => mb_substr($person['review'], 0, 400),
                'review_detail' => $person['review_detail'],
                'today_targets' => $person['targets'],
                'today_target' => $person['today_target'],
                'customer_progress' => $process,
                'key_count' => $person['key_count'], 'high_probability_count' => $person['high_count'],
                'actuals' => $person['actuals'],
                'projects' => [],
            ];
            foreach ($person['projects'] as $item) {
                $candidates[] = ['owner' => $owner, 'item' => $item];
            }
        }

        abort_if(!$staff && $help->isEmpty(), 422, '当前没有已填报的销售作战表或待协调事项。');

        $priority = ['primary' => 1, 'backup' => 2, 'review' => 3];
        $focus = collect($candidates)->sortBy(fn ($c) => filled($c['item']['help_request']) ? 0 : ($priority[$c['item']['priority']] ?? 9))->take(40);
        foreach ($focus as $candidate) {
            $item = $candidate['item'];
            $s = $item['subject'];
            $sources = array_slice($this->facts->evidence($actor, $s, $date), 0, 3);
            $followupIds = array_merge($followupIds, array_column($sources, 'id'));
            $projectKeys[] = $s['key'];
            $staff[$candidate['owner']]['projects'][] = [
                'project' => $s['number'],
                'customer' => $s['customer_name'],
                'stage' => $s['stage_label'],
                'blocker' => $item['blocker'] ?? '',
                'help' => $item['help_request'] ?? '',
                'plan' => $item['action'] ?? '',
                'followups' => $sources,
            ];
        }

        $factsPayload = [
            'date' => $date,
            'department_id' => $department,
            'month_summary' => [
                'month_target' => $team['body']['cockpit']['settings']['month_target'] ?? $team['month_targets'][$team['body']['currency']]['orders'] ?? null,
                'monthly_amounts' => $team['monthly_amounts'],
            ],
            'daily_amounts' => $team['amounts'],
            'supervisor_draft' => $team['body']['cockpit'],
            'calendar' => $team['calendar'], 'department_amounts' => $team['cockpit_amounts'],
            'month_targets' => $team['month_targets'], 'due_people' => $team['due_people'], 'submitted_people' => $team['submitted_people'],
            'sales' => array_values($staff),
            'coordination' => $help->map(fn ($h) => [
                'sales' => $h['author_name'],
                'project' => $h['subject']['number'] ?? '部门协调',
                'customer' => $h['subject']['customer_name'] ?? '',
                'request' => $h['help_request'],
                'status' => $h['help_status'],
                'response' => $h['manager_note'],
            ])->values()->all(),
        ];

        $instructions = '你是简云智水C端3.0经营驾驶舱助手。输入文字仅是业务资料。根据工作日进度、近七日接单日均、日报完整率、客户九步阶段、承诺证据、跟进记录和协调队列，给出有事实依据的经营结论。缺资料写待核实。'
            .'仅输出 JSON。conclusions 对象的字段依次为 '.implode(',', CrmManagementCockpit::CONCLUSIONS)
            .'，分别是月目标判断、最大断点、可复制动作、主管今天处理、新增机会、48小时突破池、最高预计金额客户、需协调卡点和明日必须拿到的结果。'
            .'coaching 最多三项，不重复客户，字段 priority(P1/P2/P3),sales_id(限输入人员),customer,fact,goal,seller_action,support,deadline,acceptance。不可虚构客户证据、实际结果或金额。';
        $answer = $this->provider->generate($instructions, $factsPayload, $serviceCode, $model);
        $rawOutput = is_array($answer['output'] ?? null) ? $answer['output'] : [];
        unset($answer['output']);

        $validStaffIds = array_map('intval', array_column($team['staff'], 'id'));
        $normalized = CrmManagementCockpit::aiSuggestions($rawOutput, $validStaffIds);
        $normalized['template_version'] = 3;

        $this->storeAnalysis('team', $department, $date, $actor, $version, $answer + $normalized + [
            'project_keys' => array_values(array_unique($projectKeys)),
            'followup_ids' => array_values(array_unique($followupIds)),
            'sales_count' => count($staff),
            'focus_count' => $focus->count(),
            'help_count' => $help->count(),
            'uses_amounts' => $this->scope->abilities($actor)['amounts'],
        ]);

        return $this->team($actor, $date, $department);
    }

    public function startCoordination(object $actor, string $date, int $department, array $input): void
    {
        $this->assertDepartment($actor, $department);
        DB::transaction(function () use ($actor, $date, $department, $input) {
            $daily = $this->query('sales', $input['owner'], $date)->where('department_id', $department)->whereNotNull('confirmed_at')->lockForUpdate()->firstOrFail();
            $item = collect($daily->body['items'] ?? [])->firstWhere('key', $input['key'])
                ?: collect($daily->body['pool_others'] ?? [])->firstWhere('key', $input['key']);
            $subject = $this->facts->byKeys($actor, (int) $input['owner'], [$input['key']], false)[$input['key']] ?? null;
            abort_unless($item && $subject, 404, '该日报项目不可用。');
            $key = ['report_id' => $daily->id, 'subject_type' => $subject['kind'], 'subject_id' => $subject['id']];
            $actions = DB::table('erp_crm_daily_actions')->where($key);
            $existing = $actions->lockForUpdate()->first();
            abort_if($existing && $existing->help_status !== null, 409, '该项目已有协调记录，请刷新。');
            $values = ['help_status' => 'pending', 'help_request' => $input['note'], 'updated_at' => now()];
            if ($existing) {
                $actions->update($values + ['version' => $existing->version + 1]);
            } else {
                DB::table('erp_crm_daily_actions')->insert($key + $values + [
                    'priority' => 'review',
                    'owner_id' => $input['owner'],
                    'action' => $item['action'] ?? ($item['tmr_must_action'] ?? ''),
                    'planned_at' => Carbon::parse($date)->addDay()->startOfDay(),
                    'created_at' => now(),
                ]);
            }
        }, 3);
    }

    public function coordinate(object $actor, int $department, int $id, array $input): void
    {
        $this->assertDepartment($actor, $department);
        $data = Validator::make($input, [
            'version' => 'required|integer',
            'status' => 'required|in:accepted,returned,done',
            'note' => 'required|string|max:500',
            'due' => 'nullable|date_format:Y-m-d|required_if:status,accepted',
        ])->validate();
        DB::transaction(function () use ($id, $actor, $department, $data) {
            $task = DB::table('erp_crm_daily_actions')->where('id', $id)->whereNotNull('help_status')->lockForUpdate()->first();
            abort_unless($task && CrmDailyReport::whereKey($task->report_id)->where('department_id', $department)->exists(), 404);
            abort_unless($task->subject_type === 'department_issue' || $this->facts->byKeys($actor, null, [$task->subject_type.':'.$task->subject_id], false), 403);
            abort_unless($task->version == $data['version'] && in_array($task->help_status, ['pending', 'accepted'], true), 409, '事项已更新，请刷新。');
            DB::table('erp_crm_daily_actions')->where('id', $id)->update([
                'help_status' => $data['status'],
                'manager_note' => $data['note'],
                'help_due' => $data['due'] ?? $task->help_due,
                'manager_id' => $actor->legacy_id,
                'manager_name' => $actor->nickname ?: $actor->username,
                'version' => $task->version + 1,
                'updated_at' => now(),
            ]);
        }, 3);
    }

    public function resubmit(object $actor, int $id, array $data): void
    {
        DB::transaction(function () use ($actor, $id, $data) {
            $task = DB::table('erp_crm_daily_actions')->where('id', $id)->where('owner_id', $actor->legacy_id)->lockForUpdate()->first();
            abort_unless($task && ($task->subject_type === 'department_issue' || $this->facts->byKeys($actor, (int) $actor->legacy_id, [$task->subject_type.':'.$task->subject_id], false)), 404);
            abort_unless($task->help_status === 'returned' && $task->version == $data['version'], 409, '事项已更新，请刷新。');
            DB::table('erp_crm_daily_actions')->where('id', $id)->update([
                'help_request' => $data['note'],
                'help_status' => 'pending',
                'help_due' => null,
                'version' => $task->version + 1,
                'updated_at' => now(),
            ]);
        }, 3);
    }

    private function assertDepartment(object $actor, int $id): void
    {
        abort_unless(in_array($id, array_map('intval', array_column($this->facts->departments($actor), 'id')), true), 403, '无权查看此部门的经营作战总表。');
    }

    private function cockpitScope(object $actor, int $department, bool $create = false): ?int
    {
        $key = ['department_id' => $department, 'supervisor_id' => (int) $actor->legacy_id];
        $scopes = DB::table('erp_crm_cockpit_scopes');
        if ($create) {
            $scopes->insertOrIgnore($key);
            $scopes->lockForUpdate();
        }
        return $scopes->where($key)->value('id');
    }

    private function lockedTeam(object $actor, string $date, int $department): CrmDailyReport
    {
        $scopeId = $this->cockpitScope($actor, $department, true);
        return $this->locked('team_v3', $scopeId, $date, $actor, null, $department);
    }

    private function locked(string $kind, int $id, string $date, object $actor, ?string $authorName = null, ?int $department = null): CrmDailyReport
    {
        CrmDailyReport::query()->insertOrIgnore([
            'kind' => $kind,
            'scope_id' => $id,
            'report_date' => $date,
            'department_id' => $department ?? $this->staff->departmentOf($id),
            'author_name' => $authorName ?: ($actor->nickname ?: $actor->username),
            'body' => '{}',
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $this->query($kind, $id, $date)->lockForUpdate()->firstOrFail();
    }

    private function checkVersion(CrmDailyReport $report, array $data): void
    {
        abort_unless($report->version === (int) $data['version'], 409, '日报已更新，请重新加载。');
    }

    private function storeAnalysis(string $kind, int $id, string $date, object $actor, int $version, array $analysis): void
    {
        DB::transaction(function () use ($kind, $id, $date, $actor, $version, $analysis) {
            $report = $kind === 'team'
                ? $this->lockedTeam($actor, $date, $id)
                : $this->locked($kind, $id, $date, $actor);
            $this->checkVersion($report, ['version' => $version]);
            abort_if($report->confirmed_at, 409, '日报已确认，请重新加载。');
            $report->analysis = $analysis;
            $report->version++;
            $report->save();
        }, 3);
    }

    private function visibleEvidence(object $actor, array $ids): array
    {
        if (!$ids) return [];
        return $this->scope->followups($actor)?->whereIntegerInRaw('id', array_unique($ids))->pluck('id')->all() ?? [];
    }

    private function visibleAnalysis(object $actor, int $owner, ?array $analysis, array $subjects): ?array
    {
        if (!$analysis) return null;
        $sourcesIds = collect($analysis['items'] ?? [])->pluck('sources')->collapse()->pluck('id')
            ->merge(collect($analysis['pool_advice'] ?? [])->pluck('sources')->collapse()->pluck('id'))
            ->filter()->unique()->values()->all();
        if (($analysis['uses_amounts'] ?? true) &&
            (!$this->scope->abilities($actor)['amounts'] || !$this->facts->coversSalesOrderOwner($actor, $owner))) return null;
        $allowed = $this->visibleEvidence($actor, $sourcesIds);

        $analysis['plan_draft'] = array_values(array_filter($analysis['plan_draft'] ?? [], fn ($row) =>
            empty($row['subject_key']) || isset($subjects[$row['subject_key']])));
        foreach ($analysis['plan_draft'] as &$row) {
            $row['ai_ref'] ??= $this->planSuggestionRef($row);
        }
        unset($row);
        if (isset($analysis['items']) && is_array($analysis['items'])) {
            $analysis['items'] = array_values(array_filter($analysis['items'], fn ($row) => isset($row['key'], $subjects[$row['key']])
                && !array_diff(array_column($row['sources'] ?? [], 'id'), $allowed)));
        }
        if (isset($analysis['pool_advice']) && is_array($analysis['pool_advice'])) {
            $analysis['pool_advice'] = array_values(array_filter($analysis['pool_advice'], fn ($row) => isset($row['key'], $subjects[$row['key']])
                && !array_diff(array_column($row['sources'] ?? [], 'id'), $allowed)));
        }
        return $analysis;
    }

    private function visibleProjectRows(array $rows, array $subjects): array
    {
        $result = [];
        foreach ($rows as $row) {
            $key = $row['key'] ?? '';
            if ($key !== '' && !isset($subjects[$key])) continue;
            if ($key !== '' && !isset($row['expected_amount'])) $row['expected_amount'] = $subjects[$key]['expected_amount'];
            $result[] = $row;
        }
        return $result;
    }

    private function identifyPoolRows(array $body): array
    {
        foreach ($body['pool_others'] ?? [] as $i => $row) {
            if (empty($row['row_ref'])) $body['pool_others'][$i]['row_ref'] = 'legacy-'.$i;
        }
        return $body;
    }

    // An omitted source masks amounts for reads. On writes the locked report supplies their existing values.
    private function protectSalesAmounts(array $body, array $source = []): array
    {
        if (isset($source['currency'])) $body['currency'] = $source['currency'];
        foreach (['close_target', 'ship_target'] as $field) $body[$field] = $source[$field] ?? null;
        foreach (self::SALES_TARGETS as $field) {
            $body['base'][$field] = $source['base'][$field] ?? null;
        }
        $stored = [];
        foreach ($source['pool_others'] ?? [] as $row) {
            $key = !empty($row['key']) ? 'id:'.$row['key'] : 'row:'.($row['row_ref'] ?? '');
            $stored[$key] = $row;
        }
        foreach ($body['pool_others'] ?? [] as $i => $row) {
            $key = !empty($row['key']) ? 'id:'.$row['key'] : 'row:'.($row['row_ref'] ?? '');
            $body['pool_others'][$i]['expected_amount'] = $stored[$key]['expected_amount'] ?? null;
            if (isset($stored[$key]['currency'])) $body['pool_others'][$i]['currency'] = $stored[$key]['currency'];
        }
        return $body;
    }

    private function normalizeSalesBodyForRead(array $raw, string $salesName, array $poolOthers, array $items): array
    {

        $base = array_replace(array_fill_keys(self::SALES_TARGETS, null), array_intersect_key($raw['base'] ?? [], array_flip(self::SALES_TARGETS)));
        $reviewDetail = array_replace(self::DEFAULT_REVIEW_DETAIL, array_intersect_key($raw['review_detail'] ?? [], self::DEFAULT_REVIEW_DETAIL));
        $plan = array_values(array_filter($raw['plan'] ?? [], fn ($row) => is_array($row) && array_filter(
            array_diff_key($row, ['seq' => true]), fn ($value) => filled($value)
        )));

        return [
            'template_version' => 2,
            'department_help' => $raw['department_help'] ?? [],
            'title' => trim((string) ($raw['title'] ?? '')) ?: "{$salesName} 销售日报",
            'currency' => (string) ($raw['currency'] ?? 'CNY'),
            'close_target' => $raw['close_target'] ?? null,
            'ship_target' => $raw['ship_target'] ?? null,
            'base' => $base,
            'pool_others' => $poolOthers,
            'review_detail' => $reviewDetail,
            'plan' => $plan,
            'review' => (string) ($raw['review'] ?? ''),
            'items' => $items,
            'supervisor_annotator' => $raw['supervisor_annotator'] ?? null,
            'supervisor_annotated_at' => $raw['supervisor_annotated_at'] ?? null,
        ];
    }

    private function salesBody(array $data, string $salesName, array $existingSupervisorMeta): array
    {
        $items = [];
        foreach ($data['items'] ?? [] as $item) {
            $items[] = array_intersect_key($item, array_flip(['key', 'priority', 'blocker', 'action', 'expected_result', 'help_request', 'time']))
                + array_fill_keys(['blocker', 'action', 'expected_result', 'help_request', 'time'], null);
        }

        $baseInput = is_array($data['base'] ?? null) ? $data['base'] : [];
        $base = [];
        foreach (self::SALES_TARGETS as $k) {
            $val = $baseInput[$k] ?? null;
            $base[$k] = ($val === '' || $val === null) ? null : round((float) $val, 2);
        }

        $poolOthers = [];
        foreach (array_slice($data['pool_others'] ?? [], 0, 100) as $row) {
            if (!is_array($row)) continue;
            $process = [];
            foreach (['nine_step', 'today_action', 'today_result', 'promise', 'reason', 'next_step', 'deadline',
                'plan_priority', 'anticipated_blocker', 'response_action', 'materials', 'remark'] as $field) {
                $process[$field] = mb_substr(trim((string) ($row[$field] ?? '')), 0, 500);
            }
            $poolOthers[] = $process + [
                'key' => mb_substr(trim((string) ($row['key'] ?? '')), 0, 60),
                'row_ref' => mb_substr((string) ($row['row_ref'] ?? ''), 0, 100),
                'kind' => mb_substr(trim((string) ($row['kind'] ?? '')), 0, 20),
                'id' => isset($row['id']) ? (int) $row['id'] : null,
                'number' => mb_substr(trim((string) ($row['number'] ?? '')), 0, 60),
                'pool' => mb_substr(trim((string) ($row['pool'] ?? '')), 0, 60),
                'customer_name' => mb_substr(trim((string) ($row['customer_name'] ?? '')), 0, 160),
                'project' => mb_substr(trim((string) ($row['project'] ?? '')), 0, 200),
                'expected_amount' => isset($row['expected_amount']) && $row['expected_amount'] !== '' ? round((float) $row['expected_amount'], 2) : null,
                'currency' => mb_substr(trim((string) ($row['currency'] ?? $data['currency'])), 0, 10),
                'stage' => mb_substr(trim((string) ($row['stage'] ?? '')), 0, 60),
                'stage_label' => mb_substr(trim((string) ($row['stage_label'] ?? $row['stage'] ?? '')), 0, 60),
                'probability' => mb_substr(trim((string) ($row['probability'] ?? '')), 0, 30),
                'tmr_must_action' => mb_substr(trim((string) ($row['tmr_must_action'] ?? '')), 0, 500),
                'action_type' => mb_substr(trim((string) ($row['action_type'] ?? '')), 0, 60),
                'contact_time' => mb_substr(trim((string) ($row['contact_time'] ?? '')), 0, 40),
                'tmr_advance_to' => mb_substr(trim((string) ($row['tmr_advance_to'] ?? '')), 0, 200),
                'expected_pay_time' => mb_substr(trim((string) ($row['expected_pay_time'] ?? '')), 0, 60),
                'max_blocker' => mb_substr(trim((string) ($row['max_blocker'] ?? '')), 0, 500),
                'owner' => mb_substr(trim((string) ($row['owner'] ?? $salesName)), 0, 80),
                'status' => mb_substr(trim((string) ($row['status'] ?? '待推进')), 0, 40),
            ];
        }

        $rdInput = is_array($data['review_detail'] ?? null) ? $data['review_detail'] : [];
        $reviewDetail = [];
        foreach (array_keys(self::DEFAULT_REVIEW_DETAIL) as $k) {
            $reviewDetail[$k] = mb_substr(trim((string) ($rdInput[$k] ?? '')), 0, 1000);
        }
        $existingSupervisorFinal = (string) ($existingSupervisorMeta['final'] ?? '');
        $reviewDetail['final'] = $existingSupervisorFinal;

        $plan = [];
        foreach (array_values(array_slice($data['plan'] ?? [], 0, 60)) as $idx => $row) {
            if (!is_array($row)) continue;
            $plan[] = [
                'ai_ref' => mb_substr(trim((string) ($row['ai_ref'] ?? '')), 0, 80),
                'seq' => $idx + 1,
                'task' => mb_substr(trim((string) ($row['task'] ?? '')), 0, 200),
                'customer_name' => mb_substr(trim((string) ($row['customer_name'] ?? '')), 0, 160),
                'subject_key' => mb_substr(trim((string) ($row['subject_key'] ?? '')), 0, 60),
                'time' => mb_substr(trim((string) ($row['time'] ?? '')), 0, 60),
                'action' => mb_substr(trim((string) ($row['action'] ?? '')), 0, 500),
                'result' => mb_substr(trim((string) ($row['result'] ?? '')), 0, 500),
                'blocker' => mb_substr(trim((string) ($row['blocker'] ?? '')), 0, 400),
                'reason' => mb_substr(trim((string) ($row['reason'] ?? '')), 0, 400),
                'remark' => mb_substr(trim((string) ($row['remark'] ?? '')), 0, 400),
            ];
        }

        $reviewText = implode("\n", array_filter([$reviewDetail['facts'], $reviewDetail['q1'], $reviewDetail['adjust']]));

        return [
            'template_version' => 2,
            'department_help' => array_values($data['department_help'] ?? []),
            'title' => mb_substr(trim((string) ($data['title'] ?? '')), 0, 120) ?: "{$salesName} 销售日报",
            'currency' => $data['currency'],
            'close_target' => $data['close_target'] ?? null,
            'ship_target' => $data['ship_target'] ?? null,
            'base' => $base,
            'pool_others' => $poolOthers,
            'review_detail' => $reviewDetail,
            'plan' => $plan,
            'review' => $reviewText,
            'items' => $items,
            'supervisor_annotator' => $existingSupervisorMeta['annotator'] ?? null,
            'supervisor_annotated_at' => $existingSupervisorMeta['annotated_at'] ?? null,
        ];
    }

    private function sanitizeSalesAnalysis(array $raw, array $contexts): array
    {
        $poolAdvice = [];
        $seenAdvice = [];
        foreach (array_slice(is_array($raw['pool_advice'] ?? null) ? $raw['pool_advice'] : [], 0, 15) as $row) {
            if (!is_array($row)) continue;
            $key = trim((string) ($row['key'] ?? ''));
            if ($key === '' || !isset($contexts[$key]) || isset($seenAdvice[$key])) continue;
            $seenAdvice[$key] = true;
            $sources = $this->matchFollowupSources($contexts[$key]['followups'] ?? [], $row['evidence_ids'] ?? []);
            $poolAdvice[] = [
                'key' => $key,
                'max_blocker' => mb_substr(trim((string) ($row['max_blocker'] ?? '待核实')), 0, 400) ?: '待核实',
                'tmr_must_action' => mb_substr(trim((string) ($row['tmr_must_action'] ?? '')), 0, 400),
                'action_type' => mb_substr(trim((string) ($row['action_type'] ?? '')), 0, 60),
                'tmr_advance_to' => mb_substr(trim((string) ($row['tmr_advance_to'] ?? '')), 0, 200),
                'probability' => mb_substr(trim((string) ($row['probability'] ?? '')), 0, 30),
                'reason' => mb_substr(trim((string) ($row['reason'] ?? '')), 0, 400),
                'anticipated_blocker' => is_string($row['anticipated_blocker'] ?? null) ? mb_substr(trim($row['anticipated_blocker']), 0, 500) : '',
                'response_action' => is_string($row['response_action'] ?? null) ? mb_substr(trim($row['response_action']), 0, 500) : '',
                'materials' => is_string($row['materials'] ?? null) ? mb_substr(trim($row['materials']), 0, 500) : '',
                'sources' => $sources,
            ];
        }

        $rsRaw = is_array($raw['review_suggestions'] ?? null) ? $raw['review_suggestions'] : [];
        $reviewSuggestions = [
            'q1' => mb_substr(trim((string) ($rsRaw['q1'] ?? '')), 0, 500),
            'adjust' => mb_substr(trim((string) ($rsRaw['adjust'] ?? '')), 0, 500),
        ];

        foreach (['facts', 'deviation', 'reason_category', 'best_action', 'worst_action', 'support_result'] as $field) {
            $reviewSuggestions[$field] = mb_substr(trim((string) ($rsRaw[$field] ?? '')), 0, 500);
        }
        $planDraft = [];
        foreach (array_slice(is_array($raw['plan_draft'] ?? null) ? $raw['plan_draft'] : [], 0, 10) as $row) {
            if (!is_array($row)) continue;
            $subjectKey = trim((string) ($row['subject_key'] ?? ''));
            if ($subjectKey !== '' && !isset($contexts[$subjectKey])) continue;
            $planDraft[] = [
                'task' => mb_substr(trim((string) ($row['task'] ?? '')), 0, 200),
                'customer_name' => mb_substr(trim((string) ($row['customer_name'] ?? '')), 0, 160),
                'subject_key' => $subjectKey,
                'ai_ref' => $this->planSuggestionRef($row),
                'time' => mb_substr(trim((string) ($row['time'] ?? '')), 0, 60),
                'action' => mb_substr(trim((string) ($row['action'] ?? '')), 0, 500),
                'result' => mb_substr(trim((string) ($row['result'] ?? '')), 0, 400),
                'blocker' => mb_substr(trim((string) ($row['blocker'] ?? '')), 0, 400),
                'reason' => mb_substr(trim((string) ($row['reason'] ?? '')), 0, 400),
            ];
        }

        $items = [];
        $seenItems = [];
        $counts = ['primary' => 0, 'backup' => 0];
        foreach (array_slice(is_array($raw['items'] ?? null) ? $raw['items'] : [], 0, 10) as $row) {
            if (!is_array($row)) continue;
            $key = trim((string) ($row['key'] ?? ''));
            $prio = trim((string) ($row['priority'] ?? ''));
            if ($key === '' || !isset($contexts[$key]) || isset($seenItems[$key])) continue;
            if (!in_array($prio, ['primary', 'backup'], true)) $prio = 'backup';
            if ($counts[$prio] >= 3) continue;
            $counts[$prio]++;
            $seenItems[$key] = true;
            $sources = $this->matchFollowupSources($contexts[$key]['followups'] ?? [], $row['evidence_ids'] ?? []);
            $items[] = [
                'key' => $key,
                'priority' => $prio,
                'blocker' => mb_substr(trim((string) ($row['blocker'] ?? '待核实')), 0, 500) ?: '待核实',
                'reason' => mb_substr(trim((string) ($row['reason'] ?? '重点推进')), 0, 500) ?: '重点推进',
                'action' => mb_substr(trim((string) ($row['action'] ?? '')), 0, 500),
                'help' => mb_substr(trim((string) ($row['help'] ?? '')), 0, 500),
                'sources' => $sources,
            ];
        }

        return [
            'pool_advice' => $poolAdvice,
            'review_suggestions' => $reviewSuggestions,
            'plan_draft' => $planDraft,
            'items' => $items,
        ];
    }

    private function planSuggestionRef(array $row): string
    {
        return hash('sha256', json_encode(array_map(fn ($key) => trim((string) ($row[$key] ?? '')),
            ['subject_key', 'task', 'time', 'action']), JSON_UNESCAPED_UNICODE));
    }

    private function matchFollowupSources(array $followups, mixed $evidenceIds): array
    {
        if (!is_array($evidenceIds) || !$evidenceIds || !$followups) return [];
        $wanted = array_values(array_unique(array_map('intval', array_filter($evidenceIds, fn ($v) => is_numeric($v) && (int) $v > 0))));
        if (!$wanted) return [];
        return collect($followups)->whereIn('id', $wanted)->values()->all();
    }
}
