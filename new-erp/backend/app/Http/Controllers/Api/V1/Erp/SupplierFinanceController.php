<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\SupplierFinanceQueryService;
use Illuminate\Http\Request;

class SupplierFinanceController extends Controller
{
    public function statistics(Request $request, SupplierFinanceQueryService $service)
    {
        $this->authorize($request);
        return response()->json($service->paginate($this->filters($request), $this->perPage($request)));
    }

    public function entries(Request $request, int $supplierId, SupplierFinanceQueryService $service)
    {
        $permissions = $this->authorize($request);
        $filters = $this->filters($request);
        if (in_array($filters['event_family'] ?? '', ['cash', 'allocation', 'void'], true)) {
            abort_unless($permissions['cash'], 403, '付款、收退款与核销明细需要财务查看权限。');
        }
        return response()->json($service->entries($supplierId, $filters, $this->perPage($request), $permissions['cash']));
    }

    private function authorize(Request $request): array
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        abort_unless($user, 401, '请先登录。');
        $super = $auth->isSuperAdmin($user);
        $permissions = $auth->permissionCodes($user);
        abort_unless($super || in_array('finance.supplier-ledger.view', $permissions, true), 403, '没有供应商往来查看权限。');
        return ['cash' => $super || in_array('finance.view', $permissions, true)];
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'supplier_id' => 'nullable|integer|min:1', 'supplier_keyword' => 'nullable|string|max:160',
            'currency' => 'nullable|string|max:10|regex:/^[A-Z]{3,10}$/',
            'period_start' => 'nullable|date_format:Y-m-d', 'period_end' => 'nullable|date_format:Y-m-d|after_or_equal:period_start',
            'business_date_start' => 'nullable|date_format:Y-m-d', 'business_date_end' => 'nullable|date_format:Y-m-d|after_or_equal:business_date_start',
            'payment_status' => 'nullable|in:unpaid,partial,paid,frozen,settled',
            'invoice_status' => 'nullable|in:unreceived,partial,received,not_required',
            'has_balance' => 'nullable|in:yes,no', 'only_prepayment' => 'nullable|boolean',
            'keyword' => 'nullable|string|max:160', 'event_family' => 'nullable|in:receipt,return,cash,allocation,void',
            'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100',
        ]);
    }

    private function perPage(Request $request): int
    {
        return min(100, max(1, (int) $request->input('per_page', 20)));
    }
}
