<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\PhysicalInventoryApplicationService;
use Illuminate\Http\Request;

class PhysicalInventoryController extends Controller
{
    public function transfer(Request $request, int $id, PhysicalInventoryApplicationService $service)
    {
        $user = $this->authorizePhysicalAction($request);
        $payload = $request->validate([
            'client_command_id' => 'required|string|max:120',
            'expected_version' => 'required|integer|min:1',
            'target_warehouse_id' => 'required|integer|exists:erp_warehouses,id',
            'target_location_id' => 'required|integer|exists:erp_locations,id',
            'target_batch_no' => 'required|string|max:80',
            'reason' => 'required|string|max:1000',
        ]);
        return response()->json(['message' => '实物材料调拨已过账', 'data' => $service->transfer($id, $payload, $user)]);
    }

    public function dispose(Request $request, int $id, PhysicalInventoryApplicationService $service)
    {
        $user = $this->authorizePhysicalAction($request);
        $payload = $request->validate([
            'client_command_id' => 'required|string|max:120',
            'expected_version' => 'required|integer|min:1',
            'reason' => 'required|string|max:1000',
        ]);
        return response()->json(['message' => '仓库实物材料已报废出库', 'data' => $service->dispose($id, $payload, $user)]);
    }

    private function authorizePhysicalAction(Request $request): object
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        abort_unless($user, 401, '未登录或登录已过期。');
        abort_unless(
            $auth->isSuperAdmin($user) || in_array('inventory.physical.manage', $auth->permissionCodes($user), true),
            403,
            '无按钮权限：inventory.physical.manage',
        );
        return $user;
    }
}
