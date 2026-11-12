<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\Crm\CrmCustomerAccessService;
use App\Services\Erp\Crm\CrmDashboardPipelineService;
use App\Services\Erp\Crm\CrmDashboardQueryService;
use App\Services\Erp\Crm\CrmDashboardScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CrmDashboardController extends Controller
{
    public function __construct(private readonly CrmDashboardQueryService $queries, private readonly CrmDashboardScopeService $scope) {}

    public function metadata(Request $request) { return $this->read($request, 'metadata'); }
    public function salesOptions(Request $request) { return $this->read($request, 'salesOptions'); }
    public function overview(Request $request) { return $this->read($request, 'overview'); }
    public function sales(Request $request) { return $this->read($request, 'sales'); }
    public function dimensions(Request $request) { return $this->read($request, 'dimensions'); }
    public function sources(Request $request) { return $this->read($request, 'sources'); }
    public function income(Request $request) { return $this->read($request, 'income'); }
    public function orders(Request $request) { return $this->read($request, 'orders'); }
    public function drilldown(Request $request) { return $this->read($request, 'drilldown'); }
    public function pipeline(Request $request) { return $this->read($request, 'pipeline'); }
    public function followups(Request $request) { return $this->read($request, 'followups'); }

    private function read(Request $request, string $method)
    {
        $actor = app(AuthContextService::class)->currentUser($request);
        abort_unless($actor, 401, '未登录');
        abort_unless(app(CrmCustomerAccessService::class)->can($actor, 'crm.dashboard.view'), 403, '没有查看经营看板的权限。');
        $filters = $this->filters($request);
        $this->scope->assertFilters($actor, $filters);
        $service = in_array($method, ['pipeline', 'followups'], true) ? app(CrmDashboardPipelineService::class) : $this->queries;
        return response()->json(DB::transaction(fn () => $service->{$method}($actor, $filters)));
    }

    private function filters(Request $request): array
    {
        $request->merge(['date_from' => $request->input('date_from') ?: today()->startOfMonth()->toDateString(),
            'date_to' => $request->input('date_to') ?: today()->toDateString()]);
        return $request->validate([
            'date_from' => 'required|date_format:Y-m-d', 'date_to' => 'required|date_format:Y-m-d|after_or_equal:date_from',
            'cohort_from' => 'nullable|date_format:Y-m-d', 'cohort_to' => 'nullable|date_format:Y-m-d|after_or_equal:cohort_from',
            'observed_on' => 'nullable|date_format:Y-m-d|before_or_equal:today',
            'department_legacy_id' => 'nullable|integer|min:1', 'group_legacy_id' => 'nullable|integer|min:1',
            'sales_user_legacy_id' => 'nullable|integer|min:1', 'sales_user_unassigned' => 'nullable|boolean',
            'business_line' => 'nullable|string|max:120', 'currency' => 'nullable|string|max:20',
            'dimension' => 'nullable|string|in:'.implode(',', array_keys(CrmDashboardQueryService::DIMENSIONS)),
            'cross_sales' => 'nullable|boolean', 'value' => 'nullable|string|max:250', 'value_is_null' => 'nullable|boolean',
            'dimension_page' => 'nullable|integer|min:1', 'dimension_per_page' => 'nullable|integer|min:1|max:100',
            'kind' => $request->route()?->getActionMethod() === 'drilldown' ? 'required|string|max:80' : 'nullable|string|max:80',
            'stage' => 'nullable|string|max:80', 'status' => 'nullable|string|max:40',
            'lost_reason' => 'nullable|string|max:500', 'dimension_empty' => 'nullable|boolean',
            'conversion_bucket' => 'nullable|string|in:total,within_30,over_30,new,old,source_unknown',
            'outstanding_status' => 'nullable|string|in:outstanding,settled,verification',
            'customer_id' => 'nullable|integer|min:1', 'keyword' => 'nullable|string|max:160',
            'ids' => 'nullable|array|max:100', 'ids.*' => 'integer|min:1',
            'stalled_days' => 'nullable|integer|min:1|max:3650', 'due_days' => 'nullable|integer|min:0|max:365',
            'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100',
        ]);
    }
}
