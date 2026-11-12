<?php

namespace App\Services\Erp\Crm;

use App\Models\Erp\CrmSavedView;
use App\Models\Erp\SalesCustomer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** 只保存配置；共享配置可见与业务数据可见分别鉴权，不能借视图取得他人的客户或金额。 */
final class CrmSavedViewApplicationService
{
    public const PERMISSIONS = ['customers' => 'sales.customer', 'leads' => 'crm.lead.view',
        'opportunities' => 'crm.opportunity.view', 'dashboard' => 'crm.dashboard.view'];
    public const LIST_FIELDS = [
        'customers' => ['customer_name' => '客户名称', 'customer_code' => '客户编码', 'company_background' => '公司背景',
            'customer_kind' => '客户类型', 'customer_grade' => '客户等级', 'industry' => '行业', 'purchasing_power' => '年采购力',
            'business_lines' => '业务线', 'default_contact' => '联系人', 'owner_name' => '负责销售', 'deal_amount' => '成交金额',
            'customer_pools' => '客户池', 'last_contact_at' => '最近跟进', 'next_contact_at' => '下次跟进', 'status' => '状态',
            'region' => '地区', 'credit_code' => '统一信用代码', 'first_source' => '首次来源', 'customer_tags' => '客户标签', 'remark' => '备注',
            'open_opportunity_count' => '进行中商机', 'expected_amounts' => '待成交金额'],
        'leads' => ['customer_name' => '客户名称', 'lead_no' => '线索编号', 'customer_kind' => '客户类型', 'contact_name' => '联系人',
            'mobile' => '联系电话', 'region' => '地区', 'demand_summary' => '需求摘要', 'expected_amount' => '预计成交额',
            'trade_type' => '贸易类型', 'business_lines' => '业务线', 'source_channel' => '本次咨询来源', 'entry_method' => '录入方式',
            'platform_name' => '平台与店铺', 'owner_name' => '负责销售', 'department_name' => '所属部门', 'status' => '状态',
            'customer_pool' => '客户池', 'last_contact_at' => '最近跟进', 'next_contact_at' => '下次跟进', 'next_plan' => '跟进计划',
            'recycle_reason' => '回收原因', 'recycled_at' => '回收时间', 'previous_owner_name' => '原负责人', 'recycled_by_name' => '回收人'],
        'opportunities' => ['customer_name' => '客户名称', 'name' => '商机名称', 'business_line' => '业务线', 'owner_name' => '负责销售',
            'stage' => '阶段', 'expected_amount' => '预计金额', 'expected_close_date' => '预计成交日期',
            'last_contact_at' => '最近跟进', 'next_contact_at' => '下次跟进'],
    ];
    public const SORT_FIELDS = [
        'customers' => ['id', 'customer_name', 'customer_code', 'customer_grade', 'industry', 'purchasing_power', 'owner_name', 'status', 'last_contact_at', 'next_contact_at', 'created_at'],
        'leads' => ['id', 'lead_no', 'customer_name', 'owner_name', 'department_name', 'status', 'source_channel', 'customer_pool', 'last_contact_at', 'next_contact_at', 'created_at'],
        'opportunities' => ['id', 'name', 'customer_name', 'owner_name', 'stage', 'status', 'business_line', 'currency', 'expected_close_date', 'last_contact_at', 'next_contact_at', 'created_at'],
    ];
    public const GROUP_FIELDS = [
        'customers' => ['owner_name', 'industry', 'customer_grade', 'status', 'customer_kind', 'purchasing_power'],
        'leads' => ['status', 'source_channel', 'customer_pool', 'owner_name', 'department_name'],
        'opportunities' => ['stage', 'status', 'owner_name', 'business_line', 'currency'],
    ];
    public const DASHBOARD_PAGES = ['overview', 'sales', 'dimensions', 'pipeline', 'sources', 'income'];
    public const DEFAULT_COLUMNS = [
        'customers' => ['customer_name', 'customer_code', 'company_background', 'customer_kind', 'customer_grade', 'industry', 'purchasing_power',
            'business_lines', 'default_contact', 'owner_name', 'deal_amount', 'customer_pools', 'last_contact_at', 'next_contact_at', 'status'],
        'leads' => ['customer_name', 'lead_no', 'customer_kind', 'contact_name', 'mobile', 'region', 'demand_summary', 'expected_amount', 'trade_type',
            'business_lines', 'source_channel', 'entry_method', 'platform_name', 'owner_name', 'department_name', 'status', 'customer_pool',
            'last_contact_at', 'next_contact_at', 'next_plan', 'recycle_reason', 'recycled_at', 'previous_owner_name', 'recycled_by_name'],
        'opportunities' => ['customer_name', 'name', 'business_line', 'owner_name', 'stage', 'expected_amount', 'expected_close_date', 'last_contact_at', 'next_contact_at'],
    ];
    private const REQUIRED_FIELDS = ['customers' => ['customer_name'], 'leads' => ['customer_name'], 'opportunities' => ['customer_name', 'name']];
    private const PRIVATE_FILTERS = ['keyword', 'customer_id', 'custom_tag', 'recycle_reason'];

