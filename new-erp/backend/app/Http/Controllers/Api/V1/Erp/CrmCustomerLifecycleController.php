<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\Crm\CrmCustomerAccessService;
use App\Services\Erp\Crm\CrmCustomerLifecycleQueryService;
use Illuminate\Http\Request;

final class CrmCustomerLifecycleController extends Controller
{
    public function __construct(private readonly CrmCustomerLifecycleQueryService $query) {}

    public function receipts(Request $request, int $id)
    {
        return response()->json($this->query->receipts($id, $this->filters($request), $this->actor($request)));
    }

    public function shipments(Request $request, int $id)
    {
        return response()->json($this->query->shipments($id, $this->filters($request), $this->actor($request)));
    }

    public function aftersales(Request $request, int $id)
    {
        return response()->json($this->query->aftersales($id, $this->filters($request), $this->actor($request)));
    }

    public function delivery(Request $request, int $id, int $orderId)
    {
        return response()->json($this->query->delivery($id, $orderId, $this->filters($request), $this->actor($request)));
    }

    private function filters(Request $request): array
    {
        return $request->validate(['page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
    }

    private function actor(Request $request): object
    {
        $actor = app(AuthContextService::class)->currentUser($request);
        abort_unless($actor, 401, '未登录或登录已过期。');
        $access = app(CrmCustomerAccessService::class);
        abort_unless($access->can($actor, 'sales.customer') || $access->can($actor, 'sales.customer.view'), 403, '没有查看客户的权限。');
        return $actor;
    }
}
