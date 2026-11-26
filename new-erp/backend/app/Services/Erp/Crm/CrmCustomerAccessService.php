<?php

namespace App\Services\Erp\Crm;

use App\Models\Erp\SalesCustomer;
use App\Services\Erp\AuthContextService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * 客户可见范围的唯一规则：列表、详情、汇总、负责人选项、选择器和所有写入口都走这里。
 * 数据范围只取授予对应 CRM 权限的角色，查看全部不等于管理全部。
 * 普通无负责人客户仍公开可选；已有正式线索的未分配客户按线索接收与管理范围保护。
 * 不要在各控制器里另写“按负责人过滤”，否则查重、选择器和建单会出现不同口径。
 */
final class CrmCustomerAccessService
{
    public const RELATIONS = ['all', 'owner', 'department', 'collaborator', 'public', 'lead'];

    private array $contexts = [];

    public function __construct(
        private readonly AuthContextService $auth,
        private readonly CrmAssignmentAccessService $assignments,
        private readonly CrmSalesStaffService $staff,
    ) {}

    public function context(object $user): array
    {
        $id = (int) $user->legacy_id;
        if (isset($this->contexts[$id])) return $this->contexts[$id];
        $codes = $this->auth->permissionCodes($user);
        $super = $this->auth->isSuperAdmin($user);
        $scopes = [];
        foreach (DB::table('erp_rbac_user_roles as ur')->join('erp_rbac_roles as r', 'r.id', '=', 'ur.role_id')
            ->join('erp_rbac_role_permissions as rp', 'rp.role_id', '=', 'r.id')
            ->join('erp_rbac_permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('ur.user_legacy_id', $id)->where('r.enabled', true)->where('p.enabled', true)
            ->get(['p.code', 'r.data_scope']) as $row) {
            if (($scopes[$row->code] ?? 'self') === 'all' || $row->data_scope === 'self') continue;
            $scopes[$row->code] = $row->data_scope;
        }
        $scope = in_array('all', [$scopes['sales.customer'] ?? 'self', $scopes['sales.customer.view'] ?? 'self'], true) ? 'all'
            : (in_array('department', [$scopes['sales.customer'] ?? 'self', $scopes['sales.customer.view'] ?? 'self'], true) ? 'department' : 'self');
        $all = $super || $scope === 'all' || in_array('sales.customer.view_all', $codes, true);

        return $this->contexts[$id] = [
            'actor' => $id,
            'all' => $all,
            'super' => $super,
            'scopes' => $scopes,
            'scope' => $scope,
            'department_users' => $this->auth->departmentUserIds($user),
            'department_ids' => DB::table('erp_department_users')->where('user_legacy_id', $id)->pluck('department_legacy_id')->map(fn ($id) => (int) $id)->all(),
            'codes' => $codes,
        ];
    }

    public function dataScope(object $user, string $permission = 'sales.customer'): string
    {
        $ctx = $this->context($user);
        return $ctx['super'] ? 'all' : ($permission === 'sales.customer' ? $ctx['scope'] : ($ctx['scopes'][$permission] ?? 'self'));
    }

    public function can(object $user, string $permission): bool
    {
        $codes = $this->context($user)['codes'];
        return $this->auth->isSuperAdmin($user) || in_array($permission, $codes, true)
            || ($permission === 'sales.customer' && in_array('sales.customer.view', $codes, true));
    }

    public function applyVisible(Builder|QueryBuilder $query, object $user, string $table = 'erp_sales_customers'): void
    {
        $ctx = $this->context($user);
        if ($ctx['all']) return;
        $query->where(function ($visible) use ($ctx, $table, $user): void {
            $visible->where("$table.owner_legacy_id", $ctx['actor'])
                ->orWhere(function ($unowned) use ($ctx, $table, $user) {
                    $unowned->whereNull("$table.owner_legacy_id")->where(function ($public) use ($ctx, $table, $user) {
                        $public->whereNotExists(fn ($lead) => $this->formalLeads($lead)->whereColumn('l.customer_id', "$table.id"))
                            ->orWhereExists(function ($lead) use ($ctx, $table, $user) {
                                $this->formalLeads($lead)->whereColumn('l.customer_id', "$table.id");
                                $this->applyRelatedLead($lead, $user, $ctx);
                            });
                    });
                });
            if ($ctx['scope'] === 'department') $visible->orWhereIn("$table.owner_legacy_id", $ctx['department_users']);
            $visible->orWhereIn("$table.owner_legacy_id", app(CrmSalesDelegationService::class)->activeOwners($user));
            $visible->orWhereExists(fn ($collab) => $this->activeCollaboration($collab, $ctx['actor'])->whereColumn('c.customer_id', "$table.id"));
            // 释放只清线索负责人，客户原归属仍保留；公海共享不能据此取得客户管理或建单权限。
            $visible->orWhere(function ($poolCustomer) use ($table, $user, $ctx) {
                $poolCustomer->where("$table.status", 'potential')
                    ->whereExists(function ($lead) use ($table, $user, $ctx) {
                        $this->formalLeads($lead)->whereColumn('l.customer_id', "$table.id");
                        $this->applyVisiblePoolLead($lead, $user, $ctx);
                    });
            });
        });
    }

    /** 当前用户有效协作的客户（“我协作的”页签）。 */
    public function applyCollaborating(Builder $query, object $user): void
    {
        $actor = (int) $user->legacy_id;
        $query->whereExists(fn ($collab) => $this->activeCollaboration($collab, $actor)->whereColumn('c.customer_id', 'erp_sales_customers.id'));
    }

    /** 返回当前用户与客户的关系；null 表示不可见。 */
    public function relation(SalesCustomer $customer, object $user): ?string
    {
        $relation = $this->customerRelation($customer, $user);
        return $relation === 'public_pool' ? 'lead' : $relation;
    }

    private function customerRelation(SalesCustomer $customer, object $user): ?string
    {
        $ctx = $this->context($user);
        $owner = $customer->owner_legacy_id ? (int) $customer->owner_legacy_id : null;
        if ($owner === $ctx['actor']) return 'owner';
        if ($ctx['all']) return 'all';
        if ($owner && $ctx['scope'] === 'department' && in_array($owner, $ctx['department_users'], true)) return 'department';
        if ($this->collaboration($customer->id, $ctx['actor'])) return 'collaborator';
        if ($owner && app(CrmSalesDelegationService::class)->delegatesFor($user, $owner)) return 'delegate';
        if (!$owner) {
            if (!$this->hasFormalLeads($customer->id)) return 'public';
            $leads = $this->formalLeads(DB::query())->where('l.customer_id', $customer->id);
            $this->applyRelatedLead($leads, $user, $ctx);
            if ($leads->exists()) return 'lead';
        }
        if ($customer->status === 'potential') {
            $leads = $this->formalLeads(DB::query())->where('l.customer_id', $customer->id);
            $this->applyVisiblePoolLead($leads, $user, $ctx);
            if ($leads->exists()) return 'public_pool';
        }
        return null;
    }

    public function collaboration(int $customerId, int $userId, bool $lock = false): ?object
    {
        return $this->activeCollaboration(DB::query(), $userId)->where('c.customer_id', $customerId)
            ->when($lock, fn ($query) => $query->lockForUpdate())->select('c.*')->first();
    }

    /** 权限按钮与对象关系同时成立才可操作；前端按钮只读这里的结果。 */
    public function abilities(SalesCustomer $customer, object $user): array
    {
        $relation = $this->customerRelation($customer, $user);
        $poolReadOnly = $relation === 'public_pool';
        if ($poolReadOnly) $relation = 'lead';
        $ctx = $this->context($user);
        $protected = !$customer->owner_legacy_id && $this->hasFormalLeads($customer->id);
        $department = $customer->owner_legacy_id
            ? in_array((int) $customer->owner_legacy_id, $ctx['department_users'], true)
            : ($protected && $this->formalLeads(DB::query())->where('l.customer_id', $customer->id)->whereIn('l.department_legacy_id', $ctx['department_ids'])->exists());
        $manages = function (string $permission) use ($customer, $user, $ctx, $relation, $protected, $department, $poolReadOnly): bool {
            $scope = $this->dataScope($user, $permission);
            return !$poolReadOnly && $relation !== null && ($ctx['super'] || $scope === 'all' || (int) $customer->owner_legacy_id === $ctx['actor']
                || ($scope === 'department' && $department) || (!$customer->owner_legacy_id && !$protected));
        };
        $collab = $this->collaboration($customer->id, (int) $user->legacy_id);
        $collabActions = $collab ? (json_decode($collab->actions, true) ?: []) : [];
        $edit = $relation !== null && $this->can($user, 'sales.customer.edit') && ($manages('sales.customer.edit') || !empty($collabActions['edit_master']));

        return [
            'relation' => $relation,
            'view' => $relation !== null,
            'link_lead' => $relation !== null && !$poolReadOnly,
            'select' => $relation !== null && $relation !== 'lead' && !$protected,
            'edit' => $edit,
            'follow' => $manages('crm.lead.follow') || !empty($collabActions['follow']),
            'bind_identity' => $edit && $this->can($user, 'sales.customer.identity') && ($manages('sales.customer.identity') || !empty($collabActions['edit_master'])),
            'collaborate' => ($customer->owner_legacy_id || $protected) && $manages('sales.customer.collaborate') && $this->can($user, 'sales.customer.collaborate'),
            // 转交是部组长/全部范围的动作，负责销售本人不能把客户直接转给别人。
            'transfer' => $this->dataScope($user, 'sales.customer.transfer') !== 'self' && $manages('sales.customer.transfer') && $this->can($user, 'sales.customer.transfer'),
            'delete' => $manages('sales.customer.delete') && $this->can($user, 'sales.customer.delete'),
        ];
    }

    public function hasFormalLeads(int $customerId): bool
    {
        return $this->formalLeads(DB::query())->where('l.customer_id', $customerId)->exists();
    }

    private function formalLeads(QueryBuilder $query): QueryBuilder
    {
        return $query->from('erp_crm_leads as l')->whereNotIn('l.status', ['draft', 'pending_review']);
    }

    private function applyRelatedLead(QueryBuilder $query, object $user, array $ctx): void
    {
        $query->where(function ($related) use ($user, $ctx) {
            $related->where('l.owner_legacy_id', $ctx['actor'])->orWhere('l.pending_user_legacy_id', $ctx['actor'])
                ->orWhere(fn ($creator) => $creator->where('l.created_by_legacy_id', $ctx['actor'])->where('l.status', 'pending_assignment'));
            if ($ctx['scope'] === 'department') $related->orWhereIn('l.department_legacy_id', $ctx['department_ids']);
            $related->orWhere(fn ($assigned) => $this->assignments->applyVisible($assigned, $user, 'l'));
        });
    }

    /** 只复用公海可见谓词，不能反向依赖含客户服务的线索应用服务。 */
    private function applyVisiblePoolLead(QueryBuilder $query, object $user, array $ctx): void
    {
        $assignments = $this->assignments;
        $query->where('l.status', 'public_pool');
        if (!$this->can($user, 'crm.lead.view') || !$assignments->activeAccount($ctx['actor'])) {
            $query->whereRaw('1 = 0');
            return;
        }
        $scope = $this->dataScope($user, 'crm.lead.view');
        if ($scope === 'all') return;
        $departments = $assignments->visiblePoolDepartments($user, $this->staff->departmentOf($ctx['actor']), $scope);
        $grant = $assignments->activeGrant($user);
        $query->where(function ($pool) use ($assignments, $user, $departments, $grant) {
            $pool->whereIn('l.department_legacy_id', $departments)
                ->orWhere(fn ($assigned) => $assignments->applyVisible($assigned, $user, 'l'));
            if ($grant && in_array('claim', $grant->actions, true) && $this->can($user, 'crm.lead.claim')) {
                $pool->orWhere(function ($claimed) use ($assignments, $grant) {
                    $claimed->whereIn('l.department_legacy_id', $assignments->expandDepartments($grant->department_ids));
                    if ($grant->channels) $claimed->whereIn('l.source_channel', $grant->channels);
                });
            }
        });
    }

    public function assert(SalesCustomer $customer, object $user, string $ability, string $message): void
    {
        abort_unless($this->abilities($customer, $user)[$ability] ?? false, 403, $message);
    }

    /** 不可见客户只返回查重必要信息：名称、编码、部门、负责人，不含联系方式、身份与业务记录。 */
    public function masked(SalesCustomer $customer): array
    {
        return [
            'id' => $customer->id,
            'customer_code' => $customer->customer_code,
            'customer_name' => $customer->customer_name,
            'customer_kind' => $customer->customer_kind,
            'status' => $customer->status,
            'owner_name' => $customer->owner_name,
            'owner_department_name' => $this->departmentName($customer->owner_department_legacy_id),
            'visible' => false,
        ];
    }

    public function departmentName(?int $legacyId): ?string
    {
        static $cache = [];
        if (!$legacyId) return null;
        return $cache[$legacyId] ??= DB::table('erp_departments')->where('legacy_id', $legacyId)->value('name');
    }

    private function activeCollaboration(QueryBuilder $query, int $userId): QueryBuilder
    {
        $today = now()->toDateString();
        return $query->from('erp_crm_customer_collaborators as c')
            ->where('c.user_legacy_id', $userId)->where('c.status', 'active')
            ->where('c.effective_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('c.effective_to')->orWhere('c.effective_to', '>=', $today))
            ->where(fn ($valid) => $valid->whereNull('c.scope->type')->orWhere('c.scope->type', '!=', 'owner_opportunity_delegation')
                ->orWhereExists(fn ($owner) => $owner->from('erp_sales_customers as delegated_customer')->whereColumn('delegated_customer.id', 'c.customer_id')
                    ->whereColumn('delegated_customer.owner_legacy_id', 'c.created_by_legacy_id')
                    ->whereExists(fn ($opportunity) => $opportunity->from('erp_crm_opportunities as delegated_opportunity')
                        ->whereColumn('delegated_opportunity.customer_id', 'c.customer_id')->whereColumn('delegated_opportunity.owner_legacy_id', 'c.created_by_legacy_id')
                        ->whereRaw("JSON_CONTAINS(c.scope, CAST(delegated_opportunity.id AS JSON), '$.opportunity_ids')"))));
    }
}
