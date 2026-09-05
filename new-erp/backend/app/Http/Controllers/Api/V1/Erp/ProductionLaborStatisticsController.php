<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\ProductionLaborStatisticsService;
use Illuminate\Http\Request;

class ProductionLaborStatisticsController extends Controller
{
    public function index(Request $request, ProductionLaborStatisticsService $service)
    {
        $filters = $request->validate([
            'output_item_id' => 'nullable|integer|min:1', 'routing_id' => 'nullable|integer|min:1',
            'routing_version' => 'nullable|integer|min:1', 'routing_operation_id' => 'nullable|integer|min:1',
            'operation_id' => 'nullable|integer|min:1', 'employee_legacy_id' => 'nullable|integer|min:1',
        ]);
        $auth = app(AuthContextService::class); $user = $auth->currentUser($request);
        if (! $user) throw new WorkOrderDomainException('unauthenticated', '请先登录 ERP。', 401);
        return response()->json(['data' => $service->statistics($filters, $auth->permissionCodes($user))]);
    }
}
