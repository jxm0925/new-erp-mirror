<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\Manufacturing\WorkOrderQueryService;
use App\Services\Erp\Manufacturing\WorkOrderService;
use Illuminate\Http\Request;

/** 新生产工单接口：列表、详情、备货新建、下达、调整、暂停/恢复、取消、序列号查询。 */
class MfgWorkOrderController extends Controller
{
    public function index(Request $request, WorkOrderQueryService $query)
    {
        [, $amounts] = $this->user($request, 'production.work_order.view');
        $filters = $request->validate(['keyword' => 'nullable|string|max:120', 'status' => 'nullable|in:planned,released,in_progress,paused,completed,closed,cancelled',
            'source_type' => 'nullable|in:sales_order,stock', 'owner_legacy_id' => 'nullable|integer|min:1', 'due_from' => 'nullable|date', 'due_to' => 'nullable|date',
            'stuck_only' => 'nullable|boolean', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        return response()->json($query->paginate($filters, $amounts));
    }

    public function show(Request $request, int $id, WorkOrderQueryService $query)
    {
        [, $amounts] = $this->user($request, 'production.work_order.view');
        return response()->json(['data' => $query->detail($id, $amounts)]);
    }

    public function preview(Request $request, WorkOrderQueryService $query)
    {
        [, $amounts] = $this->user($request, 'production.work_order.create');
        $data = $request->validate(['item_id' => 'required|integer|exists:erp_items,id', 'product_id' => 'nullable|integer', 'sku_id' => 'nullable|integer',
            'planned_qty' => 'nullable|numeric|min:0.0001|max:100000', 'bom_id' => 'nullable|integer', 'routing_id' => 'nullable|integer']);
        return response()->json(['data' => $query->preview($data, $amounts)]);
    }

    public function options(Request $request, int $id, WorkOrderQueryService $query)
    {
        $this->user($request, 'production.work_order.view');
        $itemId = (int) \App\Models\Erp\MfgWorkOrder::query()->whereKey($id)->value('item_id');
        abort_unless($itemId, 404);
        return response()->json(['data' => ['bom_options' => $query->bomOptions($itemId), 'routing_options' => $query->routingOptions($itemId)]]);
    }

    public function store(Request $request, WorkOrderService $service, WorkOrderQueryService $query)
    {
        [$user, $amounts] = $this->user($request, 'production.work_order.create');
        $data = $request->validate(['item_id' => 'required|integer|exists:erp_items,id', 'product_id' => 'nullable|integer', 'sku_id' => 'nullable|integer',
            'product_name' => 'nullable|string|max:200', 'sku_name' => 'nullable|string|max:200',
            'planned_qty' => 'required|numeric|min:0.0001|max:100000', 'stock_reason' => 'required|in:alert,regular,other',
            'planned_start_date' => 'required|date', 'planned_finish_date' => 'required|date|after_or_equal:planned_start_date',
            'owner_legacy_id' => 'nullable|integer|min:1', 'owner_name' => 'nullable|string|max:120', 'warehouse_id' => 'nullable|integer|exists:erp_warehouses,id',
            'bom_id' => 'nullable|integer', 'routing_id' => 'nullable|integer', 'is_urgent' => 'nullable|boolean', 'remark' => 'nullable|string|max:500',
            'release' => 'nullable|boolean']);
        if (! empty($data['release'])) $this->user($request, 'production.work_order.publish');
        $wo = $service->createStock($data, $user);
        return response()->json(['message' => $wo->status === 'released' ? '备货工单已下达，序列号已生成。' : '备货工单已保存为计划。', 'data' => $query->detail($wo->id, $amounts)]);
    }

    public function release(Request $request, int $id, WorkOrderService $service, WorkOrderQueryService $query)
    {
        [$user, $amounts] = $this->user($request, 'production.work_order.publish');
        $wo = $service->release($id, $this->version($request), $user);
        return response()->json(['message' => '工单已下达，序列号已生成。', 'data' => $query->detail($wo->id, $amounts)]);
    }

    public function update(Request $request, int $id, WorkOrderService $service, WorkOrderQueryService $query)
    {
        [$user, $amounts] = $this->user($request, 'production.work_order.edit');
        $data = $request->validate(['expected_version' => 'required|integer|min:1', 'owner_legacy_id' => 'sometimes|nullable|integer|min:1',
            'owner_name' => 'sometimes|nullable|string|max:120', 'planned_start_date' => 'sometimes|nullable|date', 'planned_finish_date' => 'sometimes|nullable|date',
            'is_urgent' => 'sometimes|boolean', 'remark' => 'sometimes|nullable|string|max:500', 'warehouse_id' => 'sometimes|nullable|integer|exists:erp_warehouses,id',
            'bom_id' => 'sometimes|nullable|integer', 'routing_id' => 'sometimes|nullable|integer', 'planned_qty' => 'sometimes|numeric|min:0.0001|max:100000']);
        $wo = $service->adjust($id, $data, $user);
        return response()->json(['message' => '工单已调整。', 'data' => $query->detail($wo->id, $amounts)]);
    }

    public function pause(Request $request, int $id, WorkOrderService $service, WorkOrderQueryService $query)
    {
        [$user, $amounts] = $this->user($request, 'production.work_order.edit');
        $reason = $request->validate(['reason' => 'required|string|max:500'])['reason'];
        $wo = $service->pause($id, $this->version($request), $reason, $user);
        return response()->json(['message' => '工单已暂停，车间暂时不能报工。', 'data' => $query->detail($wo->id, $amounts)]);
    }

    public function resume(Request $request, int $id, WorkOrderService $service, WorkOrderQueryService $query)
    {
        [$user, $amounts] = $this->user($request, 'production.work_order.edit');
        $wo = $service->resume($id, $this->version($request), $user);
        return response()->json(['message' => '工单已恢复。', 'data' => $query->detail($wo->id, $amounts)]);
    }

    public function cancel(Request $request, int $id, WorkOrderService $service, WorkOrderQueryService $query)
    {
        [$user, $amounts] = $this->user($request, 'production.work_order.cancel');
        $reason = $request->validate(['reason' => 'required|string|max:500'])['reason'];
        $wo = $service->cancel($id, $this->version($request), $reason, $user);
        return response()->json(['message' => '工单已取消。', 'data' => $query->detail($wo->id, $amounts)]);
    }

    public function serial(Request $request, WorkOrderQueryService $query)
    {
        $this->user($request, 'production.serial.view');
        $data = $request->validate(['serial_no' => 'required|string|max:120']);
        $result = $query->serial(trim($data['serial_no']));
        if (! $result) return response()->json(['data' => null, 'suggestions' => $query->serialSuggestions(trim($data['serial_no'])), 'message' => '没有找到这个序列号。']);
        return response()->json(['data' => $result]);
    }

    public function salesOrder(Request $request, int $orderId, WorkOrderQueryService $query)
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        if (! $user) throw new WorkOrderDomainException('unauthenticated', '请先登录 ERP。', 401);
        $codes = $auth->permissionCodes($user);
        $super = $auth->isSuperAdmin($user);
        if (! $super && ! array_intersect(['sales_order.view', 'production.work_order.view'], $codes))
            throw new WorkOrderDomainException('permission_denied', '没有查看订单生产进度的权限。', 403);
        return response()->json(['data' => $query->forSalesOrder($orderId, $super || in_array('sales_order.amount.view', $codes, true))]);
    }

    private function version(Request $request): int
    {
        return (int) $request->validate(['expected_version' => 'required|integer|min:1'])['expected_version'];
    }

    /** @return array{0: object, 1: bool} 当前用户、能否看金额（订单金额与预计成本） */
    private function user(Request $request, string $permission): array
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        if (! $user) throw new WorkOrderDomainException('unauthenticated', '请先登录 ERP。', 401);
        $super = $auth->isSuperAdmin($user);
        $codes = $auth->permissionCodes($user);
        if (! $super && ! in_array($permission, $codes, true)) throw new WorkOrderDomainException('permission_denied', '当前账号没有这项工单权限。', 403);
        return [$user, $super || in_array('sales_order.amount.view', $codes, true) || in_array('production.performance.manage', $codes, true)];
    }
}
