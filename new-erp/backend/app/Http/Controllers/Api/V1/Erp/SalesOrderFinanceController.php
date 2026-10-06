<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\SalesOrder;
use App\Services\Erp\{AuthContextService, SalesOrderFinanceQueryService, SalesOrderPurchaseLinkApplicationService};
use Illuminate\Http\Request;

class SalesOrderFinanceController extends Controller
{
    public function __construct(private readonly SalesOrderFinanceQueryService $query) {}

    public function overview(Request $request, int $id) { return response()->json(['data' => $this->query->overview($this->order($request, $id))]); }
    public function links(Request $request, int $id) { $this->order($request, $id); return response()->json($this->query->links($id, $this->perPage($request))); }
    public function candidates(Request $request, int $id)
    {
        $this->order($request, $id, true);
        return response()->json($this->query->candidates($request->validate(['keyword' => 'nullable|string|max:160', 'category_id' => 'nullable|integer|min:1', 'purchase_order_item_id' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:50'])));
    }
    public function categories(Request $request, int $id)
    {
        $this->order($request, $id, true);
        return response()->json(['data' => $this->query->categories()]);
    }
    public function add(Request $request, int $id, SalesOrderPurchaseLinkApplicationService $service)
    {
        $this->order($request, $id, true);
        $data = $request->validate(['version' => 'required|integer|min:1', 'purchase_order_item_id' => 'required|integer|min:1',
            'purchase_qty' => ['required', 'regex:/^\d{1,10}(\.\d{1,8})?$/'], 'idempotency_key' => 'required|string|max:100', 'reason' => 'required|string|max:500']);
        return response()->json(['data' => $this->query->linkPayload($service->add($id, $data, $this->operator($request)))]);
    }
    public function reverse(Request $request, int $id, int $linkId, SalesOrderPurchaseLinkApplicationService $service)
    {
        $this->order($request, $id, true);
        return response()->json(['data' => $this->query->linkPayload($service->reverse($id, $linkId, $request->validate(['version' => 'required|integer|min:1', 'reason' => 'required|string|max:500']), $this->operator($request)))]);
    }
    public function statistics(Request $request)
    {
        $user = $this->authorize($request);
        $filters = $request->validate(['keyword' => 'nullable|string|max:160', 'currency' => 'nullable|string|max:20',
            'order_status' => 'nullable|in:draft,confirmed,closed,cancelled', 'purchase_link_status' => 'nullable|in:linked,unlinked',
            'order_date_start' => 'nullable|date_format:Y-m-d',
            'order_date_end' => ['nullable', 'date_format:Y-m-d', ...($request->filled('order_date_start') ? ['after_or_equal:order_date_start'] : [])],
            'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:50']);
        return response()->json($this->query->statistics($user, $filters));
    }
    private function order(Request $request, int $id, bool $write = false): SalesOrder
    {
        return $this->query->visibleOrders($this->authorize($request, $write))->findOrFail($id);
    }
    private function authorize(Request $request, bool $write = false): object
    {
        $auth = app(AuthContextService::class); $user = $auth->currentUser($request);
        abort_unless($user, 401, '请先登录。');
        $permissions = ['sales_order.view', 'sales_order.amount.view', 'finance.view'];
        if ($write) $permissions[] = 'purchase.order.edit';
        abort_unless($auth->isSuperAdmin($user) || !array_diff($permissions, $auth->permissionCodes($user)), 403, '缺少订单金额、财务查看或采购关联维护权限。');
        return $user;
    }
    private function operator(Request $request): string
    {
        $user = app(AuthContextService::class)->currentUser($request);
        return (string) ($user->nickname ?? $user->username ?? '系统');
    }
    private function perPage(Request $request): int { return min(50, max(1, (int) $request->input('per_page', 10))); }
}
