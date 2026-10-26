<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesReturn;
use App\Models\Erp\SalesReturnItem;
use App\Models\Erp\SalesReturnReceipt;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\SalesReturnApplicationService;
use App\Services\Erp\SalesReturnSettlementService;
use App\Services\Erp\SalesReturnIdentityService;
use App\Services\Erp\SalesCostVisibilityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class SalesReturnController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizePermission($request, 'sales_return.view');
        $query = SalesReturn::query()
            ->with(['order', 'customer', 'items.item', 'items.baseUnit',
                $this->canViewAmounts($request) ? 'terms' : 'terms:id,sales_return_id,return_type'])
            ->latest('updated_at');
        $this->applyOrderVisibility($query, $request);

        if ($request->filled('keyword')) {
            $keyword = trim((string) $request->input('keyword'));
            $query->where(function ($builder) use ($keyword): void {
                $builder->where('return_no', 'like', "%{$keyword}%")
                    ->orWhereHas('order', fn ($order) => $order
                        ->where('sales_order_no', 'like', "%{$keyword}%")
                        ->orWhere('origin_order_no', 'like', "%{$keyword}%"));
            });
        }
        if ($request->filled('order_no')) {
            $orderNo = trim((string) $request->input('order_no'));
            $query->whereHas('order', fn ($order) => $order->where(fn ($builder) => $builder
                ->where('sales_order_no', 'like', "%{$orderNo}%")
                ->orWhere('origin_order_no', 'like', "%{$orderNo}%")));
        }
        if ($request->filled('customer_keyword')) {
            $customerKeyword = trim((string) $request->input('customer_keyword'));
            // 退货单本身不存客户名/订单日期/发货状态，这些条件都落在原销售订单上。
            $query->whereHas('order', fn ($order) => $order->where(fn ($builder) => $builder
                ->where('customer_name', 'like', "%{$customerKeyword}%")
                ->orWhere('customer_phone', 'like', "%{$customerKeyword}%")));
        }
        if ($request->filled('order_date_from')) $query->whereHas('order', fn ($order) => $order->whereDate('order_date', '>=', $request->input('order_date_from')));
        if ($request->filled('order_date_to')) $query->whereHas('order', fn ($order) => $order->whereDate('order_date', '<=', $request->input('order_date_to')));
        if ($request->filled('shipment_status')) $query->whereHas('order', fn ($order) => $order->where('shipment_status', $request->input('shipment_status')));
        if ($request->filled('return_type')) $query->whereHas('terms', fn ($terms) => $terms->where('return_type', $request->input('return_type')));
        if ($request->filled('return_status')) $query->where('return_status', $request->input('return_status'));
        if ($request->filled('customer_id')) $query->where('customer_id', $request->integer('customer_id'));
        if ($request->filled('sales_user_legacy_id')) {
            $salesUserId = $request->integer('sales_user_legacy_id');
            $query->whereHas('order', fn ($order) => $order->where('sales_user_legacy_id', $salesUserId));
        }
        if ($request->filled('date_from')) $query->whereDate('return_date', '>=', $request->input('date_from'));
        if ($request->filled('date_to')) $query->whereDate('return_date', '<=', $request->input('date_to'));

        return $this->costSafeJson($query->paginate($this->perPage($request)));
    }

    public function sources(Request $request)
    {
        $this->authorizePermission($request, 'sales_return.view');
        $query = SalesOrder::query()
            ->with(['lines' => fn ($lines) => $lines
                ->where('shipped_qty', '>', 0)
                ->with(['item.unit', 'sku', 'product'])])
            ->whereHas('lines', fn ($lines) => $lines->where('shipped_qty', '>', 0))
            ->latest('updated_at');
        $this->applySalesOrderVisibility($query, $request);

        if ($request->filled('keyword')) {
            $keyword = trim((string) $request->input('keyword'));
            $query->where(function ($builder) use ($keyword): void {
                $builder->where('sales_order_no', 'like', "%{$keyword}%")
                    ->orWhere('origin_order_no', 'like', "%{$keyword}%")
                    ->orWhere('customer_name', 'like', "%{$keyword}%")
                    ->orWhere('customer_phone', 'like', "%{$keyword}%");
            });
        }

        $paginator = $query->paginate($this->perPage($request));
        $paginator->setCollection($paginator->getCollection()->map(function (SalesOrder $order): array {
            return [
                'id' => $order->id,
                'sales_order_no' => $order->sales_order_no,
                'origin_order_no' => $order->origin_order_no,
                'order_date' => $order->order_date?->toDateString(),
                'order_amount' => (float) $order->total_amount,
                'shipped_amount' => (float) $order->lines->sum(fn ($line) => (float) $line->shipped_qty * (float) $line->unit_price),
                'returned_amount' => (float) SalesReturnItem::query()
                    ->join('erp_sales_returns', 'erp_sales_returns.id', '=', 'erp_sales_return_items.sales_return_id')
                    ->join('erp_sales_order_lines', 'erp_sales_order_lines.id', '=', 'erp_sales_return_items.sales_order_line_id')
                    ->where('erp_sales_returns.sales_order_id', $order->id)
                    ->whereNotIn('erp_sales_returns.return_status', ['cancelled', 'closed'])
                    ->sum(\Illuminate\Support\Facades\DB::raw('erp_sales_return_items.requested_sales_qty * erp_sales_order_lines.unit_price')),
                'customer_id' => $order->customer_id,
                'customer_name' => $order->customer_name,
                'customer_phone' => $order->customer_phone,
                'shipment_status' => $order->shipment_status,
                'lines' => $order->lines->map(function ($line): array {
                    $reserved = (float) SalesReturnItem::query()
                        ->join('erp_sales_returns', 'erp_sales_returns.id', '=', 'erp_sales_return_items.sales_return_id')
                        ->where('erp_sales_return_items.sales_order_line_id', $line->id)
                        ->whereNotIn('erp_sales_returns.return_status', ['cancelled', 'closed'])
                        ->sum('erp_sales_return_items.requested_sales_qty');

                    return [
                        'sales_order_line_id' => $line->id,
                        'product_name' => $line->product_name,
                        'sku_name' => $line->sku_name,
                        'item_id' => $line->item_id,
                        'item_code' => $line->item?->item_code,
                        'item_name' => $line->item_name ?: $line->item?->item_name,
                        'base_unit_id' => $line->item_base_unit_id ?: $line->item?->unit_id,
                        'base_unit_name' => $line->item?->unit?->unit_name,
                        'shipped_qty' => (float) $line->shipped_qty,
                        'reserved_return_qty' => $reserved,
                        'available_return_qty' => max(0, (float) $line->shipped_qty - $reserved),
                        'fulfillment_factor_snapshot' => (float) ($line->fulfillment_factor_snapshot ?: 1),
                        // 退货单价默认值：原订单行含税净单价（销售单位）。
                        'refund_unit_price' => (float) SalesReturnSettlementService::lineUnitPrice($line),
                    ];
                })->values(),
            ];
        }));

        return $this->costSafeJson($paginator);
    }

    public function show(Request $request, int $id)
    {
        $this->authorizePermission($request, 'sales_return.view');
        $query = SalesReturn::with([
            'order',
            'customer',
            'items.orderLine',
            'items.fulfillment',
            'items.item',
            'items.baseUnit',
            'items.costAllocations.shipment',
            'items.costAllocations.outboundTransactionItem.transaction',
            'receipts.items.item',
            'receipts.items.warehouse',
            'receipts.items.location',
            'terms',
            'items.credit',
            'dispositions',
            'logs',
        ])->whereKey($id);
        $this->applyOrderVisibility($query, $request);
        $return = $query->firstOrFail();
        $data = $return->toArray();
        // 前端据此决定收货/处置时是否必须逐件选择序列号。
        $identities = app(SalesReturnIdentityService::class);
        foreach ($return->items as $index => $item) $data['items'][$index]['serial_required'] = $identities->requiresIdentities($item);
        // 金额口径只给有销售金额/财务权限的人；仓库收货人员只看数量与处置。
        if ($this->canViewAmounts($request)) {
            $data['settlement'] = app(SalesReturnSettlementService::class)->summary($return);
        } else {
            unset($data['terms']['credit_amount']);
            foreach ($data['items'] as &$item) unset($item['credit']);
            unset($item);
        }
        return $this->costSafeJson($data);
    }

    public function orderReturns(Request $request, int $orderId)
    {
        $this->authorizePermission($request, 'sales_return.view');
        $orderQuery = SalesOrder::query()->whereKey($orderId);
        $this->applySalesOrderVisibility($orderQuery, $request);
        abort_unless($orderQuery->exists(), 403, '无权查看该订单的退货记录。');

        return $this->costSafeJson(
            SalesReturn::query()
                ->with(['items.item', 'receipts'])
                ->where('sales_order_id', $orderId)
                ->latest('updated_at')
                ->paginate($this->perPage($request))
        );
    }

    public function store(Request $request, SalesReturnApplicationService $service)
    {
        $this->authorizePermission($request, 'sales_return.create');
        $payload = $request->validate([
            'reservation_token' => 'nullable|uuid',
            'creation_session_id' => 'nullable|uuid',
            'sales_order_id' => 'required|exists:erp_sales_orders,id',
            'return_date' => 'nullable|date',
            'return_reason' => 'required|string|max:160',
            'return_type' => 'nullable|in:refund,exchange',
            'remark' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.sales_order_line_id' => 'required|exists:erp_sales_order_lines,id',
            'items.*.fulfillment_id' => 'nullable|exists:erp_sales_order_fulfillments,id',
            'items.*.requested_sales_qty' => 'required|numeric|min:0.00000001',
            'items.*.refund_unit_price' => 'nullable|numeric|min:0',
            'items.*.remark' => 'nullable|string',
        ]);
        $orderQuery = SalesOrder::query()->whereKey($payload['sales_order_id']);
        $this->applySalesOrderVisibility($orderQuery, $request);
        abort_unless($orderQuery->exists(), 403, '无权为该销售订单创建退货单。');
        [$operatorId, $operatorName] = $this->operator($request);

        return $this->costSafeJson([
            'message' => '销售退货单已保存',
            'data' => $service->create($payload, $operatorId, $operatorName),
        ], 201);
    }

    public function confirm(Request $request, int $id, SalesReturnApplicationService $service)
    {
        $this->authorizePermission($request, 'sales_return.confirm');
        $this->assertReturnVisible($request, $id);
        return $this->costSafeJson([
            'message' => '销售退货单已确认，等待客户寄回',
            'data' => $service->confirm($id, ...$this->operator($request)),
        ]);
    }

    public function receive(Request $request, int $id, SalesReturnApplicationService $service)
    {
        $this->authorizePermission($request, 'sales_return.receive');
        $this->assertReturnVisible($request, $id);
        $payload = $request->validate([
            'reservation_token' => 'nullable|uuid',
            'creation_session_id' => 'nullable|uuid',
            'receipt_date' => 'nullable|date',
            'remark' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.sales_return_item_id' => 'required|exists:erp_sales_return_items,id',
            'items.*.received_base_qty' => 'required|numeric|min:0.00000001',
            'items.*.restock_base_qty' => 'nullable|numeric|min:0',
            'items.*.pending_base_qty' => 'nullable|numeric|min:0',
            'items.*.scrap_base_qty' => 'nullable|numeric|min:0',
            'items.*.rejected_base_qty' => 'nullable|numeric|min:0',
            'items.*.warehouse_id' => 'nullable|exists:erp_warehouses,id',
            'items.*.location_id' => 'nullable|exists:erp_locations,id',
            'items.*.batch_no' => 'nullable|string|max:80',
            'items.*.inspection_remark' => 'nullable|string',
            'items.*.serial_dispositions' => 'nullable|array:restock,pending,scrap,rejected',
            'items.*.serial_dispositions.*' => 'array|max:1000',
            'items.*.serial_dispositions.*.*' => 'integer|min:1|distinct',
        ]);
        $payload['sales_return_id'] = $id;

        return $this->costSafeJson([
            'message' => '销售退货到货已确认',
            'data' => $service->receive($payload, ...$this->operator($request)),
        ], 201);
    }

    public function postReceipt(Request $request, int $id, int $receiptId, SalesReturnApplicationService $service)
    {
        $this->authorizePermission($request, 'sales_return.post');
        $this->assertReturnVisible($request, $id);
        $receipt = SalesReturnReceipt::query()->where('sales_return_id', $id)->findOrFail($receiptId);
        return $this->costSafeJson([
            'message' => '销售退货重新入库完成',
            'data' => $service->postReceipt($receipt->id, ...$this->operator($request)),
        ]);
    }

    /** 收货候选序列：只列该退货行原发货、当前仍在客户手里的序列。 */
    public function serialCandidates(Request $request, int $id, SalesReturnIdentityService $identities)
    {
        $this->authorizePermission($request, 'sales_return.receive');
        $this->assertReturnVisible($request, $id);
        $filters = $request->validate(['return_item_id' => 'required|integer|min:1', 'keyword' => 'nullable|string|max:120',
            'batch_no' => 'nullable|string|max:80', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        return response()->json($identities->candidates($id, (int) $filters['return_item_id'], $filters));
    }

    /** 到货单逐件追溯：序列号、处理去向、原发货单与入库状态（不含成本）。 */
    public function receiptSerials(Request $request, int $id, int $receiptId, SalesReturnIdentityService $identities)
    {
        $this->authorizePermission($request, 'sales_return.view');
        $this->assertReturnVisible($request, $id);
        SalesReturnReceipt::query()->where('sales_return_id', $id)->findOrFail($receiptId);
        $filters = $request->validate(['line_id' => 'nullable|integer|min:1', 'disposition' => 'nullable|in:restock,pending,scrap,rejected',
            'keyword' => 'nullable|string|max:120', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        return response()->json($identities->receiptIdentities($receiptId, $filters));
    }

    public function dispose(Request $request, int $id, SalesReturnApplicationService $service)
    {
        $this->authorizePermission($request, 'sales_return.receive');
        $this->assertReturnVisible($request, $id);
        $payload = $request->validate([
            'receipt_item_id' => 'required|integer|exists:erp_sales_return_receipt_items,id',
            'disposition' => 'required|in:restock,scrap,rejected',
            'base_qty' => 'required|numeric|min:0.00000001',
            'warehouse_id' => 'nullable|exists:erp_warehouses,id',
            'location_id' => 'nullable|exists:erp_locations,id',
            'batch_no' => 'nullable|string|max:80',
            'serial_ids' => 'nullable|array|max:1000',
            'serial_ids.*' => 'integer|min:1|distinct',
            'remark' => 'nullable|string|max:500',
        ]);
        return $this->costSafeJson([
            'message' => '待处理数量已处置',
            'data' => $service->disposePending($id, $payload, ...$this->operator($request)),
        ]);
    }

    public function cancel(Request $request, int $id, SalesReturnApplicationService $service)
    {
        $this->authorizePermission($request, 'sales_return.cancel');
        $this->assertReturnVisible($request, $id);
        return $this->costSafeJson([
            'message' => '销售退货单已取消',
            'data' => $service->cancel($id, ...$this->operator($request)),
        ]);
    }

    public function close(Request $request, int $id, SalesReturnApplicationService $service)
    {
        $this->authorizePermission($request, 'sales_return.close');
        $this->assertReturnVisible($request, $id);
        return $this->costSafeJson([
            'message' => '销售退货单已关闭',
            'data' => $service->close($id, ...$this->operator($request)),
        ]);
    }

    public function destroy(Request $request, int $id, SalesReturnApplicationService $service)
    {
        $this->authorizePermission($request, 'sales_return.delete');
        $visible = SalesReturn::query()->whereKey($id);
        $this->applyOrderVisibility($visible, $request);
        abort_unless($visible->exists(), 403, '无权删除该销售退货草稿。');
        $service->deleteDraft($id);
        return $this->costSafeJson(['message' => '销售退货草稿已删除。']);
    }

    /** Return reads and warehouse action results must never expose cost facts. */
    private function costSafeJson(mixed $payload, int $status = 200): \Illuminate\Http\JsonResponse
    {
        return response()->json(app(SalesCostVisibilityService::class)->redact($payload), $status);
    }

    private function assertReturnVisible(Request $request, int $id): void
    {
        $query = SalesReturn::query()->whereKey($id);
        $this->applyOrderVisibility($query, $request);
        abort_unless($query->exists(), 403, '当前退货单不在可操作的数据范围内。');
    }

    private function canViewAmounts(Request $request): bool
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        return $user && ($auth->isSuperAdmin($user) || array_intersect(['sales_order.amount.view', 'finance.view'], $auth->permissionCodes($user)) !== []);
    }

    private function operator(Request $request): array
    {
        $user = app(AuthContextService::class)->currentUser($request);
        return [$user?->legacy_id, $user?->nickname ?: $user?->username];
    }

    private function authorizePermission(Request $request, string $permission): object
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        abort_unless($user, 401, '未登录或登录已过期。');
        abort_unless(
            $auth->isSuperAdmin($user) || in_array($permission, $auth->permissionCodes($user), true),
            403,
            '无按钮权限：'.$permission
        );

        return $user;
    }

    private function applyOrderVisibility(Builder $query, Request $request): void
    {
        $query->whereHas('order', function (Builder $orders) use ($request): void {
            $this->applySalesOrderVisibility($orders, $request);
        });
    }

    private function applySalesOrderVisibility(Builder $query, Request $request): void
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        abort_unless($user, 401, '未登录或登录已过期。');
        app(\App\Services\Erp\SalesOrderVisibilityService::class)->apply($query, $user);
    }

    private function perPage(Request $request): int
    {
        return min(100, max(10, (int) $request->input('per_page', 10)));
    }
}
