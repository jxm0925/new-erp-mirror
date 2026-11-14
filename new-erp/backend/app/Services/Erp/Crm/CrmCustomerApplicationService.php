<?php

namespace App\Services\Erp\Crm;

use App\Models\Erp\SalesCustomer;
use App\Models\Erp\SalesCustomerAddress;
use App\Models\Erp\SalesCustomerContact;
use App\Services\Erp\CustomerRegionService;
use App\Services\Erp\DocumentNumberService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 客户档案的正式写入口：新建、编辑、转交负责销售、首次使用自动归属。
 * 负责销售（owner_legacy_id）只由系统规则或转交改变，表单不能直接改；历史订单的销售归属不受影响。
 */
final class CrmCustomerApplicationService
{
    /** 编辑客户时可改的主资料字段；负责销售、平台身份、联系时间不在此列。 */
    public const EDITABLE = [
        'customer_name', 'customer_short_name', 'customer_kind', 'customer_type', 'status', 'remark',
        'company_background', 'customer_grade', 'industry', 'purchasing_power', 'business_lines', 'credit_code', 'region_id',
        'invoice_type', 'invoice_title', 'invoice_tax_no', 'invoice_address_phone', 'invoice_bank_account', 'invoice_email',
    ];

    public function __construct(
        private readonly CrmCustomerAccessService $access,
        private readonly CrmCustomerDedupeService $dedupe,
        private readonly CrmCustomerLogService $logs,
        private readonly CrmSalesStaffService $staff,
        private readonly DocumentNumberService $numbers,
    ) {}

    public function create(array $data, object $actor, ?string $reservationToken = null, ?string $creationSessionId = null): SalesCustomer
    {
        return DB::transaction(function () use ($data, $actor, $reservationToken, $creationSessionId) {
            if (!empty($data['region_id'])) app(CustomerRegionService::class)->selectablePath((int) $data['region_id']);
            $contacts = $data['contacts'] ?? null;
            $addresses = $data['addresses'] ?? null;
            $this->guardDuplicates($data, $contacts, $addresses, null, $actor);

            $values = array_intersect_key($data, array_flip(self::EDITABLE));
            $values['customer_kind'] = $values['customer_kind'] ?? 'individual';
            $values['credit_code'] = $this->dedupe->normalizeCode($values['credit_code'] ?? null);
            $values['dedupe_name_key'] = CrmCustomerDedupeService::dedupeKey($values['customer_name'] ?? null);
            // 销售本人建档即为负责销售；运营等非销售人员建档暂无负责人，之后由首次使用或部组长分配归属。
            if ($this->staff->isActiveSales((int) $actor->legacy_id)) {
                $values['owner_legacy_id'] = (int) $actor->legacy_id;
                $values['owner_name'] = $this->staff->name((int) $actor->legacy_id);
                $values['owner_department_legacy_id'] = $this->staff->departmentOf((int) $actor->legacy_id);
            }
            $values['customer_code'] = $reservationToken
                ? $this->numbers->reservedNumber($reservationToken, 'customer', $actor->legacy_id, $creationSessionId)
                : $this->numbers->next('customer');
            $customer = $this->persist(fn () => SalesCustomer::create($values));
            $this->syncChildren($customer, $contacts, $addresses);
            if ($reservationToken) $this->numbers->consume($reservationToken, 'customer', $customer->customer_code, $actor->legacy_id, 'customer', $customer->id);
            $reason = !empty($data['duplicate_confirmed']) ? (string) ($data['duplicate_reason'] ?? '') : null;
            $this->logs->write($customer->id, 'create', '新建客户'.($customer->owner_name ? "，负责销售 {$customer->owner_name}" : '，暂无负责销售'), null, $this->snapshot($customer), $reason, $actor);
            return $customer->fresh(['contacts', 'addresses']);
        }, 3);
    }

