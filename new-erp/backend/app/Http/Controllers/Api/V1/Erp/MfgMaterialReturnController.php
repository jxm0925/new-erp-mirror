<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\ProductionMediaService;
use App\Services\Erp\WarehouseCommandService;
use App\Services\Erp\Manufacturing\MaterialReturnService;
use App\Services\Erp\Manufacturing\WorkOrderQueryService;
use Illuminate\Http\Request;

/** 生产负责人与仓库分别办理方案审核和实际接收，共用正式命令账本恢复重试结果。 */
class MfgMaterialReturnController extends Controller
{
    private const REQUEST = 'production.material_return.request';
    private const DISPOSE = 'production.material_return.approve';
    private const CONFIRM = 'inventory.inbound.confirm';
    private const READ = [self::REQUEST, self::DISPOSE, self::CONFIRM];

    public function index(Request $request, MaterialReturnService $returns)
    {
        [$user, , $confirm, $inventoryAmounts] = $this->user($request, self::READ);
        $filters = $request->validate(['status' => 'nullable|in:pending,approved,rejected,cancelled,holding', 'keyword' => 'nullable|string|max:120',
            'work_order_id' => 'nullable|integer|min:1', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        return response()->json($returns->paginate($filters, $this->canManage($user) ? null : (int) ($user->legacy_id ?? $user->id ?? 0), $inventoryAmounts)
            + ['can_approve' => $confirm, 'can_dispose' => $this->canDispose($user)]);
    }

    public function show(Request $request, int $id, MaterialReturnService $returns)
    {
        [$user, , $confirm, $inventoryAmounts] = $this->user($request, self::READ);
        $row = $returns->detail($id, $inventoryAmounts);
        if (! $this->canManage($user) && (int) $row['requested_by_legacy_id'] !== (int) ($user->legacy_id ?? $user->id ?? 0)) $this->deny();
        return response()->json(['data' => $row + ['can_approve' => $confirm, 'can_dispose' => $this->canDispose($user)]]);
    }

    public function options(Request $request, int $id, int $operationId, MaterialReturnService $returns)
    {
        [$user, , $confirm] = $this->user($request, self::READ);
        $data = $request->validate(['disposition_id' => 'nullable|integer|min:1']);
        return response()->json(['data' => $returns->options($id, $operationId, (int) ($user->legacy_id ?? $user->id ?? 0), isset($data['disposition_id']) ? (int) $data['disposition_id'] : null) + ['can_approve' => $confirm]]);
    }

    public function store(Request $request, int $id, int $operationId, WorkOrderQueryService $query)
    {
        [$user, $amounts] = $this->user($request, self::READ);
        $data = $request->validate(['client_command_id' => 'required|string|max:120', 'return_type' => 'required|in:surplus,defective,scrapped,cancellation', 'reason' => 'required|string|max:500',
            'disposition_id' => 'required_if:return_type,cancellation|nullable|integer|min:1',
            'lines' => 'required|array|min:1|max:50', 'lines.*.material_id' => 'required|integer|min:1', 'lines.*.qty' => 'nullable|numeric|min:0|max:1000000',
            'lines.*.serial_ids' => 'nullable|array|max:1000', 'lines.*.serial_ids.*' => 'integer|min:1|distinct',
            'direct' => 'nullable|boolean', 'location_id' => 'nullable|integer|min:1', 'serial_nos' => 'sometimes|array|max:1000',
            'serial_nos.*' => 'required|string|max:120|distinct', 'with_detail' => 'nullable|boolean',
            'disposition_plan' => 'nullable|array', 'disposition_plan.action' => 'required_with:disposition_plan|in:rework,scrap,release',
            'disposition_plan.start_sequence' => 'nullable|integer|min:1', 'disposition_plan.photos' => 'nullable|array|max:9', 'disposition_plan.photos.*' => 'string|max:1000']
            + ProductionMediaService::rules('disposition_plan.media'));
        $row = $this->command('material_return.request', $operationId, $data + ['work_order_id' => $id], $user);
        $message = ($row['status'] ?? '') === 'approved' ? '退料已确认入库。'
            : (($row['receipt_ready'] ?? false) ? '退料申请已提交，待仓库确认。' : '处置方案已提交，待生产负责人审核。');
        return response()->json(['message' => $message, 'data' => ! empty($data['with_detail']) ? $query->detail($id, $amounts) : $row]);
    }

    public function approve(Request $request, int $id)
    {
        [$user] = $this->user($request, self::CONFIRM);
        $data = $request->validate(['client_command_id' => 'required|string|max:120', 'expected_version' => 'required|integer|min:1',
            'location_id' => 'required|integer|min:1', 'serial_nos' => 'present|array|max:1000', 'serial_nos.*' => 'required|string|max:120|distinct',
            'remark' => 'nullable|string|max:500']);
        return response()->json(['message' => '退料已确认入库。', 'data' => $this->command('material_return.approve', $id, $data, $user)]);
    }

    public function reject(Request $request, int $id)
    {
        [$user] = $this->user($request, self::CONFIRM);
        $data = $request->validate(['client_command_id' => 'required|string|max:120', 'expected_version' => 'required|integer|min:1', 'remark' => 'required|string|max:500']);
        return response()->json(['message' => '退料已驳回。', 'data' => $this->command('material_return.reject', $id, $data, $user)]);
    }

    public function dispose(Request $request, int $id)
    {
        [$user] = $this->user($request, self::DISPOSE);
        $data = $request->validate(['client_command_id' => 'required|string|max:120', 'expected_version' => 'required|integer|min:1',
            'lines' => 'required|array|min:1|max:200', 'lines.*.line_id' => 'required|integer|min:1',
            'lines.*.action' => 'required|in:release,scrap,supplier_return', 'lines.*.qty' => 'nullable|numeric|min:0|max:1000000', 'remark' => 'nullable|string|max:500']);
        return response()->json(['message' => '不良退料已处置。', 'data' => $this->command('material_return.dispose', $id, $data, $user)]);
    }

    public function cancel(Request $request, int $id)
    {
        [$user] = $this->user($request, self::READ);
        $data = $request->validate(['client_command_id' => 'required|string|max:120', 'expected_version' => 'required|integer|min:1']);
        return response()->json(['message' => '退料申请已撤回。', 'data' => $this->command('material_return.cancel', $id, $data, $user)]);
    }

    private function command(string $action, int $id, array $data, object $user): array
    {
        $commandId = $data['client_command_id'];
        unset($data['client_command_id'], $data['with_detail']);
        $auth = app(AuthContextService::class);
        return app(WarehouseCommandService::class)->run($action, $id, $commandId, $data, $user, $auth->permissionCodes($user), $auth->isSuperAdmin($user))['result'];
    }

    private function canDispose(object $user): bool
    {
        $auth = app(AuthContextService::class);
        return $auth->isSuperAdmin($user) || in_array(self::DISPOSE, $auth->permissionCodes($user), true);
    }

    private function canManage(object $user): bool
    {
        $auth = app(AuthContextService::class);
        return $auth->isSuperAdmin($user) || (bool) array_intersect([self::DISPOSE, self::CONFIRM], $auth->permissionCodes($user));
    }

    /** @return array{0: object, 1: bool, 2: bool, 3: bool} 用户、销售资金可见、仓库确认权限、库存金额可见 */
    private function user(Request $request, string|array $permission): array
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        if (! $user) throw new WorkOrderDomainException('unauthenticated', '请先登录 ERP。', 401);
        $super = $auth->isSuperAdmin($user);
        $codes = $auth->permissionCodes($user);
        if (! $super && ! array_intersect((array) $permission, $codes)) $this->deny();
        $amounts = $super || in_array('sales_order.amount.view', $codes, true) || in_array('production.performance.manage', $codes, true)
            || in_array('inventory.balance.view', $codes, true);
        return [$user, $amounts, $super || in_array(self::CONFIRM, $codes, true),
            app(\App\Services\Erp\InventoryAmountVisibilityService::class)->canView($request)];
    }

    private function deny(string $message = '当前账号没有生产退料权限。'): never
    {
        throw new WorkOrderDomainException('permission_denied', $message, 403);
    }
}
