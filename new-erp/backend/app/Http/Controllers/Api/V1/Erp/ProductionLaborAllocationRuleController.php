<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\ProductionLaborAllocationRuleService;
use Illuminate\Http\Request;

class ProductionLaborAllocationRuleController extends Controller
{
    public function index(Request $request, ProductionLaborAllocationRuleService $service)
    { [$user, $permissions] = $this->context($request); return response()->json(['data' => $service->list($permissions)]); }
    public function store(Request $request, ProductionLaborAllocationRuleService $service)
    { $payload = $request->validate(['client_command_id' => 'required|string|max:120', 'rule_name' => 'nullable|string|max:160', 'owner_ratio' => 'required|numeric|min:0|max:1', 'collaborator_total_ratio' => 'required|numeric|min:0|max:1']); [$user, $permissions] = $this->context($request); return response()->json(['message' => '工时分配规则新版本已保存为草稿。', 'data' => $service->createVersion($payload, $user, $permissions)], 201); }
    public function activate(Request $request, int $id, ProductionLaborAllocationRuleService $service)
    { $payload = $this->statePayload($request); [$user, $permissions] = $this->context($request); return response()->json(['message' => '工时分配规则已生效，历史任务冻结值不变。', 'data' => $service->activate($id, $payload, $user, $permissions)]); }
    public function retire(Request $request, int $id, ProductionLaborAllocationRuleService $service)
    { $payload = $this->statePayload($request); [$user, $permissions] = $this->context($request); return response()->json(['message' => '工时分配规则已退役。', 'data' => $service->retire($id, $payload, $user, $permissions)]); }
    private function statePayload(Request $request): array { return $request->validate(['client_command_id' => 'required|string|max:120', 'expected_version' => 'required|integer|min:1']); }
    private function context(Request $request): array { $auth = app(AuthContextService::class); $user = $auth->currentUser($request); if (! $user) throw new WorkOrderDomainException('unauthenticated', '请先登录 ERP。', 401); return [$user, $auth->permissionCodes($user)]; }
}
