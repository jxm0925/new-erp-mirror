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
            'keyword' => 'nullable|string|max:120',
            'execution_filter' => 'nullable|in:all,running,waiting,completed,kitting,current',
            'include_stats' => 'nullable|boolean',
        ]);
        $context = $this->context($request);
        $result = $service->paginate($filters, ...$context)->toArray();
        if ($request->boolean('include_stats')) $result['stats'] = $service->summary($filters, ...$context);
        return response()->json($result);
    }

    public function show(Request $request, int $id, ProductionTaskQueryService $service)
    {
        return response()->json(['data' => $service->show($id, ...$this->context($request))]);
    }

    public function workbenchSummary(Request $request, ProductionTaskQueryService $service)
    {
        return response()->json(['data' => $service->workbenchSummary(
            ['view' => 'owned'],
            ...$this->context($request),
        )]);
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

    public function addCollaborators(Request $request, int $id, ProductionTaskCollaborationService $service)
    {
        $payload = $request->validate([
            'client_command_id' => 'required|string|max:120', 'expected_version' => 'required|integer|min:1',
            'employee_legacy_ids' => 'required|array|min:1|max:20',
            'employee_legacy_ids.*' => 'required|integer|min:1|distinct',
        ]);
        [$user, $permissions] = $this->writeContext($request);
        return response()->json(['message' => '协同人员已添加。', 'data' => $service->add($id, $payload, $user, $permissions)]);
    }

    public function startCollaboratorLabor(Request $request, int $taskId, string $targetType, int $targetId, ProductionTaskCollaborationService $service)
    { return $this->collaboratorLabor($request, $taskId, $targetType, $targetId, $service, true); }

    public function pauseCollaboratorLabor(Request $request, int $taskId, string $targetType, int $targetId, ProductionTaskCollaborationService $service)
    { return $this->collaboratorLabor($request, $taskId, $targetType, $targetId, $service, false); }

    private function collaboration(Request $request, int $id, ProductionTaskCollaborationService $service, bool $join)
    {
        $payload = $request->validate(['client_command_id' => 'required|string|max:120', 'expected_version' => 'required|integer|min:1',
            'user_id' => 'prohibited', 'employee_legacy_id' => 'prohibited']);
        [$user, $permissions] = $this->writeContext($request);
        $result = $join ? $service->join($id, $payload, $user, $permissions) : $service->leave($id, $payload, $user, $permissions);
        return response()->json(['message' => $join ? '已加入生产协同。' : '已退出生产协同。', 'data' => $result]);
    }

    private function collaboratorLabor(Request $request, int $taskId, string $targetType, int $targetId, ProductionTaskCollaborationService $service, bool $start)
    {
        $payload = $request->validate(['client_command_id' => 'required|string|max:120', 'expected_version' => 'required|integer|min:1',
            'switch_active_labor' => 'nullable|boolean', 'expected_active_labor_session_id' => 'nullable|integer|min:1|required_if:switch_active_labor,true']);
        [$user, $permissions] = $this->writeContext($request);
        $result = $start
            ? $service->startLabor($taskId, $targetType, $targetId, $payload, $user, $permissions)
            : $service->pauseLabor($taskId, $targetType, $targetId, $payload, $user, $permissions);
        return response()->json(['message' => $start ? '协同计时已开始。' : '协同计时已暂停。', 'data' => $result]);
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
