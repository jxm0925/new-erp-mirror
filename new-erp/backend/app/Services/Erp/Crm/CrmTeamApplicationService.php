<?php

namespace App\Services\Erp\Crm;

use App\Models\Erp\CrmAssignGrant;
use App\Models\Erp\CrmStaffProfile;
use App\Services\Erp\AuthContextService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class CrmTeamApplicationService
{
    public const TAG_GROUPS = ['products' => '产品与业务', 'industries' => '客户行业', 'skills' => '销售能力',
        'regions' => '负责区域', 'languages' => '语言', 'businesses' => '适合业务'];

    public function __construct(
        private readonly CrmAssignmentAccessService $access,
        private readonly CrmSalesStaffService $staff,
        private readonly AuthContextService $auth,
    ) {}

    public function metadata(object $actor): array
    {
        $this->permission($actor, 'crm.team.view');
        $super = $this->auth->isSuperAdmin($actor);
        $grant = $this->access->activeGrant($actor);
        $managed = $this->access->managedDepartments($actor);
        $departments = DB::table('erp_departments')->whereIn('legacy_id', $managed)
            ->orderBy('sort')->orderBy('legacy_id')->get(['legacy_id as id', 'parent_legacy_id as parent_id', 'name']);
        $actions = $super ? CrmAssignmentAccessService::ACTIONS : array_filter(CrmAssignmentAccessService::ACTIONS,
            fn ($label, $key) => $grant && in_array($key, $grant->actions, true) && $this->access->hasPermission($actor, CrmAssignmentAccessService::PERMISSIONS[$key]), ARRAY_FILTER_USE_BOTH);
        $channels = $this->channels();
        if (!$super && $grant?->channels) $channels = collect($grant->channels)->map(fn ($value) => ['value' => $value, 'label' => $value])->all();
        if (!$super && !$grant) $channels = [];
        $ownDepartment = $this->staff->departmentOf((int) $actor->legacy_id);
        return [
            'current_user' => ['id' => (int) $actor->legacy_id, 'name' => $this->actorName($actor),
                'department_name' => $ownDepartment ? DB::table('erp_departments')->where('legacy_id', $ownDepartment)->value('name') : null],
            'departments' => $departments,
            'channels' => $channels,
            'actions' => collect($actions)->map(fn ($label, $value) => compact('value', 'label'))->values(),
            'grant_limits' => ['department_ids' => $super ? $managed : ($grant ? array_values(array_intersect($managed, $this->access->expandDepartments($grant->department_ids))) : []),
                'all_channels' => $super || ($grant && !$grant->channels), 'assignee_mode' => $super ? 'all' : $grant?->assignee_mode,
                'assignee_ids' => $super ? [] : ($grant?->assignee_ids ?? []), 'effective_from' => $grant?->effective_from?->toDateString(), 'effective_to' => $grant?->effective_to?->toDateString()],
            'tag_groups' => collect(self::TAG_GROUPS)->map(fn ($label, $value) => compact('value', 'label'))->values(),
            'abilities' => ['capability' => $this->access->hasPermission($actor, 'crm.team.capability'), 'grant' => $this->access->hasPermission($actor, 'crm.team.grant')],
        ];
    }

    public function staff(object $actor, array $filters)
    {
        $this->permission($actor, 'crm.team.view');
        $query = $this->staffQuery($filters)->whereIn('bs.department_legacy_id', $this->access->managedDepartments($actor));
        if (!empty($filters['department_id'])) $query->whereIn('bs.department_legacy_id', $this->access->expandDepartments([(int) $filters['department_id']]));
        if (isset($filters['department_ids'])) $query->whereIn('bs.department_legacy_id', $this->access->expandDepartments($filters['department_ids']));
        if (isset($filters['accepting'])) {
            if ($filters['accepting']) $query->where(fn ($q) => $q->whereNull('p.accepting')->orWhere('p.accepting', true));
            else $query->where('p.accepting', false);
        }
        return $this->staffPage($query, $filters);
    }

    public function showStaff(object $actor, int $userId): array
    {
        $this->permission($actor, 'crm.team.view');
        $row = $this->staffQuery(['ids' => [$userId]])->whereIn('bs.department_legacy_id', $this->access->managedDepartments($actor))
            ->first(['u.legacy_id as id', 'u.nickname', 'u.username', 'u.status as account_status', 'bs.department_legacy_id', 'd.name as department_name']);
        abort_unless($row, 404, '该销售不在可管理的团队范围内。');
        return $this->presentStaff($row);
    }

    /** 线索分配候选在查询阶段过滤授权，分页总数与可选人员保持一致。 */
    public function staffOptions(object $actor, string $action, int $departmentId, ?string $channel, array $filters)
    {
        $this->access->assert($actor, $action, $departmentId, $channel);
        $query = $this->staffQuery($filters)->whereIn('bs.department_legacy_id', $this->access->expandDepartments([$departmentId]));
        $this->applyEffectiveSales($query);
        $query->where(fn ($q) => $q->whereNull('p.accepting')->orWhere('p.accepting', true));
        if (!$this->auth->isSuperAdmin($actor)) {
            $grant = $this->access->activeGrant($actor);
            if ($grant?->assignee_mode === 'specified') $query->whereIn('u.legacy_id', $grant->assignee_ids);
        }
        return $this->staffPage($query, $filters);
    }

    public function users(object $actor, array $filters)
    {
        $this->permission($actor, 'crm.team.grant');
        $query = DB::table('erp_legacy_admin_users as u')->whereNull('u.deleted_at')
            ->whereIn(DB::raw('LOWER(TRIM(u.status))'), ['normal', 'active']);
        if (!$this->auth->isSuperAdmin($actor)) $query->whereExists(fn ($q) => $q->selectRaw('1')->from('erp_department_users as du')
            ->whereColumn('du.user_legacy_id', 'u.legacy_id')->whereIn('du.department_legacy_id', $this->access->managedDepartments($actor)));
        if (!empty($filters['keyword'])) $query->where(fn ($q) => $q->where('u.nickname', 'like', '%'.$filters['keyword'].'%')->orWhere('u.username', 'like', '%'.$filters['keyword'].'%'));
        if (isset($filters['ids'])) $query->whereIn('u.legacy_id', $filters['ids']);
        return $query->orderBy('u.legacy_id')->paginate($this->perPage($filters), ['u.legacy_id as id', 'u.nickname', 'u.username'])
            ->through(fn ($u) => ['id' => (int) $u->id, 'name' => $u->nickname ?: $u->username]);
    }

    public function saveCapability(object $actor, int $userId, array $payload): array
    {
        $this->permission($actor, 'crm.team.capability');
        $rules = ['tags' => 'required|array:'.implode(',', array_keys(self::TAG_GROUPS)), 'tag_source' => 'required|string|max:120',
            'confirmed_at' => 'required|date|before_or_equal:now', 'accepting' => 'required|boolean', 'remark' => 'nullable|string|max:500',
            'region' => 'nullable|string|max:120', 'expected_version' => 'required|integer|min:0'];
        foreach (self::TAG_GROUPS as $key => $label) { $rules['tags.'.$key] = 'sometimes|array|max:30'; $rules['tags.'.$key.'.*'] = 'required|string|max:80|distinct'; }
        $data = Validator::make($payload, $rules)->validate();
        return DB::transaction(function () use ($actor, $userId, $data) {
            $this->access->lockPeople([(int) $actor->legacy_id, $userId]);
            $this->showStaff($actor, $userId);
            $existing = CrmStaffProfile::query()->where('user_legacy_id', $userId)->lockForUpdate()->first();
            abort_unless((int) ($existing?->version ?? 0) === (int) $data['expected_version'], 409, '销售能力已被修改，请刷新后重试。');
            $before = $existing?->toArray();
            $values = array_intersect_key($data, array_flip(['tags', 'tag_source', 'confirmed_at', 'accepting', 'remark', 'region']));
            $values += ['user_legacy_id' => $userId, 'confirmed_by_legacy_id' => (int) $actor->legacy_id,
                'confirmed_by_name' => $this->actorName($actor), 'version' => (int) ($existing?->version ?? 0) + 1];
            if ($existing) $existing->update($values); else $existing = CrmStaffProfile::create($values);
            $this->audit('capability_save', 'crm_staff_profile', $existing->id, $before, $existing->fresh()->toArray(), $actor);
            return $this->showStaff($actor, $userId);
        }, 3);
    }

    public function grants(object $actor, array $filters)
    {
        $this->permission($actor, 'crm.team.grant');
        $query = CrmAssignGrant::query();
        if (!$this->auth->isSuperAdmin($actor)) {
            $own = $this->access->activeGrant($actor);
            if (!$own) $query->whereRaw('1 = 0');
            else {
                // 部分交集的授权不能编辑，否则保存会覆盖其他部门的授权。
                $ids = $this->access->expandDepartments($own->department_ids);
                $query->whereRaw('JSON_CONTAINS(?, department_ids)', [json_encode($ids)]);
            }
        }
        if (!empty($filters['status'])) $query->where('status', $filters['status']);
        if (!empty($filters['keyword'])) $query->whereIn('user_legacy_id', DB::table('erp_legacy_admin_users')->where(fn ($q) => $q->where('nickname', 'like', '%'.$filters['keyword'].'%')->orWhere('username', 'like', '%'.$filters['keyword'].'%'))->select('legacy_id'));
        return $query->orderByDesc('id')->paginate($this->perPage($filters))->through(fn ($grant) => $this->presentGrant($grant, $actor));
    }

    public function saveGrant(object $actor, ?int $id, array $payload): array
    {
        $this->permission($actor, 'crm.team.grant');
        $data = Validator::make($payload, [
            'user_legacy_id' => 'required|integer|min:1', 'department_ids' => 'required|array|min:1|max:100', 'department_ids.*' => 'required|integer|min:1|distinct',
            'channels' => 'present|array|max:100', 'channels.*' => 'required|string|max:120|distinct',
            'actions' => 'required|array|min:1|max:7', 'actions.*' => ['required', Rule::in(array_keys(CrmAssignmentAccessService::ACTIONS)), 'distinct'],
            'assignee_mode' => 'required|in:all,specified', 'assignee_ids' => 'present|array|max:1000', 'assignee_ids.*' => 'required|integer|min:1|distinct',
            'effective_from' => 'required|date_format:Y-m-d', 'effective_to' => 'nullable|date_format:Y-m-d|after_or_equal:effective_from',
            'status' => 'required|in:active,disabled', 'expected_version' => ($id ? 'required' : 'nullable').'|integer|min:1',
        ])->validate();
        $data['department_ids'] = array_map('intval', $data['department_ids']);
        $data['assignee_ids'] = $data['assignee_mode'] === 'all' ? [] : array_map('intval', $data['assignee_ids']);
        abort_if($data['assignee_mode'] === 'specified' && !$data['assignee_ids'], 422, '指定销售时至少选择一位销售。');
        return DB::transaction(function () use ($actor, $id, $data) {
            $this->access->lockPeople([(int) $actor->legacy_id, (int) $data['user_legacy_id'], ...$data['assignee_ids']]);
            abort_unless($this->access->activeAccount((int) $data['user_legacy_id'], true), 422, '授权对象账号已停用或删除。');
            $existing = $id ? CrmAssignGrant::query()->lockForUpdate()->findOrFail($id) : null;
            if ($existing) {
                abort_unless((int) $existing->user_legacy_id === (int) $data['user_legacy_id'], 422, '不能更换原授权对象。');
                abort_unless($existing->version === (int) $data['expected_version'], 409, '授权已被修改，请刷新后重试。');
            } else abort_if(CrmAssignGrant::query()->where('user_legacy_id', $data['user_legacy_id'])->exists(), 422, '该人员已有授权，请编辑原授权。');
            $this->assertGrantScope($actor, $data);
            if ($existing) $this->assertGrantScope($actor, $existing->toArray(), false);
            $target = DB::table('erp_legacy_admin_users')->where('legacy_id', $data['user_legacy_id'])->first();
            foreach ($data['actions'] as $action) {
                abort_unless($this->access->hasPermission($target, CrmAssignmentAccessService::PERMISSIONS[$action]), 422, '授权对象缺少对应功能权限，请先在角色管理中配置。');
                if (!in_array($action, ['verify', 'collab'], true)) abort_unless($this->access->hasPermission($target, 'crm.lead.view'), 422, '授权对象尚无线索查看权限，请先在角色管理中配置。');
            }
            foreach ($data['assignee_ids'] as $assigneeId) abort_unless($this->access->activeSales($assigneeId, true)
                && in_array($this->staff->departmentOf($assigneeId, true), $this->access->expandDepartments($data['department_ids']), true), 422, '指定销售须为目标部门内有效的销售人员。');
            $before = $existing?->toArray();
            $values = array_diff_key($data, ['expected_version' => true]);
            $values += ['updated_by_legacy_id' => (int) $actor->legacy_id, 'version' => (int) ($existing?->version ?? 0) + 1];
            if ($existing) $existing->update($values);
            else $existing = CrmAssignGrant::create($values + ['created_by_legacy_id' => (int) $actor->legacy_id]);
            $this->audit('grant_save', 'crm_assign_grant', $existing->id, $before, $existing->fresh()->toArray(), $actor);
            return $this->presentGrant($existing->fresh(), $actor);
        }, 3);
    }

    private function assertGrantScope(object $actor, array $data, bool $checkPeople = true): void
    {
        foreach ($data['department_ids'] as $id) abort_unless($this->access->activeDepartment((int) $id, true), 422, '目标部门已停用或删除。');
        if ($this->auth->isSuperAdmin($actor)) return;
        $own = $this->access->activeGrant($actor, true);
        abort_unless($own, 403, '只有在本人当前 CRM 授权范围内才能授权他人。');
        abort_if(array_diff($data['department_ids'], $this->access->expandDepartments($own->department_ids)), 403, '不能授权本人范围以外的部门。');
        abort_if(array_diff($data['department_ids'], $this->access->managedDepartments($actor)), 403, '不能授权本人组织管理范围以外的部门。');
        abort_if(array_diff($data['actions'], $own->actions), 403, '不能授予本人没有的 CRM 操作授权。');
        foreach ($data['actions'] as $action) $this->permission($actor, CrmAssignmentAccessService::PERMISSIONS[$action]);
        abort_if($own->channels && (!$data['channels'] || array_diff($data['channels'], $own->channels)), 403, '不能扩大来源渠道范围。');
        abort_if($own->assignee_mode === 'specified' && ($data['assignee_mode'] === 'all' || array_diff($data['assignee_ids'], $own->assignee_ids)), 403, '不能扩大可分配销售范围。');
        abort_if($data['effective_from'] < $own->effective_from->toDateString()
            || ($own->effective_to && (empty($data['effective_to']) || $data['effective_to'] > $own->effective_to->toDateString())), 403, '授权有效期不能超过本人有效期。');
        if ($checkPeople) abort_unless(DB::table('erp_department_users')->where('user_legacy_id', $data['user_legacy_id'])
            ->whereIn('department_legacy_id', $this->access->managedDepartments($actor))->exists(), 403, '只能维护所管理部门人员的授权。');
    }

    private function staffQuery(array $filters): Builder
    {
        $query = DB::table('erp_business_staff as bs')->join('erp_legacy_admin_users as u', 'u.legacy_id', '=', 'bs.user_legacy_id')
            ->leftJoin('erp_departments as d', 'd.legacy_id', '=', 'bs.department_legacy_id')
            ->leftJoin('erp_crm_staff_profiles as p', 'p.user_legacy_id', '=', 'u.legacy_id')->where('bs.staff_type', 'sales');
        if (isset($filters['ids'])) $query->whereIn('u.legacy_id', $filters['ids']);
        if (!empty($filters['keyword'])) $query->where(fn ($q) => $q->where('u.nickname', 'like', '%'.$filters['keyword'].'%')
            ->orWhere('u.username', 'like', '%'.$filters['keyword'].'%')->orWhere('p.tags', 'like', '%'.$filters['keyword'].'%'));
        return $query;
    }

    private function applyEffectiveSales(Builder $query): void
    {
        $query->whereNull('u.deleted_at')->whereIn(DB::raw('LOWER(TRIM(u.status))'), ['normal', 'active'])->where('bs.status', 'enabled')
            ->where(fn ($q) => $q->whereNull('bs.effective_from')->orWhere('bs.effective_from', '<=', today()->toDateString()))
            ->where(fn ($q) => $q->whereNull('bs.effective_to')->orWhere('bs.effective_to', '>=', today()->toDateString()));
    }

    private function staffPage(Builder $query, array $filters)
    {
        return $query->orderBy('u.legacy_id')->paginate($this->perPage($filters), ['u.legacy_id as id', 'u.nickname', 'u.username', 'u.status as account_status',
            'u.deleted_at as account_deleted_at', 'bs.status as staff_status', 'bs.department_legacy_id', 'bs.effective_from', 'bs.effective_to', 'd.name as department_name',
            'p.region', 'p.tags', 'p.tag_source', 'p.confirmed_by_legacy_id', 'p.confirmed_by_name', 'p.confirmed_at', 'p.accepting', 'p.remark', 'p.version'])
            ->through(fn ($row) => $this->presentStaff($row));
    }

    private function presentStaff(object $row): array
    {
        $profile = CrmStaffProfile::query()->where('user_legacy_id', $row->id)->first();
        $pending = Schema::hasTable('erp_crm_leads') ? DB::table('erp_crm_leads')->where('pending_user_legacy_id', $row->id)->where('status', 'pending_acceptance')->count() : 0;
        return ['id' => (int) $row->id, 'name' => $row->nickname ?: $row->username, 'department_legacy_id' => (int) $row->department_legacy_id,
            'department_name' => $row->department_name, 'account_status' => $row->account_status, 'is_effective' => $this->access->activeSales((int) $row->id),
            'region' => $profile?->region ?: implode('、', $profile?->tags['regions'] ?? []), 'joined_at' => null, 'tags' => $profile?->tags ?? [], 'tag_source' => $profile?->tag_source,
            'confirmed_by_legacy_id' => $profile?->confirmed_by_legacy_id, 'confirmed_by_name' => $profile?->confirmed_by_name,
            'confirmed_at' => $profile?->confirmed_at?->toDateTimeString(), 'accepting' => $profile?->accepting ?? true,
            'remark' => $profile?->remark, 'version' => $profile?->version ?? 0, 'open_opportunities' => null, 'pending_leads' => $pending];
    }

    private function presentGrant(CrmAssignGrant $grant, object $actor): array
    {
        $assignees = DB::table('erp_legacy_admin_users')->whereIn('legacy_id', $grant->assignee_ids)->get(['legacy_id', 'nickname', 'username'])
            ->map(fn ($u) => ['id' => (int) $u->legacy_id, 'name' => $u->nickname ?: $u->username])->values()->all();
        return $grant->toArray() + ['user_name' => $this->staff->name((int) $grant->user_legacy_id),
            'department_names' => DB::table('erp_departments')->whereIn('legacy_id', $grant->department_ids)->pluck('name')->all(),
            'assignees' => $assignees, 'assignee_names' => array_column($assignees, 'name'),
            'is_effective' => $this->access->activeAccount((int) $grant->user_legacy_id) && $grant->status === 'active'
                && $grant->effective_from->toDateString() <= today()->toDateString() && (!$grant->effective_to || $grant->effective_to->toDateString() >= today()->toDateString())];
    }

    private function channels(): array
    {
        return collect(CrmLeadApplicationService::CHANNELS)->map(fn ($label, $value) => compact('value', 'label'))->values()->all();
    }

    private function permission(object $actor, string $permission): void { abort_unless($this->access->hasPermission($actor, $permission), 403, '没有该操作权限。'); }
    private function perPage(array $filters): int { return max(1, min(100, (int) ($filters['per_page'] ?? 10))); }
    private function actorName(object $actor): ?string { return ($actor->nickname ?? null) ?: ($actor->username ?? null); }
    private function audit(string $action, string $type, int $id, ?array $before, array $after, object $actor): void
    {
        DB::table('erp_operation_logs')->insert(['module' => 'crm', 'action' => $action, 'target_type' => $type, 'target_id' => $id,
            'old_snapshot' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE), 'new_snapshot' => json_encode($after, JSON_UNESCAPED_UNICODE),
            'operator_id' => $actor->legacy_id, 'operator_name' => $this->actorName($actor), 'created_at' => now()]);
    }
}
