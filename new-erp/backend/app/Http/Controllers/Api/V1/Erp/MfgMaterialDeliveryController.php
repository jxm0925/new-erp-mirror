<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\Manufacturing\MaterialDeliveryService;
use App\Services\Erp\Manufacturing\ShortageService;
use App\Services\Erp\Manufacturing\WorkOrderQueryService;
use Illuminate\Http\Request;

/** 仓库配料配送：库管配料、发出、重新送达、收回、取消（production.material_delivery.manage）；车间签收、拒收（报工或编辑工单权限）。 */
class MfgMaterialDeliveryController extends Controller
{
    private const MANAGE = 'production.material_delivery.manage';
    private const SIGN = ['production.task.execute', 'production.work_order.edit'];

    public function pending(Request $request, MaterialDeliveryService $deliveries)
    {
        $this->user($request, self::MANAGE);
        $filters = $request->validate(['keyword' => 'nullable|string|max:120', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        return response()->json($deliveries->pending($filters));
    }

    public function index(Request $request, MaterialDeliveryService $deliveries)
    {
        [, $canManage] = $this->user($request, [self::MANAGE, 'production.work_order.view']);
        $filters = $request->validate(['status' => 'nullable|in:prepared,dispatched,signed,rejected,recalled,cancelled', 'keyword' => 'nullable|string|max:120',
            'work_order_id' => 'nullable|integer|min:1', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        return response()->json($deliveries->paginate($filters) + ['can_manage' => $canManage]);
    }

    public function preview(Request $request, int $id, int $operationId, MaterialDeliveryService $deliveries)
    {
        $this->user($request, self::MANAGE);
        $data = $request->validate(['units' => 'nullable|integer|min:1|max:100000'] + MaterialDeliveryService::selectionRules());
        return response()->json(['data' => $deliveries->preview($id, $operationId, isset($data['units']) ? (int) $data['units'] : null, $data['selections'] ?? [])]);
    }

    public function candidates(Request $request, int $id, int $operationId, MaterialDeliveryService $deliveries)
    {
        $this->user($request, self::MANAGE);
        $filters = $request->validate(['material_id' => 'required|integer|min:1', 'item_id' => 'nullable|integer|min:1',
            'mode' => 'nullable|in:balance,serial', 'balance_id' => 'required_if:mode,serial|integer|min:1', 'serial_id' => 'nullable|integer|min:1',
            'exclude_serial_ids' => 'sometimes|array|max:5000', 'exclude_serial_ids.*' => 'integer|min:1|distinct',
            'keyword' => 'nullable|string|max:120', 'location_id' => 'nullable|integer|min:1', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        return response()->json($deliveries->candidates($id, $operationId, $filters));
    }

    public function shortagePurchasePreview(Request $request, int $id, int $operationId, MaterialDeliveryService $deliveries)
    {
        $this->user($request, self::MANAGE);
        $data = $request->validate(['units' => 'nullable|integer|min:1|max:100000'] + MaterialDeliveryService::selectionRules());
        return response()->json(['data' => $deliveries->shortagePurchasePreview($id, $operationId,
            isset($data['units']) ? (int) $data['units'] : null, $data['selections'] ?? [])]);
    }

    public function shortagePurchaseRequest(Request $request, int $id, int $operationId, ShortageService $shortages)
    {
        [$user] = $this->user($request, self::MANAGE);
        $data = $request->validate(['units' => 'nullable|integer|min:1|max:100000', 'lines' => 'required|array|min:1|max:100',
            'lines.*.item_id' => 'required|integer|min:1|distinct', 'lines.*.qty' => 'required|numeric|min:0.0001|max:100000000|decimal:0,4',
            'lines.*.purchase_unit_id' => 'required|integer|min:1'] + MaterialDeliveryService::selectionRules());
        $record = $shortages->createRequest($data['lines'], $id, null, $user, ['operation_id' => $operationId,
            'units' => isset($data['units']) ? (int) $data['units'] : null, 'selections' => $data['selections'] ?? []]);
        return response()->json(['message' => "已生成采购需求 {$record->request_no}（草稿），请采购确认后转计划。",
            'data' => ['id' => $record->id, 'request_no' => $record->request_no]], 201);
    }

    public function store(Request $request, int $id, int $operationId, MaterialDeliveryService $deliveries)
    {
        [$user] = $this->user($request, self::MANAGE);
        $data = $request->validate(['units' => 'nullable|integer|min:1|max:100000', 'remark' => 'nullable|string|max:500', 'dispatch' => 'nullable|boolean',
            'expected_source_revision' => 'nullable|string|regex:/^[a-f0-9]{64}$/'] + MaterialDeliveryService::selectionRules());
        $delivery = $deliveries->prepare($id, $operationId, isset($data['units']) ? (int) $data['units'] : null, $data['remark'] ?? null, $user,
            $data['expected_source_revision'] ?? null, $data['selections'] ?? []);
        if (! empty($data['dispatch'])) {
            $delivery = $deliveries->dispatch($delivery->id, $user, $data['expected_source_revision'] ?? null);
            return response()->json(['message' => "配料单 {$delivery->delivery_no} 已发出，已扣库存，等车间签收。", 'data' => $deliveries->row($delivery)], 201);
        }
        return response()->json(['message' => "配料单 {$delivery->delivery_no} 已生成，照单拣货后点“发出”。", 'data' => $deliveries->row($delivery)], 201);
    }

    public function dispatch(Request $request, int $id, MaterialDeliveryService $deliveries)
    {
        [$user] = $this->user($request, self::MANAGE);
        $delivery = $deliveries->dispatch($id, $user);
        return response()->json(['message' => "配料单 {$delivery->delivery_no} 已发出，已扣库存，等车间签收。", 'data' => $deliveries->row($delivery)]);
    }

    /** 拣货来源失效时按先入先用重新拣货（单号不变）。 */
    public function repick(Request $request, int $id, MaterialDeliveryService $deliveries)
    {
        [$user] = $this->user($request, self::MANAGE);
        $delivery = $deliveries->repick($id, $user);
        return response()->json(['message' => "配料单 {$delivery->delivery_no} 已重新拣货，请按新拣货单取料后发出。", 'data' => $deliveries->row($delivery)]);
    }

    public function cancel(Request $request, int $id, MaterialDeliveryService $deliveries)
    {
        [$user] = $this->user($request, self::MANAGE);
        $data = $request->validate(['remark' => 'nullable|string|max:500']);
        $delivery = $deliveries->cancel($id, $data['remark'] ?? null, $user);
        return response()->json(['message' => "配料单 {$delivery->delivery_no} 已取消。", 'data' => $deliveries->row($delivery)]);
    }

    public function redeliver(Request $request, int $id, MaterialDeliveryService $deliveries)
    {
        [$user] = $this->user($request, self::MANAGE);
        $data = $request->validate(['remark' => 'nullable|string|max:500']);
        $delivery = $deliveries->redeliver($id, $data['remark'] ?? null, $user);
        return response()->json(['message' => "配料单 {$delivery->delivery_no} 已重新送达，等车间签收。", 'data' => $deliveries->row($delivery)]);
    }

    public function recall(Request $request, int $id, \App\Services\Erp\WarehouseCommandService $commands)
    {
        [$user] = $this->user($request, self::MANAGE);
        $data = $request->validate(['client_command_id' => 'required|string|max:120', 'reason' => 'required|string|max:300',
            'confirm_inbound' => 'nullable|boolean', 'location_id' => 'nullable|integer|min:1', 'serial_nos' => 'sometimes|array|max:1000',
            'serial_nos.*' => 'required|string|max:120|distinct']);
        $commandId = $data['client_command_id'];
        unset($data['client_command_id']);
        $auth = app(AuthContextService::class);
        $row = $commands->run('material_delivery.recall', $id, $commandId, $data, $user, $auth->permissionCodes($user), $auth->isSuperAdmin($user))['result'];
        return response()->json(['message' => ($row['return_status'] ?? '') === 'approved' ? '配料单已收回并确认入库。' : '配料单已收回，待仓库确认入库。', 'data' => $row]);
    }

    public function sign(Request $request, int $id, MaterialDeliveryService $deliveries, WorkOrderQueryService $query)
    {
        [$user, , $amounts] = $this->user($request, self::SIGN);
        $data = $request->validate(['with_detail' => 'nullable|boolean']);
        $delivery = $deliveries->sign($id, $user);
        return response()->json(['message' => "已签收 {$delivery->delivery_no}。",
            'data' => ! empty($data['with_detail']) ? $query->detail((int) $delivery->work_order_id, $amounts) : $deliveries->row($delivery)]);
    }

    public function reject(Request $request, int $id, MaterialDeliveryService $deliveries, WorkOrderQueryService $query)
    {
        [$user, , $amounts] = $this->user($request, self::SIGN);
        $data = $request->validate(['reason' => 'required|string|max:500', 'with_detail' => 'nullable|boolean']);
        $delivery = $deliveries->reject($id, $data['reason'], $user);
        return response()->json(['message' => "已拒收 {$delivery->delivery_no}，等库管处理。",
            'data' => ! empty($data['with_detail']) ? $query->detail((int) $delivery->work_order_id, $amounts) : $deliveries->row($delivery)]);
    }

    /** @return array{0: object, 1: bool, 2: bool} 当前用户、能否配料发料、能否看金额 */
    private function user(Request $request, string|array $permission): array
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        if (! $user) throw new WorkOrderDomainException('unauthenticated', '请先登录 ERP。', 401);
        $super = $auth->isSuperAdmin($user);
        $codes = $auth->permissionCodes($user);
        if (! $super && ! array_intersect((array) $permission, $codes)) throw new WorkOrderDomainException('permission_denied', '当前账号没有这个配料配送操作的权限。', 403);
        $amounts = $super || in_array('sales_order.amount.view', $codes, true) || in_array('production.performance.manage', $codes, true) || in_array('inventory.balance.view', $codes, true);
        return [$user, $super || in_array(self::MANAGE, $codes, true), $amounts];
    }
}
