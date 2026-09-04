<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesReturn;
use App\Models\Erp\SalesReturnItem;
use App\Models\Erp\SalesReturnReceipt;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\SalesReturnApplicationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class SalesReturnController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizePermission($request, 'sales_return.view');
        $query = SalesReturn::query()
            ->with(['order', 'customer', 'items.item', 'items.baseUnit'])
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
            $query->where(function ($builder) use ($orderNo): void {
                $builder->where('sales_order_no', 'like', "%{$orderNo}%")
                    ->orWhere('origin_order_no', 'like', "%{$orderNo}%");
            });
        }
        if ($request->filled('customer_keyword')) {
            $customerKeyword = trim((string) $request->input('customer_keyword'));
            $query->where(function ($builder) use ($customerKeyword): void {
                $builder->where('customer_name', 'like', "%{$customerKeyword}%")
                    ->orWhere('customer_phone', 'like', "%{$customerKeyword}%");
            });
        }
        if ($request->filled('order_date_from')) $query->whereDate('order_date', '>=', $request->input('order_date_from'));
        if ($request->filled('order_date_to')) $query->whereDate('order_date', '<=', $request->input('order_date_to'));
        if ($request->filled('shipment_status')) $query->where('shipment_status', $request->input('shipment_status'));
        if ($request->filled('return_status')) $query->where('return_status', $request->input('return_status'));
        if ($request->filled('customer_id')) $query->where('customer_id', $request->integer('customer_id'));
        if ($request->filled('sales_user_legacy_id')) {
            $salesUserId = $request->integer('sales_user_legacy_id');
            $query->whereHas('order', fn ($order) => $order->where('sales_user_legacy_id', $salesUserId));
        }
        if ($request->filled('date_from')) $query->whereDate('return_date', '>=', $request->input('date_from'));
        if ($request->filled('date_to')) $query->whereDate('return_date', '<=', $request->input('date_to'));

        return response()->json($query->paginate($this->perPage($request)));
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
                    ];
                })->values(),
            ];
        }));

        return response()->json($paginator);
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
            'logs',
        ])->whereKey($id);
        $this->applyOrderVisibility($query, $request);
        return response()->json($query->firstOrFail());
    }

    public function orderReturns(Request $request, int $orderId)
    {
        $this->authorizePermission($request, 'sales_return.view');
        $orderQuery = SalesOrder::query()->whereKey($orderId);
        $this->applySalesOrderVisibility($orderQuery, $request);
        abort_unless($orderQuery->exists(), 403, '无权查看该订单的退货记录。');

        return response()->json(
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
            'remark' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.sales_order_line_id' => 'required|exists:erp_sales_order_lines,id',
            'items.*.fulfillment_id' => 'nullable|exists:erp_sales_order_fulfillments,id',
            'items.*.requested_sales_qty' => 'required|numeric|min:0.00000001',
            'items.*.remark' => 'nullable|string',
        ]);
        $orderQuery = SalesOrder::query()->whereKey($payload['sales_order_id']);
        $this->applySalesOrderVisibility($orderQuery, $request);
        abort_unless($orderQuery->exists(), 403, '无权为该销售订单创建退货单。');
        [$operatorId, $operatorName] = $this->operator($request);

        return response()->json([
            'message' => '销售退货单已保存',
            'data' => $service->create($payload, $operatorId, $operatorName),
        ], 201);
    }

    public function confirm(Request $request, int $id, SalesReturnApplicationService $service)
    {
        $this->authorizePermission($request, 'sales_return.confirm');
        return response()->json([
            'message' => '销售退货单已确认，等待客户寄回',
            'data' => $service->confirm($id, ...$this->operator($request)),
        ]);
    }

    public function receive(Request $request, int $id, SalesReturnApplicationService $service)
    {
        $this->authorizePermission($request, 'sales_return.receive');
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
        ]);
        $payload['sales_return_id'] = $id;

        return response()->json([
            'message' => '销售退货到货已确认',
            'data' => $service->receive($payload, ...$this->operator($request)),
        ], 201);
    }

    public function postReceipt(Request $request, int $id, int $receiptId, SalesReturnApplicationService $service)
    {
        $this->authorizePermission($request, 'sales_return.post');
        $receipt = SalesReturnReceipt::query()->where('sales_return_id', $id)->findOrFail($receiptId);
        return response()->json([
            'message' => '销售退货重新入库完成',
            'data' => $service->postReceipt($receipt->id, ...$this->operator($request)),
        ]);
    }

    public function cancel(Request $request, int $id, SalesReturnApplicationService $service)
    {
        $this->authorizePermission($request, 'sales_return.cancel');
        return response()->json([
            'message' => '销售退货单已取消',
            'data' => $service->cancel($id, ...$this->operator($request)),
        ]);
    }

    public function close(Request $request, int $id, SalesReturnApplicationService $service)
    {
        $this->authorizePermission($request, 'sales_return.close');
        return response()->json([
            'message' => '销售退货单已关闭',
            'data' => $service->close($id, ...$this->operator($request)),
        ]);
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
        if ($auth->isSuperAdmin($user) || $auth->dataScope($user) === 'all') return;

        if ($auth->dataScope($user) === 'department') {
            $query->whereIn('sales_user_legacy_id', $auth->departmentUserIds($user));
            return;
        }

        $legacyId = (int) $user->legacy_id;
        $query->where(function (Builder $scope) use ($legacyId): void {
            $scope->where('sales_user_legacy_id', $legacyId)
                ->orWhere(function (Builder $fallback) use ($legacyId): void {
                    $fallback->whereNull('sales_user_legacy_id')
                        ->where('created_by_legacy_id', $legacyId);
                });
        });
    }

    private function perPage(Request $request): int
    {
        return min(100, max(10, (int) $request->input('per_page', 20)));
    }
}
