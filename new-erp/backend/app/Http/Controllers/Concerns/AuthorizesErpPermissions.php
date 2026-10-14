<?php

namespace App\Http\Controllers\Concerns;

use App\Services\Erp\AuthContextService;
use Illuminate\Http\Request;

/**
 * 统一的按钮权限校验。传入多个权限码时满足其一即可。
 * 超级管理员直接放行；其余账号必须拥有对应权限码，否则 403。
 * 后端是权限的最终防线，前端隐藏按钮不能替代这里的校验。
 */
trait AuthorizesErpPermissions
{
    protected function erpAuthorize(Request $request, string|array $permissions): object
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        abort_unless($user, 401, '未登录或登录已过期。');
        $permissions = (array) $permissions;
        abort_unless(
            $auth->isSuperAdmin($user) || array_intersect($permissions, $auth->permissionCodes($user)),
            403,
            '无按钮权限：'.implode(' 或 ', $permissions),
        );

        return $user;
    }
}
