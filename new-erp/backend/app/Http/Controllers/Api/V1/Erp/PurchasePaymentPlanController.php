<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\PurchasePaymentPlanApplicationService;
use App\Services\Erp\PurchasePaymentPlanQueryService;
use Illuminate\Http\Request;

class PurchasePaymentPlanController extends Controller
{
    public function show(Request $request, int $id, PurchasePaymentPlanQueryService $query)
    {
        $this->authorize($request, ['purchase.order.view', 'finance.view']);
        return response()->json(['data' => $query->forOrder($id)]);
    }

    public function update(Request $request, int $id, PurchasePaymentPlanApplicationService $service)
    {
        $user = $this->authorize($request, ['purchase.order.edit']);
        $data = $request->validate(PurchasePaymentPlanApplicationService::rules());
        return response()->json(['data' => $service->save($id, $data, $user->legacy_id, $this->name($user))]);
    }

    public function orders(Request $request, PurchasePaymentPlanQueryService $query)
    {
        $this->authorize($request, ['finance.view']);
        return response()->json($query->listing($this->filters($request), $this->perPage($request), false));
    }

    public function statistics(Request $request, PurchasePaymentPlanQueryService $query)
    {
        $this->authorize($request, ['finance.view']);
        return response()->json($query->listing($this->filters($request), $this->perPage($request), true));
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'keyword' => 'nullable|string|max:160', 'supplier_id' => 'nullable|integer|min:1', 'currency' => 'nullable|string|max:10',
            'payment_status' => 'nullable|in:unpaid,partial,paid,overpaid',
            'trigger_type' => 'nullable|in:'.implode(',', PurchasePaymentPlanApplicationService::TRIGGERS),
            'order_date_start' => 'nullable|date_format:Y-m-d',
            'order_date_end' => ['nullable', 'date_format:Y-m-d', ...($request->filled('order_date_start') ? ['after_or_equal:order_date_start'] : [])],
            'due_date_start' => 'nullable|date_format:Y-m-d',
            'due_date_end' => ['nullable', 'date_format:Y-m-d', ...($request->filled('due_date_start') ? ['after_or_equal:due_date_start'] : [])],
            'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100',
        ]);
    }

    private function authorize(Request $request, array $permissions): object
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        abort_unless($user, 401, '请先登录。');
        abort_unless($auth->isSuperAdmin($user) || array_intersect($permissions, $auth->permissionCodes($user)), 403, '当前用户没有执行此采购付款操作的权限。');
        return $user;
    }

    private function name(object $user): string
    {
        return (string) ($user->nickname ?: $user->username ?: '系统');
    }

    private function perPage(Request $request): int
    {
        return min(100, max(1, (int) $request->input('per_page', 20)));
    }
}
