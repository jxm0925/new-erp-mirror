<?php

namespace App\Services\Erp\Crm;

use App\Services\Erp\BusinessStaffService;
use Illuminate\Support\Facades\DB;

/** CRM 的“负责销售”只能是账号在职、销售员档案启用且在生效期内的人员。 */
final class CrmSalesStaffService
{
    public function __construct(private readonly BusinessStaffService $staff) {}

    public function isActiveSales(int $userId): bool
    {
        return $userId > 0 && $this->staff->isActive($userId, 'sales');
    }

    /** 负责销售的部门：优先销售员档案登记的部门，其次账号所在的第一个部门。 */
    public function departmentOf(int $userId, bool $lock = false): ?int
    {
        $dept = DB::table('erp_business_staff')->where('user_legacy_id', $userId)->where('staff_type', 'sales')->when($lock, fn ($q) => $q->lockForUpdate())->value('department_legacy_id')
            ?: DB::table('erp_department_users')->where('user_legacy_id', $userId)->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->value('department_legacy_id');
        return $dept ? (int) $dept : null;
    }

    public function name(int $userId): ?string
    {
        $user = DB::table('erp_legacy_admin_users')->where('legacy_id', $userId)->first(['nickname', 'username']);
        return $user ? (($user->nickname ?: null) ?? $user->username) : null;
    }

    /** 有效销售员候选，供转交、协作选择；服务端分页。 */
    public function options(string $keyword, int $perPage, ?array $onlyIds = null, array $excludeIds = [])
    {
        $users = DB::table('erp_legacy_admin_users as u')->whereNull('u.deleted_at')
            ->whereIn(DB::raw('LOWER(TRIM(u.status))'), ['normal', 'active']);
        $this->staff->ensureBackfilled();
        $this->staff->applyActive($users, 'sales', 'u.legacy_id');
        if ($onlyIds !== null) $users->whereIn('u.legacy_id', $onlyIds ?: [0]);
        if ($excludeIds) $users->whereNotIn('u.legacy_id', $excludeIds);
        if ($keyword !== '') $users->where(fn ($q) => $q->where('u.nickname', 'like', "%{$keyword}%")->orWhere('u.username', 'like', "%{$keyword}%"));
        $page = $users->orderBy('u.legacy_id')->paginate($perPage, ['u.legacy_id as id', 'u.nickname', 'u.username']);
        $page->through(function ($row) {
            $dept = $this->departmentOf((int) $row->id);
            return ['id' => (int) $row->id, 'name' => $row->nickname ?: $row->username, 'department_legacy_id' => $dept,
                'department_name' => $dept ? DB::table('erp_departments')->where('legacy_id', $dept)->value('name') : null];
        });
        return $page;
    }
}
