<?php

namespace App\Services\Erp;

use App\Models\Erp\ApprovalFlowTemplate;
use App\Models\Erp\ApprovalFlowVersion;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApprovalFlowApplicationService
{
    public function __construct(
        private readonly ApprovalConfigurationCatalog $catalog,
        private readonly ApprovalBusinessObjectRegistry $objects,
        private readonly ApprovalBusinessEventRegistry $events,
        private readonly ApprovalExpressionEngine $expressions,
        private readonly ApprovalBusinessObjectAccessService $access,
    ) {}

    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = ApprovalFlowTemplate::query()
            ->with(['versions' => fn ($q) => $q->orderByDesc('version_no')->limit(1)])
            ->withCount(['versions', 'versions as draft_count' => fn ($q) => $q->where('version_status', 'draft')])
            ->withCount(['versions as node_version_count']);
        if ($value = trim((string) ($filters['business_module'] ?? ''))) $query->where('business_module', $value);
        if ($value = trim((string) ($filters['business_type'] ?? ''))) $query->where('business_type', $value);
        if ($value = trim((string) ($filters['status'] ?? ''))) $query->where('status', $value);
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) {
            $query->where(fn ($q) => $q->where('flow_code', 'like', "%{$keyword}%")
                ->orWhere('flow_name', 'like', "%{$keyword}%")
                ->orWhere('business_scene', 'like', "%{$keyword}%"));
        }
        $page = $query->orderBy('id')->paginate(min(max((int) ($filters['per_page'] ?? 20), 1), 100));
        $taskCounts = DB::table('erp_approval_tasks')->selectRaw('flow_template_id, count(*) total')->whereNotNull('flow_template_id')->groupBy('flow_template_id')->pluck('total', 'flow_template_id');
        $page->getCollection()->transform(function (ApprovalFlowTemplate $flow) use ($taskCounts) {
            $version = $flow->versions->first();
            $definition = (array) ($version?->definition_snapshot ?? []);
            $flow->setAttribute('node_count', count($definition['nodes'] ?? []));
            $flow->setAttribute('trigger_count', (int) ($taskCounts[$flow->id] ?? 0));
            $flow->setAttribute('latest_version_status', $version?->version_status);
            $flow->setAttribute('latest_version_no', $version?->version_no ?? 0);
            return $flow;
        });
        return $page;
    }

    public function summary(): array
    {
        return [
            'enabled' => ApprovalFlowTemplate::query()->where('status', 'enabled')->count(),
            'draft' => ApprovalFlowVersion::query()->where('version_status', 'draft')->count(),
            'invalid' => ApprovalFlowVersion::query()->where('version_status', 'draft')->get()->filter(fn ($row) => !($row->validation_snapshot['valid'] ?? false))->count(),
            'monthly_triggered' => DB::table('erp_approval_tasks')->where('created_at', '>=', now()->startOfMonth())->count(),
        ];
    }

    public function configOptions(): array
    {
        return [
            'flow_categories' => $this->catalog->flowCategories(),
            'field_types' => $this->catalog->fieldTypes(),
            'business_objects' => $this->catalog->businessObjects(),
            'completion_actions' => $this->catalog->actions(),
            'custom_forms' => $this->customFormOptions(),
            'scope_types' => [
                ['value' => 'all', 'label' => '全部组织', 'help' => '所有组织提交的该类业务都可使用此流程'],
                ['value' => 'initiator_department', 'label' => '发起人所属部门', 'help' => '按发起人的所属部门自动适用'],
                ['value' => 'departments', 'label' => '指定部门', 'help' => '仅选中的部门可以使用此流程'],
            ],
            'departments' => DB::table('erp_departments')->where('status', 'normal')
                ->orderBy('sort')->orderBy('legacy_id')->get(['legacy_id as value', 'name as label'])->all(),
            'roles' => DB::table('erp_rbac_roles')->where('enabled', true)
                ->orderBy('id')->get(['code as value', 'name as label'])->all(),
            'users' => DB::table('erp_legacy_admin_users')->where('status', 'normal')
                ->orderBy('legacy_id')->get(['legacy_id as value', 'nickname', 'username'])
                ->map(fn ($row) => ['value' => (int) $row->value, 'label' => ($row->nickname ?: $row->username).'（'.$row->username.'）'])->all(),
            'positions' => DB::table('erp_legacy_admin_users')->where('status', 'normal')
                ->whereNotNull('legacy_payload')
                ->selectRaw("DISTINCT JSON_UNQUOTE(JSON_EXTRACT(legacy_payload, '$.position')) AS value")
                ->havingRaw("value IS NOT NULL AND value <> ''")
                ->orderBy('value')
                ->get()
                ->map(fn ($row) => ['value' => (string) $row->value, 'label' => (string) $row->value])
                ->values()->all(),
            'approver_sources' => [
                ['value' => 'user', 'label' => '指定人员'], ['value' => 'role', 'label' => '角色'],
                ['value' => 'position', 'label' => '岗位'], ['value' => 'department', 'label' => '部门成员'],
                ['value' => 'department_manager', 'label' => '指定部门负责人'],
                ['value' => 'initiator_manager', 'label' => '发起人直属负责人'],
                ['value' => 'initiator_department_manager', 'label' => '发起人部门负责人'],
                ['value' => 'business_record_owner', 'label' => '业务记录负责人'],
                ['value' => 'business_record_department_manager', 'label' => '业务记录所属部门负责人'],
                ['value' => 'field_user', 'label' => '表单人员字段'],
                ['value' => 'field_user_manager', 'label' => '表单人员字段的负责人'],
                ['value' => 'field_department_manager', 'label' => '表单部门字段负责人'],
            ],
            'business_types' => ApprovalFlowTemplate::query()->select('business_type', 'business_module', 'business_scene')
                ->whereNotNull('business_type')->orderBy('business_module')->get()->unique('business_type')->map(fn ($row) => [
                    'value' => $row->business_type,
                    'label' => ($row->business_scene ?: $row->business_type).'（'.$row->business_type.'）',
                    'module' => $row->business_module, 'scene' => $row->business_scene,
                ])->values()->all(),
            'operators' => [
                ['value' => '=', 'label' => '等于'], ['value' => '!=', 'label' => '不等于'],
                ['value' => '>', 'label' => '大于'], ['value' => '>=', 'label' => '大于等于'],
                ['value' => '<', 'label' => '小于'], ['value' => '<=', 'label' => '小于等于'],
                ['value' => 'in', 'label' => '属于'], ['value' => 'not_in', 'label' => '不属于'],
                ['value' => 'contains', 'label' => '包含'], ['value' => 'not_contains', 'label' => '不包含'],
                ['value' => 'empty', 'label' => '为空'], ['value' => 'not_empty', 'label' => '不为空'],
            ],
            'trigger_modes' => [['value' => 'MANUAL_START', 'label' => '仅手动发起'], ['value' => 'EVENT_TRIGGER', 'label' => '仅业务动作触发'], ['value' => 'BOTH', 'label' => '手动与业务动作均可触发']],
            'execution_modes' => [['value' => 'BEFORE_ACTION', 'label' => '业务动作前阻断'], ['value' => 'AFTER_ACTION', 'label' => '业务动作后审核'], ['value' => 'OBSERVE_ONLY', 'label' => '仅观察不阻断']],
            'node_types' => [['value' => 'APPROVAL', 'label' => '审批节点'], ['value' => 'CONDITION', 'label' => '条件节点'], ['value' => 'CC', 'label' => '抄送节点'], ['value' => 'ACTION', 'label' => '动作节点']],
        ];
    }

    public function launchOptions(object $user, array $permissionCodes, bool $isSuperAdmin): array
    {
        $departmentId = DB::table('erp_department_users')
            ->where('user_legacy_id', $user->legacy_id)->value('department_legacy_id');

        return ApprovalFlowTemplate::query()->where('status', 'enabled')->whereIn('trigger_mode', ['MANUAL_START', 'BOTH'])
            ->with(['versions' => fn ($query) => $query->where('version_status', 'published')->orderByDesc('version_no')])
            ->orderBy('business_module')->orderBy('flow_name')->get()
            ->map(function (ApprovalFlowTemplate $flow) use ($departmentId, $user, $permissionCodes, $isSuperAdmin) {
                $version = $flow->versions->firstWhere('version_no', $flow->current_version) ?: $flow->versions->first();
                if (!$version) return null;
                $definition = (array) $version->definition_snapshot;
                $scope = (array) ($definition['applicable_scope'] ?? ['type' => 'all']);
                if (($scope['type'] ?? 'all') === 'departments'
                    && !in_array((int) $departmentId, array_map('intval', (array) ($scope['department_ids'] ?? [])), true)) return null;
                $sourceMode = (string) ($definition['source_mode'] ?? 'existing');
                $object = $sourceMode === 'existing' ? $this->catalog->linkedObject($definition) : null;
                if ($sourceMode === 'existing') {
                    $registered = $this->objects->find((string) ($definition['business_object_code'] ?? ''), false);
                    if (!$registered || !$this->access->canBrowse($registered, $user, $permissionCodes, $isSuperAdmin)) return null;
                }
                $formId = $sourceMode === 'custom_form' ? (int) ($definition['form_template_id'] ?? 0) : 0;
                $form = $formId ? collect($this->customFormOptions())->firstWhere('value', $formId) : null;
                return [
                    'id' => (int) $flow->id,
                    'flow_code' => $flow->flow_code,
                    'flow_name' => $flow->flow_name,
                    'business_type' => $flow->business_type,
                    'business_module' => $flow->business_module,
                    'business_scene' => $flow->business_scene,
                    'source_mode' => $sourceMode,
                    'trigger_action' => $definition['trigger_action'] ?? null,
                    'event_code' => $flow->event_code ?: ($definition['event_code'] ?? $definition['trigger_action'] ?? null),
                    'trigger_mode' => $flow->trigger_mode,
                    'business_object' => $object,
                    'form_template_id' => $formId ?: null,
                    'custom_form' => $form,
                ];
            })->filter()->values()->all();
    }

    public function sourceRecords(int $flowId, array $filters, object $user, array $permissionCodes, bool $isSuperAdmin): LengthAwarePaginator
    {
        [$flow, $definition] = $this->publishedFlow($flowId);
        if (($definition['source_mode'] ?? 'existing') !== 'existing') {
            throw ValidationException::withMessages(['flow' => '自定义表单流程不需要选择已有业务记录。']);
        }
        $object = $this->catalog->linkedObject($definition);
        if (!$object) throw ValidationException::withMessages(['flow' => '流程没有绑定有效的ERP业务对象。']);
        $registered = $this->objects->find((string) ($definition['business_object_code'] ?? ''));
        $primaryKey = (string) $registered->primary_key;
        $fields = collect($object['fields'] ?? []);
        $searchFields = collect($object['search_fields'] ?? [])->filter()->values();
        if ($searchFields->isEmpty()) $searchFields = collect([$object['number_field'] ?? $primaryKey]);
        $columns = collect([$primaryKey, $object['number_field'] ?? $primaryKey, 'created_at'])
            ->merge($searchFields)->merge(collect($object['status_fields'] ?? [])->pluck('value'))->unique()
            ->filter(fn ($column) => $fields->contains('value', $column))->values()->all();
        if (!in_array($primaryKey, $columns, true)) $columns[] = $primaryKey;
        $query = DB::table($object['table'])->select($columns);
        $this->access->applyVisibleScope($query, $registered, $user, $permissionCodes, $isSuperAdmin);
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) {
            $query->where(function ($where) use ($searchFields, $keyword) {
                foreach ($searchFields as $index => $field) {
                    $index === 0 ? $where->where($field, 'like', "%{$keyword}%") : $where->orWhere($field, 'like', "%{$keyword}%");
                }
            });
        }
        $page = $query->orderByDesc($primaryKey)->paginate(min(max((int) ($filters['per_page'] ?? 10), 1), 50));
        $page->getCollection()->transform(function ($row) use ($object, $fields, $searchFields, $primaryKey) {
            $raw = (array) $row;
            $number = $raw[$object['number_field']] ?? $raw[$primaryKey];
            $nameField = $searchFields->first(fn ($field) => str_ends_with((string) $field, '_name'));
            $statusField = collect($object['status_fields'] ?? [])->pluck('value')->first();
            return [
                'id' => (int) $raw[$primaryKey],
                'business_no' => (string) $number,
                'title' => (string) ($nameField ? ($raw[$nameField] ?? $number) : $number),
                'status' => $statusField ? ($raw[$statusField] ?? null) : null,
                'summary' => collect($raw)->reject(fn ($value, $key) => in_array($key, [$primaryKey, 'created_at'], true))
                    ->take(6)->map(fn ($value, $key) => ['key' => $key, 'label' => data_get($fields->firstWhere('value', $key), 'label', $key), 'value' => $value])->values()->all(),
            ];
        });
        return $page;
    }

    public function launchDefinition(int $flowId, ?object $user = null, array $permissionCodes = [], bool $isSuperAdmin = false, ?int $businessId = null): array
    {
        [$flow, $definition] = $this->publishedFlow($flowId);
        if ($user && ($definition['source_mode'] ?? 'existing') === 'existing') {
            $object = $this->objects->find((string) ($definition['business_object_code'] ?? ''));
            $this->access->assertCanBrowse($object, $user, $permissionCodes, $isSuperAdmin);
            if ($businessId) $this->access->assertCanAccessRecord($object, $businessId, $user, $permissionCodes, $isSuperAdmin);
        }
        return ['flow' => $flow, 'definition' => $definition, 'object' => $this->catalog->linkedObject($definition)];
    }

    public function show(int $id): ApprovalFlowTemplate
    {
        $flow = ApprovalFlowTemplate::query()->with(['versions' => fn ($q) => $q->orderByDesc('version_no')])->findOrFail($id);
        $draft = $flow->versions->firstWhere('version_status', 'draft');
        $current = $flow->versions->firstWhere('version_no', $flow->current_version);
        $flow->setAttribute('editing_version', $draft ?: $current);
        return $flow;
    }

    public function saveDraft(?int $id, array $payload, string $operator): ApprovalFlowTemplate
    {
        return DB::transaction(function () use ($id, $payload, $operator) {
            $flow = $id
                ? ApprovalFlowTemplate::query()->lockForUpdate()->findOrFail($id)
                : new ApprovalFlowTemplate();
            $payload['business_type'] = trim((string) ($payload['business_type'] ?? '')) ?: strtoupper(trim((string) $payload['flow_code']));
            $payload['business_scene'] = trim((string) ($payload['business_scene'] ?? '')) ?: trim((string) $payload['flow_name']);
            $flow->fill(Arr::only($payload, ['flow_code', 'flow_name', 'business_module', 'business_type', 'business_scene', 'applicable_scope', 'description']));
            $definition = (array) ($payload['definition'] ?? []);
            $flow->business_object_code = $definition['business_object_code'] ?? null;
            $flow->event_code = $definition['event_code'] ?? $definition['trigger_action'] ?? null;
            $flow->trigger_mode = $definition['trigger_mode'] ?? 'MANUAL_START';
            $flow->execution_mode = $definition['execution_mode'] ?? 'BEFORE_ACTION';
            $flow->priority = max(0, (int) ($definition['priority'] ?? 100));
            $flow->match_strategy = $definition['match_strategy'] ?? 'FIRST_MATCH';
            if (!$flow->exists) $flow->created_by = $operator;
            $flow->updated_by = $operator;
            $flow->status = $flow->status ?: 'draft';
            $flow->save();

            $validation = $this->validateDefinition($definition);
            $draft = ApprovalFlowVersion::query()->where('flow_template_id', $flow->id)->where('version_status', 'draft')->lockForUpdate()->first();
            if (!$draft) {
                $nextVersion = max((int) $flow->current_version, (int) ApprovalFlowVersion::where('flow_template_id', $flow->id)->max('version_no')) + 1;
                $draft = new ApprovalFlowVersion(['flow_template_id' => $flow->id, 'version_no' => $nextVersion, 'version_status' => 'draft']);
            }
            $draft->definition_snapshot = $definition;
            $draft->validation_snapshot = $validation;
            $draft->updated_by = $operator;
            $draft->save();
            return $this->show($flow->id);
        });
    }

    public function publish(int $id, array $payload, string $operator): ApprovalFlowTemplate
    {
        return DB::transaction(function () use ($id, $payload, $operator) {
            $flow = ApprovalFlowTemplate::query()->lockForUpdate()->findOrFail($id);
            if (isset($payload['definition'])) $this->saveDraft($id, $payload, $operator);
            $draft = ApprovalFlowVersion::query()->where('flow_template_id', $id)->where('version_status', 'draft')->lockForUpdate()->firstOrFail();
            $validation = $this->validateDefinition((array) $draft->definition_snapshot);
            if (!$validation['valid']) throw ValidationException::withMessages(['definition' => $validation['errors']]);
            $this->assertNoPublishedConflict($flow, (array) $draft->definition_snapshot);
            $draft->update(['version_status' => 'published', 'validation_snapshot' => $validation, 'published_by' => $operator, 'published_at' => now(), 'updated_by' => $operator]);
            $flow->update(['status' => 'enabled', 'current_version' => $draft->version_no, 'updated_by' => $operator]);
            return $this->show($flow->id);
        });
    }

    public function toggle(int $id, bool $enabled, string $operator): ApprovalFlowTemplate
    {
        return DB::transaction(function () use ($id, $enabled, $operator) {
            $flow = ApprovalFlowTemplate::query()->lockForUpdate()->findOrFail($id);
            if ($enabled && $flow->current_version < 1) throw ValidationException::withMessages(['status' => '未发布的流程不能启用。']);
            $flow->update(['status' => $enabled ? 'enabled' : 'disabled', 'updated_by' => $operator]);
            return $this->show($flow->id);
        });
    }

    public function copy(int $id, string $operator): ApprovalFlowTemplate
    {
        $source = $this->show($id);
        $definition = (array) ($source->editing_version?->definition_snapshot ?? []);
        return $this->saveDraft(null, [
            'flow_code' => $source->flow_code.'_COPY_'.now()->format('His'),
            'flow_name' => $source->flow_name.'（复制）', 'business_module' => $source->business_module,
            'business_type' => $source->business_type.'_COPY', 'business_scene' => $source->business_scene,
            'applicable_scope' => $source->applicable_scope, 'description' => $source->description,
            'definition' => $definition,
        ], $operator);
    }

    public function validateDefinition(array $definition): array
    {
        $errors = []; $warnings = [];
        $schemaVersion = (int) ($definition['schema_version'] ?? 1);
        $formFields = array_values((array) ($definition['form_fields'] ?? []));
        $conditionFields = [];
        if ($schemaVersion >= 2) {
            $sourceMode = (string) ($definition['source_mode'] ?? 'existing');
            if (!in_array($sourceMode, ['existing', 'custom_form'], true)) $errors[] = '审核对象来源无效。';
            if ($sourceMode === 'existing') {
                $object = $this->catalog->businessObject((string) ($definition['business_object_code'] ?? ''));
                if (!$object) $errors[] = '请选择已有ERP业务单据。';
                else {
                    $registeredObject = $this->objects->find((string) ($definition['business_object_code'] ?? ''), false);
                    $triggerMode = (string) ($definition['trigger_mode'] ?? 'MANUAL_START');
                    if (in_array($triggerMode, ['MANUAL_START', 'BOTH'], true)
                        && (!$registeredObject || trim((string) $registeredObject->view_permission_code) === '')) {
                        $errors[] = '允许手动发起的业务对象必须配置来源查看权限码。';
                    }
                    $conditionFields = collect($object['fields'])->where('condition_enabled', true)->pluck('value')->all();
                    $eventCode = (string) ($definition['event_code'] ?? $definition['trigger_action'] ?? '');
                    if (!in_array($triggerMode, ['MANUAL_START', 'EVENT_TRIGGER', 'BOTH'], true)) $errors[] = '触发模式无效。';
                    else try {
                        if ($triggerMode === 'BOTH') {
                            $this->events->assertEnabled((string) $definition['business_object_code'], $eventCode, 'MANUAL_START');
                            $this->events->assertEnabled((string) $definition['business_object_code'], $eventCode, 'EVENT_TRIGGER');
                        } else $this->events->assertEnabled((string) $definition['business_object_code'], $eventCode, $triggerMode);
                    } catch (ValidationException $exception) { $errors[] = $exception->getMessage(); }
                    if (!in_array((string) ($definition['execution_mode'] ?? 'BEFORE_ACTION'), ['BEFORE_ACTION', 'AFTER_ACTION', 'OBSERVE_ONLY'], true)) $errors[] = '业务阻断模式无效。';
                    if (!in_array((string) ($definition['match_strategy'] ?? 'FIRST_MATCH'), ['FIRST_MATCH', 'MULTI_MATCH'], true)) $errors[] = '流程匹配策略无效。';
                    if (($definition['match_strategy'] ?? 'FIRST_MATCH') === 'MULTI_MATCH') $warnings[] = 'MULTI_MATCH 仅预留，当前版本按 FIRST_MATCH 执行。';
                    $fieldTypes = collect($object['fields'])->pluck('type', 'value')->all();
                    $errors = [...$errors, ...$this->expressions->validate((array) ($definition['start_conditions'] ?? []), $conditionFields, $fieldTypes)];
                }
            } else {
                $formId = (int) ($definition['form_template_id'] ?? 0);
                if (!$formId) $errors[] = '请先创建并选择自定义表单，再配置审核流程。';
                elseif (!DB::getSchemaBuilder()->hasTable('erp_approval_form_templates') || !DB::table('erp_approval_form_templates')->where('id', $formId)->where('status', 'enabled')->exists()) $errors[] = '所选自定义表单不存在或未启用。';
                else $conditionFields = collect($this->customFormSchema($formId)['fields'] ?? [])->pluck('key')->filter()->values()->all();
            }
            $actions = array_values((array) ($definition['completion_actions'] ?? []));
            foreach (['approved', 'rejected'] as $event) {
                $row = collect($actions)->firstWhere('event', $event);
                if (!$row || empty($row['action_key'])) $errors[] = '缺少'.($event === 'approved' ? '审核通过' : '审核驳回').'处理动作。';
                else {
                    try {
                        $action = $this->catalog->assertActionAllowed($definition, $event, (string) $row['action_key']);
                        if (!empty($action['requires_updates'])) {
                            $object = $this->catalog->linkedObject($definition);
                            $updates = array_values((array) data_get($row, 'config.updates', []));
                            if (!$object) $errors[] = '字段更新动作必须先选择现有ERP业务单据。';
                            if (!$updates) $errors[] = ($event === 'approved' ? '审核通过' : '审核驳回').'字段更新动作至少配置一个字段。';
                            $allowedFields = collect($object['fields'] ?? [])->where('approval_writable', true)->pluck('value')->all();
                            foreach ($updates as $updateIndex => $update) {
                                $field = (string) ($update['field'] ?? '');
                                if (!$field || !in_array($field, $allowedFields, true) || in_array($field, ['id', 'created_at', 'updated_at'], true)) {
                                    $errors[] = ($event === 'approved' ? '审核通过' : '审核驳回').'字段更新动作第'.($updateIndex + 1).'项字段无效。';
                                }
                                if (!array_key_exists('value', $update) || $update['value'] === '') {
                                    $errors[] = ($event === 'approved' ? '审核通过' : '审核驳回').'字段更新动作第'.($updateIndex + 1).'项未填写目标值。';
                                }
                            }
                        }
                    }
                    catch (ValidationException $exception) { $errors[] = $exception->getMessage(); }
                }
            }
        } else {
            $errors[] = '该草稿仍为旧版数据库字段模式，请在页面重新选择已注册业务对象后保存为新版流程。';
        }
        $scope = (array) ($definition['applicable_scope'] ?? ['type' => 'all']);
        $scopeType = (string) ($scope['type'] ?? 'all');
        if (!in_array($scopeType, ['all', 'initiator_department', 'departments'], true)) $errors[] = '适用组织规则无效。';
        if ($scopeType === 'departments') {
            $departmentIds = array_map('intval', (array) ($scope['department_ids'] ?? []));
            if (!$departmentIds) $errors[] = '适用组织选择“指定部门”时，必须至少选择一个部门。';
            elseif (DB::table('erp_departments')->whereIn('legacy_id', $departmentIds)->count() !== count(array_unique($departmentIds))) $errors[] = '适用组织中存在无效部门。';
        }
        $nodes = array_values($definition['nodes'] ?? []);
        if (!$nodes) $errors[] = '至少配置一个审核节点。';
        $keys = [];
        foreach ($nodes as $index => $node) {
            $prefix = '第'.($index + 1).'个节点';
            $nodeType = strtoupper((string) ($node['node_type'] ?? 'APPROVAL'));
            if (!in_array($nodeType, ['APPROVAL', 'CONDITION', 'CC', 'ACTION'], true)) $errors[] = $prefix.'节点类型无效。';
            if (!trim((string) ($node['key'] ?? ''))) $errors[] = $prefix.'缺少节点标识。';
            if (!trim((string) ($node['name'] ?? ''))) $errors[] = $prefix.'缺少节点名称。';
            if ($nodeType === 'APPROVAL' && !trim((string) ($node['permission_code'] ?? ''))) $errors[] = $prefix.'缺少处理权限。';
            $key = (string) ($node['key'] ?? '');
            if ($key !== '' && in_array($key, $keys, true)) $errors[] = '节点标识重复：'.$key;
            $keys[] = $key;
            $ruleType = (string) data_get($node, 'approver_rule.type', '');
            $ruleValue = data_get($node, 'approver_rule.value');
            $supportedAssignees = ['role', 'department_principal', 'user', 'specified_users', 'position', 'department', 'department_manager', 'initiator_manager', 'initiator_department_manager', 'business_record_owner', 'business_record_department_manager', 'field_user', 'field_user_manager', 'field_department_manager'];
            if (in_array($nodeType, ['APPROVAL', 'CC'], true)) {
                if (!in_array($ruleType, $supportedAssignees, true)) {
                    $errors[] = $prefix.'处理人来源无效。';
                } elseif ($ruleType === 'role' && !trim((string) $ruleValue)) {
                    $errors[] = $prefix.'未选择处理角色。';
                } elseif ($ruleType === 'role' && !DB::table('erp_rbac_roles')->where('code', (string) $ruleValue)->where('enabled', true)->exists()) {
                    $errors[] = $prefix.'选择的处理角色不存在或已停用。';
                } elseif ($ruleType === 'department_principal' && !trim((string) $ruleValue)) {
                    $errors[] = $prefix.'未选择负责人所属部门规则。';
                } elseif ($ruleType === 'department_principal' && $ruleValue !== 'task_department' && !DB::table('erp_departments')->where('legacy_id', (int) $ruleValue)->exists()) {
                    $errors[] = $prefix.'选择的负责人部门不存在。';
                } elseif (in_array($ruleType, ['user', 'specified_users'], true) && empty((array) $ruleValue)) {
                    $errors[] = $prefix.'未选择指定处理人。';
                } elseif (in_array($ruleType, ['user', 'specified_users'], true)) {
                    $userIds = array_map('intval', (array) $ruleValue);
                    if (DB::table('erp_legacy_admin_users')->whereIn('legacy_id', $userIds)->where('status', 'normal')->count() !== count(array_unique($userIds))) $errors[] = $prefix.'指定处理人中存在无效或停用账号。';
                }
                if (in_array($ruleType, ['business_record_owner', 'business_record_department_manager', 'field_user', 'field_user_manager', 'field_department_manager'], true)) {
                    $sourceField = (string) data_get($node, 'approver_rule.field', $ruleValue);
                    $allowedReferenceFields = isset($object)
                        ? collect($object['fields'] ?? [])->filter(fn ($field) => ($field['reference_enabled'] ?? false) || in_array($field['type'] ?? '', ['user', 'department'], true))->pluck('value')->all()
                        : $conditionFields;
                    if (!$sourceField || !in_array($sourceField, $allowedReferenceFields, true)) $errors[] = $prefix.'处理人来源字段未在业务对象 Registry 中声明为可引用字段。';
                }
            }
            $processingStrategy = (string) ($node['processing_strategy'] ?? 'sequential');
            if ($nodeType === 'APPROVAL' && !in_array($processingStrategy, ['sequential', 'parallel'], true)) {
                $errors[] = $prefix.'多人处理策略无效。';
            } elseif ($nodeType === 'APPROVAL' && $processingStrategy === 'parallel' && (!in_array($ruleType, ['user', 'specified_users'], true) || count(array_unique((array) $ruleValue)) < 2)) {
                $errors[] = $prefix.'选择“全部指定人员通过”时，必须至少指定两名处理人。';
            }
            if ($nodeType === 'APPROVAL') {
                $completionStrategy = strtoupper((string) ($node['completion_strategy'] ?? ($processingStrategy === 'parallel' ? 'ALL' : 'ANY')));
                if (!in_array($completionStrategy, ['ANY', 'ALL', 'COUNT', 'RATIO'], true)) $errors[] = $prefix.'节点完成规则无效。';
                if ($completionStrategy === 'COUNT' && (int) ($node['required_approver_count'] ?? 0) < 1) $errors[] = $prefix.'指定人数通过规则必须填写大于零的人数。';
                $ratio = (float) ($node['required_approver_ratio'] ?? 0);
                if ($completionStrategy === 'RATIO' && ($ratio <= 0 || $ratio > 1)) $errors[] = $prefix.'指定比例通过规则必须填写大于0且不超过1的比例。';
            }
            $objectForTypes = isset($object) ? $object : null;
            $fieldTypesForNode = $objectForTypes ? collect($objectForTypes['fields'])->pluck('type', 'value')->all() : [];
            $nodeConditions = (array) ($node['entry_conditions'] ?? $node['conditions'] ?? []);
            if ($nodeConditions) foreach ($this->expressions->validate($nodeConditions, $conditionFields, $fieldTypesForNode) as $conditionError) $errors[] = $prefix.$conditionError;
            if ($nodeType === 'CONDITION' && !$nodeConditions) $errors[] = $prefix.'条件节点必须配置进入条件。';
            if ($nodeType === 'ACTION') {
                $actionCode = trim((string) ($node['action_code'] ?? ''));
                $action = $actionCode ? $this->catalog->action($actionCode) : null;
                if (!$actionCode || !$action || ($action['event'] ?? null) !== 'node_action') $errors[] = $prefix.'必须选择适用于当前业务对象的已注册节点动作。';
                elseif (!empty($action['object_code']) && isset($object) && $action['object_code'] !== ($object['code'] ?? null)) $errors[] = $prefix.'选择的节点动作不适用于当前业务对象。';
            }
            if ($nodeType === 'APPROVAL') {
                if (!array_key_exists('allow_reject', $node)) $errors[] = $prefix.'未配置是否允许驳回。';
                if (!array_key_exists('allow_transfer', $node)) $errors[] = $prefix.'未配置是否允许转交。';
                if (!empty($node['reminder_enabled']) && empty($node['sla_hours'])) $errors[] = $prefix.'启用催办时必须配置 SLA。';
            }
        }
        if (($definition['business_object_code'] ?? null) === 'SALES_ORDER_CHANGE') {
            $duplicates = collect($nodes)->filter(fn ($node) => strtoupper((string) ($node['node_type'] ?? 'APPROVAL')) === 'APPROVAL')
                ->groupBy(fn ($node) => strtolower(trim((string) ($node['approval_type'] ?? 'business'))))
                ->filter(fn ($rows, $type) => in_array($type, ['business', 'finance', 'fulfillment'], true) && $rows->count() > 1)
                ->keys()->all();
            if ($duplicates) $errors[] = '销售订单变更流程的业务、财务、履约审核类型不得重复：'.implode('、', $duplicates).'。';
        }
        if ($schemaVersion < 2) foreach (['approved', 'rejected'] as $callback) {
            if (empty($definition['callbacks'][$callback])) $errors[] = '缺少 '.$callback.' 业务回调。';
        }
        return ['valid' => !$errors, 'errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings))];
    }

    private function customFormOptions(): array
    {
        if (!DB::getSchemaBuilder()->hasTable('erp_approval_form_templates')) return [];
        return DB::table('erp_approval_form_templates as f')
            ->join('erp_approval_form_versions as v', function ($join) {
                $join->on('v.form_template_id', '=', 'f.id')->on('v.version_no', '=', 'f.current_version');
            })
            ->where('f.status', 'enabled')->where('v.version_status', 'published')
            ->orderBy('f.form_name')->get(['f.id as value', 'f.form_code', 'f.form_name as label', 'f.business_module', 'v.schema_snapshot'])
            ->map(function ($row) {
                $schema = json_decode((string) $row->schema_snapshot, true) ?: [];
                return [
                    'value' => (int) $row->value,
                    'form_code' => $row->form_code,
                    'label' => $row->label,
                    'business_module' => $row->business_module,
                    'fields' => collect((array) ($schema['fields'] ?? []))->map(function ($field) {
                        $type = $field['type'] ?? 'text';
                        $options = $field['options'] ?? [];
                        if ($type === 'user') {
                            $options = DB::table('erp_legacy_admin_users')->where('status', 'normal')->orderBy('legacy_id')
                                ->get(['legacy_id', 'nickname', 'username'])->map(fn ($user) => [
                                    'value' => (string) $user->legacy_id,
                                    'label' => ($user->nickname ?: $user->username).'（'.$user->username.'）',
                                ])->all();
                        } elseif ($type === 'department') {
                            $options = DB::table('erp_departments')->where('status', 'normal')->orderBy('sort')->get(['legacy_id', 'name'])
                                ->map(fn ($department) => ['value' => (string) $department->legacy_id, 'label' => $department->name])->all();
                        }
                        return [
                        'value' => $field['key'] ?? null,
                        'label' => $field['label'] ?? ($field['key'] ?? ''),
                        'type' => $type,
                        'required' => (bool) ($field['required'] ?? false),
                        'placeholder' => $field['placeholder'] ?? null,
                        'help' => $field['help'] ?? null,
                        'options' => $options,
                    ];
                    })->filter(fn ($field) => $field['value'])->values()->all(),
                ];
            })->all();
    }

    private function publishedFlow(int $flowId): array
    {
        $flow = ApprovalFlowTemplate::query()->where('status', 'enabled')->findOrFail($flowId);
        $version = ApprovalFlowVersion::query()->where('flow_template_id', $flow->id)
            ->where('version_no', $flow->current_version)->where('version_status', 'published')->first();
        if (!$version) throw ValidationException::withMessages(['flow' => '所选流程没有已发布且启用的版本。']);
        return [$flow, (array) $version->definition_snapshot];
    }

    private function customFormSchema(int $formId): array
    {
        $row = DB::table('erp_approval_form_templates as f')
            ->join('erp_approval_form_versions as v', function ($join) {
                $join->on('v.form_template_id', '=', 'f.id')->on('v.version_no', '=', 'f.current_version');
            })
            ->where('f.id', $formId)->where('f.status', 'enabled')->where('v.version_status', 'published')
            ->value('v.schema_snapshot');
        return json_decode((string) $row, true) ?: [];
    }

    private function assertNoPublishedConflict(ApprovalFlowTemplate $flow, array $definition): void
    {
        $objectCode = (string) ($definition['business_object_code'] ?? '');
        $eventCode = (string) ($definition['event_code'] ?? $definition['trigger_action'] ?? '');
        $priority = max(0, (int) ($definition['priority'] ?? 100));
        $conflict = ApprovalFlowTemplate::query()->where('status', 'enabled')->where('id', '!=', $flow->id)
            ->where('business_object_code', $objectCode)->where('event_code', $eventCode)->where('priority', $priority)
            ->whereIn('trigger_mode', array_unique([(string) ($definition['trigger_mode'] ?? 'MANUAL_START'), 'BOTH']))->exists();
        if ($conflict && ($definition['match_strategy'] ?? 'FIRST_MATCH') === 'FIRST_MATCH') {
            throw ValidationException::withMessages(['definition' => '同一业务对象、事件、触发模式和优先级已存在启用流程，请调整优先级或停用冲突流程。']);
        }
    }
}
