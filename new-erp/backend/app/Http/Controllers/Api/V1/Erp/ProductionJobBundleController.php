<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\{AuthContextService, ProductionFinancialProjectionService, ProductionJobBundleApplicationService, ProductionJobBundleQueryService};
use Illuminate\Http\Request;

final class ProductionJobBundleController extends Controller
{
    public function index(Request $request, ProductionJobBundleQueryService $service)
    {
        return response()->json($this->redact($service->paginate($request->validate($this->filters() + [
            'view' => 'nullable|in:mine,all', 'status' => 'nullable|in:WAIT_CLAIM,CLAIMED,IN_PROGRESS,PAUSED,COMPLETED,CANCELLED',
        ]), ...$this->context($request))));
    }

    public function candidates(Request $request, ProductionJobBundleQueryService $service)
    {
        return response()->json($this->redact($service->candidates($request->validate($this->filters() + [
            'operation_id' => 'nullable|integer|min:1',
        ]), ...$this->context($request))));
    }

    public function show(Request $request, int $id, ProductionJobBundleQueryService $service)
    {
        return response()->json(['data' => $this->redact($service->show($id, ...$this->context($request)))]);
    }

    public function store(Request $request, ProductionJobBundleApplicationService $service)
    {
        $payload = $request->validate(['client_command_id' => 'required|string|max:120', 'title' => 'nullable|string|max:160',
            'tasks' => 'required|array|min:2|max:20', 'tasks.*.task_id' => 'required|integer|min:1|distinct',
            'tasks.*.expected_task_version' => 'required|integer|min:1',
            'assignee_user_legacy_id' => 'prohibited', 'standard_weight_minutes' => 'prohibited']);
        return response()->json(['message' => '共同加工安排已创建。', 'data' => $this->redact($service->create($payload, ...$this->context($request)))]);
    }

    public function claim(Request $r, int $id, ProductionJobBundleApplicationService $s) { return $this->action($r, $id, $s, 'claim'); }
    public function start(Request $r, int $id, ProductionJobBundleApplicationService $s) { return $this->action($r, $id, $s, 'start'); }
    public function pause(Request $r, int $id, ProductionJobBundleApplicationService $s) { return $this->action($r, $id, $s, 'pause'); }
    public function resume(Request $r, int $id, ProductionJobBundleApplicationService $s) { return $this->action($r, $id, $s, 'resume'); }
    public function finish(Request $r, int $id, ProductionJobBundleApplicationService $s) { return $this->action($r, $id, $s, 'finish'); }
    public function cancel(Request $r, int $id, ProductionJobBundleApplicationService $s) { return $this->action($r, $id, $s, 'cancel'); }

    public function report(Request $request, int $id, int $lineId, ProductionJobBundleApplicationService $service)
    {
        $payload = $request->validate($this->commandRules() + ['expected_target_version' => 'required|integer|min:1',
            'qualified_base_qty' => 'required|numeric|min:0', 'unqualified_base_qty' => 'required|numeric|min:0',
            'scrapped_base_qty' => 'required|numeric|min:0', 'defect_reason' => 'nullable|string|max:1000',
            'remark' => 'nullable|string|max:1000', 'attachments' => 'nullable|array|max:20', 'attachments.*' => 'string|max:2000',
            'end_labor' => 'prohibited', 'task_id' => 'prohibited', 'target_id' => 'prohibited']);
        return response()->json(['message' => '本条明细报工已登记。', 'data' => $this->redact($service->report($id, $lineId, $payload, ...$this->context($request)))]);
    }

    public function complete(Request $request, int $id, int $lineId, ProductionJobBundleApplicationService $service)
    {
        $payload = $request->validate($this->commandRules() + ['expected_target_version' => 'required|integer|min:1',
            'disposition' => 'nullable|in:warehouse,direct_handover', 'material_cost_allocation' => 'nullable|array:output_total_cost,loss_total_cost',
            'material_cost_allocation.output_total_cost' => 'required_with:material_cost_allocation|string',
            'material_cost_allocation.loss_total_cost' => 'required_with:material_cost_allocation|string',
            'remark' => 'nullable|string|max:1000', 'completed_base_qty' => 'prohibited', 'scrapped_base_qty' => 'prohibited']);
        return response()->json(['message' => '本条原工序已正式完工。', 'data' => $this->redact($service->complete($id, $lineId, $payload, ...$this->context($request)))]);
    }

    private function action(Request $request, int $id, ProductionJobBundleApplicationService $service, string $action)
    {
        return response()->json(['message' => '共同加工作业已更新。',
            'data' => $this->redact($service->{$action}($id, $request->validate($this->commandRules()), ...$this->context($request)))]);
    }
    private function commandRules(): array { return ['client_command_id' => 'required|string|max:120', 'expected_version' => 'required|integer|min:1']; }
    private function filters(): array { return ['work_order_id' => 'nullable|integer|min:1', 'keyword' => 'nullable|string|max:120', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:20']; }
    private function redact(mixed $data): mixed { return app(ProductionFinancialProjectionService::class)->redact($data); }
    private function context(Request $request): array
    {
        $auth = app(AuthContextService::class); $user = $auth->currentUser($request);
        if (! $user) throw new WorkOrderDomainException('unauthenticated', '请先登录 ERP。', 401);
        return [$user, $auth->permissionCodes($user), $auth->isSuperAdmin($user)];
    }
}
