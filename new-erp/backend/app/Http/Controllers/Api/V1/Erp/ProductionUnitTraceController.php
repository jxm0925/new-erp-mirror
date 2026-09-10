<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\ProductionUnitTraceService;
use Illuminate\Http\Request;

class ProductionUnitTraceController extends Controller
{
    public function workOrderUnits(Request $request, int $id, ProductionUnitTraceService $service)
    {
        $filters = $request->validate([
            'status' => 'nullable|string|max:30',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);
        return response()->json($service->units($id, $filters, ...$this->context($request)));
    }
    public function unit(Request $request, int $id, ProductionUnitTraceService $service)
    { return response()->json(['data' => $service->unit($id, ...$this->context($request))]); }
    public function trace(Request $request, ProductionUnitTraceService $service)
    { $payload = $request->validate(['keyword' => 'required|string|max:120']); return response()->json(['data' => $service->trace($payload['keyword'], ...$this->context($request))]); }
    private function context(Request $request): array
    { $auth = app(AuthContextService::class); $user = $auth->currentUser($request); if (! $user) throw new WorkOrderDomainException('unauthenticated', '请先登录 ERP。', 401); return [$user, $auth->permissionCodes($user), $auth->isSuperAdmin($user)]; }
}
