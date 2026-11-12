<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\CrmOpportunity;
use App\Models\Erp\CrmOpportunityFile;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\Crm\CrmCustomerAccessService;
use App\Services\Erp\Crm\CrmOpportunityApplicationService;
use App\Services\Erp\Crm\CrmListViewQueryService;
use App\Services\Erp\Crm\CrmOpportunityOrderService;
use App\Services\Erp\Crm\CrmSalesStaffService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class CrmOpportunityController extends Controller
{
    public function __construct(private readonly CrmOpportunityApplicationService $opportunities) {}

    public function metadata(Request $request, CrmCustomerAccessService $access, CrmSalesStaffService $staff)
    {
        $actor = $this->user($request, 'view');
        $query = CrmOpportunity::query();
        $this->opportunities->applyVisible($query, $actor);
        $department = $staff->departmentOf((int) $actor->legacy_id);
        $abilities = [];
        foreach (['view', 'create', 'edit', 'stage', 'lose', 'follow', 'file', 'order'] as $action) $abilities[$action] = $access->can($actor, 'crm.opportunity.'.$action);
        return response()->json(['data' => [
            'stages' => CrmOpportunityApplicationService::STAGES, 'statuses' => CrmOpportunityApplicationService::STATUSES,
            'file_categories' => CrmOpportunityApplicationService::FILE_CATEGORIES, 'file_statuses' => CrmOpportunityApplicationService::FILE_STATUSES,
            'currencies' => array_values(array_unique(array_merge(['CNY', 'USD', 'EUR', 'HKD'], (clone $query)->distinct()->pluck('currency')->all()))),
            'business_lines' => (clone $query)->whereNotNull('business_line')->where('business_line', '!=', '')->distinct()->orderBy('business_line')->limit(100)->pluck('business_line'),
            'current_user' => ['id' => (int) $actor->legacy_id, 'name' => ($actor->nickname ?? null) ?: $actor->username,
                'department_legacy_id' => $department, 'department_name' => $access->departmentName($department)], 'abilities' => $abilities,
        ]]);
    }

    public function owners(Request $request)
    {
        $actor = $this->user($request, 'view');
        return response()->json($this->opportunities->owners($request->validate(['keyword' => 'nullable|string|max:100'] + $this->pageRules() + $this->idsRules()), $actor));
    }

    public function index(Request $request)
    {
        $actor = $this->user($request, 'view');
        $data = $request->validate([
            'keyword' => 'nullable|string|max:160', 'tab' => 'nullable|in:all,mine,collaborating,won,lost',
            'status' => ['nullable', Rule::in(array_keys(CrmOpportunityApplicationService::STATUSES))],
            'stage' => ['nullable', Rule::in(array_keys(CrmOpportunityApplicationService::STAGES))],
            'customer_id' => 'nullable|integer|min:1', 'source_lead_id' => 'nullable|integer|min:1', 'business_line' => 'nullable|string|max:120',
            'expected_close_from' => 'nullable|date_format:Y-m-d',
            'expected_close_to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('expected_close_from'), 'after_or_equal:expected_close_from')],
            'collaboratable' => 'nullable|boolean',
        ] + $this->pageRules() + $this->idsRules() + CrmListViewQueryService::rules('opportunities'));
        $query = CrmOpportunity::query()->with('customer');
        $this->opportunities->applyVisible($query, $actor);
        if (!empty($data['collaboratable'])) $this->opportunities->applyManageable($query, $actor);
        if (!empty($data['ids'])) $query->whereIn('id', $data['ids']);
        foreach (['customer_id', 'source_lead_id', 'business_line', 'stage', 'status'] as $field) if (!empty($data[$field])) $query->where($field, $data[$field]);
        if (!empty($data['expected_close_from'])) $query->whereDate('expected_close_date', '>=', $data['expected_close_from']);
        if (!empty($data['expected_close_to'])) $query->whereDate('expected_close_date', '<=', $data['expected_close_to']);
        if (!empty($data['keyword'])) {
            $keyword = '%'.trim($data['keyword']).'%';
            $query->where(fn ($q) => $q->where('opportunity_no', 'like', $keyword)->orWhere('name', 'like', $keyword)->orWhere('customer_name', 'like', $keyword));
        }
        $collaborating = clone $query;
        $this->opportunities->applyCollaborating($collaborating, $actor);
        $tabs = ['all' => (clone $query)->count(), 'mine' => (clone $query)->where('owner_legacy_id', $actor->legacy_id)->where('status', 'open')->count(),
            'collaborating' => $collaborating->where('status', 'open')->count(), 'won' => (clone $query)->where('status', 'won')->count(), 'lost' => (clone $query)->where('status', 'lost')->count()];
        $tab = $data['tab'] ?? (!empty($data['collaboratable']) || !empty($data['ids']) || !empty($data['customer_id']) ? 'all' : 'mine');
        if ($tab === 'mine') $query->where('owner_legacy_id', $actor->legacy_id)->where('status', 'open');
        elseif ($tab === 'collaborating') { $this->opportunities->applyCollaborating($query, $actor); $query->where('status', 'open'); }
        elseif (in_array($tab, ['won', 'lost'], true)) $query->where('status', $tab);
        $open = (clone $query)->where('status', 'open');
        $stageCounts = (clone $open)->selectRaw('stage, COUNT(*) AS total')->groupBy('stage')->pluck('total', 'stage');
        // 预计金额按币种汇总；未填金额独立计数，不伪装成零或成交事实。
        $amounts = (clone $open)->whereNotNull('expected_amount')->selectRaw('currency, SUM(expected_amount) AS amount')->groupBy('currency')->pluck('amount', 'currency');
        $summary = ['open_count' => (clone $open)->count(), 'unknown_amount_count' => (clone $open)->whereNull('expected_amount')->count(),
            'expected_amounts' => $amounts, 'stage_counts' => array_replace(array_fill_keys(array_keys(CrmOpportunityApplicationService::STAGES), 0), $stageCounts->map(fn ($value) => (int) $value)->all())];
        $query->orderByDesc('id');
        CrmListViewQueryService::apply($query, $data);
        $page = $query->paginate((int) ($data['per_page'] ?? 10))->through(fn ($opportunity) => $this->opportunities->present($opportunity, $actor));
        return response()->json($page->toArray() + ['tabs' => $tabs] + $summary);
    }

    public function show(Request $request, int $id)
    {
        $actor = $this->user($request, 'view');
        return response()->json(['data' => $this->opportunities->present($this->opportunities->visible($id, $actor), $actor)]);
    }

    public function store(Request $request)
    {
        $actor = $this->user($request, 'create');
        return response()->json(['message' => '商机已保存', 'data' => $this->opportunities->present($this->opportunities->save(null, $this->opportunityData($request, false), $actor), $actor)], 201);
    }

    public function update(Request $request, int $id)
    {
        $actor = $this->user($request, 'edit');
        return response()->json(['message' => '商机已保存', 'data' => $this->opportunities->present($this->opportunities->save($id, $this->opportunityData($request, true), $actor), $actor)]);
    }

    public function stage(Request $request, int $id)
    {
        $actor = $this->user($request, 'stage');
        $data = $request->validate(['version' => 'required|integer|min:1', 'direction' => 'required|in:forward,backward', 'reason' => 'required|string|max:5000',
            'file_id' => 'nullable|integer|min:1', 'expected_amount' => 'nullable|numeric|decimal:0,2|min:0|max:9999999999999999.99', 'expected_close_date' => 'nullable|date_format:Y-m-d']);
        return response()->json(['message' => '商机阶段已调整', 'data' => $this->opportunities->present($this->opportunities->stage($id, $data, $actor), $actor)]);
    }

    public function lose(Request $request, int $id)
    {
        $actor = $this->user($request, 'lose');
        $data = $request->validate(['version' => 'required|integer|min:1', 'lost_reason' => 'required|string|max:160',
            'lost_destination' => 'nullable|string|max:255', 'lost_note' => 'required|string|max:5000']);
        return response()->json(['message' => '商机已标记丢单', 'data' => $this->opportunities->present($this->opportunities->lose($id, $data, $actor), $actor)]);
    }

    public function files(Request $request, int $id)
    {
        $actor = $this->user($request, 'view');
        $this->opportunities->visible($id, $actor);
        $data = $request->validate(['keyword' => 'nullable|string|max:160', 'category' => ['nullable', Rule::in(array_keys(CrmOpportunityApplicationService::FILE_CATEGORIES))],
            'status' => ['nullable', Rule::in(array_keys(CrmOpportunityApplicationService::FILE_STATUSES))]] + $this->pageRules() + $this->idsRules());
        $query = CrmOpportunityFile::query()->with('attachment')->where('opportunity_id', $id);
        if (!empty($data['keyword'])) $query->whereHas('attachment', fn ($q) => $q->where('name', 'like', '%'.trim($data['keyword']).'%'));
        if (!empty($data['ids'])) $query->whereIn('id', $data['ids']);
        foreach (['category', 'status'] as $field) if (!empty($data[$field])) $query->where($field, $data[$field]);
        return response()->json($query->orderByDesc('id')->paginate((int) ($data['per_page'] ?? 10))->through(fn ($file) => $this->opportunities->presentFile($file)));
    }

    public function storeFile(Request $request, int $id)
    {
        $actor = $this->user($request, 'file');
        $data = $request->validate(['version' => 'required|integer|min:1'] + $this->fileRules());
        $file = $this->opportunities->saveFile($id, $data, $actor);
        return response()->json(['message' => '文件版本已保存', 'data' => $this->opportunities->presentFile($file), 'opportunity_version' => $this->opportunities->visible($id, $actor)->version], 201);
    }

    public function confirmFile(Request $request, int $id, int $fileId)
    {
        $actor = $this->user($request, 'file');
        $data = $request->validate(['version' => 'required|integer|min:1', 'reason' => 'required|string|max:5000']);
        $file = $this->opportunities->confirmFile($id, $fileId, $data, $actor);
        return response()->json(['message' => '文件版本已确认', 'data' => $this->opportunities->presentFile($file), 'opportunity_version' => $this->opportunities->visible($id, $actor)->version]);
    }

    public function logs(Request $request, int $id)
    {
        $actor = $this->user($request, 'view');
        $this->opportunities->visible($id, $actor);
        $data = $request->validate($this->pageRules());
        return response()->json(DB::table('erp_crm_opportunity_stage_logs')->where('opportunity_id', $id)->orderByDesc('id')->paginate((int) ($data['per_page'] ?? 10),
            ['id', 'action', 'summary', 'from_stage', 'to_stage', 'from_status', 'to_status', 'file_id', 'reason', 'operator_name', 'created_at']));
    }

    public function orderContext(Request $request, int $id, CrmOpportunityOrderService $orders)
    {
        $actor = $this->user($request, 'order');
        return response()->json(['data' => $orders->context($id, $actor)]);
    }

    public function orders(Request $request, int $id, CrmOpportunityOrderService $orders)
    {
        $actor = $this->user($request, 'view');
        return response()->json($orders->orders($id, $request->validate($this->pageRules()), $actor));
    }

    private function opportunityData(Request $request, bool $editing): array
    {
        $rules = [
            'version' => ($editing ? 'required' : 'nullable').'|integer|min:1', 'reason' => ($editing ? 'required' : 'nullable').'|string|max:5000',
            'request_key' => ($editing ? 'nullable' : 'required').'|string|max:64', 'customer_id' => ($editing ? 'nullable' : 'required').'|integer|min:1',
            'source_lead_id' => 'nullable|integer|min:1', 'source_lead_version' => ($editing ? 'nullable' : 'required_with:source_lead_id').'|integer|min:1',
            'name' => 'required|string|max:160', 'contact_id' => 'nullable|integer|min:1', 'owner_legacy_id' => 'nullable|integer|min:1', 'business_line' => 'nullable|string|max:120',
            'expected_amount' => 'nullable|numeric|decimal:0,2|min:0|max:9999999999999999.99', 'currency' => 'nullable|string|size:3',
            'expected_close_date' => 'nullable|date_format:Y-m-d', 'demand_summary' => 'nullable|string|max:5000',
            'next_contact_at' => 'nullable|date', 'next_plan' => 'nullable|string|max:5000', 'files' => 'nullable|array|max:30',
        ];
        foreach ($this->fileRules() as $field => $rule) $rules['files.*.'.$field] = $rule;
        return $request->validate($rules);
    }

    private function fileRules(): array
    {
        return ['attachment_id' => 'required|integer|min:1', 'category' => ['required', Rule::in(array_keys(CrmOpportunityApplicationService::FILE_CATEGORIES))],
            'status' => 'nullable|in:current,pending', 'note' => 'nullable|string|max:1000'];
    }

    private function pageRules(): array { return ['page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']; }
    private function idsRules(): array { return ['ids' => 'nullable|array|max:100', 'ids.*' => 'required|integer|min:1|distinct']; }

    private function user(Request $request, string $action): object
    {
        $actor = app(AuthContextService::class)->currentUser($request);
        abort_unless($actor, 401, '未登录');
        $this->opportunities->permission($actor, 'crm.opportunity.'.$action);
        return $actor;
    }
}
