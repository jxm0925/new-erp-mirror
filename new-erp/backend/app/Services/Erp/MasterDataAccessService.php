<?php

namespace App\Services\Erp;

use Illuminate\Http\Request;

final class MasterDataAccessService
{
    public function authorize(Request $request, string $entity, string $action): object
    {
        $prefix = match ($entity) {
            'products' => 'master.product',
            'skus' => 'master.sku',
            'items' => 'master.item',
            'suppliers' => 'master.supplier',
            'warehouses' => 'master.warehouse',
            'locations' => $action === 'edit' ? 'master.warehouse' : 'master.location',
            'units', 'categories', 'trade-platforms' => 'master.base_archive',
            default => abort(405, '不支持的主数据操作。'),
        };
        // 基础档案沿用现有“新增/维护”权限，库位编辑沿用仓库库位编辑权限。
        // 菜单可见不代表具有写权限，不能用父菜单权限代替具体动作权限。
        if ($prefix === 'master.base_archive' && $action === 'edit') $action = 'create';
        $permissions = $action === 'image' ? [$prefix.'.create', $prefix.'.edit'] : [$prefix.'.'.$action];
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        abort_unless($user, 401, '未登录或登录已过期。');
        abort_unless($auth->isSuperAdmin($user) || array_intersect($permissions, $auth->permissionCodes($user)), 403, '没有当前主数据操作权限。');
        return $user;
    }
}
