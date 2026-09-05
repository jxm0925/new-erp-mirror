<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\ProductionHandoverService;
use Illuminate\Http\Request;

class ProductionHandoverController extends Controller
{
    public function pending(Request $request, ProductionHandoverService $service)
    { [$user, $permissions] = $this->context($request); return response()->json(['data' => $service->pending($user, $permissions)]); }
    public function accept(Request $request, int $id, ProductionHandoverService $service)
    { return $this->decide($request, $id, $service, true); }
    public function reject(Request $request, int $id, ProductionHandoverService $service)
    { return $this->decide($request, $id, $service, false); }
    private function decide(Request $request, int $id, ProductionHandoverService $service, bool $accept)
    {
        $payload = $request->validate(['client_command_id' => 'required|string|max:120', 'expected_version' => 'required|integer|min:1',
            'reason' => $accept ? 'prohibited' : 'required|string|max:1000', 'completeness' => $accept ? 'nullable|array' : 'prohibited']);
        [$user, $permissions] = $this->context($request);
        $result = $accept ? $service->accept($id, $payload, $user, $permissions) : $service->reject($id, $payload, $user, $permissions);
        return response()->json(['message' => $accept ? '工序交接接收成功。' : '工序交接已拒收并退回上游返工。', 'data' => $result]);
    }
    private function context(Request $request): array
    { $auth = app(AuthContextService::class); $user = $auth->currentUser($request); if (! $user) throw new WorkOrderDomainException('unauthenticated', '请先登录 ERP。', 401); return [$user, $auth->permissionCodes($user)]; }
}
