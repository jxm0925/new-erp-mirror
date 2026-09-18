<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\ProductionInternalIssueService;
use App\Services\Erp\ProductionExecutionInboxService;
use Illuminate\Http\Request;

class ProductionInternalIssueController extends Controller
{
    public function index(Request $request, ProductionExecutionInboxService $service) { return response()->json($service->paginate('internal_issues', $this->filters($request), ...$this->readContext($request))); }
    public function show(Request $request, int $id, ProductionExecutionInboxService $service) { return response()->json(['data' => $service->show('internal_issues', $id, ...$this->readContext($request))]); }
    public function dispatch(Request $request, int $id, ProductionInternalIssueService $service) { return $this->action($request, $id, $service, true); }
    public function receive(Request $request, int $id, ProductionInternalIssueService $service) { return $this->action($request, $id, $service, false); }
    private function action(Request $request, int $id, ProductionInternalIssueService $service, bool $dispatch) { $payload = $request->validate(['client_command_id' => 'required|string|max:120', 'expected_version' => 'required|integer|min:1']); [$user, $permissions, $superAdmin] = $this->context($request); app(ProductionExecutionInboxService::class)->assertVisible('internal_issues', $id, $user, $permissions, $superAdmin); $result = $dispatch ? $service->dispatch($id, $payload, $user, $permissions, $superAdmin) : $service->receive($id, $payload, $user, $permissions, $superAdmin); return response()->json(['message' => $dispatch ? '半成品已由仓库交出，等待下一工序接收。' : '半成品已确认接收并正式出库。', 'data' => $result]); }
    private function context(Request $request): array { $auth = app(AuthContextService::class); $user = $auth->currentUser($request); if (! $user) throw new WorkOrderDomainException('unauthenticated', '请先登录 ERP。', 401); return [$user, $auth->permissionCodes($user), $auth->isSuperAdmin($user)]; }
    private function readContext(Request $request): array { return $this->context($request); }
    private function filters(Request $request): array { return $request->validate(['status' => 'nullable|string|max:30', 'work_order_id' => 'nullable|integer|min:1', 'task_id' => 'nullable|integer|min:1', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']); }
}
