<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Models\Erp\ProductionTask;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\ProductionTaskAssignmentService;
use App\Services\Erp\ProductionTaskQueryService;
use App\Services\Erp\ProductionTaskCollaborationService;
use Illuminate\Http\Request;

class ProductionTaskController extends Controller
{
    public function index(Request $request, ProductionTaskQueryService $service)
    {
        $filters = $request->validate([
            'view' => 'nullable|in:pool,mine,owned,collaboration,all', 'status' => 'nullable|string|max:30',
            'work_order_id' => 'nullable|integer|min:1', 'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        return response()->json($service->paginate($filters, ...$this->context($request)));
    }

    public function show(Request $request, int $id, ProductionTaskQueryService $service)
    {
        return response()->json(['data' => $service->show($id, ...$this->context($request))]);
    }

    public function claim(Request $request, int $id, ProductionTaskAssignmentService $service)
    {
        $payload = $request->validate([
            'client_command_id' => 'required|string|max:120',
            'expected_version' => 'required|integer|min:1',
            'user_id' => 'prohibited', 'assignee_user_id' => 'prohibited', 'assignee_user_legacy_id' => 'prohibited',
        ]);
        [$user, $permissions] = $this->writeContext($request);
        return response()->json(['message' => '接单成功。', 'data' => $service->claim($id, $payload, $user, $permissions)]);
    }

    public function autoAssign(Request $request, int $id, ProductionTaskAssignmentService $service)
    {
        [$user, $permissions] = $this->writeContext($request);
        if (! in_array('production.assignment.auto', $permissions, true)) throw new WorkOrderDomainException('permission_denied', '当前用户没有自动派单权限。', 403);
        $service->autoAssign(ProductionTask::findOrFail($id));
    }

    public function join(Request $request, int $id, ProductionTaskCollaborationService $service)
    { return $this->collaboration($request, $id, $service, true); }

    public function leave(Request $request, int $id, ProductionTaskCollaborationService $service)
    { return $this->collaboration($request, $id, $service, false); }

    private function collaboration(Request $request, int $id, ProductionTaskCollaborationService $service, bool $join)
    {
        $payload = $request->validate(['client_command_id' => 'required|string|max:120', 'expected_version' => 'required|integer|min:1',
            'user_id' => 'prohibited', 'employee_legacy_id' => 'prohibited']);
        [$user, $permissions] = $this->writeContext($request);
        $result = $join ? $service->join($id, $payload, $user, $permissions) : $service->leave($id, $payload, $user, $permissions);
        return response()->json(['message' => $join ? '已加入生产协同。' : '已退出生产协同。', 'data' => $result]);
    }

    private function context(Request $request): array
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        if (! $user) throw new WorkOrderDomainException('unauthenticated', '请先登录 ERP。', 401);
        return [$user, $auth->permissionCodes($user), $auth->isSuperAdmin($user)];
    }

    private function writeContext(Request $request): array
    {
        $context = $this->context($request);
        return [$context[0], $context[1]];
    }
}
