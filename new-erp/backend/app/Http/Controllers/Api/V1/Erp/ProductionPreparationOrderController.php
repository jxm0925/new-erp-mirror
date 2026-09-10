<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\ProductionPreparationOrderService;
use Illuminate\Http\Request;

final class ProductionPreparationOrderController extends Controller
{
    public function index(Request $request, ProductionPreparationOrderService $service)
    {
        $filters = $request->validate([
            'keyword' => 'nullable|string|max:160',
            'status' => 'nullable|string|max:30',
            'production_master_order_id' => 'nullable|integer|min:1',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);
        return response()->json($service->paginate($filters, ...$this->context($request)));
    }

    public function show(Request $request, int $id, ProductionPreparationOrderService $service)
    {
        return response()->json(['data' => $service->show($id, ...$this->context($request))]);
    }

    private function context(Request $request): array
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        if (! $user) throw new WorkOrderDomainException('unauthenticated', '请先登录 ERP。', 401);
        return [$user, $auth->permissionCodes($user), $auth->isSuperAdmin($user)];
    }
}
