<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\ProductionOutputService;
use Illuminate\Http\Request;

class ProductionOutputController extends Controller
{
    public function inspect(Request $request, int $id, ProductionOutputService $service)
    {
        $payload = $request->validate(['client_command_id' => 'required|string|max:120', 'expected_version' => 'required|integer|min:1',
            'result' => 'required|in:passed,failed', 'qualified_base_qty' => 'required|numeric|min:0',
            'unqualified_base_qty' => 'required|numeric|min:0', 'reason' => 'nullable|string|max:1000', 'inspection_snapshot' => 'nullable|array',
            'next_step' => 'nullable|in:direct_handover,warehouse']);
        [$user, $permissions] = $this->context($request);
        return response()->json(['message' => '生产质量检验已记录。', 'data' => $service->inspect($id, $payload, $user, $permissions)]);
    }
    public function warehouse(Request $request, int $id, ProductionOutputService $service)
    {
        $payload = $request->validate(['client_command_id' => 'required|string|max:120', 'expected_version' => 'required|integer|min:1',
            'warehouse_id' => 'required|integer|min:1', 'location_id' => 'required|integer|min:1',
            'batch_no' => 'required|string|max:80', 'unit_cost' => 'nullable|numeric|min:0']);
        [$user, $permissions] = $this->context($request);
        return response()->json(['message' => '生产产出已正式入库。', 'data' => $service->warehouse($id, $payload, $user, $permissions)]);
    }
    private function context(Request $request): array
    { $auth = app(AuthContextService::class); $user = $auth->currentUser($request); if (! $user) throw new WorkOrderDomainException('unauthenticated', '请先登录 ERP。', 401); return [$user, $auth->permissionCodes($user)]; }
}
