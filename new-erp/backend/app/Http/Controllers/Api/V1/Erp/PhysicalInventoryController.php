<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\PhysicalInventoryApplicationService;
use Illuminate\Http\Request;

class PhysicalInventoryController extends Controller
{
    public function transfer(Request $request, int $id)
    {
        $row = $this->transferCommand($request, 'dispatch', $id, ['inventory.physical.manage']);
        return response()->json(['message' => ($row['status'] ?? '') === 'IN_TRANSIT' ? '实物已调出，待目标仓接收。' : '实物同仓移库已过账。', 'data' => $row]);
    }

    public function transfers(Request $request, PhysicalInventoryApplicationService $service)
    {
        $user = $this->authorizeTransfer($request, ['inventory.physical.manage', 'inventory.transfer.view', 'inventory.inbound.confirm']);
        $filters = $request->validate(['status' => 'nullable|in:IN_TRANSIT,POSTED,in_transit,posted', 'has_exception' => 'nullable|boolean',
            'warehouse_id' => 'nullable|integer|min:1', 'keyword' => 'nullable|string|max:120', 'per_page' => 'nullable|integer|min:1|max:100']);
        return response()->json($service->paginateTransfers($filters, $user));
    }

    public function showTransfer(Request $request, int $id, PhysicalInventoryApplicationService $service)
    {
        $user = $this->authorizeTransfer($request, ['inventory.physical.manage', 'inventory.transfer.view', 'inventory.inbound.confirm']);
        return response()->json(['data' => $service->transferDetail($id, $user)]);
    }

    public function confirmTransfer(Request $request, int $id)
    {
        return response()->json(['message' => '实物调拨已整单接收。', 'data' => $this->transferCommand($request, 'confirm', $id, ['inventory.inbound.confirm'])]);
    }

    public function transferException(Request $request, int $id)
    {
        return response()->json(['message' => '实物接收异常已记录，仍保持在途。',
            'data' => $this->transferCommand($request, 'exception', $id, ['inventory.inbound.confirm', 'inventory.physical.manage'])]);
    }

    private function transferCommand(Request $request, string $action, int $id, array $permissions): array
    {
        $user = $this->authorizeTransfer($request, $permissions);
        $request->validate(['client_command_id' => 'required|string|max:120']);
        $auth = app(AuthContextService::class);
        return app(\App\Services\Erp\WarehouseCommandService::class)->run('material_physical_transfer.'.$action, $id,
            $request->input('client_command_id'), $request->except('client_command_id'), $user, $auth->permissionCodes($user), $auth->isSuperAdmin($user))['result'];
    }

    private function authorizeTransfer(Request $request, array $permissions): object
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        abort_unless($user, 401, '未登录或登录已过期。');
        abort_unless($auth->isSuperAdmin($user) || array_intersect($permissions, $auth->permissionCodes($user)), 403, '没有对应实物调拨权限。');
        return $user;
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
