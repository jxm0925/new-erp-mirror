<?php

namespace App\Services\Erp\Crm;

use App\Models\Erp\CrmCustomerCollaborator;
use App\Models\Erp\SalesCustomer;
use App\Services\Erp\AuthContextService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 客户协作：负责销售或部组长把客户的查看/跟进范围授给指定销售。
 * 协作不改变负责销售、商机负责人和历史订单归属；订单金额只有在协作人员本身有金额查看权限时才能勾选，
 * 防止借协作扩大金额权限。
 */
final class CrmCustomerCollaborationService
{
    public function __construct(
        private readonly CrmCustomerAccessService $access,
        private readonly CrmSalesStaffService $staff,
        private readonly CrmCustomerLogService $logs,
        private readonly AuthContextService $auth,
    ) {}

    public function add(int $customerId, array $data, object $actor): CrmCustomerCollaborator
    {
        return DB::transaction(function () use ($customerId, $data, $actor) {
            $customer = SalesCustomer::query()->lockForUpdate()->findOrFail($customerId);
            $this->access->assert($customer, $actor, 'collaborate', '无权为该客户添加协作。');
            $userId = (int) $data['user_legacy_id'];
            if (!$this->staff->isActiveSales($userId)) {
                throw ValidationException::withMessages(['user_legacy_id' => '协作人员须为在职且销售员档案有效的销售。']);
            }
            if ($userId === (int) $customer->owner_legacy_id) {
                throw ValidationException::withMessages(['user_legacy_id' => '该人员已是负责销售，无需添加协作。']);
            }
            $scope = [
                'basic' => true,
                'opportunity_ids' => array_values(array_map('intval', (array) ($data['scope']['opportunity_ids'] ?? []))),
                'order_amount' => !empty($data['scope']['order_amount']),
                'finance' => !empty($data['scope']['finance']),
            ];
            if ($scope['order_amount'] || $scope['finance']) {
                $target = DB::table('erp_legacy_admin_users')->where('legacy_id', $userId)->first();
                if (!$target || !($this->auth->isSuperAdmin($target) || in_array('sales_order.amount.view', $this->auth->permissionCodes($target), true))) {
                    throw ValidationException::withMessages(['scope' => '该人员没有金额查看权限，不能共享订单金额或财务收款。']);
                }
            }
            $actions = ['view' => true, 'follow' => !empty($data['actions']['follow']), 'edit_master' => !empty($data['actions']['edit_master'])];
            try {
                $collab = CrmCustomerCollaborator::create([
                    'customer_id' => $customer->id, 'user_legacy_id' => $userId, 'user_name' => $this->staff->name($userId) ?? (string) $userId,
                    'department_legacy_id' => $this->staff->departmentOf($userId), 'scope' => $scope, 'actions' => $actions,
                    'effective_from' => $data['effective_from'], 'effective_to' => $data['effective_to'] ?? null,
                    'status' => 'active', 'active_key' => $customer->id.':'.$userId, 'reason' => $data['reason'],
                    'created_by_legacy_id' => $actor->legacy_id, 'created_by_name' => ($actor->nickname ?? null) ?: ($actor->username ?? null),
                ]);
            } catch (QueryException $error) {
                if (($error->errorInfo[1] ?? null) !== 1062) throw $error;
                throw ValidationException::withMessages(['user_legacy_id' => '该人员已有生效中的协作，请先结束原协作再重新添加。']);
            }
            $this->logs->write($customer->id, 'collab_add', "添加协作：{$collab->user_name}", null, $collab->only(['user_legacy_id', 'user_name', 'scope', 'actions', 'effective_from', 'effective_to']), $data['reason'], $actor);
            return $collab;
        }, 3);
    }

    public function end(int $customerId, int $collabId, string $reason, object $actor): void
    {
        DB::transaction(function () use ($customerId, $collabId, $reason, $actor) {
            $customer = SalesCustomer::query()->lockForUpdate()->findOrFail($customerId);
            $this->access->assert($customer, $actor, 'collaborate', '无权结束该客户的协作。');
            $collab = CrmCustomerCollaborator::query()->where('customer_id', $customerId)->lockForUpdate()->findOrFail($collabId);
            if ($collab->status !== 'active') throw ValidationException::withMessages(['status' => '该协作已结束。']);
            $collab->update(['status' => 'ended', 'active_key' => null, 'ended_by_legacy_id' => $actor->legacy_id, 'ended_at' => now(), 'end_reason' => $reason, 'version' => $collab->version + 1]);
            $this->logs->write($customerId, 'collab_end', "结束协作：{$collab->user_name}", null, null, $reason, $actor);
        }, 3);
    }
}
