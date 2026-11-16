<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\Crm\CrmTeamApplicationService;
use App\Services\Erp\Crm\CrmSalesDelegationService;
use Illuminate\Http\Request;

final class CrmTeamController extends Controller
{
    public function __construct(private readonly CrmTeamApplicationService $team, private readonly AuthContextService $auth) {}

    public function metadata(Request $request) { return response()->json(['data' => $this->team->metadata($this->actor($request))]); }
    public function staff(Request $request) { return response()->json($this->team->staff($this->actor($request), $this->filters($request))); }
    public function showStaff(Request $request, int $userId) { return response()->json(['data' => $this->team->showStaff($this->actor($request), $userId)]); }
    public function users(Request $request) { return response()->json($this->team->users($this->actor($request), $this->filters($request))); }
    public function grants(Request $request) { return response()->json($this->team->grants($this->actor($request), $this->filters($request))); }
    public function saveCapability(Request $request, int $userId) { return response()->json(['data' => $this->team->saveCapability($this->actor($request), $userId, $request->all())]); }
    public function saveGrant(Request $request, ?int $id = null) { return response()->json(['data' => $this->team->saveGrant($this->actor($request), $id, $request->all())]); }

    public function salesDelegations(Request $request, CrmSalesDelegationService $service)
    {
        $filters = $request->validate(['keyword' => 'nullable|string|max:80', 'status' => 'nullable|in:scheduled,active,expired,ended',
            'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        return response()->json($service->listing($this->actor($request), $filters, $request->routeIs('crm.sales-delegations.self.*')));
    }

    public function createSalesDelegation(Request $request, CrmSalesDelegationService $service)
    {
        $self = $request->routeIs('crm.sales-delegations.self.*');
        $data = $request->validate(['owner_legacy_id' => ($self ? 'sometimes' : 'required').'|integer|min:1', 'delegate_legacy_id' => 'required|integer|min:1|different:owner_legacy_id',
            'effective_from' => 'required|date_format:Y-m-d', 'effective_to' => 'required|date_format:Y-m-d|after_or_equal:effective_from',
            'reason' => 'required|string|max:500'], ['delegate_legacy_id.different' => '请假销售和代班销售不能是同一人。']);
        return response()->json(['data' => ['id' => $service->create($this->actor($request), $data, $self)]], 201);
    }

    public function endSalesDelegation(Request $request, int $id, CrmSalesDelegationService $service)
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);
        $service->end($this->actor($request), $id, $data['reason'], $request->routeIs('crm.sales-delegations.self.*'));
        return response()->json(['message' => '已结束销售代班']);
    }

    public function delegationCandidates(Request $request, CrmSalesDelegationService $service)
    {
        $actor = $this->actor($request);
        $service->assertSelfSales($actor);
        return response()->json($this->team->delegationCandidates($actor, $this->filters($request)));
    }

    private function actor(Request $request): object
    {
        $actor = $this->auth->currentUser($request);
        abort_unless($actor, 401, '未登录');
        return $actor;
    }

    private function filters(Request $request): array
    {
        return $request->validate(['keyword' => 'nullable|string|max:80', 'department_id' => 'nullable|integer|min:1',
            'department_ids' => 'nullable|array|max:100', 'department_ids.*' => 'required|integer|min:1',
            'accepting' => 'nullable|boolean', 'effective_only' => 'nullable|boolean', 'status' => 'nullable|in:active,disabled', 'per_page' => 'nullable|integer|min:1|max:100',
            'ids' => 'nullable|array|max:1000', 'ids.*' => 'required|integer|min:1']);
    }
}
