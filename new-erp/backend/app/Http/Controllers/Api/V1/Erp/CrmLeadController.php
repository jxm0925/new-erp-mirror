<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\CrmLead;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\Crm\CrmAssignmentAccessService;
use App\Services\Erp\Crm\CrmAttachmentApplicationService;
use App\Services\Erp\Crm\CrmCustomerAccessService;
use App\Services\Erp\Crm\CrmContactPlanQueryService;
use App\Services\Erp\Crm\CrmDuplicateReviewApplicationService;
use App\Services\Erp\Crm\CrmFollowupApplicationService;
use App\Services\Erp\Crm\CrmLeadApplicationService;
use App\Services\Erp\Crm\CrmListViewQueryService;
use App\Services\Erp\Crm\CrmSalesStaffService;
use App\Services\Erp\Crm\CrmTeamApplicationService;
use App\Services\Erp\TradePlatformApplicationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class CrmLeadController extends Controller
{
    public function __construct(private readonly CrmLeadApplicationService $leads) {}

    public function metadata(Request $request, CrmAssignmentAccessService $assignments, CrmSalesStaffService $staff, CrmCustomerAccessService $access)
    {
        $actor = $this->user($request, 'crm.lead.view');
        $dept = $staff->departmentOf((int) $actor->legacy_id);
        $grant = $assignments->activeGrant($actor);
        $ids = $assignments->expandDepartments(array_unique(array_filter(array_merge([$dept], $grant?->department_ids ?? [], $assignments->managedDepartments($actor)))));
        $query = CrmLead::query();
        $this->leads->applyVisible($query, $actor);
        $channels = array_values(array_unique(array_merge(array_keys(CrmLeadApplicationService::CHANNELS), $grant?->channels ?? [], (clone $query)->whereNotNull('source_channel')->distinct()->pluck('source_channel')->all())));
        $dictionaries = [];
        foreach (['lead_type', 'trade_type'] as $field) $dictionaries[$field] = (clone $query)->whereNotNull($field)->distinct()->limit(100)->pluck($field)->all();
        foreach (['customer_tags', 'business_lines'] as $field) $dictionaries[$field] = (clone $query)->whereNotNull($field)->select($field)->limit(500)->get()->pluck($field)->flatten()->unique()->values()->all();
        $dictionaries['custom_tags'] = (clone $query)->where('created_by_legacy_id', $actor->legacy_id)->whereNotNull('custom_tags')->select('custom_tags')->limit(500)->get()->pluck('custom_tags')->flatten()->unique()->values()->all();
        $abilities = [];
        foreach (['create', 'edit', 'assign', 'transfer', 'recycle', 'claim', 'follow', 'pool', 'invalidate', 'confirm'] as $action) $abilities[$action] = $access->can($actor, 'crm.lead.'.$action);
        foreach (['assign', 'transfer', 'recycle', 'claim'] as $action) {
            $abilities[$action] = $abilities[$action] && (app(AuthContextService::class)->isSuperAdmin($actor) || ($grant && in_array($action, $grant->actions, true)));
        }
        $abilities['review'] = $access->can($actor, 'sales.customer.verify') && (app(AuthContextService::class)->isSuperAdmin($actor) || ($grant && in_array('verify', $grant->actions, true)));
        return response()->json(['data' => [
            'customer_pools' => CrmLeadApplicationService::POOLS, 'statuses' => CrmLeadApplicationService::STATUSES,
            'source_channels' => $channels, 'trade_types' => array_values(array_unique(array_merge(['内贸销售', '内贸业务', '外贸'], $dictionaries['trade_type']))),
            'lead_types' => array_values(array_unique(array_merge(['普通咨询'], $dictionaries['lead_type']))),
            'customer_tags' => $dictionaries['customer_tags'], 'custom_tags' => $dictionaries['custom_tags'], 'business_lines' => $dictionaries['business_lines'],
            'platforms' => DB::table('erp_sales_order_trade_platforms')->where('parent_legacy_id', 0)->where('enabled', true)->orderBy('sort')->orderBy('legacy_id')
                ->get(['legacy_id', 'name', 'legacy_payload'])->map(fn ($row) => ['id' => (int) $row->legacy_id, 'name' => $row->name] + TradePlatformApplicationService::buyerIdentity($row->legacy_payload))->values(),
            'entry_methods' => ['self' => '销售自录', 'operations' => '运营代录'],
            'departments' => DB::table('erp_departments')->whereIn('legacy_id', $ids)->orderBy('sort')->orderBy('legacy_id')->get(['legacy_id as id', 'parent_legacy_id as parent_id', 'name']),
            'current_user' => ['id' => (int) $actor->legacy_id, 'name' => ($actor->nickname ?? null) ?: $actor->username,
                'department_legacy_id' => $dept, 'department_name' => $access->departmentName($dept), 'is_sales' => $assignments->activeSales((int) $actor->legacy_id)],
            'abilities' => $abilities, 'automatic_recycle_enabled' => false,
        ]]);
    }

    public function index(Request $request)
    {
        $actor = $this->user($request, 'crm.lead.view');
        $data = $request->validate([
            'keyword' => 'nullable|string|max:160', 'tab' => 'nullable|in:all,mine,collaborating,pending_assignment,pending_acceptance,pending_review,public_pool,draft',
            'status' => ['nullable', Rule::in(array_keys(CrmLeadApplicationService::STATUSES))],
            'source_channel' => 'nullable|string|max:120', 'department_legacy_id' => 'nullable|integer|min:1', 'customer_id' => 'nullable|integer|min:1',
            'exclude_id' => 'nullable|integer|min:1',
            'recycle_reason' => 'nullable|string|max:500',
            'base_confirmed' => 'nullable|boolean', 'unconverted' => 'nullable|boolean', 'convertible' => 'nullable|boolean',
            'customer_pool' => ['nullable', Rule::in(CrmLeadApplicationService::POOLS)], 'entry_method' => 'nullable|in:self,operations',
            'customer_tag' => 'nullable|string|max:80', 'custom_tag' => 'nullable|string|max:80',
            'due' => 'nullable|in:today,overdue,pending', 'per_page' => 'nullable|integer|min:1|max:100', 'page' => 'nullable|integer|min:1',
        ] + CrmListViewQueryService::rules('leads'));
        $query = CrmLead::query()->with(['customer', 'attachments']);
        $this->leads->applyVisible($query, $actor);
        if (!empty($data['exclude_id'])) $query->where('id', '!=', $data['exclude_id']);
        if (!empty($data['base_confirmed'])) $query->whereNotNull('confirmed_at');
        if (!empty($data['unconverted'])) $query->whereNotExists(fn ($o) => $o->from('erp_crm_opportunities')->whereColumn('source_lead_id', 'erp_crm_leads.id'));
        if (!empty($data['convertible'])) app(\App\Services\Erp\Crm\CrmOpportunityApplicationService::class)->applyConvertible($query, $actor);
        foreach (['status', 'source_channel', 'department_legacy_id', 'customer_id', 'customer_pool', 'entry_method'] as $field) if (!empty($data[$field])) $query->where($field, $data[$field]);
        if (isset($data['customer_tag']) && $data['customer_tag'] !== '') $query->whereJsonContains('customer_tags', $data['customer_tag']);
        if (isset($data['custom_tag']) && $data['custom_tag'] !== '') $query->where('created_by_legacy_id', $actor->legacy_id)->whereJsonContains('custom_tags', $data['custom_tag']);
        if (!empty($data['recycle_reason'])) $query->where('recycle_reason', 'like', '%'.trim($data['recycle_reason']).'%');
        if (!empty($data['keyword'])) {
            $keyword = '%'.trim($data['keyword']).'%';
            $query->where(fn ($q) => $q->where('lead_no', 'like', $keyword)->orWhere('customer_name', 'like', $keyword)
                ->orWhere('mobile', 'like', $keyword)->orWhere('demand_summary', 'like', $keyword)->orWhere('content', 'like', $keyword));
        }
        if (!empty($data['due'])) {
            $query->where('status', 'active')->where('owner_legacy_id', $actor->legacy_id);
            CrmContactPlanQueryService::applyDue($query, 'next_contact_at', $data['due']);
        }
        // 数量与列表共用已授权、已筛选的范围，只排除当前页签与分页，不能统计不可见线索。
        $statusCounts = (clone $query)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $collaborating = clone $query;
        $this->leads->applyCollaborating($collaborating, $actor);
        $tabs = ['all' => (int) $statusCounts->sum(), 'mine' => (clone $query)->where('owner_legacy_id', $actor->legacy_id)->count(),
            'collaborating' => $collaborating->count(), 'pending_acceptance' => (clone $query)->where('status', 'pending_acceptance')->where('pending_user_legacy_id', $actor->legacy_id)->count()];
        foreach (['pending_assignment', 'pending_review', 'public_pool', 'draft'] as $status) $tabs[$status] = (int) ($statusCounts[$status] ?? 0);
        $tab = $data['tab'] ?? 'all';
        if ($tab === 'mine') $query->where('owner_legacy_id', $actor->legacy_id);
        elseif ($tab === 'collaborating') $this->leads->applyCollaborating($query, $actor);
        elseif ($tab === 'pending_acceptance') $query->where('status', $tab)->where('pending_user_legacy_id', $actor->legacy_id);
        elseif (in_array($tab, ['pending_assignment', 'pending_review', 'public_pool', 'draft'], true)) $query->where('status', $tab);
        $query->orderByDesc('id');
        CrmListViewQueryService::apply($query, $data);
        $page = $query->paginate((int) ($data['per_page'] ?? 10))->through(fn ($lead) => $this->leads->present($lead, $actor));
        return response()->json($page->toArray() + ['tabs' => $tabs]);
    }

    public function show(Request $request, int $id)
    {
        $actor = $this->user($request, 'crm.lead.view');
        $lead = $this->leads->visible($id, $actor);
        $data = $this->leads->present($lead, $actor);
        $data['review'] = app(CrmDuplicateReviewApplicationService::class)->forLead($lead, $actor);
        return response()->json(['data' => $data]);
    }

    public function store(Request $request)
    {
        $actor = $this->user($request, 'crm.lead.create');
        $lead = $this->leads->save(null, $this->leadData($request, false), $actor);
        return response()->json(['message' => $lead->status === 'draft' ? '草稿已保存' : '线索已保存', 'data' => $this->leads->present($lead, $actor)], 201);
    }

    public function update(Request $request, int $id)
    {
        $actor = $this->user($request, 'crm.lead.edit');
        $lead = $this->leads->save($id, $this->leadData($request, true), $actor);
        return response()->json(['message' => '线索已保存', 'data' => $this->leads->present($lead, $actor)]);
    }

    public function assignees(Request $request, int $id, CrmTeamApplicationService $team, CrmAssignmentAccessService $access)
    {
        $actor = $this->user($request, 'crm.lead.view');
        $lead = $this->leads->visible($id, $actor);
        $data = $request->validate(['action' => 'required|in:assign,transfer', 'department_legacy_id' => 'nullable|integer|min:1',
            'keyword' => 'nullable|string|max:100', 'per_page' => 'nullable|integer|min:1|max:100', 'page' => 'nullable|integer|min:1']);
        $access->assert($actor, $data['action'], $lead->department_legacy_id, $lead->source_channel);
        return response()->json($team->staffOptions($actor, $data['action'], (int) ($data['department_legacy_id'] ?? $lead->department_legacy_id), $lead->source_channel, $data));
    }

    public function logs(Request $request, int $id)
    {
        $actor = $this->user($request, 'crm.lead.view');
        $this->leads->visible($id, $actor);
        return response()->json(DB::table('erp_crm_lead_logs')->where('lead_id', $id)->orderByDesc('id')
            ->paginate(min(100, max(1, $request->integer('per_page', 10))), ['id', 'action', 'summary', 'reason', 'operator_name', 'created_at']));
    }

    public function assign(Request $request, int $id) { return $this->action($request, $id, 'assign'); }
    public function accept(Request $request, int $id) { return $this->action($request, $id, 'accept'); }
    public function returnLead(Request $request, int $id) { return $this->action($request, $id, 'return'); }
    public function transfer(Request $request, int $id) { return $this->action($request, $id, 'transfer'); }
    public function recycle(Request $request, int $id) { return $this->action($request, $id, 'recycle'); }
    public function claim(Request $request, int $id) { return $this->action($request, $id, 'claim'); }
    public function changePool(Request $request, int $id) { return $this->action($request, $id, 'pool'); }
    public function invalidate(Request $request, int $id) { return $this->action($request, $id, 'invalidate'); }
    public function confirm(Request $request, int $id) { return $this->action($request, $id, 'confirm'); }

    public function followups(Request $request, CrmFollowupApplicationService $followups)
    {
        $actor = $this->user($request);
        $data = $request->validate(['subject_type' => 'required|in:customer,lead,opportunity', 'subject_id' => 'required|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100', 'page' => 'nullable|integer|min:1']);
        return response()->json($followups->listing($data, $actor));
    }

    public function storeFollowup(Request $request, CrmFollowupApplicationService $followups)
    {
        $actor = $this->user($request);
        $followup = $followups->save(null, $this->followupData($request, false), $actor);
        return response()->json(['message' => '跟进已保存，下次联系计划已更新', 'data' => $followups->present($followup, $actor)], 201);
    }

    public function updateFollowup(Request $request, int $id, CrmFollowupApplicationService $followups)
    {
        $actor = $this->user($request, 'crm.followup.edit');
        $followup = $followups->save($id, $this->followupData($request, true), $actor);
        return response()->json(['message' => '跟进已修改并保留修改记录', 'data' => $followups->present($followup, $actor)]);
    }

    public function uploadAttachment(Request $request, CrmAttachmentApplicationService $files, CrmCustomerAccessService $access)
    {
        $actor = $this->user($request);
        abort_unless($access->can($actor, 'crm.lead.create') || $access->can($actor, 'crm.lead.follow') || $access->can($actor, 'crm.lead.edit') || $access->can($actor, 'crm.lead.assign')
            || $access->can($actor, 'crm.opportunity.file') || $access->can($actor, 'crm.opportunity.follow'), 403, '没有上传附件的权限。');
        $data = $request->validate(['file' => 'required|file|max:20480|mimes:jpg,jpeg,png,gif,webp,bmp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip']);
        return response()->json(['message' => '附件已上传', 'data' => $files->present($files->upload($data['file'], $actor))], 201);
    }

    public function downloadAttachment(Request $request, int $id, CrmAttachmentApplicationService $files)
    {
        return $files->download($id, $this->user($request));
    }

    private function action(Request $request, int $id, string $action)
    {
        $actor = $this->user($request, 'crm.lead.'.$action);
        $data = $request->validate(['version' => 'required|integer|min:1', 'reason' => 'nullable|string|max:500', 'note' => 'nullable|string|max:500',
            'to_user_legacy_id' => 'nullable|integer|min:1', 'customer_pool' => ['nullable', Rule::in(CrmLeadApplicationService::POOLS)],
            'transfer_customer' => 'nullable|boolean', 'customer_version' => 'nullable|integer|min:1', 'assignment_attachment_ids' => 'nullable|array|max:30',
            'assignment_attachment_ids.*' => 'required|integer|min:1|distinct']);
        return response()->json(['message' => '操作已完成', 'data' => $this->leads->present($this->leads->act($id, $action, $data, $actor), $actor)]);
    }

    private function leadData(Request $request, bool $editing): array
    {
        $rules = ['mode' => 'required|in:draft,submit,review', 'version' => ($editing ? 'required' : 'nullable').'|integer|min:1',
            'customer_id' => 'nullable|integer|min:1', 'customer_kind' => 'nullable|in:enterprise,individual', 'customer_name' => 'nullable|string|max:160',
            'contact_id' => 'nullable|integer|min:1', 'contact_name' => 'nullable|string|max:80', 'contact_role' => 'nullable|string|max:80',
            'mobile' => 'nullable|string|max:80', 'contact_value2' => 'nullable|string|max:80', 'wechat' => 'nullable|string|max:80',
            'email' => 'nullable|email|max:160', 'credit_code' => 'nullable|string|max:40', 'region_id' => 'nullable|integer|min:1',
            'province' => 'nullable|string|max:80', 'city' => 'nullable|string|max:80', 'district' => 'nullable|string|max:80',
            'detail_address' => 'nullable|string|max:500', 'full_address' => 'nullable|string|max:500', 'trade_type' => 'nullable|string|max:80',
            'department_legacy_id' => 'nullable|integer|min:1', 'source_channel' => 'nullable|string|max:120', 'lead_type' => 'nullable|string|max:80',
            'is_new_inquiry' => 'nullable|boolean', 'customer_pool' => ['nullable', Rule::in(CrmLeadApplicationService::POOLS)],
            'expected_amount' => 'nullable|numeric|min:0|max:9999999999999999.99', 'currency' => 'nullable|string|size:3',
            'content' => 'nullable|string|max:100000', 'demand_summary' => 'nullable|string|max:5000',
            'platform_legacy_id' => 'nullable|integer|min:1', 'platform_buyer_id' => 'nullable|string|max:160',
            'next_contact_at' => 'nullable|date', 'next_plan' => 'nullable|string|max:5000',
            'duplicate_confirmed' => 'nullable|boolean', 'duplicate_reason' => 'nullable|string|max:500', 'review_note' => 'nullable|string|max:500',
            'new_demand_confirmed' => 'nullable|boolean',
            'attachment_ids' => 'nullable|array|max:30', 'attachment_ids.*' => 'required|integer|min:1|distinct'];
        foreach (['customer_tags', 'custom_tags', 'business_lines'] as $field) { $rules[$field] = 'nullable|array|max:30'; $rules[$field.'.*'] = 'required|string|max:80|distinct'; }
        return $request->validate($rules);
    }

    private function followupData(Request $request, bool $editing): array
    {
        return $request->validate(['subject_type' => ($editing ? 'nullable' : 'required').'|in:customer,lead,opportunity', 'subject_id' => ($editing ? 'nullable' : 'required').'|integer|min:1',
            'version' => ($editing ? 'required' : 'nullable').'|integer|min:1', 'reason' => ($editing ? 'required' : 'nullable').'|string|max:500',
            'contact_id' => 'nullable|integer|min:1', 'contact_name' => 'required|string|max:100', 'method' => 'required|string|max:80',
            'contacted_at' => 'required|date|before_or_equal:now', 'content' => 'required|string|max:100000', 'result' => 'required|string|max:120',
            'next_contact_at' => 'required|date|after:contacted_at', 'next_plan' => 'required|string|max:5000',
            'attachment_ids' => 'nullable|array|max:30', 'attachment_ids.*' => 'required|integer|min:1|distinct']);
    }

    private function user(Request $request, ?string $permission = null): object
    {
        $actor = app(AuthContextService::class)->currentUser($request);
        abort_unless($actor, 401, '未登录');
        if ($permission) $this->leads->permission($actor, $permission);
        return $actor;
    }
}
