<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\RbacBootstrapService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class DepartmentController extends Controller
{
    public function index(Request $request, RbacBootstrapService $rbac)
    {
        $this->authorizePermission($request, 'system.department.view');
        $rbac->bootstrap();

        $memberCounts = DB::table('erp_department_users')
            ->select('department_legacy_id', DB::raw('COUNT(*) as member_count'))
            ->groupBy('department_legacy_id');

        $query = DB::table('erp_departments as d')
            ->leftJoinSub($memberCounts, 'mc', 'mc.department_legacy_id', '=', 'd.legacy_id')
            ->when($request->filled('keyword'), function ($q) use ($request) {
                $keyword = trim((string) $request->input('keyword'));
                $q->where('d.name', 'like', "%{$keyword}%");
            })
            ->orderBy('d.parent_legacy_id')
            ->orderBy('d.sort')
            ->orderBy('d.legacy_id')
            ->select('d.*', DB::raw('COALESCE(mc.member_count, 0) as member_count'));

        // Department hierarchy is an explicit tree selector used by the
        // department workbench. Regular list consumers receive pagination.
        if ($request->boolean('tree')) {
            return response()->json($query->get());
        }

        return $this->paginated($query->paginate($this->perPage($request)));
    }

    public function members(Request $request, int $legacyId, RbacBootstrapService $rbac)
    {
        $this->authorizePermission($request, 'system.department.view');
        $rbac->bootstrap();

        $base = DB::table('erp_department_users as du')
            ->join('erp_legacy_admin_users as u', 'du.user_legacy_id', '=', 'u.legacy_id')
            ->where('du.department_legacy_id', $legacyId);

        $principals = (clone $base)
            ->where('du.is_principal', true)
            ->orderByDesc('du.is_principal')
            ->orderBy('u.legacy_id')
            ->get([
                'u.legacy_id as id',
                'u.username',
                'u.nickname',
                'u.mobile',
                'u.status',
                'du.is_principal',
                'du.is_owner',
            ]);

        $query = $base
            ->when($request->filled('keyword'), function ($q) use ($request) {
                $keyword = trim((string) $request->input('keyword'));
                $q->where(function ($sub) use ($keyword) {
                    $sub->where('u.nickname', 'like', "%{$keyword}%")
                        ->orWhere('u.username', 'like', "%{$keyword}%")
                        ->orWhere('u.mobile', 'like', "%{$keyword}%");
                });
            })
            ->when($request->filled('status'), fn ($q) => $q->where('u.status', $request->input('status')))
            ->when($request->input('role') === 'principal', fn ($q) => $q->where('du.is_principal', true))
            ->when($request->input('role') === 'normal', fn ($q) => $q->where('du.is_principal', false))
            ->when($request->input('role') === 'sales', fn ($q) => $q->where('u.is_sales', true))
            ->orderByDesc('du.is_principal')
            ->orderBy('u.legacy_id')
            ->select([
                'u.legacy_id as id',
                'u.username',
                'u.nickname',
                'u.mobile',
                'u.status',
                'du.is_principal',
                'du.is_owner',
            ]);

        $paginator = $query->paginate($this->perPage($request));

        return response()->json([
            'data' => $paginator->items(),
            'principals' => $principals,
            'meta' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function savePrincipals(Request $request, int $legacyId, RbacBootstrapService $rbac)
    {
        $this->authorizePermission($request, 'system.department.set_principal');
        $data = $request->validate([
            'principal_ids' => 'nullable|array',
            'principal_ids.*' => 'integer',
        ]);
        $rbac->bootstrap();
        $principalIds = array_map('intval', $data['principal_ids'] ?? []);

        DB::transaction(function () use ($legacyId, $principalIds): void {
            DB::table('erp_department_users')->where('department_legacy_id', $legacyId)->update(['is_principal' => false, 'updated_at' => now()]);
            $roleId = DB::table('erp_rbac_roles')->where('code', 'department_principal')->value('id');
            foreach (array_unique($principalIds) as $userId) {
                DB::table('erp_department_users')->updateOrInsert(
                    ['department_legacy_id' => $legacyId, 'user_legacy_id' => $userId],
                    ['is_principal' => true, 'updated_at' => now(), 'created_at' => now()]
                );
                if ($roleId) DB::table('erp_rbac_user_roles')->updateOrInsert(['user_legacy_id' => $userId, 'role_id' => $roleId]);
            }
        });

        return response()->json(['message' => '部门负责人已保存']);
    }

    public function sync(Request $request, RbacBootstrapService $rbac)
    {
        $this->authorizePermission($request, 'system.department.save');
        $rbac->syncDepartments(true);

        return response()->json([
            'message' => '旧系统部门已手动同步',
            'total' => DB::table('erp_departments')->count(),
            'members' => DB::table('erp_department_users')->count(),
        ]);
    }

    private function perPage(Request $request): int
    {
        return max(1, min(100, (int) $request->input('per_page', 20)));
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        $auth = app(AuthContextService::class);
        $user = $request->attributes->get('erp_user') ?: $auth->currentUser($request);
        abort_unless($user, 401, '请先登录 ERP。');
        abort_unless($auth->isSuperAdmin($user) || in_array($permission, $auth->permissionCodes($user), true), 403, '当前用户没有部门管理权限。');
    }

    private function paginated(LengthAwarePaginator $paginator)
    {
        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
}
