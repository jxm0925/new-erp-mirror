<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\Crm\CrmCustomerAccessService;
use App\Services\Erp\Crm\CrmWorkbenchBusinessQueryService;
use App\Services\Erp\Crm\CrmWorkbenchQueryService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CrmWorkbenchController extends Controller
{
    public function __construct(private readonly CrmWorkbenchQueryService $workbench, private readonly CrmCustomerAccessService $access) {}

    public function index(Request $request)
    {
        return response()->json(['data' => $this->workbench->overview($this->actor($request))]);
    }

    public function tasks(Request $request)
    {
        $actor = $this->actor($request);
        $filters = $request->validate([
            'type' => ['nullable', Rule::in(array_keys(CrmWorkbenchQueryService::TASKS))],
            'per_page' => 'nullable|integer|min:1|max:100', 'page' => 'nullable|integer|min:1',
        ]);
        return response()->json($this->workbench->tasks($actor, $filters));
    }

    public function priorities(Request $request)
    {
        $actor = $this->actor($request);
        $filters = $request->validate([
            'type' => ['nullable', Rule::in(array_keys(CrmWorkbenchQueryService::PRIORITIES))],
            'per_page' => 'nullable|integer|min:1|max:100', 'page' => 'nullable|integer|min:1',
        ]);
        return response()->json($this->workbench->priorities($actor, $filters));
    }

    public function results(Request $request, CrmWorkbenchBusinessQueryService $business)
    {
        return response()->json(['data' => $business->results($this->actor($request))]);
    }

    public function business(Request $request, CrmWorkbenchBusinessQueryService $business)
    {
        $actor = $this->actor($request);
        $filters = $request->validate([
            'type' => 'nullable|in:delivery,outstanding,aftersales,month_orders',
            'currency' => 'nullable|string|size:3',
            'currency_missing' => 'nullable|boolean',
            'per_page' => 'nullable|integer|min:1|max:100', 'page' => 'nullable|integer|min:1',
        ]);
        return response()->json($business->business($actor, $filters));
    }

    private function actor(Request $request): object
    {
        $actor = app(AuthContextService::class)->currentUser($request);
        abort_unless($actor, 401, '未登录');
        abort_unless($this->access->can($actor, 'crm.workbench.view'), 403, '没有查看工作台的权限。');
        abort_unless($this->access->can($actor, 'crm.lead.view') || $this->access->can($actor, 'crm.opportunity.view')
            || $this->access->can($actor, 'sales.customer'), 403, '没有查看工作台的权限。');
        return $actor;
    }
}
