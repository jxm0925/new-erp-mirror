<?php

namespace App\Services\Erp;

use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesOrderLog;
use App\Services\Erp\Crm\CrmSalesDelegationService;
use App\Services\Erp\Crm\CrmSalesStaffService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Shared by shipment/return detail guards and the warehouse discovery queries. */
final class SalesOrderVisibilityService
{
    private const DELEGATED_CREATE_ACTION = 'sales_delegation_create';

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
        $delegatedOwners = app(CrmSalesDelegationService::class)->activeOwners($user);
        $orders->where(function (Builder $visible) use ($actor, $departmentIds, $includeShared, $delegatedOwners): void {
            $visible->where(function (Builder $owned) use ($actor, $departmentIds): void {
                if ($departmentIds !== null) $owned->whereIn('sales_user_legacy_id', $departmentIds);
                else $owned->where('sales_user_legacy_id', $actor);
            });
            $visible->orWhereIn('sales_user_legacy_id', $delegatedOwners);
            if ($actor > 0) {
                $visible->orWhere(fn (Builder $created) => $created->where('created_by_legacy_id', $actor)
                    ->whereNotExists(fn ($fact) => $fact->selectRaw('1')->from('erp_sales_order_logs as delegation_fact')
                        ->whereColumn('delegation_fact.sales_order_id', $created->qualifyColumn('id'))
                        ->where('delegation_fact.action', self::DELEGATED_CREATE_ACTION)
                        ->where('delegation_fact.payload->actor_legacy_id', $actor))
                    ->when(!$includeShared, fn (Builder $draft) => $draft->where('order_status', 'draft')));
                if ($includeShared) $visible->orWhere(fn (Builder $shared) => $shared->where('is_share', true)
                    ->whereRaw('FIND_IN_SET(?, '.$shared->qualifyColumn('share_user').') > 0', [(string) $actor]));
            }
        });
    }

    public function recordCreation(SalesOrder $order, ?object $actor): void
    {
        if (!$actor || !app(CrmSalesDelegationService::class)->delegatesFor($actor, (int) $order->sales_user_legacy_id, true)) return;
        $name = $actor->nickname ?: $actor->username;
        $order->update(['created_by_legacy_id' => $actor->legacy_id, 'created_by' => $name]);
        // 创建事实不能放进客户端可改的订单快照，否则到期后仍能借创建人身份续权。
        SalesOrderLog::create([
            'sales_order_id' => $order->id, 'order_no_snapshot' => $order->sales_order_no,
            'action' => self::DELEGATED_CREATE_ACTION, 'after_status' => $order->order_status,
            'operator' => $name, 'content' => '销售代班期间代开订单，保留原销售归属',
            'payload' => ['actor_legacy_id' => (int) $actor->legacy_id, 'sales_user_legacy_id' => (int) $order->sales_user_legacy_id],
        ]);
    }

    public function prepareCreation(array &$payload, ?object $actor): void
    {
        $owner = (int) ($payload['delegated_owner_legacy_id'] ?? 0);
        unset($payload['delegated_owner_legacy_id']);
        if (!$owner) return;
        $delegates = $actor && app(CrmSalesDelegationService::class)->delegatesFor($actor, $owner, true);
        if (!$delegates && $actor && !empty($payload['opportunity_id'])) {
            $opportunities = app(\App\Services\Erp\Crm\CrmOpportunityApplicationService::class);
            $opportunity = $opportunities->visible((int) $payload['opportunity_id'], $actor, true);
            $delegates = (int) $opportunity->owner_legacy_id === $owner && $opportunities->hasOrderDelegation($opportunity, $actor, true);
        }
        abort_unless($delegates, 403, '代班已结束、到期或人员状态已变化，请刷新后重新办理。');
        $payload['sales_user_legacy_id'] = $owner;
        $payload['created_by_legacy_id'] = $actor->legacy_id;
        $payload['created_by'] = $actor->nickname ?: $actor->username;
    }

    public function delegationAccess(SalesOrder $order, ?object $actor, bool $lock = false): array
    {
        if (!$actor) return ['delegated_sales_user' => null, 'writable' => true];
        $owner = (int) $order->sales_user_legacy_id;
        $delegates = app(CrmSalesDelegationService::class)->delegatesFor($actor, $owner, $lock);
        $created = (int) $order->created_by_legacy_id === (int) $actor->legacy_id;
        $delegatedCreation = $created && SalesOrderLog::query()->where('sales_order_id', $order->id)
            ->where('action', self::DELEGATED_CREATE_ACTION)->where('payload->actor_legacy_id', (int) $actor->legacy_id)
            ->when($lock, fn ($q) => $q->lockForUpdate())->first(['id']);
        $normal = $this->hasNormalScope($order, $actor);
        return [
            'delegated_sales_user' => $delegates || $delegatedCreation
                ? ['id' => $owner, 'nickname' => app(CrmSalesStaffService::class)->name($owner)] : null,
            // 普通代建者的特殊办理仍由原入口裁定；这里仅阻止代班创建事实永久续权。
            'writable' => $delegates || $normal || ($created && !$delegatedCreation),
        ];
    }

    public function hasNormalScope(SalesOrder $order, object $actor): bool
    {
        $owner = (int) $order->sales_user_legacy_id;
        return $this->auth->isSuperAdmin($actor) || $this->auth->dataScope($actor) === 'all'
            || $owner === (int) $actor->legacy_id
            || ($this->auth->dataScope($actor) === 'department' && in_array($owner, $this->auth->departmentUserIds($actor), true));
    }

    public function assertWritable(SalesOrder $order, object $actor, ?int $salesUserId = null): void
    {
        $access = $this->delegationAccess($order, $actor, true);
        abort_unless($access['writable'], 403, '订单不在当前负责或代班范围内，代班可能已结束、到期或归属已变化。');
        if ($access['delegated_sales_user'] && $salesUserId !== null && $salesUserId !== (int) $order->sales_user_legacy_id) {
            throw ValidationException::withMessages(['sales_user_legacy_id' => '代班办理订单时须保留当前负责销售。']);
        }
    }

    public function normalizeShareUsers(mixed $users, int $salesUserId, int $actorId, ?SalesOrder $order = null): ?string
    {
        if ($users !== null && !is_array($users) && !is_string($users)) {
            throw ValidationException::withMessages(['share_user' => '请通过员工选择框选择共享人员。']);
        }
        $users = is_array($users) ? $users : explode(',', (string) ($users ?? ''));
        $users = array_values(array_filter($users, fn ($id) => $id !== null && $id !== ''));
        Validator::make(['share_user' => $users], ['share_user' => 'array|max:1000', 'share_user.*' => 'required|integer|min:1'])->validate();
        $ids = array_values(array_unique(array_map('intval', $users)));
        // 未改动的历史共享保留；换销售产生的新重复身份仍须主动移除。
        $existing = $order?->is_share ? array_map('intval', explode(',', (string) $order->share_user)) : [];
        $added = array_diff($ids, $existing);
        if ($actorId > 0 && in_array($actorId, $added, true)) {
            throw ValidationException::withMessages(['share_user' => '共享人员不能选择自己，请移除后重新保存。']);
        }
        if (in_array($salesUserId, $ids, true)
            && ((int) $order?->sales_user_legacy_id !== $salesUserId || in_array($salesUserId, $added, true))) {
            throw ValidationException::withMessages(['share_user' => '订单销售人员无需共享权限，请从共享人员中移除。']);
        }
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
