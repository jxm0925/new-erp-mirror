<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\ProductionMaterialSupplementService;
use Illuminate\Http\Request;

class ProductionMaterialSupplementController extends Controller
{
    public function store(Request $request, ProductionMaterialSupplementService $service)
    {
        $payload = $request->validate(['client_command_id' => 'required|string|max:120', 'expected_version' => 'required|integer|min:1',
            'task_id' => 'required|integer|min:1', 'target_type' => 'required|in:unit_operation,quantity_operation', 'target_id' => 'required|integer|min:1',
            'blocking' => 'required|boolean', 'reason' => 'required|string|max:1000', 'lines' => 'required|array|min:1',
            'lines.*.component_item_id' => 'required|integer|min:1', 'lines.*.additional_base_qty' => 'required|numeric|gt:0']);
        [$user, $permissions] = $this->context($request);
        return response()->json(['message' => '生产追加补料申请已提交。', 'data' => $service->request($payload, $user, $permissions)], 201);
    }
    public function decide(Request $request, int $id, ProductionMaterialSupplementService $service)
    {
        $payload = $request->validate(['client_command_id' => 'required|string|max:120', 'expected_version' => 'required|integer|min:1',
            'approved' => 'required|boolean', 'reason' => 'nullable|string|max:1000']);
        [$user, $permissions] = $this->context($request);
        return response()->json(['message' => $payload['approved'] ? '补料申请已批准并形成独立追加需求。' : '补料申请已拒绝。',
            'data' => $service->approve($id, $payload, $user, $permissions)]);
    }
    private function context(Request $request): array
    { $auth = app(AuthContextService::class); $user = $auth->currentUser($request); if (! $user) throw new WorkOrderDomainException('unauthenticated', '请先登录 ERP。', 401); return [$user, $auth->permissionCodes($user)]; }
}
