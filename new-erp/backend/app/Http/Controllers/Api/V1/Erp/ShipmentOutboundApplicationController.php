<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\ShipmentOutboundApplicationService;
use Illuminate\Http\Request;

final class ShipmentOutboundApplicationController extends Controller
{
    public function index(Request $request, ShipmentOutboundApplicationService $applications)
    {
        $actor = $this->reviewer($request);
        $filters = $request->validate([
            'status' => 'nullable|in:pending,processed',
            'keyword' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|in:10',
        ]);
        return response()->json(['data' => $applications->reviewList($filters, $actor)]);
    }

    public function show(Request $request, int $id, ShipmentOutboundApplicationService $applications)
    {
        return response()->json(['data' => $applications->reviewDetail($id, $this->reviewer($request))]);
    }

    public function review(Request $request, int $id, ShipmentOutboundApplicationService $applications)
    {
        $actor = $this->reviewer($request);
        $payload = $request->validate([
            'decision' => 'required|in:approve,reject',
            'reason' => 'required_if:decision,reject|nullable|string|max:500',
            'client_command_id' => 'required|string|max:100',
        ]);
        return response()->json(['data' => $applications->review($id, $payload, $actor)]);
    }

    private function reviewer(Request $request): object
    {
        $auth = app(AuthContextService::class);
        $actor = $auth->currentUser($request);
        abort_unless($actor, 401, '未登录或登录已过期。');
        abort_unless($auth->isSuperAdmin($actor) || in_array('sales_order.shipment.post', $auth->permissionCodes($actor), true),
            403, '没有发货出库审核权限。');
        return $actor;
    }
}
