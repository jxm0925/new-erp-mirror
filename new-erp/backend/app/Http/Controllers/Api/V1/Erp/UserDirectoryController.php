<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\ErpUserDirectoryService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class UserDirectoryController extends Controller
{
    public function users(Request $request, ErpUserDirectoryService $service, AuthContextService $auth)
    {
        $user = $auth->currentUser($request);
        $permissions = $user ? $auth->permissionCodes($user) : [];
        $scope = (string) $request->input('scope', 'system');
        $required = match ($scope) {
            'warehouse' => ['master.warehouse.create', 'master.warehouse.edit'],
            'production' => ['production.demand.view', 'production.work_order.view'],
            'sales' => ['sales.order', 'sales_return.view'],
            default => ['system.admin.view'],
        };
        if (! $user || (! $auth->isSuperAdmin($user) && array_intersect($required, $permissions) === [])) {
            return response()->json([
                'message' => '无权读取该用户目录。',
                'error_code' => 'permission_denied',
                'errors' => ['permission' => ['无权读取该用户目录。']],
                'details' => ['required_any' => $required],
            ], 403);
        }

        $result = $service->users([
            'scope' => $scope,
            'capability' => $scope === 'production' && $request->input('capability') === 'collaborate' ? 'collaborate' : null,
            'status' => $request->input('status', 'normal'),
            'department_name' => $request->input('department_name'),
            'department_id' => $request->integer('department_id'),
            'group_name' => $request->input('group_name'),
            'role_id' => $request->integer('role_id'),
            'data_scope' => $request->input('data_scope'),
            'keyword' => $request->input('keyword'),
            'page' => $request->input('page'),
            'per_page' => $request->input('per_page'),
        ]);

        if ($result instanceof LengthAwarePaginator) {
            return response()->json([
                'data' => $result->items(),
                ...($scope === 'warehouse' && $request->boolean('include_departments') ? [
                    // 选择器明确请求部门树，仅提供真实分类，不额外授予系统账号管理权限。
                    'departments' => DB::table('erp_departments')->whereNull('deleted_at')->orderBy('sort')->orderBy('legacy_id')
                        ->get(['legacy_id as id', 'parent_legacy_id as parent_id', 'name']),
                ] : []),
                'meta' => [
                    'total' => $result->total(),
                    'per_page' => $result->perPage(),
                    'current_page' => $result->currentPage(),
                    'last_page' => $result->lastPage(),
                ],
            ]);
        }

        return response()->json($result);
    }

}
