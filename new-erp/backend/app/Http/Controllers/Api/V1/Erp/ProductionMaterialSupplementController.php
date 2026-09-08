<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\ProductionMaterialSupplementService;
use App\Services\Erp\ProductionExecutionInboxService;
use Illuminate\Http\Request;

class ProductionMaterialSupplementController extends Controller
{
    public function index(Request $request, ProductionExecutionInboxService $service) { return response()->json($service->paginate('material_supplements', $this->filters($request), ...$this->readContext($request))); }
    public function show(Request $request, int $id, ProductionExecutionInboxService $service) { return response()->json(['data' => $service->show('material_supplements', $id, ...$this->readContext($request))]); }
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
        [$user, $permissions, $superAdmin] = $this->context($request);
        app(ProductionExecutionInboxService::class)->assertVisible('material_supplements', $id, $user, $permissions, $superAdmin);
        return response()->json(['message' => $payload['approved'] ? '补料申请已批准并形成独立追加需求。' : '补料申请已拒绝。',
            'data' => $service->approve($id, $payload, $user, $permissions)]);
    }
    private function context(Request $request): array
    { $auth = app(AuthContextService::class); $user = $auth->currentUser($request); if (! $user) throw new WorkOrderDomainException('unauthenticated', '请先登录 ERP。', 401); return [$user, $auth->permissionCodes($user), $auth->isSuperAdmin($user)]; }
    private function readContext(Request $request): array { return $this->context($request); }
    private function filters(Request $request): array { return $request->validate(['status' => 'nullable|string|max:30', 'work_order_id' => 'nullable|integer|min:1', 'task_id' => 'nullable|integer|min:1', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']); }
}
