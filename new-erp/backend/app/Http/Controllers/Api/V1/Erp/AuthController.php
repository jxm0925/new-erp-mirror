<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\RbacBootstrapService;
use App\Services\ErpAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $request, ErpAuthService $auth, RbacBootstrapService $rbac)
    {
        $data = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);
        $admin = $auth->authenticate($data['username'], $data['password']);
        abort_if(!$admin, 422, '账号或密码错误');

        $rbac->bootstrap();
        $this->ensureUserRole((int) $admin['id']);

        $plainToken = Str::random(64);
        DB::table('erp_auth_tokens')->insert([
            'user_legacy_id' => (int) $admin['id'],
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $authContext = app(AuthContextService::class);
        $user = DB::table('erp_legacy_admin_users')->where('legacy_id', (int) $admin['id'])->first();
        abort_if(!$user, 409, '新系统未找到该管理员快照，请先手动同步旧系统管理员数据');
        return response()->json([
            'token' => $plainToken,
            'user' => $user,
            'data_scope' => $authContext->dataScope($user),
            'permissions' => $authContext->permissionCodes($user),
            'is_super_admin' => $authContext->isSuperAdmin($user),
            'is_department_principal' => $authContext->isDepartmentPrincipal($user),
        ]);
    }

    public function me(Request $request, AuthContextService $auth, RbacBootstrapService $rbac)
    {
        $rbac->bootstrap();
        $user = $auth->currentUser($request);
        abort_if(!$user, 401, '未登录或登录已过期');

        return response()->json([
            'user' => $user,
            'data_scope' => $auth->dataScope($user),
            'permissions' => $auth->permissionCodes($user),
            'is_super_admin' => $auth->isSuperAdmin($user),
            'is_department_principal' => $auth->isDepartmentPrincipal($user),
        ]);
    }

    public function logout(Request $request)
    {
        if ($token = $request->bearerToken()) {
            DB::table('erp_auth_tokens')->where('token_hash', hash('sha256', $token))->delete();
        }
        return response()->json(['message' => '已退出登录']);
    }

    private function ensureUserRole(int $legacyId): void
    {
        $user = DB::table('erp_legacy_admin_users')->where('legacy_id', $legacyId)->first();
        if (!$user) return;

        $groups = json_decode($user->auth_group_names ?: '[]', true) ?: [];
        $groupText = implode(' ', $groups);
        $isPrincipal = DB::table('erp_department_users')->where('user_legacy_id', $legacyId)->where('is_principal', true)->exists();
        $roleCode = match (true) {
            ($user->username ?? '') === 'admin', in_array('Admin group', $groups, true) => 'admin',
            str_contains($groupText, '销售负责人') || str_contains(strtolower($groupText), 'sales manager') => 'sales_manager',
            $isPrincipal => 'department_principal',
            (bool) ($user->is_sales ?? false) => 'sales_user',
            default => null,
        };
        if (!$roleCode) return;

        $roleId = DB::table('erp_rbac_roles')->where('code', $roleCode)->value('id');
        if ($roleId) {
            DB::table('erp_rbac_user_roles')->updateOrInsert(['user_legacy_id' => $legacyId, 'role_id' => $roleId]);
        }
    }
}
