<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\ProductionInternalIssueService;
use Illuminate\Http\Request;

class ProductionInternalIssueController extends Controller
{
    public function dispatch(Request $request, int $id, ProductionInternalIssueService $service) { return $this->action($request, $id, $service, true); }
    public function receive(Request $request, int $id, ProductionInternalIssueService $service) { return $this->action($request, $id, $service, false); }
    private function action(Request $request, int $id, ProductionInternalIssueService $service, bool $dispatch) { $payload = $request->validate(['client_command_id' => 'required|string|max:120', 'expected_version' => 'required|integer|min:1']); [$user, $permissions] = $this->context($request); $result = $dispatch ? $service->dispatch($id, $payload, $user, $permissions) : $service->receive($id, $payload, $user, $permissions); return response()->json(['message' => $dispatch ? '半成品已由仓库交出，等待下一工序接收。' : '半成品已确认接收并正式出库。', 'data' => $result]); }
    private function context(Request $request): array { $auth = app(AuthContextService::class); $user = $auth->currentUser($request); if (! $user) throw new WorkOrderDomainException('unauthenticated', '请先登录 ERP。', 401); return [$user, $auth->permissionCodes($user)]; }
}