    public function __construct(
        private readonly CrmCustomerAccessService $access,
        private readonly CrmAssignmentAccessService $organizations,
    ) {}

    public function metadata(object $actor, string $resource): array
    {
        $this->permission($actor, $resource);
        $fields = $resource === 'dashboard' ? [] : $this->fields($actor, $resource);
        return ['resource' => $resource, 'fields' => $fields,
            'sort_fields' => $this->fieldOptions(self::SORT_FIELDS[$resource] ?? [], $resource),
            'group_fields' => $this->fieldOptions(self::GROUP_FIELDS[$resource] ?? [], $resource),
            'filter_keys' => array_keys($this->filterRules($resource)),
            'shared_filter_keys' => array_values(array_diff(array_keys($this->filterRules($resource)), self::PRIVATE_FILTERS)),
            'share_scopes' => $this->shareScopes($actor),
            'dashboard_components' => $resource === 'dashboard' ? $this->dashboardComponents($actor) : [],
            'dashboard_settings' => $resource === 'dashboard' ? ['dimensions' => $this->dashboardDimensions($actor),
                'sources' => $this->access->can($actor, 'crm.lead.view') ? ['first_source', 'inquiry_source', 'entry_method'] : ['first_source']] : null,
            'default_configuration' => $resource === 'dashboard' ? ['page' => 'overview', 'filters' => (object) [], 'settings' => (object) [],
                'components' => array_map(fn ($item) => array_intersect_key($item, array_flip(['key', 'title', 'position', 'width', 'order'])) + ['enabled' => $item['available'], 'drilldown' => true], $this->dashboardComponents($actor))]
                : ['columns' => array_values(array_intersect(self::DEFAULT_COLUMNS[$resource], array_column($fields, 'key'))), 'filters' => (object) [], 'sort' => (object) [], 'group_by' => '']];
    }

