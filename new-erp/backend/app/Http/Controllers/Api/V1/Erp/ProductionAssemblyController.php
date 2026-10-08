<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\AssemblyProductionApplicationService;
use App\Services\Erp\AuthContextService;
use Illuminate\Http\Request;

final class ProductionAssemblyController extends Controller
{
    public function preview(Request $request, int $id, AssemblyProductionApplicationService $service)
    {
        return response()->json(['data' => $service->preview($id, ...$this->context($request))]);
    }
    public function prepare(Request $request, int $id, AssemblyProductionApplicationService $service)
    {
        $payload = $request->validate(['client_command_id' => 'required|string|max:120', 'expected_version' => 'required|integer|min:1']);
        return response()->json(['message' => '自产部件已准备，库存和关联子工单已保留。', 'data' => $service->prepare($id, $payload, ...$this->context($request))]);
    }
    private function context(Request $request): array
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        if (! $user) throw new WorkOrderDomainException('unauthenticated', '请先登录 ERP。', 401);
        $permissions = $auth->permissionCodes($user); $super = $auth->isSuperAdmin($user);
        $request->attributes->set('erp_action_context', ['permissions' => $permissions, 'super_admin' => $super]);
        return [$user, $permissions, $super];
    }
}
