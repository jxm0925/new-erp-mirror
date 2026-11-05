<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Models\Erp\MfgCuttingBatch;
use App\Models\Erp\MfgTaskParticipant;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\Manufacturing\CuttingService;
use App\Services\Erp\Manufacturing\CuttingRemnantReceiptService;
use Illuminate\Http\Request;

/** 原项目生产工单的集中开料入口；权限与执行岗位沿用正式生产权限。 */
class MfgCuttingController extends Controller
{
    public function context(Request $request, int $id, CuttingService $cutting)
    {
        [$user, $amounts] = $this->user($request, false, null, $id);
        $data = $request->validate(['page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:50',
            'operation_id' => 'required_if:scope,units|nullable|integer|min:1', 'scope' => 'nullable|in:units',
            'batch_id' => 'nullable|integer|min:1', 'keyword' => 'nullable|string|max:120']);
        $result = $cutting->context($id, $data, $amounts, $user);
        return response()->json(($data['scope'] ?? null) === 'units' ? $result : ['data' => $result]);
    }

    public function sources(Request $request, int $id, CuttingService $cutting)
    {
        $data = $request->validate(['item_id' => 'nullable|required_without:operation_id|integer|min:1',
            'operation_id' => 'nullable|required_without:item_id|integer|min:1', 'category_id' => 'nullable|integer|min:1',
            'keyword' => 'nullable|string|max:120', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:50']);
        [, $amounts] = $this->user($request, false, isset($data['operation_id']) ? (int) $data['operation_id'] : null, $id);
        return response()->json($cutting->sources($id, $data, $amounts));
    }

    public function preview(Request $request, int $id, CuttingService $cutting)
    {
        $data = $request->validate($this->planRules());
        [, $amounts] = $this->user($request, false, (int) $data['operation_id'], $id);
        return response()->json(['data' => $cutting->preview($id, $data, $amounts)]);
    }

    public function recommend(Request $request, int $id, CuttingService $cutting)
    {
        $data = $request->validate($this->unitRules() + ['operation_id' => 'required|integer|min:1', 'unit_qty' => 'required|integer|min:1|max:1000']);
        [, $amounts] = $this->user($request, false, (int) $data['operation_id'], $id);
        return response()->json(['data' => $cutting->recommend($id, $data, $amounts)]);
    }

    public function issue(Request $request, int $id, CuttingService $cutting)
    {
        $data = $request->validate($this->planRules() + ['expected_version' => 'required|integer|min:1',
            'idempotency_key' => 'required|string|min:16|max:80', 'remark' => 'nullable|string|max:500']);
        [$user, $amounts] = $this->user($request, true, (int) $data['operation_id']);
        return response()->json(['message' => '已领入真实开料来源，待登记产出并确认余料。', 'data' => $cutting->issue($id, $data, $user, $amounts)]);
    }

    public function settle(Request $request, int $id, CuttingService $cutting)
    {
        $batch = MfgCuttingBatch::query()->findOrFail($id);
        [$user] = $this->user($request, true, (int) $batch->work_order_operation_id);
        $data = $request->validate(CuttingRemnantReceiptService::requestRules() + ['client_command_id' => 'required|string|max:120']);
        $commandId = $data['client_command_id']; unset($data['client_command_id']);
        $auth = app(AuthContextService::class);
        $result = app(\App\Services\Erp\WarehouseCommandService::class)->run('cutting_batch.request', $id, $commandId, $data,
            $user, $auth->permissionCodes($user), $auth->isSuperAdmin($user))['result'];
        return response()->json(['message' => '实际产出与余料已记录，开料已结算。', 'data' => $result]);
    }

    private function planRules(): array
    {
        return $this->unitRules() + $this->assignmentRules() + ['operation_id' => 'required|integer|min:1', 'unit_qty' => 'required|integer|min:1|max:1000',
            'sources' => 'required|array|min:1|max:1000', 'sources.*.source_key' => 'required|string|max:120',
            'sources.*.item_id' => 'required|integer|min:1', 'sources.*.inventory_balance_id' => 'required|integer|min:1',
            'sources.*.physical_material_id' => 'nullable|integer|min:1', 'sources.*.source_version' => 'required|string|size:64',
            'sources.*.qty' => 'required|string|regex:/^\d{1,10}(?:\.\d{1,8})?$/D'];
    }

    private function assignmentRules(): array
    {
        return ['assignments' => 'sometimes|array|max:10000', 'assignments.*.source_key' => 'required|string|max:120',
            'assignments.*.part_key' => 'required|string|max:80', 'assignments.*.qty' => 'required|string|regex:/^\d{1,10}(?:\.\d{1,8})?$/D'];
    }

    private function unitRules(): array
    {
        return ['unit_ids' => 'required|array|min:1|max:1000', 'unit_ids.*' => 'required|integer|min:1|distinct'];
    }

    private function user(Request $request, bool $write, ?int $operationId = null, ?int $workOrderId = null): array
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        if (! $user) throw new WorkOrderDomainException('unauthenticated', '请先登录 ERP。', 401);
        $codes = $auth->permissionCodes($user);
        $super = $auth->isSuperAdmin($user);
        $permissions = $write ? ['production.work_order.edit', 'production.task.execute'] : ['production.work_order.view', 'production.work_order.edit', 'production.task.view', 'production.task.execute'];
        if (! $super && ! array_intersect($permissions, $codes)) throw new WorkOrderDomainException('permission_denied', '当前账号没有这项开料权限。', 403);
        if (! $write && ! $super && ! array_intersect(['production.work_order.view', 'production.work_order.edit'], $codes)
            && ! MfgTaskParticipant::query()->where('work_order_id', $workOrderId)->when($operationId, fn ($q) => $q->where('work_order_operation_id', $operationId))
                ->where('employee_legacy_id', $user->legacy_id ?? $user->id)->where('is_active', true)->exists()) {
            throw new WorkOrderDomainException('permission_denied', '只能查看本人当前参与工单的开料来源。', 403);
        }
        if ($write && ! $super && ! in_array('production.work_order.edit', $codes, true)
            && ! MfgTaskParticipant::query()->where('work_order_operation_id', $operationId)->where('employee_legacy_id', $user->legacy_id ?? $user->id)->where('is_active', true)->exists()) {
            throw new WorkOrderDomainException('permission_denied', '只能办理本人已接单或参与工序的开料。', 403);
        }
        return [$user, app(\App\Services\Erp\InventoryAmountVisibilityService::class)->canView($request)];
    }
}
