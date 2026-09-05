<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\ProductionMaterialReturnService;
use Illuminate\Http\Request;

class ProductionMaterialReturnController extends Controller
{
    public function store(Request $request, ProductionMaterialReturnService $service)
    {
        $payload = $request->validate(['client_command_id' => 'required|string|max:120', 'expected_version' => 'required|integer|min:1',
            'task_id' => 'required|integer|min:1', 'target_type' => 'required|in:unit_operation,quantity_operation', 'target_id' => 'required|integer|min:1',
            'return_type' => 'required|in:normal_return,quality_return', 'reason' => 'required|string|max:1000', 'lines' => 'required|array|min:1',
            'lines.*.material_requirement_id' => 'required|integer|min:1', 'lines.*.warehouse_id' => 'required|integer|min:1',
            'lines.*.location_id' => 'required|integer|min:1', 'lines.*.batch_no' => 'nullable|string|max:80',
            'lines.*.serial_ids' => 'nullable|array', 'lines.*.serial_ids.*' => 'integer|min:1', 'lines.*.return_base_qty' => 'required|numeric|gt:0']);
        [$user, $permissions] = $this->context($request);
        return response()->json(['message' => '生产退料单已提交。', 'data' => $service->create($payload, $user, $permissions)], 201);
    }
    public function receive(Request $request, int $id, ProductionMaterialReturnService $service)
    { $payload = $request->validate(['client_command_id' => 'required|string|max:120', 'expected_version' => 'required|integer|min:1']); [$user, $permissions] = $this->context($request); return response()->json(['message' => '仓库已接收生产退料。', 'data' => $service->receive($id, $payload, $user, $permissions)]); }
    public function quality(Request $request, int $id, ProductionMaterialReturnService $service)
    { $payload = $request->validate(['client_command_id' => 'required|string|max:120', 'expected_version' => 'required|integer|min:1', 'passed' => 'required|boolean', 'reason' => 'nullable|string|max:1000']); [$user, $permissions] = $this->context($request); return response()->json(['message' => '质量退料检验已记录。', 'data' => $service->quality($id, $payload, $user, $permissions)]); }
    private function context(Request $request): array
    { $auth = app(AuthContextService::class); $user = $auth->currentUser($request); if (! $user) throw new WorkOrderDomainException('unauthenticated', '请先登录 ERP。', 401); return [$user, $auth->permissionCodes($user)]; }
}
