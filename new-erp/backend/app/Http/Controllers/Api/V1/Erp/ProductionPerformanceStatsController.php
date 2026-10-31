<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\Manufacturing\PerformanceStatsService;
use Illuminate\Http\Request;

/** 订单提成与工时统计（只读）：订单列表、订单明细（含库存半成品按用量追溯）、备货批次去向。 */
class ProductionPerformanceStatsController extends Controller
{
    public function index(Request $request, PerformanceStatsService $stats)
    {
        $amounts = $this->user($request);
        $data = $request->validate(['keyword' => 'nullable|string|max:120', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:30']);
        return response()->json($stats->index($data, $amounts));
    }

    public function show(Request $request, int $id, PerformanceStatsService $stats)
    {
        return response()->json(['data' => $stats->order($id, $this->user($request))]);
    }

    public function batch(Request $request, PerformanceStatsService $stats)
    {
        $this->user($request);
        $data = $request->validate(['work_order_id' => 'nullable|integer|min:1', 'work_order_no' => 'nullable|string|max:60|required_without:work_order_id']);
        return response()->json(['data' => $stats->batch(isset($data['work_order_id']) ? (int) $data['work_order_id'] : null, $data['work_order_no'] ?? null)]);
    }

    /** 能看提成统计：提成统计菜单或维护提成模板；金额另需看金额权限。 */
    private function user(Request $request): bool
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        if (! $user) throw new WorkOrderDomainException('unauthenticated', '请先登录 ERP。', 401);
        $codes = $auth->permissionCodes($user);
        $super = $auth->isSuperAdmin($user);
        if (! $super && ! array_intersect(['production.performance_stats', 'production.performance.manage'], $codes))
            throw new WorkOrderDomainException('permission_denied', '当前账号没有提成统计权限。', 403);
        return $super || array_intersect(['sales_order.amount.view', 'production.performance.manage'], $codes) !== [];
    }
}
