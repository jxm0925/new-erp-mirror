<?php

namespace App\Services\Erp\Crm;

use App\Models\Erp\SalesCustomer;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\DocumentNumberService;
use App\Services\Erp\SalesCustomerOrderDetailsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 建单时识别客户的唯一入口（草稿链 SalesOrderSnapshotService 与旧控制器共用）。
 * 用户确认的新规则：
 * - 买家ID已属于另一位客户时拒绝保存，不再静默改挂客户；
 * - 订单上的客户名、平台、买家ID只进订单快照，不覆盖客户主资料；
 * - 未选客户时，同名或同电话的已有客户只作为候选，要求录单人通过选择框选用，不自动复用；
 * - 选择不可见客户（其他部门负责）需先申请协作；订单原有客户保持可编辑，不因范围调整而锁死旧单。
 * 平台身份在订单事务内绑定，订单回滚时身份一起回滚。
 */
final class CrmOrderCustomerResolver
{
    public function __construct(
        private readonly CrmCustomerAccessService $access,
        private readonly CrmPlatformIdentityService $identities,
        private readonly CrmCustomerDedupeService $dedupe,
        private readonly CrmCustomerLogService $logs,
        private readonly CrmSalesStaffService $staff,
        private readonly SalesCustomerOrderDetailsService $details,
    ) {}

    public function resolve(array &$payload, ?int $currentCustomerId = null): SalesCustomer
    {
        $actor = request() ? app(AuthContextService::class)->currentUser(request()) : null;
        $requested = !empty($payload['customer_id'])
            ? SalesCustomer::query()->findOrFail($payload['customer_id'])
            : null;
        if ($requested && $actor && (int) $requested->id !== (int) $currentCustomerId && $this->access->relation($requested, $actor) === null) {
            abort(403, '该客户由其他部门负责，不在你的可见范围内，请先申请协作或联系负责销售。');
        }

        $buyerId = trim((string) ($payload['platform_buyer_id'] ?? ''));
        $platform = ctype_digit(trim((string) ($payload['platform'] ?? ''))) ? (int) $payload['platform'] : null;
        $resolved = $buyerId !== '' && $platform ? $this->identities->resolve($platform, $buyerId, true) : ['key' => null];
        $owner = $this->identities->owner($resolved['key'], true);

        if ($requested) {
            if ($owner && (int) $owner->customer_id !== (int) $requested->id) {
                $this->identities->conflict($owner);
            }
            $customer = $requested;
        } elseif ($owner) {
            $customer = SalesCustomer::query()->findOrFail($owner->customer_id);
            if ($actor && $this->access->relation($customer, $actor) === null) {
                abort(403, '该平台买家ID属于其他部门的客户，请先申请协作后再建单。');
            }
        } else {
            $customer = $this->createFromOrder($payload, $actor);
        }
        abort_if($customer->status === 'blacklisted', 422, '该客户已在黑名单，不能创建销售订单。');
        abort_if($customer->status === 'disabled', 422, '该客户已停用，不能创建销售订单。');

        if ($resolved['key'] && !$owner) {
            $this->identities->bind($customer, $platform, $buyerId, 'order', $actor, '建单时登记');
        }

        $kind = $customer->customer_kind ?: 'individual';
        $contactName = trim((string) ($payload['contact_name'] ?? ''));
        if ($contactName === '' && $kind === 'individual') $contactName = $customer->customer_name;
        $contactPhone = trim((string) ($payload['contact_phone'] ?? $payload['customer_phone'] ?? '')) ?: null;
        $fullAddress = trim((string) ($payload['full_address'] ?? $payload['address'] ?? ''));
        if (empty($payload['customer_contact_id'])) {
            $payload['customer_contact_id'] = $this->details->contact($customer, $contactName, $contactPhone)?->id;
        }
        if (empty($payload['customer_address_id'])) {
            $payload['customer_address_id'] = $this->details->address($customer, $fullAddress, $contactName, $contactPhone, CrmCustomerDedupeService::dedupeKey($fullAddress))?->id;
        }
        $payload['customer_id'] = $customer->id;
        return $customer->fresh();
    }

    private function createFromOrder(array $payload, ?object $actor): SalesCustomer
    {
        $name = trim((string) ($payload['customer_name'] ?? ''));
        abort_if($name === '', 422, '请选择客户或填写客户名称。');
        $kind = in_array($payload['customer_kind'] ?? null, ['enterprise', 'individual'], true) ? $payload['customer_kind'] : 'individual';
        $matches = $this->dedupe->match([
            'customer_kind' => $kind, 'customer_name' => $name,
            'phones' => array_filter([$payload['contact_phone'] ?? null, $payload['customer_phone'] ?? null]),
            'full_address' => $payload['full_address'] ?? $payload['address'] ?? null,
        ]);
        if ($matches) {
            throw ValidationException::withMessages(['customer_name' => '已有同名或同电话的客户，请通过“选择客户”确认后选用，或在客户中心确认不是同一客户后新建。']);
        }
        $values = [
            'customer_code' => app(DocumentNumberService::class)->next('customer'),
            'customer_name' => $name, 'customer_kind' => $kind, 'status' => 'enabled',
            'dedupe_name_key' => CrmCustomerDedupeService::dedupeKey($name),
        ];
        if ($actor && $this->staff->isActiveSales((int) $actor->legacy_id)) {
            $values += ['owner_legacy_id' => (int) $actor->legacy_id, 'owner_name' => $this->staff->name((int) $actor->legacy_id),
                'owner_department_legacy_id' => $this->staff->departmentOf((int) $actor->legacy_id)];
        }
        $customer = SalesCustomer::create($values);
        $this->logs->write($customer->id, 'create', '建单时新建客户', null, $customer->only(['customer_name', 'customer_kind', 'owner_name']), null, $actor);
        return $customer;
    }
}
