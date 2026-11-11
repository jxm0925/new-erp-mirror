<?php

namespace App\Services\Erp\Crm;

use App\Models\Erp\CrmAssignGrant;
use App\Models\Erp\CrmStaffProfile;
use App\Services\Erp\AuthContextService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/** 分配授权只允许办理指定部门/渠道的动作，不增加客户资料、金额或财务查看权限。 */
final class CrmAssignmentAccessService
{
    public const ACTIONS = [
        'self_entry' => '自行录入', 'assign' => '分配线索', 'transfer' => '部门内转交',
        'recycle' => '人工回收', 'claim' => '公海领取', 'verify' => '重复客户核实', 'collab' => '管理协作',
    ];
    public const PERMISSIONS = [
        'self_entry' => 'crm.lead.create', 'assign' => 'crm.lead.assign', 'transfer' => 'crm.lead.transfer',
        'recycle' => 'crm.lead.recycle', 'claim' => 'crm.lead.claim', 'verify' => 'sales.customer.verify', 'collab' => 'sales.customer.collaborate',
    ];

    public function __construct(private readonly AuthContextService $auth, private readonly CrmSalesStaffService $staff) {}

    public function hasPermission(object $actor, string $permission): bool
    {
        return $this->auth->isSuperAdmin($actor) || in_array($permission, $this->auth->permissionCodes($actor), true);
    }

