<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\{SalesOrder, CancellationProductionDisposition as Disposition};
use App\Services\Erp\{AuthContextService, SalesOrderVisibilityService};
use App\Services\Erp\Manufacturing\{OrderChangeLaborService, DispositionLaborService};
use Illuminate\Http\Request;

final class OrderChangeLaborController extends Controller
{
    public function order(Request $request, int $id, OrderChangeLaborService $service)
    {
        $actor = $this->user($request, 'sales_order.view');
        $query = SalesOrder::query();
        app(SalesOrderVisibilityService::class)->apply($query, $actor, true);
        $query->findOrFail($id);
        $filters = $request->validate(['page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        return response()->json(['data' => $service->order($id, $filters)]);
    }

    public function show(Request $request, int $id, DispositionLaborService $service)
    {
        $actor = $this->user($request, ['production.work_order.view', 'production.task.view', ...DispositionLaborService::EXECUTE_PERMISSIONS]);
        $this->assertDispositionVisible($id, $actor);
        $filters = $request->validate(['page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        return response()->json(['data' => $service->show($id, $actor, $filters)]);
    }

    public function start(Request $request, int $id, DispositionLaborService $service)
    {
        $actor = $this->user($request, DispositionLaborService::EXECUTE_PERMISSIONS);
        $data = $request->validate(['activity' => 'required|in:material_return,dismantle,scrap']);
        return response()->json(['data' => $service->start($id, $data['activity'], $actor)]);
    }

    public function pause(Request $request, int $id, DispositionLaborService $service)
    {
        return response()->json(['data' => $service->stop($id, $this->user($request, ['production.work_order.view', 'production.task.view', ...DispositionLaborService::EXECUTE_PERMISSIONS]), 'paused')]);
    }

    public function finish(Request $request, int $id, DispositionLaborService $service)
    {
        return response()->json(['data' => $service->stop($id, $this->user($request, ['production.work_order.view', 'production.task.view', ...DispositionLaborService::EXECUTE_PERMISSIONS]), 'completed')]);
    }

    private function user(Request $request, string|array $permission): object
    {
        $auth = app(AuthContextService::class);
        $actor = $auth->currentUser($request);
        abort_unless($actor, 401, '请先登录 ERP。');
        abort_unless($auth->isSuperAdmin($actor) || array_intersect((array) $permission, $auth->permissionCodes($actor)), 403, '当前账号没有查看权限。');
        return $actor;
    }

    private function assertDispositionVisible(int $id, object $actor): void
    {
        $row = Disposition::findOrFail($id);
        if (DispositionLaborService::canRecord($row, (int) ($actor->legacy_id ?? $actor->id))) return;
        $query = SalesOrder::query();
        app(SalesOrderVisibilityService::class)->apply($query, $actor, true);
        abort_unless($query->whereKey($row->sales_order_id)->exists(), 403, '该工单不在你的查看范围。');
    }
}
