<?php

namespace App\Services\Erp;

use Illuminate\Http\Request;

final class MasterDataAccessService
{
    public function authorizeRead(Request $request, string $entity): object
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        abort_unless($user, 401, '未登录或登录已过期。');
        if (in_array($entity, ['units', 'categories', 'business-lines'], true) || $auth->isSuperAdmin($user)) return $user;

        $menus = match ($entity) {
            'products' => ['master.product', 'master.product.view'],
            'skus' => ['master.sku', 'master.sku.view'],
            'items' => ['master.item', 'master.item.view'],
            'suppliers' => ['master.supplier', 'master.supplier.view'],
            'warehouses', 'locations' => ['master.warehouse_location', 'master.warehouse.view'],
            default => abort(405, '不支持的主数据读取。'),
        };
        // Selectors inherit read access from their real business callers; no role names are assumed.
        $prefixes = match ($entity) {
            'products', 'skus' => ['master.sku', 'master.sku_item_relation', 'master.bom.performance', 'sales', 'sales_order', 'sales_return', 'crm', 'bom', 'production', 'aftersales'],
            'items' => ['item_category', 'master.sku', 'master.sku_item_relation', 'sku_item_relation', 'master.bom.performance', 'bom', 'production', 'purchase', 'purchase_return', 'inventory', 'master.supplier', 'supplier_capability', 'supplier_quotation'],
            'suppliers' => ['purchase', 'purchase_return', 'finance', 'inventory', 'supplier_capability', 'supplier_quotation'],
            'warehouses', 'locations' => ['master.item', 'inventory', 'purchase', 'purchase_return', 'production', 'sales.shipment', 'sales_order.shipment', 'sales.return', 'sales_return', 'sales.aftersales', 'aftersales'],
        };
        $permissions = $auth->permissionCodes($user);
        if (array_intersect($menus, $permissions)) return $user;
        foreach ($permissions as $permission) {
            foreach ($prefixes as $prefix) {
                if ($permission === $prefix || str_starts_with($permission, $prefix.'.')) return $user;
            }
        }
        abort(403, '没有当前主数据读取权限。');
    }

    public function authorize(Request $request, string $entity, string $action): object
    {
        $prefix = match ($entity) {
            'products' => 'master.product',
            'skus' => 'master.sku',
            'items' => 'master.item',
            'suppliers' => 'master.supplier',
            'warehouses' => 'master.warehouse',
            'locations' => $action === 'edit' ? 'master.warehouse' : 'master.location',
            'units', 'categories', 'trade-platforms', 'carriers', 'business-lines' => 'master.base_archive',
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
