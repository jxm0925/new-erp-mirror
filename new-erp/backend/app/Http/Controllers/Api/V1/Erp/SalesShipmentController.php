<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\SalesShipment;
use App\Services\Erp\SalesShipmentApplicationService;
use App\Services\Erp\SalesCostVisibilityService;
use App\Services\Erp\AuthContextService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class SalesShipmentController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizePermission($request, 'sales_order.shipment.view');
        $query = SalesShipment::query()
            ->with(['order:id,sales_order_no,customer_name', 'lines.orderLine:id,sales_order_id,line_no,product_name,sku_name,item_name', 'packages'])
            ->when($request->filled('sales_order_id'), fn ($q) => $q->where('sales_order_id', $request->integer('sales_order_id')))
            ->when($request->filled('shipment_status'), fn ($q) => $q->where('shipment_status', $request->input('shipment_status')))
            ->when($request->filled('keyword'), function ($q) use ($request) {
                $keyword = trim((string) $request->input('keyword'));
                $q->where(fn ($where) => $where->where('shipment_no', 'like', "%{$keyword}%")
                    ->orWhere('tracking_no', 'like', "%{$keyword}%"));
            })
            ->orderByDesc('id');
        $this->applySalesOrderVisibility($query, $request);

        return $this->costSafeJson(['data' => $query->paginate(min(max($request->integer('per_page', 20), 1), 100))]);
    }

    public function show(Request $request, int $id)
    {
        $this->authorizePermission($request, 'sales_order.shipment.view');
        $query = SalesShipment::query()->with([
            'order.lines', 'lines.orderLine.item', 'lines.reservation', 'packages', 'logs',
        ])->whereKey($id);
        $this->applySalesOrderVisibility($query, $request);
        return $this->costSafeJson(['data' => $query->firstOrFail()]);
    }

    public function store(Request $request, SalesShipmentApplicationService $service)
    {
        $this->authorizePermission($request, 'sales_order.shipment.create');
        $payload = $this->validateShipment($request);
        $this->assertSalesOrderVisible($request, (int) $payload['sales_order_id']);
        $shipment = $service->create((int) $payload['sales_order_id'], $payload, $this->operator($request));
        return $this->costSafeJson(['message' => '销售发货单已创建', 'data' => $shipment], 201);
    }

    public function confirm(Request $request, int $id, SalesShipmentApplicationService $service)
    {
        $this->authorizePermission($request, 'sales_order.shipment.confirm');
        $shipment = $service->confirm($this->visibleShipment($request, $id), $this->operator($request));
        return $this->costSafeJson(['message' => '销售发货单已确认，等待库存出库过账', 'data' => $shipment]);
    }

    public function postOutbound(Request $request, int $id, SalesShipmentApplicationService $service)
    {
        $this->authorizePermission($request, 'sales_order.shipment.post');
        $shipment = $service->postOutbound($this->visibleShipment($request, $id), $this->operator($request));
        return $this->costSafeJson(['message' => '销售出库已过账', 'data' => $shipment]);
    }

    public function dispatch(Request $request, int $id, SalesShipmentApplicationService $service)
    {
        $this->authorizePermission($request, 'sales_order.shipment.dispatch');
        $shipment = $service->dispatch($this->visibleShipment($request, $id), $this->operator($request));
        return $this->costSafeJson(['message' => '物流已发运', 'data' => $shipment]);
    }

    public function cancel(Request $request, int $id, SalesShipmentApplicationService $service)
    {
        $this->authorizePermission($request, 'sales_order.shipment.cancel');
        $data = $request->validate(['reason' => 'required|string|max:500']);
        $shipment = $service->cancel($this->visibleShipment($request, $id), $data['reason'], $this->operator($request));
        return $this->costSafeJson(['message' => '销售发货单已取消，库存预留已释放', 'data' => $shipment]);
    }

    public function destroyDraft(Request $request, int $id, SalesShipmentApplicationService $service)
    {
        $this->authorizePermission($request, 'sales_order.shipment.delete_draft');
        $service->deleteDraft($this->visibleShipment($request, $id));
        return $this->costSafeJson(['message' => '销售发货草稿已删除，订单库存锁定已恢复。']);
    }

    /** Shipment reads and action results share the sales cost boundary. */
    private function costSafeJson(mixed $payload, int $status = 200): \Illuminate\Http\JsonResponse
    {
        return response()->json(app(SalesCostVisibilityService::class)->redact($payload), $status);
    }

    private function validateShipment(Request $request): array
    {
        return $request->validate([
            'sales_order_id' => 'required|integer|exists:erp_sales_orders,id',
            'carrier_name' => 'nullable|string|max:120',
            'tracking_no' => 'nullable|string|max:120',
            'actual_freight_amount' => 'nullable|numeric|min:0',
            'remark' => 'nullable|string|max:2000',
            'lines' => 'required|array|min:1',
            'lines.*.sales_order_fulfillment_id' => 'required|integer|exists:erp_sales_order_fulfillments,id',
            'lines.*.base_qty' => 'required|numeric|min:0.00000001',
            'lines.*.inventory_serial_ids' => 'nullable|array',
            'lines.*.inventory_serial_ids.*' => 'integer|distinct|exists:erp_inventory_serials,id',
            'lines.*.remark' => 'nullable|string|max:500',
            'packages' => 'nullable|array',
            'packages.*.package_no' => 'nullable|string|max:80',
            'packages.*.weight' => 'nullable|numeric|min:0',
            'packages.*.volume' => 'nullable|numeric|min:0',
            'packages.*.carrier_name' => 'nullable|string|max:120',
            'packages.*.tracking_no' => 'nullable|string|max:120',
            'packages.*.freight_amount' => 'nullable|numeric|min:0',
            'packages.*.remark' => 'nullable|string|max:500',
        ]);
    }

    private function operator(Request $request): string
    {
        $user = app(AuthContextService::class)->currentUser($request);
        return (string) ($user?->nickname ?: $user?->username ?: $request->header('X-Operator') ?: '系统');
    }

    private function visibleShipment(Request $request, int $id): SalesShipment
    {
        $query = SalesShipment::query()->whereKey($id);
        $this->applySalesOrderVisibility($query, $request);
        return $query->firstOrFail();
    }

    private function assertSalesOrderVisible(Request $request, int $orderId): void
    {
        $query = \App\Models\Erp\SalesOrder::query()->whereKey($orderId);
        $user = app(AuthContextService::class)->currentUser($request);
        abort_unless($user, 401, '未登录或登录已过期。');
        app(\App\Services\Erp\SalesOrderVisibilityService::class)->apply($query, $user);
        abort_unless($query->exists(), 403, '无权操作该销售订单的发运单。');
    }

    /**
     * Shipment is a child business document of SalesOrder, so it must use the
     * same all/department/self data scope as order list, order details and
     * sales returns. Button permission alone must never expose other salespeople's
     * shipment or inventory-outbound facts.
     */
    private function applySalesOrderVisibility(Builder $query, Request $request): void
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        abort_unless($user, 401, '未登录或登录已过期。');
        $query->whereHas('order', fn (Builder $orders) => app(\App\Services\Erp\SalesOrderVisibilityService::class)->apply($orders, $user));
    }

    private function authorizePermission(Request $request, string $permission): object
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        abort_unless($user, 401, '未登录或登录已过期。');
        abort_unless(
            $auth->isSuperAdmin($user) || in_array($permission, $auth->permissionCodes($user), true),
            403,
            '无按钮权限：'.$permission,
        );
        return $user;
    }
}
