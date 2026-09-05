<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\ProductionExecutionMonitorService;
use Illuminate\Http\Request;

class ProductionExecutionMonitorController extends Controller
{
    public function index(Request $request, ProductionExecutionMonitorService $service)
    {
        $filters = $request->validate(['keyword' => 'nullable|string|max:120', 'status' => 'nullable|in:RELEASED,IN_PROGRESS,COMPLETED', 'planned_date' => 'nullable|date', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        $auth = app(AuthContextService::class); $user = $auth->currentUser($request);
        if (! $user) throw new WorkOrderDomainException('unauthenticated', '请先登录 ERP。', 401);
        $result = $service->paginate($filters, $user, $auth->permissionCodes($user), $auth->isSuperAdmin($user));
        return response()->json(['data' => $result['page']->items(), 'meta' => ['current_page' => $result['page']->currentPage(), 'per_page' => $result['page']->perPage(), 'total' => $result['page']->total()], 'stats' => $result['stats']]);
    }
}
