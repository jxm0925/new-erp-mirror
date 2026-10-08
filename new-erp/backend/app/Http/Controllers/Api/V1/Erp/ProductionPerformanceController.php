<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\ProductionPerformanceApplicationService;
use App\Services\Erp\ProductionPerformanceQueryService;
use Illuminate\Http\Request;

class ProductionPerformanceController extends Controller
{
    public function operations(Request $request, ProductionPerformanceQueryService $service)
    {
        $filters = $request->validate($this->filters() + ['keyword' => 'nullable|string|max:160']);
        [$user, $permissions] = $this->context($request);
        return response()->json($service->operations($filters, $user, $permissions));
    }

    public function index(Request $request, ProductionPerformanceQueryService $service)
    {
        $filters = $request->validate($this->filters() + ['keyword' => 'nullable|string|max:160',
            'shipped_from' => 'nullable|date_format:Y-m-d', 'shipped_to' => 'nullable|date_format:Y-m-d|after_or_equal:shipped_from']);
        [$user, $permissions, $super] = $this->context($request);
        return response()->json($service->paginate($filters, $user, $permissions, $super));
    }

    public function show(Request $request, int $id, ProductionPerformanceQueryService $service)
    {
        $filters = $request->validate($this->filters() + ['employees_page' => 'nullable|integer|min:1', 'issues_page' => 'nullable|integer|min:1']);
        [$user, $permissions, $super] = $this->context($request);
        return response()->json(['data' => $service->show($id, $filters, $user, $permissions, $super)]);
    }

    public function scope(Request $request, string $scopeType, int $scopeId, ProductionPerformanceQueryService $service)
    {
        $filters = $request->validate($this->filters());
        [$user, $permissions, $super] = $this->context($request);
        return response()->json(['data' => $service->scope($scopeType, $scopeId, $filters, $user, $permissions, $super)]);
    }

    public function confirm(Request $request, string $scopeType, int $scopeId, ProductionPerformanceApplicationService $service)
    {
        $payload = $request->validate([
            'client_command_id' => 'required|string|max:120', 'expected_scope_version' => 'required|integer|min:1',
            'expected_assignment_version' => 'required|integer|min:0', 'shares' => 'present|array|max:200',
            'shares.*.employee_legacy_id' => 'required|integer|min:1|distinct', 'shares.*.eligible' => 'required|boolean',
            'shares.*.share_ratio' => 'required|numeric|min:0|max:1', 'shares.*.remark' => 'nullable|string|max:500',
            'noncredited_confirmed' => 'required|boolean', 'noncredited_reason' => 'nullable|string|max:500',
            'owner_legacy_id' => 'prohibited', 'performance_amount' => 'prohibited', 'basis_amount' => 'prohibited',
        ]);
        [$user, $permissions] = $this->context($request);
        return response()->json(['message' => '个人份额已确认，整单发完后由统计端统一计算。',
            'data' => $service->confirm($scopeType, $scopeId, $payload, $user, $permissions)]);
    }

    public function policy(Request $request, ProductionPerformanceQueryService $service)
    {
        [, $permissions] = $this->context($request);
        if (! in_array('production.performance.view', $permissions, true)) throw new WorkOrderDomainException('permission_denied', '当前用户没有查看个人绩效统计的权限。', 403);
        return response()->json(['data' => $service->policy()]);
    }

    private function filters(): array { return ['page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']; }
    private function context(Request $request): array
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        if (! $user) throw new WorkOrderDomainException('unauthenticated', '请先登录 ERP。', 401);
        return [$user, $auth->permissionCodes($user), $auth->isSuperAdmin($user)];
    }
}
