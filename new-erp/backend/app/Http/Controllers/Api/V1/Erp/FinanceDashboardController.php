<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\FinanceDashboardQueryService;
use Illuminate\Http\Request;

class FinanceDashboardController extends Controller
{
    public function show(Request $request, FinanceDashboardQueryService $service)
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        abort_unless($user, 401, '请先登录。');
        $super = $auth->isSuperAdmin($user);
        $codes = $auth->permissionCodes($user);
        abort_unless($super || in_array('finance.view', $codes, true), 403, '没有财务查看权限。');
        $filters = $request->validate([
            'currency' => 'nullable|string|regex:/^[A-Z]{3,10}$/',
            'period_start' => 'nullable|date_format:Y-m-d',
            'period_end' => 'nullable|date_format:Y-m-d',
        ]);
        return response()->json(['data' => $service->dashboard($filters, [
            'payables' => $super || in_array('finance.payable.view', $codes, true),
            'suppliers' => $super || in_array('finance.supplier-ledger.view', $codes, true),
        ])]);
    }
}
