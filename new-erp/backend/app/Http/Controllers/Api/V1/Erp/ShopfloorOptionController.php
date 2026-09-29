<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Services\Erp\{AuthContextService, ShopfloorOptionQueryService};
use Illuminate\Http\Request;

final class ShopfloorOptionController extends Controller
{
    public function index(Request $request, string $type, ShopfloorOptionQueryService $service)
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        abort_unless($user, 401, '请先登录。');
        $filters = $request->validate([
            'keyword' => 'nullable|string|max:160',
            'category_id' => 'nullable|integer|min:1',
            'status' => 'nullable|string|in:RELEASED,IN_PROGRESS',
            'output_item_id' => 'nullable|integer|min:1', 'routing_id' => 'nullable|integer|min:1',
            'target_routing_operation_id' => 'nullable|integer|min:1',
            'reserved_for_work_order_id' => 'nullable|integer|min:1',
            'reserved_for_production_unit_id' => 'nullable|integer|min:1',
            'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:50',
        ]);
        return response()->json($service->options($type, $filters, $auth->permissionCodes($user), $auth->isSuperAdmin($user), $user));
    }
}