    public function activeGrant(object $actor, bool $lock = false): ?CrmAssignGrant
    {
        return CrmAssignGrant::query()->where('user_legacy_id', $actor->legacy_id)->where('status', 'active')
            ->where('effective_from', '<=', today()->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', today()->toDateString()))
            ->when($lock, fn ($q) => $q->lockForUpdate())->first();
    }

    public function allows(object $actor, string $action, ?int $departmentId, ?string $channel, ?int $assigneeId = null, bool $lock = false): bool
    {
        if (!$this->activeAccount((int) $actor->legacy_id, $lock) || !isset(self::PERMISSIONS[$action])
            || !$this->hasPermission($actor, self::PERMISSIONS[$action])) return false;
        if (!$departmentId || !$this->activeDepartment($departmentId, $lock)) return false;
        if ($assigneeId !== null && (!$this->activeSales($assigneeId, $lock) || ($action !== 'self_entry' && !$this->accepting($assigneeId, $lock))
            || !$this->departmentContains($departmentId, $this->staff->departmentOf($assigneeId, $lock)))) return false;
        // 销售本人自录不依赖运营分配授权；公海领取仍必须显式授权。
        if ($action === 'self_entry' && $assigneeId === (int) $actor->legacy_id && $this->activeSales($assigneeId)
            && $this->staff->departmentOf($assigneeId, $lock) === $departmentId) return true;
        if ($this->auth->isSuperAdmin($actor)) return true;
        $grant = $this->activeGrant($actor, $lock);
        return $grant && in_array($action, $grant->actions, true) && $this->grantContainsDepartment($grant, $departmentId)
            && (!$grant->channels || in_array($channel, $grant->channels, true))
            && ($assigneeId === null || $grant->assignee_mode === 'all' || in_array($assigneeId, $grant->assignee_ids, true));
    }

    public function assert(object $actor, string $action, ?int $departmentId, ?string $channel, ?int $assigneeId = null): void
    {
        $lock = DB::transactionLevel() > 0;
        if ($lock) $this->lockPeople(array_filter([(int) $actor->legacy_id, $assigneeId]));
        abort_unless($this->allows($actor, $action, $departmentId, $channel, $assigneeId, $lock), 403,
            '该操作超出 CRM 授权范围，或人员、部门、承接状态及有效期已变化，请刷新后确认。');
    }

    /** 与授权/能力维护共用顺序，防止检查后账号停用、销售失效或暂停承接仍被分配。 */
    public function lockPeople(array $userIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));
        sort($ids);
        DB::table('erp_legacy_admin_users')->whereIn('legacy_id', $ids)->orderBy('legacy_id')->lockForUpdate()->get(['legacy_id']);
        DB::table('erp_business_staff')->whereIn('user_legacy_id', $ids)->where('staff_type', 'sales')->orderBy('user_legacy_id')->lockForUpdate()->get(['id']);
        CrmStaffProfile::query()->whereIn('user_legacy_id', $ids)->orderBy('user_legacy_id')->lockForUpdate()->get(['id']);
    }

    /** 作为调用方 OR 分支的范围谓词；无授权必须恒假，不能留下空条件扩大查询。 */
    public function applyVisible(Builder|QueryBuilder $query, object $actor, string $table = 'erp_crm_leads'): void
    {
        if ($this->auth->isSuperAdmin($actor)) { $query->whereRaw('1 = 1'); return; }
        $grant = $this->activeGrant($actor);
        $actions = $grant ? array_filter($grant->actions, fn ($a) => in_array($a, ['assign', 'transfer', 'recycle', 'verify'], true)
            && $this->hasPermission($actor, self::PERMISSIONS[$a])) : [];
        if (!$actions || !$this->activeAccount((int) $actor->legacy_id)) { $query->whereRaw('1 = 0'); return; }
        $query->whereIn("$table.department_legacy_id", $this->expandDepartments($grant->department_ids));
        if ($grant->channels) $query->whereIn("$table.source_channel", $grant->channels);
    }

    public function activeAccount(int $userId, bool $lock = false): bool
    {
        return DB::table('erp_legacy_admin_users')->where('legacy_id', $userId)->whereNull('deleted_at')
            ->whereIn(DB::raw('LOWER(TRIM(status))'), ['normal', 'active'])->when($lock, fn ($q) => $q->lockForUpdate())->first(['legacy_id']) !== null;
    }

    /** 不触发旧业务员档案自动回填，CRM 的读取与授权校验没有隐藏写入。 */
    public function activeSales(int $userId, bool $lock = false): bool
    {
        return $this->activeAccount($userId, $lock) && DB::table('erp_business_staff')->where('user_legacy_id', $userId)
            ->where('staff_type', 'sales')->where('status', 'enabled')
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', today()->toDateString()))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', today()->toDateString()))
            ->when($lock, fn ($q) => $q->lockForUpdate())->first(['id']) !== null;
    }

    public function accepting(int $userId, bool $lock = false): bool
    {
        $value = CrmStaffProfile::query()->where('user_legacy_id', $userId)->when($lock, fn ($q) => $q->lockForUpdate())->value('accepting');
        return $value === null || (bool) $value;
    }

    public function activeDepartment(int $id, bool $lock = false): bool
    {
        return DB::table('erp_departments')->where('legacy_id', $id)->whereNull('deleted_at')->where('status', 'normal')
            ->when($lock, fn ($q) => $q->lockForUpdate())->first(['legacy_id']) !== null;
    }

    public function departmentContains(int $parentId, ?int $id): bool
    {
        return $id !== null && in_array($id, $this->expandDepartments([$parentId]), true);
    }

    public function grantContainsDepartment(CrmAssignGrant $grant, int $id): bool
    {
        return in_array($id, $this->expandDepartments($grant->department_ids), true);
    }

    public function expandDepartments(array $ids): array
    {
        $rows = DB::table('erp_departments')->whereNull('deleted_at')->where('status', 'normal')->get(['legacy_id', 'parent_legacy_id']);
        $result = array_values(array_intersect(array_map('intval', $ids), $rows->pluck('legacy_id')->map(fn ($id) => (int) $id)->all()));
        do {
            $before = count($result);
            foreach ($rows as $row) if (in_array((int) $row->parent_legacy_id, $result, true)) $result[] = (int) $row->legacy_id;
            $result = array_values(array_unique($result));
        } while (count($result) !== $before);
        return $result;
    }

    /** 团队资料沿正式组织范围；财务客户 view_all 不参与团队管理范围。 */
    public function managedDepartments(object $actor): array
    {
        if ($this->auth->isSuperAdmin($actor) || $this->auth->dataScope($actor) === 'all') {
            return DB::table('erp_departments')->whereNull('deleted_at')->where('status', 'normal')->pluck('legacy_id')->map(fn ($id) => (int) $id)->all();
        }
        $ids = DB::table('erp_department_users')->where('user_legacy_id', $actor->legacy_id)
            ->where('is_principal', true)->pluck('department_legacy_id')->all();
        return $this->expandDepartments($ids);
    }
}
