<?php

namespace App\Services\Erp\Crm;

use App\Models\Erp\SalesCustomer;
use App\Services\Erp\AuthContextService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * 客户可见范围的唯一规则：列表、详情、汇总、负责人选项、选择器和所有写入口都走这里。
 * 可见来源（满足任一）：全部范围（超管 / 数据范围 all / sales.customer.view_all）、本人负责、
 * 部门范围内同事负责、本人为有效协作人员、客户尚无负责销售（用户确认：无负责人客户全员可见可选）。
 * 不要在各控制器里另写“按负责人过滤”，否则查重、选择器和建单会出现不同口径。
 */
final class CrmCustomerAccessService
{
    public const RELATIONS = ['all', 'owner', 'department', 'collaborator', 'public'];

    private array $contexts = [];

    public function __construct(private readonly AuthContextService $auth) {}

    public function context(object $user): array
    {
        $id = (int) $user->legacy_id;
        if (isset($this->contexts[$id])) return $this->contexts[$id];
        $codes = $this->auth->permissionCodes($user);
        $scope = $this->auth->dataScope($user);
        $all = $this->auth->isSuperAdmin($user) || $scope === 'all' || in_array('sales.customer.view_all', $codes, true);

        return $this->contexts[$id] = [
            'actor' => $id,
            'all' => $all,
            'department_users' => !$all && $scope === 'department' ? $this->auth->departmentUserIds($user) : null,
            'codes' => $codes,
        ];
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
        $query->where(function ($visible) use ($ctx, $table): void {
            $visible->where("$table.owner_legacy_id", $ctx['actor'])
                ->orWhereNull("$table.owner_legacy_id");
            if ($ctx['department_users']) $visible->orWhereIn("$table.owner_legacy_id", $ctx['department_users']);
            $visible->orWhereExists(fn ($collab) => $this->activeCollaboration($collab, $ctx['actor'])->whereColumn('c.customer_id', "$table.id"));
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
        $ctx = $this->context($user);
        $owner = $customer->owner_legacy_id ? (int) $customer->owner_legacy_id : null;
        if ($owner === $ctx['actor']) return 'owner';
        if ($ctx['all']) return 'all';
        if ($owner && $ctx['department_users'] && in_array($owner, $ctx['department_users'], true)) return 'department';
        if ($this->collaboration($customer->id, $ctx['actor'])) return 'collaborator';
        if (!$owner) return 'public';
        return null;
    }

    public function collaboration(int $customerId, int $userId): ?object
    {
        return $this->activeCollaboration(DB::query(), $userId)->where('c.customer_id', $customerId)
            ->select('c.*')->first();
    }

    /** 权限按钮与对象关系同时成立才可操作；前端按钮只读这里的结果。 */
    public function abilities(SalesCustomer $customer, object $user): array
    {
        $relation = $this->relation($customer, $user);
        $manages = in_array($relation, ['owner', 'all', 'department', 'public'], true);
        $collab = $relation === 'collaborator' ? $this->collaboration($customer->id, (int) $user->legacy_id) : null;
        $collabActions = $collab ? (json_decode($collab->actions, true) ?: []) : [];
        $edit = $this->can($user, 'sales.customer.edit') && ($manages || !empty($collabActions['edit_master']));
        $ctx = $this->context($user);
        $transferScope = $ctx['all'] || $relation === 'public'
            || ($customer->owner_legacy_id && in_array((int) $customer->owner_legacy_id, $ctx['department_users'] ?? [], true));

        return [
            'relation' => $relation,
            'view' => $relation !== null,
            'edit' => $edit,
            'follow' => $manages || !empty($collabActions['follow']),
            'bind_identity' => $edit && $this->can($user, 'sales.customer.identity'),
            'collaborate' => in_array($relation, ['owner', 'all', 'department'], true) && $this->can($user, 'sales.customer.collaborate'),
            // 转交是部组长/全部范围的动作，负责销售本人不能把客户直接转给别人。
            'transfer' => $transferScope && $this->can($user, 'sales.customer.transfer'),
            'delete' => $manages && $this->can($user, 'sales.customer.delete'),
        ];
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
            ->where(fn ($q) => $q->whereNull('c.effective_to')->orWhere('c.effective_to', '>=', $today));
    }
}
