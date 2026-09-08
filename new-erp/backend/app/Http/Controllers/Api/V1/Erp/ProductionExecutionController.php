<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\ProductionExecutionActionService;
use App\Services\Erp\ProductionKittingService;
use App\Services\Erp\ProductionReportService;
use Illuminate\Http\Request;

class ProductionExecutionController extends Controller
{
    public function materialOptions(Request $request, int $taskId, string $targetType, int $targetId, ProductionKittingService $service)
    {
        $filters = $request->validate(['mode' => 'required|in:supplement,return', 'keyword' => 'nullable|string|max:160',
            'category_id' => 'nullable|integer|min:0', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:20']);
        [$user, $permissions] = $this->context($request);
        return response()->json($service->materialOptions($taskId, $targetType, $targetId, $filters, $user, $permissions));
    }

    public function requirements(Request $request, int $taskId, string $targetType, int $targetId, ProductionKittingService $service)
    {
        [$user, $permissions] = $this->context($request);
        return response()->json(['data' => $service->requirements($taskId, $targetType, $targetId, $user, $permissions)]);
    }

    public function confirmKitting(Request $request, int $taskId, string $targetType, int $targetId, ProductionKittingService $service)
    {
        $payload = $request->validate([
            'client_command_id' => 'required|string|max:120',
            'expected_version' => 'required|integer|min:1',
            'workstation_stock_confirmations' => 'nullable|array',
            'workstation_stock_confirmations.*.requirement_id' => 'required|integer|min:1',
            'workstation_stock_confirmations.*.onsite_available_base_qty' => 'required|numeric|min:0',
            'workstation_stock_confirmations.*.workstation' => 'nullable|string|max:160',
        ]);
        [$user, $permissions] = $this->context($request);
        return response()->json(['message' => '齐套确认成功，当前目标已就绪。', 'data' => $service->confirm($taskId, $targetType, $targetId, $payload, $user, $permissions)]);
    }

    public function start(Request $request, int $taskId, string $targetType, int $targetId, ProductionExecutionActionService $service)
    { return $this->action($request, $taskId, $targetType, $targetId, $service, 'start', '已开始加工并启动工时计时。'); }
    public function pause(Request $request, int $taskId, string $targetType, int $targetId, ProductionExecutionActionService $service)
    { return $this->action($request, $taskId, $targetType, $targetId, $service, 'pause', '已暂停加工并停止本次工时计时。'); }
    public function resume(Request $request, int $taskId, string $targetType, int $targetId, ProductionExecutionActionService $service)
    { return $this->action($request, $taskId, $targetType, $targetId, $service, 'resume', '已继续加工并重新启动工时计时。'); }
    public function report(Request $request, int $taskId, string $targetType, int $targetId, ProductionReportService $service)
    {
        $payload = $request->validate([
            'client_command_id' => 'required|string|max:120', 'expected_version' => 'required|integer|min:1',
            'qualified_base_qty' => 'required|numeric|min:0', 'unqualified_base_qty' => 'required|numeric|min:0',
            'scrapped_base_qty' => 'nullable|numeric|min:0', 'defect_reason' => 'nullable|string|max:1000',
            'remark' => 'nullable|string|max:2000', 'attachments' => 'nullable|array|max:20',
            'attachments.*' => 'array', 'end_labor' => 'nullable|boolean',
        ]);
        [$user, $permissions] = $this->context($request);
        return response()->json(['message' => '本次生产报工已形成正式事实。',
            'data' => $service->report($taskId, $targetType, $targetId, $payload, $user, $permissions)], 201);
    }
    public function complete(Request $request, int $taskId, string $targetType, int $targetId, ProductionExecutionActionService $service)
    {
        $payload = $request->validate(['client_command_id' => 'required|string|max:120', 'expected_version' => 'required|integer|min:1',
            'completed_base_qty' => 'nullable|numeric|min:0', 'scrapped_base_qty' => 'nullable|numeric|min:0',
            'defect_reason' => 'nullable|string|max:1000', 'remark' => 'nullable|string|max:2000',
            'attachments' => 'nullable|array|max:20', 'attachments.*' => 'array',
            'disposition' => 'nullable|in:direct_handover,warehouse']);
        [$user, $permissions] = $this->context($request);
        return response()->json(['message' => '工序执行已完成。', 'data' => $service->complete($taskId, $targetType, $targetId, $payload, $user, $permissions)]);
    }

    private function action(Request $request, int $taskId, string $targetType, int $targetId, ProductionExecutionActionService $service, string $method, string $message)
    {
        $payload = $request->validate(['client_command_id' => 'required|string|max:120', 'expected_version' => 'required|integer|min:1']);
        [$user, $permissions] = $this->context($request);
        return response()->json(['message' => $message, 'data' => $service->{$method}($taskId, $targetType, $targetId, $payload, $user, $permissions)]);
    }

    private function context(Request $request): array
    {
        $auth = app(AuthContextService::class); $user = $auth->currentUser($request);
        if (! $user) throw new WorkOrderDomainException('unauthenticated', '请先登录 ERP。', 401);
        return [$user, $auth->permissionCodes($user)];
    }
}
