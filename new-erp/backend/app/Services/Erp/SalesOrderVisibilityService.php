<?php

namespace App\Services\Erp;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Shared by shipment/return detail guards and the warehouse discovery queries. */
final class SalesOrderVisibilityService
{
    public function __construct(private readonly AuthContextService $auth) {}

    public function applySalesUserScope(QueryBuilder $users, object $user): void
    {
        // 建单权限允许替其他有效销售人员建单，不扩大已有订单的写入范围。
        if ($this->auth->isSuperAdmin($user) || $this->auth->dataScope($user) === 'all'
            || array_intersect(['sales_order.create', 'sales.order.create'], $this->auth->permissionCodes($user))) return;
        $ids = $this->auth->dataScope($user) === 'department'
            ? $this->auth->departmentUserIds($user)
            : [(int) ($user->legacy_id ?? $user->id ?? 0)];
        $users->whereIn('legacy_id', $ids);
    }

    public function assertAssignableSalesUser(mixed $id, object $actor): void
    {
        $users = DB::table('erp_legacy_admin_users')->where('legacy_id', (int) $id)
            ->whereNull('deleted_at')->whereIn(DB::raw('LOWER(TRIM(status))'), ['normal', 'active']);
        $staff = app(BusinessStaffService::class);
        $staff->ensureBackfilled();
        $staff->applyActive($users, 'sales');
        $this->applySalesUserScope($users, $actor);
        if (! $users->exists()) {
            throw ValidationException::withMessages(['sales_user_legacy_id' => '请选择授权范围内有效的销售人员（需在“业务员档案”登记为销售员，且已启用、在生效期内）。']);
        }
    }

    /** 共享与代建增加读取范围；代建者仅在草稿期间具有原按钮权限约束下的写入范围。 */
    public function apply(Builder $orders, object $user, bool $includeShared = false): void
    {
        if ($this->auth->isSuperAdmin($user) || $this->auth->dataScope($user) === 'all') return;
        $actor = (int) ($user->legacy_id ?? $user->id ?? 0);
        $departmentIds = $this->auth->dataScope($user) === 'department' ? $this->auth->departmentUserIds($user) : null;
        $orders->where(function (Builder $visible) use ($actor, $departmentIds, $includeShared): void {
            $visible->where(function (Builder $owned) use ($actor, $departmentIds): void {
                if ($departmentIds !== null) $owned->whereIn('sales_user_legacy_id', $departmentIds);
                else $owned->where('sales_user_legacy_id', $actor);
            });
            if ($actor > 0) {
                $visible->orWhere(fn (Builder $created) => $created->where('created_by_legacy_id', $actor)
                    ->when(!$includeShared, fn (Builder $draft) => $draft->where('order_status', 'draft')));
                if ($includeShared) $visible->orWhere(fn (Builder $shared) => $shared->where('is_share', true)
                    ->whereRaw('FIND_IN_SET(?, '.$shared->qualifyColumn('share_user').') > 0', [(string) $actor]));
            }
        });
    }

    public function normalizeShareUsers(mixed $users): ?string
    {
        if ($users !== null && !is_array($users) && !is_string($users)) {
            throw ValidationException::withMessages(['share_user' => '请通过员工选择框选择共享人员。']);
        }
        $users = is_array($users) ? $users : explode(',', (string) ($users ?? ''));
        $users = array_values(array_filter($users, fn ($id) => $id !== null && $id !== ''));
        Validator::make(['share_user' => $users], ['share_user' => 'array|max:1000', 'share_user.*' => 'required|integer|min:1'])->validate();
        $ids = array_values(array_unique(array_map('intval', $users)));
        $active = DB::table('erp_legacy_admin_users')->whereIn('legacy_id', $ids)->whereNull('deleted_at')
            ->whereIn(DB::raw('LOWER(TRIM(status))'), ['normal', 'active']);
        $staff = app(BusinessStaffService::class);
        $staff->ensureBackfilled();
        $staff->applyActive($active, 'sales');
        if ($active->count() !== count($ids)) {
            throw ValidationException::withMessages(['share_user' => '共享人员只能选择有效的销售员（账号与销售员档案均已启用，且在生效期内），请重新选择。']);
        }
        return $ids ? implode(',', $ids) : null;
    }
}
