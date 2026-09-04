<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\PurchaseExchangeOrder;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\PurchaseExchangeApplicationService;
use Illuminate\Http\Request;

class PurchaseExchangeController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizePermission($request, 'purchase.exchange.view');
        $query = PurchaseExchangeOrder::query()
            ->with(['handling', 'sourceReceipt', 'purchaseOrder', 'supplier', 'item.unit', 'replacementReceipt'])
            ->latest('updated_at');

        foreach (['exchange_no', 'current_step'] as $field) {
            if ($request->filled($field)) $query->where($field, $request->input($field));
        }
        if ($request->filled('source_receipt_no')) {
            $query->whereHas('sourceReceipt', fn ($q) => $q->where('receipt_no', 'like', '%'.$request->input('source_receipt_no').'%'));
        }
        if ($request->filled('purchase_order_no')) {
            $query->whereHas('purchaseOrder', fn ($q) => $q->where('purchase_order_no', 'like', '%'.$request->input('purchase_order_no').'%'));
        }
        if ($request->filled('supplier_id')) $query->where('supplier_id', $request->integer('supplier_id'));
        if ($request->filled('keyword')) {
            $keyword = $request->input('keyword');
            $query->whereHas('item', fn ($q) => $q->where('item_code', 'like', "%{$keyword}%")->orWhere('item_name', 'like', "%{$keyword}%"));
        }
        if ($request->filled('date_from')) $query->whereDate('created_at', '>=', $request->input('date_from'));
        if ($request->filled('date_to')) $query->whereDate('created_at', '<=', $request->input('date_to'));

        $statsQuery = clone $query;
        $stats = [
            'pending_original_return' => (clone $statsQuery)->where('current_step', 'pending_original_return')->count(),
            'supplier_receipt_pending' => (clone $statsQuery)->where('current_step', 'supplier_receipt_pending')->count(),
            'pending_replacement_shipment' => (clone $statsQuery)->where('current_step', 'pending_replacement_shipment')->count(),
            'pending_replacement_acceptance' => (clone $statsQuery)->whereIn('current_step', ['replacement_in_transit', 'pending_replacement_acceptance'])->count(),
            'completed' => (clone $statsQuery)->where('current_step', 'completed')->count(),
        ];
        $perPage = max(10, min(100, (int) $request->input('per_page', 20)));
        return response()->json(['stats' => $stats, 'data' => $query->paginate($perPage)]);
    }

    public function show(Request $request, int $id, PurchaseExchangeApplicationService $service)
    {
        $this->authorizePermission($request, 'purchase.exchange.view');
        return response()->json(['data' => $service->load(PurchaseExchangeOrder::findOrFail($id))]);
    }

    public function action(Request $request, int $id, PurchaseExchangeApplicationService $service)
    {
        $this->authorizePermission($request, 'purchase.exchange.manage');
        $data = $request->validate([
            'action' => 'required|in:register_original_return,confirm_supplier_receipt,confirm_replacement_shipment,confirm_replacement_acceptance',
            'returned_base_qty' => 'nullable|numeric|min:0.00000001',
            'return_logistics_company' => 'nullable|string|max:120',
            'return_tracking_no' => 'nullable|string|max:120',
            'original_serial_numbers' => 'nullable|array',
            'original_serial_numbers.*' => 'nullable|string|max:120',
            'supplier_receiver' => 'nullable|string|max:80',
            'replacement_shipped_date' => 'nullable|date',
            'replacement_logistics_company' => 'nullable|string|max:120',
            'replacement_tracking_no' => 'nullable|string|max:120',
            'replacement_expected_date' => 'nullable|date',
            'result_description' => 'nullable|string|max:1000',
        ]);
        $operator = app(AuthContextService::class)->currentUser($request);
        $name = $operator?->nickname ?: $operator?->username ?: '系统';
        return response()->json(['message' => '换货单状态已更新', 'data' => $service->action($id, $data['action'], $data, $name)]);
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
