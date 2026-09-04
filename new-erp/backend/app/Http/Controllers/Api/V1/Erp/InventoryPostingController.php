<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\{InventoryTransaction, PurchaseReceipt};
use App\Services\Erp\AuthContextService;
use App\Services\Erp\InventoryService;
use App\Services\Erp\PurchaseReceiptPostingEligibilityService;
use App\Services\Erp\PurchaseReceiptPostingRepairApplicationService;
use Illuminate\Http\Request;

class InventoryPostingController extends Controller
{
    public function pendingReceipts(Request $request, PurchaseReceiptPostingEligibilityService $eligibility)
    {
        $this->authorizePermission($request, 'inventory.post.view');
        $query = PurchaseReceipt::with(['supplier', 'order', 'items.item.unit', 'items.warehouse', 'items.location', 'items.allocations.warehouse', 'items.allocations.location'])
            ->where('stock_post_status', 'pending')
            ->where('receipt_status', 'confirmed')
            ->where('confirm_status', 'confirmed')
            ->whereHas('items', fn ($q) => $q
                ->where('is_stock_item_snapshot', true)
                ->where('final_stockable_base_qty', '>', 0))
            ->latest('updated_at');

        if ($request->filled('keyword')) {
            $keyword = $request->input('keyword');
            $query->where(function ($q) use ($keyword) {
                $q->where('receipt_no', 'like', "%{$keyword}%")
                    ->orWhereHas('order', fn ($oq) => $oq->where('purchase_order_no', 'like', "%{$keyword}%"));
            });
        }
        if ($request->filled('supplier_id')) $query->where('supplier_id', $request->input('supplier_id'));
        if ($request->filled('warehouse_id')) $query->whereHas('items', fn ($q) => $q->where('warehouse_id', $request->input('warehouse_id')));
        if ($request->filled('date_from')) $query->whereDate('receipt_date', '>=', $request->input('date_from'));
        if ($request->filled('date_to')) $query->whereDate('receipt_date', '<=', $request->input('date_to'));

        $page = $query->paginate($this->perPage($request));
        $page->getCollection()->transform(function (PurchaseReceipt $receipt) use ($eligibility): PurchaseReceipt {
            $receipt->setAttribute('posting_eligibility', $eligibility->evaluate($receipt));
            return $receipt;
        });
        $payload = $page->toArray();
        $payload['stats'] = [
            'posted_today' => InventoryTransaction::query()
                ->where('source_type', 'purchase_receipt')
                ->where('transaction_type', 'purchase_receipt_posting')
                ->where('posting_status', 'posted')
                ->whereDate('posted_at', now()->toDateString())
                ->distinct('source_id')
                ->count('source_id'),
        ];

        return response()->json($payload);
    }

    public function showReceipt(Request $request, int $id)
    {
        $this->authorizePermission($request, 'inventory.post.view');
        return response()->json(PurchaseReceipt::with(['supplier', 'order', 'items.item.unit', 'items.warehouse', 'items.location', 'items.allocations.warehouse', 'items.allocations.location'])->findOrFail($id));
    }

    public function repairReceiptAllocations(
        Request $request,
        int $id,
        PurchaseReceiptPostingRepairApplicationService $service,
    ) {
        $this->authorizePermission($request, 'inventory.post.repair');
        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.receipt_item_id' => 'required|integer',
            'items.*.allocations' => 'required|array|min:1',
            'items.*.allocations.*.warehouse_id' => 'required|integer|exists:erp_warehouses,id',
            'items.*.allocations.*.location_id' => 'required|integer|exists:erp_locations,id',
            'items.*.allocations.*.base_qty' => 'required|numeric|gt:0',
            'items.*.allocations.*.serial_nos' => 'nullable|array',
            'items.*.allocations.*.serial_nos.*' => 'string|max:100',
        ]);

        $receipt = $service->repair($id, $data['items'], $request->user()?->name ?: $request->user()?->username);

        return response()->json(['message' => '入库分配已补充，可返回工作台执行过账。', 'data' => $receipt]);
    }

    public function postReceipt(Request $request, int $id, InventoryService $service)
    {
        $this->authorizePermission($request, 'inventory.post.execute');
        $transaction = $service->postPurchaseReceipt($id);
        return response()->json(['message' => '库存过账成功', 'data' => $transaction]);
    }

    private function perPage(Request $request): int
    {
        return min(100, max(10, (int) $request->input('per_page', 20)));
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