    public function update(int $id, array $data, object $actor): SalesCustomer
    {
        return DB::transaction(function () use ($id, $data, $actor) {
            $customer = SalesCustomer::query()->lockForUpdate()->findOrFail($id);
            $this->access->assert($customer, $actor, 'edit', '无权编辑该客户。');
            if ((int) ($data['crm_version'] ?? 0) !== (int) $customer->crm_version) {
                throw ValidationException::withMessages(['crm_version' => '客户资料已被其他人修改，请刷新后重新编辑。']);
            }
            $before = $this->snapshot($customer);
            if (!empty($data['region_id'])) app(CustomerRegionService::class)->selectablePath((int) $data['region_id'], (int) $customer->region_id);
            $values = array_intersect_key($data, array_flip(self::EDITABLE));
            if (array_key_exists('credit_code', $values)) $values['credit_code'] = $this->dedupe->normalizeCode($values['credit_code']);
            if (array_key_exists('customer_name', $values)) $values['dedupe_name_key'] = CrmCustomerDedupeService::dedupeKey($values['customer_name']);
            $values['crm_version'] = $customer->crm_version + 1;
            $this->persist(fn () => $customer->update($values));
            $this->syncChildren($customer, $data['contacts'] ?? null, $data['addresses'] ?? null);
            $after = $this->snapshot($customer->fresh());
            $changed = array_keys(array_filter($after, fn ($v, $k) => ($before[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH));
            if ($changed || ($data['contacts'] ?? null) !== null || ($data['addresses'] ?? null) !== null) {
                $this->logs->write($customer->id, 'edit', '编辑客户资料', array_intersect_key($before, array_flip($changed)), array_intersect_key($after, array_flip($changed)), null, $actor);
            }
            return $customer->fresh(['contacts', 'addresses']);
        }, 3);
    }

    public function transfer(int $id, int $toUserId, string $reason, int $expectedVersion, object $actor): SalesCustomer
    {
        return DB::transaction(function () use ($id, $toUserId, $reason, $expectedVersion, $actor) {
            $customer = SalesCustomer::query()->lockForUpdate()->findOrFail($id);
            $this->access->assert($customer, $actor, 'transfer', '无权转交该客户。');
            if ($expectedVersion !== (int) $customer->crm_version) {
                throw ValidationException::withMessages(['crm_version' => '客户负责销售已发生变化，请刷新后重试。']);
            }
            if (!$this->staff->isActiveSales($toUserId)) {
                throw ValidationException::withMessages(['to_user_legacy_id' => '请选择在职且销售员档案有效的销售。']);
            }
            if ((int) $customer->owner_legacy_id === $toUserId) {
                throw ValidationException::withMessages(['to_user_legacy_id' => '新负责销售与当前相同。']);
            }
            $before = $customer->only(['owner_legacy_id', 'owner_name', 'owner_department_legacy_id']);
            $customer->update([
                'owner_legacy_id' => $toUserId, 'owner_name' => $this->staff->name($toUserId),
                'owner_department_legacy_id' => $this->staff->departmentOf($toUserId), 'crm_version' => $customer->crm_version + 1,
            ]);
            $after = $customer->only(['owner_legacy_id', 'owner_name', 'owner_department_legacy_id']);
            $this->logs->write($customer->id, 'transfer', '负责销售由 '.($before['owner_name'] ?: '无').' 转为 '.$after['owner_name'].'；历史订单归属不变', $before, $after, $reason, $actor);
            return $customer->fresh();
        }, 3);
    }

    /**
     * 无负责销售的客户在首次被有效订单（或后续线索）使用时自动归属该销售。
     * 行锁后再判断，避免两张订单同时确认把客户归给不同的人；已有负责人时什么也不做。
     */
    public function claimIfUnowned(int $customerId, ?int $userId, string $source, ?object $actor = null): void
    {
        if (!$userId || !$this->staff->isActiveSales($userId)) return;
        $customer = SalesCustomer::query()->lockForUpdate()->find($customerId);
        if (!$customer || $customer->owner_legacy_id) return;
        $customer->update([
            'owner_legacy_id' => $userId, 'owner_name' => $this->staff->name($userId),
            'owner_department_legacy_id' => $this->staff->departmentOf($userId), 'crm_version' => $customer->crm_version + 1,
        ]);
        $this->logs->write($customerId, 'auto_claim', "首次{$source}，自动归属负责销售 {$customer->owner_name}", null,
            $customer->only(['owner_legacy_id', 'owner_name', 'owner_department_legacy_id']), null, $actor);
    }

    /**
     * 强身份（信用代码、平台身份）命中直接拒绝新建；手机/名称等候选需录入人确认“都不是”并写原因才可新建。
     * 返回 422 + 候选清单，前端据此展示查重结果弹窗。
     */
    public function guardDuplicates(array $data, ?array $contacts, ?array $addresses, ?int $excludeId, object $actor): void
    {
        $address = collect($addresses ?? [])->firstWhere('is_default', true) ?: collect($addresses ?? [])->first();
        $input = [
            'customer_kind' => $data['customer_kind'] ?? 'individual', 'customer_name' => $data['customer_name'] ?? null,
            'credit_code' => $data['credit_code'] ?? null, 'region_id' => $data['region_id'] ?? ($address['region_id'] ?? null),
            'platform_legacy_id' => $data['platform_legacy_id'] ?? null, 'platform_buyer_id' => $data['platform_buyer_id'] ?? null,
            'phones' => collect($contacts ?? [])->flatMap(fn ($c) => [$c['mobile'] ?? null, $c['phone'] ?? null])->merge(collect($addresses ?? [])->pluck('receiver_phone'))->filter()->values()->all(),
            'wechat' => collect($contacts ?? [])->pluck('wechat')->filter()->first(),
            'email' => collect($contacts ?? [])->pluck('email')->filter()->first(),
            'full_address' => $address['full_address'] ?? null,
        ];
        $matches = $this->dedupe->match($input, $excludeId);
        if (!$matches) return;
        $strong = (bool) array_filter($matches, fn ($m) => $m['strong']);
        if (!$strong && !empty($data['duplicate_confirmed']) && trim((string) ($data['duplicate_reason'] ?? '')) !== '') return;
        throw new HttpResponseException(response()->json([
            'message' => $strong ? '统一社会信用代码或平台身份已属于已有客户，不能重复新建，请选择该客户。' : '发现疑似客户，请确认是否为同一客户。',
            'duplicates' => $this->dedupe->present($matches, $actor),
            'requires_confirmation' => !$strong,
        ], 422));
    }

    public function snapshot(SalesCustomer $customer): array
    {
        return $customer->only(array_merge(self::EDITABLE, ['owner_legacy_id', 'owner_name', 'owner_department_legacy_id']));
    }

    private function persist(callable $write): mixed
    {
        try {
            return $write();
        } catch (QueryException $error) {
            if (($error->errorInfo[1] ?? null) === 1062 && str_contains($error->getMessage(), 'credit_code')) {
                throw ValidationException::withMessages(['credit_code' => '该统一社会信用代码已属于其他客户。']);
            }
            throw $error;
        }
    }

    private function syncChildren(SalesCustomer $customer, ?array $contacts, ?array $addresses): void
    {
        if ($contacts !== null) $this->sync($customer, $contacts, SalesCustomerContact::class, ['contact_name', 'mobile', 'phone', 'email', 'wechat', 'position', 'is_default', 'status', 'remark']);
        if ($addresses !== null) $this->sync($customer, $addresses, SalesCustomerAddress::class, ['receiver_name', 'receiver_phone', 'country', 'region_id', 'province', 'city', 'district', 'detail_address', 'full_address', 'is_default', 'status', 'remark']);
        $defaultContact = $customer->contacts()->where('status', 'enabled')->orderByDesc('is_default')->first();
        $defaultAddress = $customer->addresses()->where('status', 'enabled')->orderByDesc('is_default')->first();
        $customer->update([
            'contact_name' => $defaultContact?->contact_name,
            'contact_phone' => $defaultContact?->mobile ?: $defaultContact?->phone,
            'full_address' => $defaultAddress?->full_address,
            'province' => $defaultAddress?->province,
            'city' => $defaultAddress?->city,
            'district' => $defaultAddress?->district,
            'dedupe_name_key' => CrmCustomerDedupeService::dedupeKey($customer->customer_name),
            'dedupe_address_key' => CrmCustomerDedupeService::dedupeKey($defaultAddress?->full_address),
        ]);
    }

    /** 表单未带回的子记录停用而不是删除，历史订单仍可能引用它们。 */
    private function sync(SalesCustomer $customer, array $rows, string $model, array $allowed): void
    {
        $keep = [];
        foreach ($rows as $row) {
            $payload = array_intersect_key($row, array_flip($allowed));
            $payload['is_default'] = !empty($row['is_default']);
            $record = !empty($row['id']) ? $model::where('customer_id', $customer->id)->findOrFail($row['id']) : new $model(['customer_id' => $customer->id]);
            if ($model === SalesCustomerAddress::class && !empty($payload['region_id'])) {
                $payload = array_merge($payload, app(CustomerRegionService::class)->address(
                    (int) $payload['region_id'], (string) ($payload['detail_address'] ?? $record->detail_address),
                    $record->exists ? (int) $record->region_id : null));
            }
            $record->fill($payload)->save();
            $keep[] = $record->id;
        }
        $model::where('customer_id', $customer->id)->whereNotIn('id', $keep ?: [0])->update(['status' => 'disabled', 'is_default' => false]);
        $default = $model::where('customer_id', $customer->id)->where('is_default', true)->where('status', 'enabled')->orderBy('id')->first();
        if ($default) $model::where('customer_id', $customer->id)->whereKeyNot($default->id)->update(['is_default' => false]);
    }
}
