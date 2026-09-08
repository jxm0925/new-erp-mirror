<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\ProductionOutputService;
use App\Services\Erp\ProductionExecutionInboxService;
use Illuminate\Http\Request;

class ProductionOutputController extends Controller
{
    public function index(Request $request, ProductionExecutionInboxService $service)
    { return response()->json($service->paginate('outputs', $this->filters($request, false), ...$this->readContext($request))); }
    public function show(Request $request, int $id, ProductionExecutionInboxService $service)
    { return response()->json(['data' => $service->show('outputs', $id, ...$this->readContext($request))]); }
    public function inspect(Request $request, int $id, ProductionOutputService $service)
    {
        $payload = $request->validate(['client_command_id' => 'required|string|max:120', 'expected_version' => 'required|integer|min:1',
            'result' => 'required|in:passed,failed', 'qualified_base_qty' => 'required|numeric|min:0',
            'unqualified_base_qty' => 'required|numeric|min:0', 'reason' => 'nullable|string|max:1000', 'inspection_snapshot' => 'nullable|array',
            'next_step' => 'nullable|in:direct_handover,warehouse']);
        [$user, $permissions, $superAdmin] = $this->context($request);
        app(ProductionExecutionInboxService::class)->assertVisible('outputs', $id, $user, $permissions, $superAdmin);
        return response()->json(['message' => '生产质量检验已记录。', 'data' => $service->inspect($id, $payload, $user, $permissions)]);
    }
    public function warehouse(Request $request, int $id, ProductionOutputService $service)
    {
        $payload = $request->validate(['client_command_id' => 'required|string|max:120', 'expected_version' => 'required|integer|min:1',
            'warehouse_id' => 'required|integer|min:1', 'location_id' => 'required|integer|min:1',
            'batch_no' => 'required|string|max:80', 'posted_base_qty' => 'nullable|numeric|gt:0',
            'unit_cost' => 'nullable|numeric|min:0']);
        [$user, $permissions, $superAdmin] = $this->context($request);
        app(ProductionExecutionInboxService::class)->assertVisible('outputs', $id, $user, $permissions, $superAdmin);
        return response()->json(['message' => '生产产出已正式入库。', 'data' => $service->warehouse($id, $payload, $user, $permissions)]);
    }
    private function context(Request $request): array
    { $auth = app(AuthContextService::class); $user = $auth->currentUser($request); if (! $user) throw new WorkOrderDomainException('unauthenticated', '请先登录 ERP。', 401); return [$user, $auth->permissionCodes($user), $auth->isSuperAdmin($user)]; }
    private function readContext(Request $request): array
    { return $this->context($request); }
    private function filters(Request $request, bool $task = true): array
    { return $request->validate(['status' => 'nullable|string|max:30', 'work_order_id' => 'nullable|integer|min:1', 'task_id' => $task ? 'nullable|integer|min:1' : 'prohibited', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']); }
}
