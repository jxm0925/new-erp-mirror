<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\ProductionDeliveryTriggerService;
use Illuminate\Http\Request;

final class ProductionDeliveryTriggerController extends Controller
{
    public function configure(Request $request, int $id, ProductionDeliveryTriggerService $service)
    {
        $payload = $request->validate(['expected_version' => 'required|integer|min:1', 'planned_start_at' => 'required|date', 'delivery_lead_minutes' => 'required|integer|min:0|max:10080']);
        return response()->json(['message' => '配送触发条件已配置。', 'data' => $service->configure($id, $payload, ...$this->context($request))]);
    }
    public function manualRelease(Request $request, int $id, ProductionDeliveryTriggerService $service)
    {
        $payload = $request->validate(['expected_version' => 'required|integer|min:1', 'reason' => 'required|string|max:2000']);
        return response()->json(['message' => '配送已按人工覆盖立即释放。', 'data' => $service->manualRelease($id, $payload, ...$this->context($request))]);
    }
    private function context(Request $request): array
    {
        $auth = app(AuthContextService::class); $user = $auth->currentUser($request);
        if (! $user) throw new WorkOrderDomainException('unauthenticated', '请先登录 ERP。', 401);
        return [$user, $auth->permissionCodes($user), $auth->isSuperAdmin($user)];
    }
}
