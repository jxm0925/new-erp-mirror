<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\Manufacturing\SharedCuttingService;
use Illuminate\Http\Request;

/** 集中开料（不属于工单）：待切清单、领板、登记余料；工单下料点「已备料」时按定额从开料池扣。 */
class MfgSharedCuttingController extends Controller
{
    public function demand(Request $request, SharedCuttingService $cutting)
    {
        $this->user($request, false);
        $data = $request->validate(['item_id' => 'nullable|integer|min:1']);
        return response()->json(['data' => $cutting->demand($data)]);
    }

    public function index(Request $request, SharedCuttingService $cutting)
    {
        [, $amounts] = $this->user($request, false);
        $data = $request->validate(['status' => 'nullable|in:issued,settled', 'item_id' => 'nullable|integer|min:1',
            'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:50']);
        return response()->json($cutting->index($data, $amounts));
    }

    public function show(Request $request, int $id, SharedCuttingService $cutting)
    {
        [, $amounts] = $this->user($request, false);
        return response()->json(['data' => $cutting->show($id, $amounts)]);
    }

    public function sources(Request $request, SharedCuttingService $cutting)
    {
        [, $amounts] = $this->user($request, false);
        $data = $request->validate(['item_id' => 'required|integer|min:1', 'keyword' => 'nullable|string|max:120',
            'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:50']);
        return response()->json($cutting->sources($data, $amounts));
    }

    public function store(Request $request, SharedCuttingService $cutting)
    {
        [$user, $amounts] = $this->user($request, true);
        $data = $request->validate(['item_id' => 'required|integer|min:1', 'idempotency_key' => 'required|string|min:16|max:80',
            'remark' => 'nullable|string|max:500', 'sources' => 'required|array|min:1|max:200',
            'sources.*.inventory_balance_id' => 'required|integer|min:1', 'sources.*.physical_material_id' => 'required|integer|min:1',
            'sources.*.source_version' => 'required|string|size:64']);
        return response()->json(['message' => '已领板，切完后登记每张板的余料。', 'data' => $cutting->issue($data, $user, $amounts)]);
    }

    public function settle(Request $request, int $id, SharedCuttingService $cutting)
    {
        [$user, $amounts] = $this->user($request, true);
        $data = $request->validate(['expected_version' => 'required|integer|min:1', 'idempotency_key' => 'required|string|min:16|max:80',
            'confirmed' => 'required|accepted', 'remark' => 'nullable|string|max:500',
            'remnants' => 'required|array|min:1|max:200', 'remnants.*.source_key' => 'required|string|max:120',
            'remnants.*.measure_qty' => 'required|string|regex:/^\d{1,10}(?:\.\d{1,8})?$/D',
            'remnants.*.warehouse_id' => 'nullable|integer|min:1', 'remnants.*.location_id' => 'nullable|integer|min:1']);
        foreach ($data['remnants'] as $i => $row) {
            if ((float) $row['measure_qty'] > 0 && (empty($row['warehouse_id']) || empty($row['location_id'])))
                throw new WorkOrderDomainException('remnant_location_required', '有余料的板请选放回的仓库和库位。', 422);
            $data['remnants'][$i]['warehouse_id'] = (int) ($row['warehouse_id'] ?? 0);
            $data['remnants'][$i]['location_id'] = (int) ($row['location_id'] ?? 0);
        }
        return response()->json(['message' => '已登记余料，切好的件可以在各工单下料点「已备料」。', 'data' => $cutting->settle($id, $data, $user, $amounts)]);
    }

    private function user(Request $request, bool $write): array
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        if (! $user) throw new WorkOrderDomainException('unauthenticated', '请先登录 ERP。', 401);
        $codes = $auth->permissionCodes($user);
        $super = $auth->isSuperAdmin($user);
        $permissions = $write ? ['production.work_order.edit', 'production.task.execute'] : ['production.work_order.view', 'production.work_order.edit', 'production.task.view', 'production.task.execute'];
        if (! $super && ! array_intersect($permissions, $codes)) throw new WorkOrderDomainException('permission_denied', '当前账号没有集中开料权限。', 403);
        return [$user, $super || array_intersect(['sales_order.amount.view', 'production.performance.manage'], $codes) !== []];
    }
}
