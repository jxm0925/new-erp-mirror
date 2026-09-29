<?php

namespace App\Services\Erp;

use Illuminate\Database\Eloquent\Builder;

/** Shared by shipment/return detail guards and the warehouse discovery queries. */
final class SalesOrderVisibilityService
{
    public function __construct(private readonly AuthContextService $auth) {}

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
