<?php

namespace App\Services\Erp;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Shared by shipment/return detail guards and the warehouse discovery queries. */
final class SalesOrderVisibilityService
{
    public function __construct(private readonly AuthContextService $auth) {}

    public function applySalesUserScope(QueryBuilder $users, object $user): void
    {
        if ($this->auth->isSuperAdmin($user) || $this->auth->dataScope($user) === 'all') return;
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

    public function apply(Builder $orders, object $user): void
    {
        if ($this->auth->isSuperAdmin($user) || $this->auth->dataScope($user) === 'all') return;
        if ($this->auth->dataScope($user) === 'department') {
            $orders->whereIn('sales_user_legacy_id', $this->auth->departmentUserIds($user));
            return;
        }
        $actor = (int) ($user->legacy_id ?? $user->id ?? 0);
        $orders->where(fn (Builder $q) => $q->where('sales_user_legacy_id', $actor)
            ->orWhere(fn (Builder $fallback) => $fallback->whereNull('sales_user_legacy_id')->where('created_by_legacy_id', $actor)));
    }
}
