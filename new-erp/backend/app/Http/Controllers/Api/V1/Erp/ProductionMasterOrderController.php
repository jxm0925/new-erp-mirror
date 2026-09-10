<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\ProductionMasterOrderQueryService;
use Illuminate\Http\Request;

final class ProductionMasterOrderController extends Controller
{
    public function index(Request $request, ProductionMasterOrderQueryService $service)
    {
        $filters = $this->filters($request);
        $context = $this->context($request);
        $page = $service->paginate($filters, ...$context)->toArray();
        $page['summary'] = $service->summary($filters, ...$context);
        return response()->json($page);
    }

    public function show(Request $request, int $id, ProductionMasterOrderQueryService $service)
    {
        return response()->json(['data' => $service->show($id, ...$this->context($request))]);
    }

    public function workOrders(Request $request, int $id, ProductionMasterOrderQueryService $service)
    {
        return response()->json($service->workOrders($id, $this->filters($request), ...$this->context($request)));
    }

    public function units(Request $request, int $id, ProductionMasterOrderQueryService $service)
    {
        return response()->json($service->units($id, $this->filters($request), ...$this->context($request)));
    }

    public function fundingStatus(Request $request, int $id, ProductionMasterOrderQueryService $service)
    {
        return response()->json(['data' => $service->show($id, ...$this->context($request))['funding']]);
    }

    private function filters(Request $request): array
    {
        return $request->validate(['keyword' => 'nullable|string|max:160', 'status' => 'nullable|in:IN_PROGRESS,WAIT_CONDITION,EXCEPTION,COMPLETED',
            'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:50']);
    }

    private function context(Request $request): array
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        if (! $user) throw new WorkOrderDomainException('unauthenticated', '请先登录 ERP。', 401);
        return [$user, $auth->permissionCodes($user), $auth->isSuperAdmin($user)];
    }
}
