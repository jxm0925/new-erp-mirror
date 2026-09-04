<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\ErpUserDirectoryService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class UserDirectoryController extends Controller
{
    public function users(Request $request, ErpUserDirectoryService $service, AuthContextService $auth)
    {
        $user = $auth->currentUser($request);
        $permissions = $user ? $auth->permissionCodes($user) : [];
        $scope = (string) $request->input('scope', 'system');
        $required = match ($scope) {
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
            'status' => $request->input('status', 'normal'),
            'department_name' => $request->input('department_name'),
            'group_name' => $request->input('group_name'),
            'data_scope' => $request->input('data_scope'),
            'keyword' => $request->input('keyword'),
            'page' => $request->input('page'),
            'per_page' => $request->input('per_page'),
        ]);

        if ($result instanceof LengthAwarePaginator) {
            return response()->json([
                'data' => $result->items(),
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
