<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\ConsoleDashboardQueryService;
use Illuminate\Http\Request;

class ConsoleDashboardController extends Controller
{
    public function show(Request $request, ConsoleDashboardQueryService $query)
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        abort_unless($user, 401, '请先登录。');
        $filters = $request->validate([
            'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100',
            'type' => 'nullable|in:requests,plans,orders,receipts,adjustments,boms',
            'priority' => 'nullable|in:high',
        ]);
        return response()->json(['data' => $query->dashboard($filters, $auth->permissionCodes($user), $auth->isSuperAdmin($user))]);
    }
}
