<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\ProductionDeliveryWaveService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ProductionDeliveryWaveController extends Controller
{
    public function index(Request $request, ProductionDeliveryWaveService $service)
    {
        $filters = $request->validate([
            'status' => 'nullable|string|max:30',
            'production_preparation_order_id' => 'nullable|integer|min:1',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);
        return response()->json($service->paginate($filters, ...$this->context($request)));
    }

    public function store(Request $request, ProductionDeliveryWaveService $service)
    {
        $payload = $request->validate([
            'client_command_id' => 'required|string|max:120',
            'production_preparation_order_id' => 'required|integer|min:1',
            'tasks' => 'required|array|min:1',
            'tasks.*.assignment_mode' => ['required', Rule::in(['pool_claim', 'dispatcher_assign'])],
            'tasks.*.zone_pool_code' => 'nullable|string|max:80',
            'tasks.*.warehouse_code' => 'nullable|string|max:80',
            'tasks.*.production_zone_code' => 'nullable|string|max:80',
            'tasks.*.work_center_code' => 'nullable|string|max:80',
            'tasks.*.location_code' => 'nullable|string|max:80',
            'tasks.*.priority' => 'nullable|integer|min:1|max:100',
            'tasks.*.planned_start_at' => 'nullable|date',
            'tasks.*.required_finish_at' => 'nullable|date',
            'tasks.*.delivery_user_legacy_id' => 'nullable|integer|min:1',
            'tasks.*.lines' => 'required|array|min:1',
            'tasks.*.lines.*.material_delivery_line_id' => 'required|integer|min:1',
            'tasks.*.lines.*.allocated_qty' => 'required|numeric|gt:0',
            'tasks.*.lines.*.serial_snapshot' => 'nullable|array',
            'tasks.*.lines.*.handling_unit_id' => 'nullable|integer|min:1',
        ]);
        return response()->json(['message' => '配送波次已建立。', 'data' => $service->create($payload, ...$this->context($request))], 201);
    }

    public function poolClaim(Request $request, int $id, ProductionDeliveryWaveService $service)
    {
        $payload = $request->validate(['expected_version' => 'required|integer|min:1']);
        return response()->json(['message' => '配送任务已从区域池接单。', 'data' => $service->poolClaim($id, $payload, ...$this->context($request))]);
    }

    public function dispatcherAssign(Request $request, int $id, ProductionDeliveryWaveService $service)
    {
        $payload = $request->validate([
            'expected_version' => 'required|integer|min:1',
            'delivery_user_legacy_id' => 'required|integer|min:1',
        ]);
        return response()->json(['message' => '配送任务已由调度派单。', 'data' => $service->dispatcherAssign($id, $payload, ...$this->context($request))]);
    }

    public function transition(Request $request, int $id, ProductionDeliveryWaveService $service)
    {
        $payload = $request->validate([
            'expected_version' => 'required|integer|min:1',
            'status' => ['required', Rule::in(['PICKED_UP', 'DELIVERING', 'PARTIAL_DONE', 'DONE', 'EXCEPTION', 'CANCELLED'])],
            'remark' => 'nullable|string|max:2000',
        ]);
        return response()->json(['message' => '配送执行任务状态已更新。', 'data' => $service->transitionTask($id, $payload, ...$this->context($request))]);
    }

    private function context(Request $request): array
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        if (! $user) throw new WorkOrderDomainException('unauthenticated', '请先登录 ERP。', 401);
        return [$user, $auth->permissionCodes($user), $auth->isSuperAdmin($user)];
    }
}
