<?php

namespace App\Services\Erp;

use App\Models\Erp\ProductionDemand;
use App\Models\Erp\WorkOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Resolves production visibility only from roles that actually grant the
 * requested production permission. Sales ownership must never expand this
 * scope. An unresolved production role/permission combination is denied.
 */
final class ProductionDataScopeResolver
{
    private const ACTIVE_WORK_ORDER_STATUSES = ['DRAFT', 'WAIT_RELEASE', 'RELEASED', 'IN_PROGRESS', 'COMPLETED'];

    public function __construct(private readonly AuthContextService $authContext) {}

    public function resolve(
        object $user,
        string $viewPermission,
        array $permissions,
        bool $superAdmin = false,
    ): array {
        $userId = (int) ($user->legacy_id ?? $user->id ?? 0);
        if ($superAdmin) {
            return ['mode' => 'all', 'user_ids' => [], 'can_manage_pool' => true];
        }
        if ($userId <= 0 || ! in_array($viewPermission, $permissions, true)) {
            return ['mode' => 'deny', 'user_ids' => [], 'can_manage_pool' => false];
        }

        $roleScopes = DB::table('erp_rbac_user_roles as ur')
            ->join('erp_rbac_roles as r', 'r.id', '=', 'ur.role_id')
            ->join('erp_rbac_role_permissions as rp', 'rp.role_id', '=', 'r.id')
            ->join('erp_rbac_permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('ur.user_legacy_id', $userId)
            ->where('r.enabled', true)
            ->where('p.enabled', true)
            ->where('p.code', $viewPermission)
            ->pluck('r.data_scope')
            ->all();
        if ($roleScopes === []) {
            return ['mode' => 'deny', 'user_ids' => [], 'can_manage_pool' => false];
        }

        $mode = in_array('all', $roleScopes, true)
            ? 'all'
            : (in_array('department', $roleScopes, true) ? 'department' : 'self');
        $userIds = $mode === 'department'
            ? $this->authContext->departmentUserIds($user)
            : [$userId];
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), fn (int $id): bool => $id > 0)));
        if ($mode !== 'all' && $userIds === []) {
            return ['mode' => 'deny', 'user_ids' => [], 'can_manage_pool' => false];
        }

        $managementPermissions = [
            'production.work_order.create',
            'production.work_order.edit',
            'production.work_order.submit',
            'production.work_order.cancel',
        ];

        return [
            'mode' => $mode,
            'user_ids' => $userIds,
            'can_manage_pool' => (bool) array_intersect($managementPermissions, $permissions),
        ];
    }

    public function applyDemandScope(Builder $query, array $scope): void
    {
        if (($scope['mode'] ?? 'deny') === 'all') return;
        if (($scope['mode'] ?? 'deny') === 'deny') {
            $query->whereRaw('1 = 0');
            return;
        }

        $ids = (array) ($scope['user_ids'] ?? []);
        $query->where(function (Builder $demand) use ($ids, $scope): void {
            $demand->whereHas('workOrders', fn (Builder $workOrder) => $workOrder
                ->whereIn('status', self::ACTIVE_WORK_ORDER_STATUSES)
                ->whereIn('responsible_user_legacy_id', $ids));
            if ($scope['can_manage_pool'] ?? false) {
                $demand->orWhereDoesntHave('workOrders', fn (Builder $workOrder) => $workOrder
                    ->whereIn('status', self::ACTIVE_WORK_ORDER_STATUSES)
                    ->whereNotNull('responsible_user_legacy_id'));
            }
        });
    }

    public function applyWorkOrderScope(Builder $query, array $scope): void
    {
        if (($scope['mode'] ?? 'deny') === 'all') return;
        if (($scope['mode'] ?? 'deny') === 'deny') {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->where(function (Builder $workOrder) use ($scope): void {
            $workOrder->whereIn('responsible_user_legacy_id', (array) ($scope['user_ids'] ?? []));
            if ($scope['can_manage_pool'] ?? false) $workOrder->orWhereNull('responsible_user_legacy_id');
        });
    }

    public function demandVisible(ProductionDemand $demand, array $scope): bool
    {
        $query = ProductionDemand::query()->whereKey($demand->id);
        $this->applyDemandScope($query, $scope);
        return $query->exists();
    }

    public function workOrderVisible(WorkOrder $workOrder, array $scope): bool
    {
        $query = WorkOrder::query()->whereKey($workOrder->id);
        $this->applyWorkOrderScope($query, $scope);
        return $query->exists();
    }
}
