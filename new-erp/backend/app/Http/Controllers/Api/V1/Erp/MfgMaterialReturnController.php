<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\Manufacturing\MaterialReturnService;
use App\Services\Erp\Manufacturing\WorkOrderQueryService;
use Illuminate\Http\Request;

/** 生产退料：车间申请（production.material_return.request），库管审核入库（production.material_return.approve），库管可直接退。 */
class MfgMaterialReturnController extends Controller
{
    private const REQUEST = 'production.material_return.request';
    private const APPROVE = 'production.material_return.approve';

    /** 审核列表：有审核权限看全部，只有申请权限的看自己提的。 */
    public function index(Request $request, MaterialReturnService $returns)
    {
        [$user, $amounts, $approver] = $this->user($request, [self::REQUEST, self::APPROVE]);
        $filters = $request->validate(['status' => 'nullable|in:pending,approved,rejected,cancelled', 'keyword' => 'nullable|string|max:120',
            'work_order_id' => 'nullable|integer|min:1', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        return response()->json($returns->paginate($filters, $approver ? null : (int) ($user->legacy_id ?? $user->id ?? 0), $amounts) + ['can_approve' => $approver]);
    }

    public function show(Request $request, int $id, MaterialReturnService $returns)
    {
        [$user, $amounts, $approver] = $this->user($request, [self::REQUEST, self::APPROVE]);
        $row = $returns->detail($id, $amounts);
        if (! $approver && (int) $row['requested_by_legacy_id'] !== (int) ($user->legacy_id ?? $user->id ?? 0)) $this->deny();
        return response()->json(['data' => $row + ['can_approve' => $approver]]);
    }

    /** 退料弹窗：本道可退的料、可退数量和编号。 */
    public function options(Request $request, int $id, int $operationId, MaterialReturnService $returns)
    {
        [, , $approver] = $this->user($request, [self::REQUEST, self::APPROVE]);
        return response()->json(['data' => $returns->options($id, $operationId) + ['can_approve' => $approver]]);
    }

    /** 提交退料申请；direct=true 为库管直接退（建单即审核入库）。 */
    public function store(Request $request, int $id, int $operationId, MaterialReturnService $returns, WorkOrderQueryService $query)
    {
        [$user, $amounts, $approver] = $this->user($request, [self::REQUEST, self::APPROVE]);
        $data = $request->validate(['return_type' => 'required|in:surplus,defective,scrapped', 'reason' => 'required|string|max:500',
            'lines' => 'required|array|min:1|max:50', 'lines.*.material_id' => 'required|integer|min:1', 'lines.*.qty' => 'nullable|numeric|min:0|max:1000000',
            'lines.*.serial_ids' => 'nullable|array|max:1000', 'lines.*.serial_ids.*' => 'integer|min:1',
            'direct' => 'nullable|boolean', 'location_id' => 'nullable|integer|min:1', 'with_detail' => 'nullable|boolean']);
        $direct = ! empty($data['direct']);
        if ($direct && ! $approver) $this->deny('直接退料需要“审核生产退料”权限，请提交申请等库管审核。');
        $return = $returns->request($id, $operationId, $data['return_type'], $data['lines'], $data['reason'], $user, $direct, isset($data['location_id']) ? (int) $data['location_id'] : null);
        $message = $direct ? "已退料入库（{$return->return_no}）。" : "退料申请已提交（{$return->return_no}），等库管审核后入库。";
        return response()->json(['message' => $message, 'data' => ! empty($data['with_detail']) ? $query->detail($id, $amounts) : $returns->row($return, $amounts)]);
    }

    public function approve(Request $request, int $id, MaterialReturnService $returns)
    {
        [$user, $amounts] = $this->user($request, self::APPROVE);
        $data = $request->validate(['location_id' => 'nullable|integer|min:1', 'remark' => 'nullable|string|max:500']);
        $return = $returns->approve($id, isset($data['location_id']) ? (int) $data['location_id'] : null, $data['remark'] ?? null, $user);
        return response()->json(['message' => "退料单 {$return->return_no} 已审核入库。", 'data' => $returns->row($return, $amounts)]);
    }

    public function reject(Request $request, int $id, MaterialReturnService $returns)
    {
        [$user, $amounts] = $this->user($request, self::APPROVE);
        $data = $request->validate(['remark' => 'required|string|max:500']);
        $return = $returns->reject($id, $data['remark'], $user);
        return response()->json(['message' => "退料单 {$return->return_no} 已驳回。", 'data' => $returns->row($return, $amounts)]);
    }

    public function cancel(Request $request, int $id, MaterialReturnService $returns)
    {
        [$user, $amounts, $approver] = $this->user($request, [self::REQUEST, self::APPROVE]);
        $return = $returns->cancel($id, $user, $approver);
        return response()->json(['message' => "退料申请 {$return->return_no} 已撤回。", 'data' => $returns->row($return, $amounts)]);
    }

    /** @return array{0: object, 1: bool, 2: bool} 当前用户、能否看金额、能否审核 */
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
        return [$user, $amounts, $super || in_array(self::APPROVE, $codes, true)];
    }

    private function deny(string $message = '当前账号没有生产退料权限。'): never
    {
        throw new WorkOrderDomainException('permission_denied', $message, 403);
    }
}
