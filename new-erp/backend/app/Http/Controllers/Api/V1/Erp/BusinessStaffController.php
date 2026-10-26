<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\BusinessStaffService;
use Illuminate\Http\Request;

/** 业务员档案：系统管理下维护账号的业务身份（销售员/采购员/仓管员/质检员）。 */
class BusinessStaffController extends Controller
{
    public function index(Request $request, BusinessStaffService $service)
    {
        $this->authorizePermission($request, 'system.business_staff.view');
        $paginator = $service->paginate($request->only(['staff_type', 'status', 'department_id', 'keyword', 'per_page', 'page']));

        return response()->json([
            'data' => $paginator->items(),
            'summary' => $service->summary(),
            'meta' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function options(Request $request, BusinessStaffService $service)
    {
        $this->authorizePermission($request, 'system.business_staff.view');

        return response()->json($service->options($request->only(['keyword'])));
    }

    public function store(Request $request, BusinessStaffService $service)
    {
        return response()->json($service->save(null, $request->all(), $this->actor($request, 'system.business_staff.save')), 201);
    }

    public function update(Request $request, int $id, BusinessStaffService $service)
    {
        return response()->json($service->save($id, $request->except(['user_legacy_id', 'staff_type']), $this->actor($request, 'system.business_staff.save')));
    }

    public function destroy(Request $request, int $id, BusinessStaffService $service)
    {
        $service->delete($id, $request->all(), $this->actor($request, 'system.business_staff.delete'));

        return response()->json(['message' => '业务员档案已删除']);
    }

    private function actor(Request $request, string $permission): object
    {
        return $this->authorizePermission($request, $permission);
    }

    private function authorizePermission(Request $request, string $permission): object
    {
        $auth = app(AuthContextService::class);
        $user = $request->attributes->get('erp_user') ?: $auth->currentUser($request);
        abort_unless($user, 401, '请先登录 ERP。');
        abort_unless($auth->isSuperAdmin($user) || in_array($permission, $auth->permissionCodes($user), true), 403, '当前用户没有业务员档案权限。');

        return $user;
    }
}
