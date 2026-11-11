<?php

namespace App\Services\Erp\Crm;

use App\Models\Erp\SalesCustomer;
use Illuminate\Support\Facades\DB;

/**
 * 统一客户查重：客户新建、建单、线索录入都用同一份匹配规则。
 * 强身份（平台身份、统一社会信用代码）命中只能关联已有客户，不能另建；
 * 手机、微信、邮箱、名称地址只是候选依据，由人工判断，绝不自动合并或覆盖已有资料。
 */
final class CrmCustomerDedupeService
{
    private const LABELS = [
        'identity' => '平台身份相同', 'credit_code' => '统一社会信用代码相同', 'phone' => '手机号相同',
        'wechat' => '微信号相同', 'email' => '邮箱相同', 'name' => '名称相同', 'name_address' => '姓名及地址相同', 'name_region' => '姓名及地区相同',
    ];
    private const STRONG = ['identity', 'credit_code'];

    public function __construct(
        private readonly CrmPlatformIdentityService $identities,
        private readonly CrmCustomerAccessService $access,
    ) {}

    /** @return array<int, array{customer_id:int, reasons:string[], strong:bool}> */
    public function match(array $input, ?int $excludeId = null, bool $lock = false): array
    {
        $hits = [];
        $add = function (iterable $ids, string $reason) use (&$hits, $excludeId): void {
            foreach ($ids as $id) {
                $id = (int) $id;
                if ($id === $excludeId) continue;
                $hits[$id][$reason] = true;
            }
        };

        if (!empty($input['platform_legacy_id']) && trim((string) ($input['platform_buyer_id'] ?? '')) !== '') {
            $owner = $this->identities->owner($this->identities->resolve((int) $input['platform_legacy_id'], $input['platform_buyer_id'], $lock)['key'], $lock);
            if ($owner) $add([$owner->customer_id], 'identity');
        }
        if ($credit = $this->normalizeCode($input['credit_code'] ?? null)) {
            $add(SalesCustomer::query()->where('credit_code', $credit)->when($lock, fn ($q) => $q->lockForUpdate())->pluck('id'), 'credit_code');
        }
        $phones = array_values(array_unique(array_filter(array_map(fn ($p) => trim((string) $p), (array) ($input['phones'] ?? [])))));
        if ($phones) {
            $add(DB::table('erp_sales_customer_contacts')->where('status', 'enabled')
                ->where(fn ($q) => $q->whereIn('mobile', $phones)->orWhereIn('phone', $phones))
                ->when(!$lock, fn ($q) => $q->distinct())->when($lock, fn ($q) => $q->lockForUpdate())->pluck('customer_id'), 'phone');
            $add(SalesCustomer::query()->whereIn('contact_phone', $phones)->when($lock, fn ($q) => $q->lockForUpdate())->pluck('id'), 'phone');
        }
        if ($wechat = trim((string) ($input['wechat'] ?? ''))) {
            $add(DB::table('erp_sales_customer_contacts')->where('status', 'enabled')->where('wechat', $wechat)
                ->when(!$lock, fn ($q) => $q->distinct())->when($lock, fn ($q) => $q->lockForUpdate())->pluck('customer_id'), 'wechat');
        }
        if ($email = mb_strtolower(trim((string) ($input['email'] ?? '')))) {
            $add(DB::table('erp_sales_customer_contacts')->where('status', 'enabled')->where('email', $email)
                ->when(!$lock, fn ($q) => $q->distinct())->when($lock, fn ($q) => $q->lockForUpdate())->pluck('customer_id'), 'email');
        }
        $nameKey = self::dedupeKey($input['customer_name'] ?? null);
        if ($nameKey) {
            $kind = ($input['customer_kind'] ?? 'individual') === 'enterprise' ? 'enterprise' : 'individual';
            if ($kind === 'enterprise') {
                $add(SalesCustomer::query()->where('customer_kind', 'enterprise')->where('dedupe_name_key', $nameKey)
                    ->when($lock, fn ($q) => $q->lockForUpdate())->pluck('id'), 'name');
            } else {
                if ($addressKey = self::dedupeKey($input['full_address'] ?? null)) {
                    $add(SalesCustomer::query()->where('customer_kind', 'individual')->where('dedupe_name_key', $nameKey)
                        ->where('dedupe_address_key', $addressKey)->when($lock, fn ($q) => $q->lockForUpdate())->pluck('id'), 'name_address');
                }
                if (!empty($input['region_id'])) {
                    $add(SalesCustomer::query()->where('customer_kind', 'individual')->where('dedupe_name_key', $nameKey)
                        ->where(fn ($q) => $q->where('region_id', (int) $input['region_id'])
                            ->orWhereExists(fn ($a) => $a->from('erp_sales_customer_addresses as a')->whereColumn('a.customer_id', 'erp_sales_customers.id')
                                ->where('a.status', 'enabled')->where('a.region_id', (int) $input['region_id'])->when($lock, fn ($q) => $q->lockForUpdate())))
                        ->when($lock, fn ($q) => $q->lockForUpdate())->pluck('id'), 'name_region');
                }
            }
        }

        $rows = [];
        foreach ($hits as $id => $reasons) {
            $keys = array_keys($reasons);
            $rows[] = ['customer_id' => $id, 'reasons' => $keys, 'strong' => (bool) array_intersect($keys, self::STRONG)];
        }
        usort($rows, fn ($a, $b) => [$b['strong'], count($b['reasons'])] <=> [$a['strong'], count($a['reasons'])]);
        return array_slice($rows, 0, 10);
    }

    /** 给前端的候选：可见客户给出联系人与最近联系，不可见客户只给名称、部门、负责人。 */
    public function present(array $matches, object $user): array
    {
        $customers = SalesCustomer::query()->whereIn('id', array_column($matches, 'customer_id'))->get()->keyBy('id');
        return array_values(array_filter(array_map(function (array $match) use ($customers, $user) {
            $customer = $customers->get($match['customer_id']);
            if (!$customer) return null;
            $base = $this->access->relation($customer, $user) !== null
                ? [
                    'id' => $customer->id, 'customer_code' => $customer->customer_code, 'customer_name' => $customer->customer_name,
                    'customer_kind' => $customer->customer_kind, 'status' => $customer->status, 'owner_name' => $customer->owner_name,
                    'owner_department_name' => $this->access->departmentName($customer->owner_department_legacy_id),
                    'contact_name' => $customer->contact_name, 'contact_phone' => $this->maskPhone($customer->contact_phone),
                    'full_address' => $customer->full_address, 'last_contact_at' => optional($customer->last_contact_at)->toDateString(), 'visible' => true,
                ]
                : $this->access->masked($customer);
            return $base + [
                'strong' => $match['strong'],
                'reasons' => $match['reasons'],
                'reason_labels' => array_map(fn ($r) => self::LABELS[$r] ?? $r, $match['reasons']),
            ];
        }, $matches)));
    }

    public static function dedupeKey(?string $value): ?string
    {
        $value = preg_replace('/[\s\p{P}\p{S}]+/u', '', mb_strtolower(trim((string) $value)));
        return $value === '' ? null : mb_substr($value, 0, 191);
    }

    public function normalizeCode(?string $code): ?string
    {
        $code = strtoupper(preg_replace('/\s+/', '', (string) $code));
        return $code === '' ? null : $code;
    }

    private function maskPhone(?string $phone): ?string
    {
        $phone = trim((string) $phone);
        return mb_strlen($phone) >= 7 ? mb_substr($phone, 0, 3).'****'.mb_substr($phone, -4) : ($phone ?: null);
    }
}