    public function index(object $actor, string $resource, array $filters)
    {
        $this->permission($actor, $resource);
        $query = $this->visibleQuery($actor)->where('resource', $resource);
        if (!empty($filters['keyword'])) $query->where('name', 'like', '%'.trim($filters['keyword']).'%');
        return $query->orderByDesc('updated_at')->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 10))
            ->through(fn ($view) => $this->present($view, $actor));
    }

    public function show(object $actor, int $id): array
    {
        $view = $this->visibleQuery($actor)->findOrFail($id);
        $this->permission($actor, $view->resource);
        return $this->present($view, $actor);
    }

    public function save(object $actor, ?int $id, array $payload): array
    {
        $data = Validator::make($payload, ['resource' => ['required', Rule::in(array_keys(self::PERMISSIONS))],
            'name' => 'required|string|max:80', 'scope' => 'required|in:personal,group,department',
            'scope_id' => 'nullable|integer|min:1', 'configuration' => 'required|array',
            'expected_version' => ($id ? 'required' : 'nullable').'|integer|min:1'])->validate();
        $data['name'] = trim($data['name']);
        abort_if($data['name'] === '' || preg_match('/[\x00-\x1f\x7f]/u', $data['name']), 422, '请输入有效的视图名称。');
        $this->permission($actor, $data['resource']);
        $configuration = $this->validateConfiguration($actor, $data['resource'], $data['configuration'], $data['scope'] !== 'personal');
        return DB::transaction(function () use ($actor, $id, $data, $configuration) {
            // 同一账号的同名保存串行化，重试返回原配置，避免重复点击生成多条视图。
            $this->organizations->lockPeople([(int) $actor->legacy_id]);
            abort_unless($this->organizations->activeAccount((int) $actor->legacy_id), 403, '账号已停用。');
            $view = $id ? CrmSavedView::query()->lockForUpdate()->findOrFail($id) : null;
            if ($view) {
                abort_unless($view->owner_legacy_id === (int) $actor->legacy_id, 403, '只能修改本人保存的视图。');
                abort_unless($view->resource === $data['resource'], 422, '不能更换视图所属页面。');
                abort_unless($view->version === (int) $data['expected_version'], 409, '视图已被修改，请刷新后重试。');
            }
            $scopeId = $this->assertShareScope($actor, $data['scope'], $data['scope_id'] ?? null);
            $duplicate = CrmSavedView::query()->where('owner_legacy_id', $actor->legacy_id)->where('resource', $data['resource'])
                ->where('name', $data['name'])->when($id, fn ($q) => $q->where('id', '!=', $id))->first();
            if (!$id && $duplicate && $duplicate->scope === $data['scope'] && $duplicate->scope_id === $scopeId && $duplicate->configuration == $configuration) {
                return $this->present($duplicate, $actor);
            }
            abort_if($duplicate, 422, '该页面已有同名视图，请更换名称。');
            $before = $view?->toArray();
            $values = ['resource' => $data['resource'], 'name' => $data['name'], 'scope' => $data['scope'], 'scope_id' => $scopeId,
                'configuration' => $configuration, 'version' => (int) ($view?->version ?? 0) + 1];
            if ($view) $view->update($values);
            else $view = CrmSavedView::create($values + ['owner_legacy_id' => (int) $actor->legacy_id, 'owner_name' => $this->actorName($actor)]);
            $this->audit($actor, $view, $id ? 'saved_view_update' : 'saved_view_create', $before, $view->fresh()->toArray());
            return $this->present($view->fresh(), $actor);
        }, 3);
    }

    public function destroy(object $actor, int $id, int $version): void
    {
        DB::transaction(function () use ($actor, $id, $version): void {
            $this->organizations->lockPeople([(int) $actor->legacy_id]);
            $view = CrmSavedView::query()->lockForUpdate()->findOrFail($id);
            $this->permission($actor, $view->resource);
            abort_unless($view->owner_legacy_id === (int) $actor->legacy_id, 403, '只能删除本人保存的视图。');
            abort_unless($view->version === $version, 409, '视图已被修改，请刷新后重试。');
            $this->audit($actor, $view, 'saved_view_delete', $view->toArray(), null);
            $view->delete();
        }, 3);
    }

    private function visibleQuery(object $actor): Builder
    {
        $resources = array_keys(array_filter(self::PERMISSIONS, fn ($permission) => $this->access->can($actor, $permission)));
        $organizations = $this->readableOrganizations($actor);
        return CrmSavedView::query()->whereIn('resource', $resources)->where(fn ($q) => $q->where('owner_legacy_id', $actor->legacy_id)
            ->orWhere(fn ($shared) => $shared->whereIn('scope', ['group', 'department'])->whereIn('scope_id', $organizations)));
    }

    private function readableOrganizations(object $actor): array
    {
        $rows = DB::table('erp_departments')->whereNull('deleted_at')->where('status', 'normal')->get(['legacy_id', 'parent_legacy_id']);
        $activeIds = $rows->pluck('legacy_id')->map(fn ($id) => (int) $id)->all();
        $ids = DB::table('erp_department_users')->where('user_legacy_id', $actor->legacy_id)->pluck('department_legacy_id')->map(fn ($id) => (int) $id)->all();
        $ids = array_values(array_intersect($ids, $activeIds));
        // 部门共享覆盖其当前子组成员；调岗后旧组织不再出现在每次请求重算的范围中。
        do {
            $before = count($ids);
            foreach ($rows as $row) if (in_array((int) $row->legacy_id, $ids, true) && in_array((int) $row->parent_legacy_id, $activeIds, true)) $ids[] = (int) $row->parent_legacy_id;
            $ids = array_values(array_unique($ids));
        } while ($before !== count($ids));
        return array_values(array_unique(array_merge($ids, $this->organizations->managedDepartments($actor))));
    }

    private function shareScopes(object $actor): array
    {
        $options = [['scope' => 'personal', 'scope_id' => null, 'id' => null, 'name' => '仅自己', 'label' => '仅自己', 'parent_id' => null]];
        $rows = DB::table('erp_departments')->whereIn('legacy_id', $this->organizations->managedDepartments($actor))
            ->whereNull('deleted_at')->where('status', 'normal')->orderBy('sort')->orderBy('legacy_id')->get(['legacy_id', 'parent_legacy_id', 'name']);
        foreach ($rows as $row) {
            $options[] = ['scope' => 'department', 'scope_id' => (int) $row->legacy_id, 'id' => (int) $row->legacy_id,
                'name' => $row->name, 'label' => $row->name.'（部门共享）', 'parent_id' => (int) $row->parent_legacy_id];
            if ($row->parent_legacy_id) $options[] = ['scope' => 'group', 'scope_id' => (int) $row->legacy_id, 'id' => (int) $row->legacy_id,
                'name' => $row->name, 'label' => $row->name.'（小组共享）', 'parent_id' => (int) $row->parent_legacy_id];
        }
        return $options;
    }

    private function assertShareScope(object $actor, string $scope, mixed $scopeId): ?int
    {
        if ($scope === 'personal') {
            abort_if($scopeId !== null, 422, '个人视图不应指定共享组织。');
            return null;
        }
        abort_unless($scopeId, 422, '请选择共享的部门或小组。');
        DB::table('erp_department_users')->where('user_legacy_id', $actor->legacy_id)->orderBy('id')->lockForUpdate()->get(['id']);
        $department = DB::table('erp_departments')->where('legacy_id', $scopeId)->whereNull('deleted_at')->where('status', 'normal')->lockForUpdate()->first();
        abort_unless($department && ($scope !== 'group' || $department->parent_legacy_id), 422, '共享的部门或小组已失效。');
        abort_unless(in_array((int) $scopeId, $this->organizations->managedDepartments($actor), true), 403, '只能在当前有组织管理权的部门或小组中发布共享视图。');
        return (int) $scopeId;
    }

    private function present(CrmSavedView $view, object $actor): array
    {
        [$configuration, $warnings] = $this->readableConfiguration($actor, $view);
        $own = $view->owner_legacy_id === (int) $actor->legacy_id;
        return ['id' => $view->id, 'resource' => $view->resource, 'name' => $view->name, 'scope' => $view->scope,
            'scope_id' => $view->scope_id, 'scope_name' => $view->scope_id ? $this->access->departmentName($view->scope_id) : '仅自己',
            'owner_legacy_id' => $view->owner_legacy_id, 'owner_name' => $view->owner_name, 'configuration' => $configuration,
            'version' => $view->version, 'can_edit' => $own, 'can_update' => $own, 'can_delete' => $own,
            'shared' => $view->scope !== 'personal', 'warnings' => $warnings, 'updated_at' => $view->updated_at?->toDateTimeString()];
    }

    private function fields(object $actor, string $resource): array
    {
        $fields = self::LIST_FIELDS[$resource];
        if ($resource === 'customers' && (!$this->access->can($actor, 'sales_order.view') || !$this->access->can($actor, 'sales_order.amount.view'))) unset($fields['deal_amount']);
        if ($resource === 'customers' && !$this->access->can($actor, 'crm.opportunity.view')) unset($fields['open_opportunity_count'], $fields['expected_amounts']);
        if ($resource === 'customers' && !$this->access->can($actor, 'crm.lead.view')) unset($fields['customer_tags'], $fields['customer_pools']);
        return collect($fields)->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'required' => in_array($key, self::REQUIRED_FIELDS[$resource], true),
            'category' => $this->fieldCategory($key)])->values()->all();
    }

    private function fieldCategory(string $key): string
    {
        if (in_array($key, ['default_contact', 'contact_name', 'mobile', 'credit_code', 'platform_name'], true)) return '联系人与身份';
        if (in_array($key, ['deal_amount', 'expected_amount', 'expected_amounts', 'purchasing_power'], true)) return '经营汇总';
        if (in_array($key, ['last_contact_at', 'next_contact_at', 'next_plan', 'expected_close_date'], true)) return '联系情况';
        if (in_array($key, ['recycle_reason', 'recycled_at', 'previous_owner_name', 'recycled_by_name', 'entry_method', 'first_source'], true)) return '历史与审核';
        if (in_array($key, ['open_opportunity_count', 'customer_tags', 'customer_pool', 'customer_pools', 'stage', 'lead_no', 'name'], true)) return '线索与商机';
        return '客户资料';
    }

    private function fieldOptions(array $keys, string $resource): array
    {
        $labels = (self::LIST_FIELDS[$resource] ?? []) + ['id' => '编号', 'created_at' => '创建时间', 'status' => '状态', 'currency' => '币种'];
        return array_map(fn ($key) => ['key' => $key, 'label' => $labels[$key] ?? $key], $keys);
    }

    private function permission(object $actor, string $resource): void
    {
        abort_unless(isset(self::PERMISSIONS[$resource]) && $this->access->can($actor, self::PERMISSIONS[$resource]), 403, '没有该页面的查看权限。');
    }

    private function filterRules(string $resource): array
    {
        return match ($resource) {
            'customers' => ['tab' => 'nullable|in:mine,collab,all', 'keyword' => 'nullable|string|max:160',
                'industry' => 'nullable|string|max:80', 'customer_grade' => 'nullable|string|max:40',
                'owner_name' => 'nullable|string|max:80', 'status' => 'nullable|in:enabled,potential,disabled,blacklisted'],
            'leads' => ['tab' => 'nullable|in:all,mine,collaborating,pending_assignment,pending_acceptance,pending_review,public_pool,draft',
                'keyword' => 'nullable|string|max:160', 'department_legacy_id' => 'nullable|integer|min:1',
                'source_channel' => 'nullable|string|max:120', 'entry_method' => 'nullable|in:self,operations',
                'recycle_reason' => 'nullable|string|max:500', 'status' => ['nullable', Rule::in(array_keys(CrmLeadApplicationService::STATUSES))],
                'customer_pool' => ['nullable', Rule::in(CrmLeadApplicationService::POOLS)], 'customer_id' => 'nullable|integer|min:1',
                'customer_tag' => 'nullable|string|max:80', 'custom_tag' => 'nullable|string|max:80', 'due' => 'nullable|in:today,overdue,pending'],
            'opportunities' => ['tab' => 'nullable|in:all,mine,collaborating,won,lost', 'keyword' => 'nullable|string|max:160',
                'business_line' => 'nullable|string|max:120', 'stage' => ['nullable', Rule::in(array_keys(CrmOpportunityApplicationService::STAGES))],
                'expected_close_from' => 'nullable|date_format:Y-m-d', 'expected_close_to' => 'nullable|date_format:Y-m-d'],
            'dashboard' => ['date_from' => 'nullable|date_format:Y-m-d', 'date_to' => 'nullable|date_format:Y-m-d',
                'department_legacy_id' => 'nullable|integer|min:1', 'group_legacy_id' => 'nullable|integer|min:1',
                'sales_user_legacy_id' => 'nullable|integer|min:1', 'sales_user_unassigned' => 'nullable|boolean',
                'business_line' => 'nullable|string|max:120', 'currency' => 'nullable|string|max:20'],
        };
    }

    private function settingRules(?object $actor = null): array
    {
        return ['dimension' => ['nullable', Rule::in($this->dashboardDimensions($actor))],
            'source' => ['nullable', Rule::in(!$actor || $this->access->can($actor, 'crm.lead.view') ? ['first_source', 'inquiry_source', 'entry_method'] : ['first_source'])],
            'stage_mode' => 'nullable|in:count,amount',
            'cohort_from' => 'nullable|date_format:Y-m-d', 'cohort_to' => 'nullable|date_format:Y-m-d',
            'observed_on' => 'nullable|date_format:Y-m-d|before_or_equal:today', 'stalled_days' => 'nullable|integer|min:1|max:3650',
            'risk_kind' => 'nullable|in:pipeline_overdue_followup,pipeline_stalled,pipeline_overdue_close,pipeline_active',
            'task_kind' => 'nullable|in:contact_overdue,contact_due_soon,contact_pending_accept,contact_uncontacted,contact_unverified',
            'outstanding_status' => 'nullable|in:outstanding,settled,verification'];
    }

    private function configurationRules(object $actor, string $resource): array
    {
        $filters = $this->filterRules($resource);
        $rules = ['configuration' => 'required|array:'.($resource === 'dashboard' ? 'page,filters,settings,components' : 'columns,filters,sort,group_by'),
            'configuration.filters' => 'present|array:'.implode(',', array_keys($filters))];
        foreach ($filters as $field => $rule) $rules['configuration.filters.'.$field] = $rule;
        if ($resource !== 'dashboard') {
            return $rules + ['configuration.columns' => 'required|array|min:1|max:40',
                'configuration.columns.*' => ['required', 'string', 'distinct', Rule::in(array_column($this->fields($actor, $resource), 'key'))],
                'configuration.sort' => 'present|array:field,direction',
                'configuration.sort.field' => ['nullable', Rule::in(self::SORT_FIELDS[$resource])],
                'configuration.sort.direction' => 'nullable|in:asc,desc',
                'configuration.group_by' => ['nullable', Rule::in(self::GROUP_FIELDS[$resource])]];
        }
        $rules += ['configuration.page' => ['required', Rule::in(self::DASHBOARD_PAGES)],
            'configuration.settings' => 'present|array:'.implode(',', array_keys($this->settingRules())),
            'configuration.components' => 'required|array|min:1|max:60',
            'configuration.components.*' => 'required|array:key,title,position,width,order,enabled,drilldown',
            'configuration.components.*.key' => ['required', 'string', 'distinct', Rule::in(array_column($this->dashboardComponents(), 'key'))],
            'configuration.components.*.title' => 'required|string|max:80',
            'configuration.components.*.position' => ['required', Rule::in(self::DASHBOARD_PAGES)],
            'configuration.components.*.width' => 'required|in:metric,full,half,third',
            'configuration.components.*.order' => 'required|integer|min:0|max:1000',
            'configuration.components.*.enabled' => 'required|boolean', 'configuration.components.*.drilldown' => 'required|boolean'];
        foreach ($this->settingRules($actor) as $field => $rule) $rules['configuration.settings.'.$field] = $rule;
        return $rules;
    }

    private function validateConfiguration(object $actor, string $resource, array $configuration, bool $shared): array
    {
        $value = Validator::make(['configuration' => $configuration], $this->configurationRules($actor, $resource))->validate()['configuration'];
        abort_unless(array_is_list($value[$resource === 'dashboard' ? 'components' : 'columns']), 422, '列或组件必须使用有序列表。');
        if ($shared) {
            foreach (self::PRIVATE_FILTERS as $key) abort_if(isset($value['filters'][$key]) && $value['filters'][$key] !== '', 422, '共享视图不能保存私人搜索、客户标识、个人标签或回收备注条件。');
            abort_if(($value['settings']['dimension'] ?? null) === 'custom_tag', 422, '个人标签分析不能发布为共享看板。');
        }
        $this->assertDatePair($value['filters'], $resource === 'opportunities' ? 'expected_close_from' : 'date_from', $resource === 'opportunities' ? 'expected_close_to' : 'date_to');
        if ($resource === 'dashboard') {
            $this->assertDatePair($value['settings'], 'cohort_from', 'cohort_to');
            abort_if(!empty($value['filters']['sales_user_legacy_id']) && !empty($value['filters']['sales_user_unassigned']), 422, '不能同时选择销售人员和未归属销售。');
            app(CrmDashboardScopeService::class)->assertFilters($actor, $value['filters']);
            $catalog = array_column($this->dashboardComponents($actor), null, 'key');
            foreach ($value['components'] as $component) {
                abort_if($component['enabled'] && !$catalog[$component['key']]['available'], 422, '当前无权启用所选看板组件。');
                abort_if(($catalog[$component['key']]['width'] === 'metric') !== ($component['width'] === 'metric'), 422, '指标卡和分析面板的布局类型不能互换。');
                abort_if(trim($component['title']) === '' || preg_match('/[\x00-\x1f\x7f]/u', $component['title']), 422, '组件标题无效。');
            }
        } else {
            abort_if(array_diff(self::REQUIRED_FIELDS[$resource], $value['columns']), 422, '客户和商机名称等必要列不能隐藏。');
            abort_if(!empty($value['sort']['field']) !== !empty($value['sort']['direction']), 422, '排序字段和排序方向必须同时选择。');
            if (empty($value['sort']['field'])) $value['sort'] = [];
            $value['group_by'] = $value['group_by'] ?? '';
            if ($resource === 'customers' && !empty($value['filters']['owner_name'])) {
                $owners = SalesCustomer::query();
                $this->access->applyVisible($owners, $actor);
                abort_unless($owners->where('owner_name', $value['filters']['owner_name'])->exists(), 422, '所选负责销售已不在当前可见范围内。');
            }
        }
        // 共享只保留公共筛选；空的私人输入也不进入持久配置或读取响应。
        $value['filters'] = array_filter($value['filters'], fn ($item) => $item !== null && $item !== '');
        if ($shared) $value['filters'] = array_diff_key($value['filters'], array_flip(self::PRIVATE_FILTERS));
        return $value;
    }

    private function assertDatePair(array $values, string $from, string $to): void
    {
        abort_if(!empty($values[$from]) && !empty($values[$to]) && $values[$from] > $values[$to], 422, '结束日期不能早于开始日期。');
    }

    /** 目录或权限变化只影响当前读取，不能为修复旧配置而静默覆写用户的原保存值。 */
    private function readableConfiguration(object $actor, CrmSavedView $view): array
    {
        $original = $view->configuration ?? [];
        $configuration = is_array($original) ? $original : [];
        $resource = $view->resource;
        $configuration = array_intersect_key($configuration, array_flip($resource === 'dashboard' ? ['page', 'filters', 'settings', 'components'] : ['columns', 'filters', 'sort', 'group_by']));
        $configuration['filters'] = $this->readableValues($configuration['filters'] ?? [], $this->filterRules($resource));
        if ($view->scope !== 'personal') $configuration['filters'] = array_diff_key($configuration['filters'], array_flip(self::PRIVATE_FILTERS));
        $from = $resource === 'opportunities' ? 'expected_close_from' : 'date_from';
        $to = $resource === 'opportunities' ? 'expected_close_to' : 'date_to';
        if (!empty($configuration['filters'][$from]) && !empty($configuration['filters'][$to]) && $configuration['filters'][$from] > $configuration['filters'][$to]) unset($configuration['filters'][$from], $configuration['filters'][$to]);
        if ($resource === 'dashboard') {
            $configuration['page'] = in_array($configuration['page'] ?? '', self::DASHBOARD_PAGES, true) ? $configuration['page'] : 'overview';
            $configuration['settings'] = $this->readableValues($configuration['settings'] ?? [], $this->settingRules($actor));
            if (!empty($configuration['settings']['cohort_from']) && !empty($configuration['settings']['cohort_to'])
                && $configuration['settings']['cohort_from'] > $configuration['settings']['cohort_to']) unset($configuration['settings']['cohort_from'], $configuration['settings']['cohort_to']);
            if ($view->scope !== 'personal' && ($configuration['settings']['dimension'] ?? '') === 'custom_tag') unset($configuration['settings']['dimension']);
            $catalog = array_column($this->dashboardComponents($actor), null, 'key');
            $components = [];
            foreach (is_array($configuration['components'] ?? null) ? $configuration['components'] : [] as $component) {
                if (!is_array($component) || !is_string($component['key'] ?? null) || !isset($catalog[$component['key']]) || isset($components[$component['key']])) continue;
                $definition = $catalog[$component['key']];
                $item = $this->readableValues($component, ['key' => 'required|string', 'title' => 'required|string|max:80',
                    'position' => ['required', Rule::in(self::DASHBOARD_PAGES)], 'width' => 'required|in:metric,full,half,third',
                    'order' => 'required|integer|min:0|max:1000', 'enabled' => 'required|boolean', 'drilldown' => 'required|boolean']);
                $item += array_intersect_key($definition, array_flip(['key', 'title', 'position', 'width', 'order'])) + ['enabled' => true, 'drilldown' => true];
                if (($definition['width'] === 'metric') !== ($item['width'] === 'metric')) $item['width'] = $definition['width'];
                if (!$definition['available']) $item['enabled'] = false;
                $components[$item['key']] = $item;
            }
            $configuration['components'] = array_values($components);
        } else {
            $allowed = array_column($this->fields($actor, $resource), 'key');
            $configuration['columns'] = array_values(array_unique(array_filter(is_array($configuration['columns'] ?? null) ? $configuration['columns'] : [], fn ($key) => is_string($key) && in_array($key, $allowed, true))));
            foreach (self::REQUIRED_FIELDS[$resource] as $key) if (!in_array($key, $configuration['columns'], true)) $configuration['columns'][] = $key;
            $sort = $configuration['sort'] ?? [];
            $configuration['sort'] = is_array($sort) && in_array($sort['field'] ?? '', self::SORT_FIELDS[$resource], true) && in_array($sort['direction'] ?? '', ['asc', 'desc'], true)
                ? ['field' => $sort['field'], 'direction' => $sort['direction']] : [];
            $configuration['group_by'] = in_array($configuration['group_by'] ?? '', self::GROUP_FIELDS[$resource], true) ? $configuration['group_by'] : '';
        }
        $warnings = $configuration != $original ? ['部分配置字段已失效或当前无权使用，已按当前权限忽略；原保存值未修改。'] : [];
        // 空对象保持接口形状，避免前端把筛选或排序误认为有序列表。
        foreach (['filters', $resource === 'dashboard' ? 'settings' : 'sort'] as $key) if (!$configuration[$key]) $configuration[$key] = (object) [];
        return [$configuration, $warnings];
    }

    private function readableValues(mixed $values, array $rules): array
    {
        $result = [];
        foreach (is_array($values) ? $values : [] as $key => $value) {
            if (!isset($rules[$key]) || Validator::make(['value' => $value], ['value' => $rules[$key]])->fails()) continue;
            $result[$key] = $value;
        }
        return $result;
    }

    private function dashboardDimensions(?object $actor): array
    {
        $dimensions = ['industry', 'purchase_scale', 'customer_level', 'region', 'business_line'];
        if (!$actor || $this->access->can($actor, 'crm.lead.view')) $dimensions = array_merge($dimensions, ['customer_pool', 'customer_tag', 'custom_tag']);
        return $dimensions;
    }

    private function dashboardComponents(?object $actor = null): array
    {
        $metrics = ['customers' => ['客户总数', '当前归属的去重客户数'], 'new_customers' => ['本期新增客户', '所选期间新增的去重客户数'],
            'first_contacts' => ['近7天首次联系', '今天及之前6个自然日的首次联系'], 'open_opportunities' => ['进行中商机', '当前进行中商机数'],
            'expected' => ['待成交金额', '当前进行中商机的同币种预计金额'], 'deal_amount' => ['成交金额', '所选下单期间有效订单的当前成交金额'],
            'received_amount' => ['已收金额', '同批有效订单的当前已收金额'], 'outstanding_amount' => ['尾款金额', '同批有效订单的当前尾款金额'],
            'active' => ['当前进行中', '当前进行中商机数'], 'won' => ['本期赢单', '所选期间实际赢单数'], 'lost' => ['本期丢单', '所选期间实际丢单数'],
            'due' => ['本期应跟进', '所选期间计划跟进数'], 'completed' => ['已完成', '所选期间实际完成跟进数'], 'overdue' => ['逾期', '当前未完成的逾期跟进数']];
        $metricPages = ['overview' => ['customers', 'new_customers', 'first_contacts', 'open_opportunities', 'expected', 'deal_amount'],
            'sales' => ['customers', 'new_customers', 'first_contacts', 'open_opportunities', 'expected', 'deal_amount', 'received_amount', 'outstanding_amount'],
            'pipeline' => ['active', 'expected', 'won', 'lost'], 'sources' => ['due', 'completed', 'overdue', 'first_contacts'],
            'income' => ['deal_amount', 'received_amount', 'outstanding_amount']];
        $components = [];
        foreach ($metricPages as $page => $keys) foreach ($keys as $order => $metric) {
            $components[] = ['key' => $page.'_'.$metric, 'metric' => $metric,
                'title' => $page === 'overview' && $metric === 'deal_amount' ? '本期成交金额' : $metrics[$metric][0],
                'category' => $page, 'position' => $page, 'width' => 'metric', 'order' => $order,
                'chart' => '指标卡', 'basis' => $metrics[$metric][1], 'dimension' => '固定统计口径', 'date' => $metrics[$metric][1]];
        }
        $panels = [
            ['overview_sales', '销售经营对比', 'overview', 'half', '柱状图', '成交金额、待成交金额', '原订单销售 / 当前商机销售', '所选下单期间 / 当前状态'],
            ['overview_stages', '当前商机阶段', 'overview', 'half', '柱状图', '进行中商机数', '当前商机阶段', '当前状态'],
            ['overview_industry', '客户行业分布', 'overview', 'third', '柱状图', '去重客户数', '客户行业', '当前归属'],
            ['overview_purchase', '客户年采购力分布', 'overview', 'third', '柱状图', '去重客户数', '年采购力', '当前归属'],
            ['overview_followups', '跟进提醒', 'overview', 'third', '指标与名单', '应跟进、已完成、逾期', '跟进状态', '所选期间 / 当前逾期'],
            ['sales_table', '销售经营明细', 'sales', 'full', '明细表', '客户、商机及订单金额', '销售人员', '当前归属 / 所选下单期间'],
            ['sales_funnels', '销售个人推进漏斗', 'sales', 'full', '漏斗图', '同批线索有据可查的阶段到达数', '销售人员、线索阶段', '同批录入期间与观察日'],
            ['dimension_distribution', '客户维度分布', 'dimensions', 'half', '柱状图', '去重客户数', '客户分析维度', '当前归属'],
            ['dimension_cross', '销售 × 客户维度', 'dimensions', 'half', '交叉表', '去重客户数', '销售人员 × 客户分析维度', '当前归属'],
            ['dimension_table', '客户维度经营对比', 'dimensions', 'full', '明细表', '去重客户、进行中商机及订单金额', '客户分析维度', '当前归属 / 所选下单期间'],
            ['pipeline_stages', '当前阶段分布', 'pipeline', 'half', '柱状图', '进行中商机数 / 同币种预计金额', '当前商机阶段', '当前状态'],
            ['pipeline_funnel', '同批线索推进漏斗', 'pipeline', 'half', '漏斗图', '同批线索有据可查的阶段到达数', '线索阶段', '同批录入期间与观察日'],
            ['pipeline_records', '商机推进明细', 'pipeline', 'full', '分页名单', '进行中商机', '超期、停留、预计成交日', '当前状态'],
            ['source_distribution', '客户来源分布', 'sources', 'half', '柱状图', '去重客户数', '客户首次来源 / 本次咨询来源 / 录入方式', '当前归属'],
            ['source_followups', '销售跟进计划与完成', 'sources', 'half', '柱状图', '应跟进数、已完成数', '销售人员', '所选计划 / 实际完成期间'],
            ['source_records', '跟进任务明细', 'sources', 'full', '分页名单', '需处理的跟进任务', '任务状态', '当前状态'],
            ['income_chart', '各销售成交、已收与尾款', 'income', 'half', '柱状图', '成交、已收、尾款金额', '订单原销售', '所选下单期间的当前事实'],
            ['income_table', '销售金额汇总', 'income', 'half', '汇总表', '成交、已收、尾款金额', '订单原销售', '所选下单期间的当前事实'],
            ['income_orders', '订单明细', 'income', 'full', '分页名单', '成交、已收、尾款金额', '订单原销售、尾款状态', '所选下单期间的当前事实'],
        ];
        foreach ($panels as $index => [$key, $title, $position, $width, $chart, $basis, $dimension, $date]) {
            $components[] = compact('key', 'title', 'position', 'width', 'chart', 'basis', 'dimension', 'date') + ['category' => $position, 'order' => 100 + $index];
        }
        if (!$actor) return $components;
        $customers = $this->access->can($actor, 'sales.customer');
        $leads = $this->access->can($actor, 'crm.lead.view');
        $opportunities = $this->access->can($actor, 'crm.opportunity.view');
        $amounts = $this->access->can($actor, 'sales_order.view') && $this->access->can($actor, 'sales_order.amount.view');
        return array_map(function ($component) use ($customers, $leads, $opportunities, $amounts) {
            $key = $component['key'];
            $metric = $component['metric'] ?? null;
            $component['available'] = match (true) {
                in_array($metric, ['deal_amount', 'received_amount', 'outstanding_amount'], true), str_starts_with($key, 'income_') => $amounts,
                in_array($metric, ['customers', 'new_customers', 'first_contacts'], true), in_array($key, ['overview_industry', 'overview_purchase', 'dimension_distribution', 'dimension_cross', 'dimension_table', 'source_distribution'], true) => $customers,
                in_array($metric, ['open_opportunities', 'expected', 'active', 'won', 'lost'], true), in_array($key, ['overview_stages', 'pipeline_stages', 'pipeline_records'], true) => $opportunities,
                in_array($key, ['sales_funnels', 'pipeline_funnel'], true) => $leads && $opportunities,
                default => $customers || $leads || $opportunities || ($amounts && in_array($key, ['overview_sales', 'sales_table'], true)),
            };
            return $component;
        }, $components);
    }

    private function actorName(object $actor): ?string { return ($actor->nickname ?? null) ?: ($actor->username ?? null); }
    private function audit(object $actor, CrmSavedView $view, string $action, ?array $before, ?array $after): void
    {
        DB::table('erp_operation_logs')->insert(['module' => 'crm', 'action' => $action, 'target_type' => 'crm_saved_view', 'target_id' => $view->id,
            'old_snapshot' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
            'new_snapshot' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
            'operator_id' => $actor->legacy_id, 'operator_name' => $this->actorName($actor), 'created_at' => now()]);
    }
}
