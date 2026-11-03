<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\Manufacturing\SharedCuttingService;
use App\Services\Erp\Manufacturing\CuttingRemnantReceiptService;
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
        [$user, $amounts] = $this->user($request, false);
        $data = $request->validate(['status' => 'nullable|in:issued,awaiting_confirm,settled', 'item_id' => 'nullable|integer|min:1', 'cutting_run_id' => 'nullable|integer|min:1',
            'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:50']);
        return response()->json($cutting->index($data, $amounts, $user));
    }

    public function show(Request $request, int $id, SharedCuttingService $cutting)
    {
        [$user, $amounts] = $this->user($request, false);
        return response()->json(['data' => $cutting->show($id, $amounts, $user)]);
    }

    public function sources(Request $request, SharedCuttingService $cutting)
    {
        [, $amounts] = $this->user($request, false);
        $data = $request->validate(['item_id' => 'required|integer|min:1', 'cutting_run_id' => 'nullable|integer|min:1', 'keyword' => 'nullable|string|max:120',
            'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:50']);
        return response()->json($cutting->sources($data, $amounts));
    }

    public function store(Request $request, SharedCuttingService $cutting)
    {
        [$user, $amounts] = $this->user($request, true);
        $data = $request->validate(['item_id' => 'required|integer|min:1', 'cutting_run_id' => 'required|integer|min:1', 'idempotency_key' => 'required|string|min:16|max:80',
            'remark' => 'nullable|string|max:500', 'sources' => 'required|array|min:1|max:200',
            'sources.*.inventory_balance_id' => 'required|integer|min:1', 'sources.*.physical_material_id' => 'nullable|integer|min:1',
            'sources.*.source_key' => 'nullable|string|max:120|distinct', 'sources.*.qty' => 'nullable|string|regex:/^\d{1,10}(?:\.\d{1,8})?$/D',
            'sources.*.source_version' => 'required|string|size:64']);
        return response()->json(['message' => '已记录实际领料，切完后登记各来源的实际余料。', 'data' => $cutting->issue($data, $user, $amounts)]);
    }

    public function settle(Request $request, int $id, SharedCuttingService $cutting)
    {
        [$user] = $this->user($request, true);
        $data = $request->validate(CuttingRemnantReceiptService::requestRules() + ['client_command_id' => 'required|string|max:120']);
        $commandId = $data['client_command_id']; unset($data['client_command_id']);
        $auth = app(AuthContextService::class);
        $result = app(\App\Services\Erp\WarehouseCommandService::class)->run('cutting_batch.request', $id, $commandId, $data,
            $user, $auth->permissionCodes($user), $auth->isSuperAdmin($user))['result'];
        return response()->json(['message' => $result['status'] === 'settled' ? '实际余料已记录，净耗已结算。' : '实际余料已记录，切完结束登记件数后结算。', 'data' => $result]);
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
        return [$user, app(\App\Services\Erp\InventoryAmountVisibilityService::class)->canView($request)];
    }
}
