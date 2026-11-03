<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesErpPermissions;
use App\Services\Erp\PurchaseRequestSalesAllocationService;
use App\Services\Erp\PurchaseRequestProductionSourceService;
use Illuminate\Http\Request;

class PurchaseRequestSalesController extends Controller
{
    use AuthorizesErpPermissions;

    public function workOrderCandidates(Request $request, PurchaseRequestProductionSourceService $service)
    {
        $actor = $this->erpAuthorize($request, ['purchase.request.create', 'purchase.request.edit']);
        $data = $request->validate(['keyword' => 'nullable|string|max:160', 'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100', 'ids' => 'nullable|array|max:100', 'ids.*' => 'integer|min:1']);
        $query = $service->eligibleWorkOrders($actor);
        if (!empty($data['ids'])) $query->whereIn('id', $data['ids']);
        $keyword = trim((string) ($data['keyword'] ?? ''));
        if ($keyword !== '') $query->where(fn ($search) => $search->where('work_order_no', 'like', '%'.$keyword.'%')->orWhere('item_name', 'like', '%'.$keyword.'%'));
        return response()->json($query->latest('id')->paginate($data['per_page'] ?? 10)->through(fn ($order) => [
            'id' => $order->id, 'work_order_no' => $order->work_order_no, 'item_name' => $order->item_name,
            'product_name' => $order->product_name, 'status' => $order->status,
        ]));
    }

    public function candidates(Request $request, PurchaseRequestSalesAllocationService $service)
    {
        $actor = $this->erpAuthorize($request, ['purchase.request.create', 'purchase.request.edit']);
        $data = $request->validate(['keyword' => 'nullable|string|max:160', 'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100', 'ids' => 'nullable|array|max:100', 'ids.*' => 'integer|min:1']);
        $orders = $service->eligibleOrders($actor);
        if (!empty($data['ids'])) $orders->whereIn('id', $data['ids']);
        $keyword = trim((string) ($data['keyword'] ?? ''));
        if ($keyword !== '') $orders->where(fn ($query) => $query->where('sales_order_no', 'like', '%'.$keyword.'%')
            ->orWhere('customer_name', 'like', '%'.$keyword.'%'));
        return response()->json($orders->latest('id')->paginate($data['per_page'] ?? 10)->through(fn ($order) => [
            'id' => $order->id, 'sales_order_no' => $order->sales_order_no, 'customer_name' => $order->customer_name,
        ]));
    }
}
