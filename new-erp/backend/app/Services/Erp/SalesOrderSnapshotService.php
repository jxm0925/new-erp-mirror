<?php

namespace App\Services\Erp;

use App\Models\Erp\SalesCustomer;
use App\Models\Erp\SalesCustomerAddress;
use App\Models\Erp\SalesCustomerContact;
use App\Models\Erp\SalesChannel;
use App\Models\Erp\SalesFundingPolicy;
use Illuminate\Support\Facades\DB;

class SalesOrderSnapshotService
{
    public function lock(array $payload): array
    {
        $customer = $this->resolveAndUpsertCustomer($payload);
        abort_if($customer->status === 'blacklisted', 422, '该客户已在黑名单，不能创建销售订单');

        $contact = $this->contact($customer, $payload['customer_contact_id'] ?? null);
        $address = $this->address($customer, $payload['customer_address_id'] ?? null);

        $contactName = trim((string) ($payload['contact_name'] ?? ''))
            ?: ($contact?->contact_name ?: $customer->contact_name);
        $contactPhone = trim((string) ($payload['contact_phone'] ?? $payload['customer_phone'] ?? ''))
            ?: ($contact?->mobile ?: $contact?->phone ?: $customer->contact_phone);
        $fullAddress = trim((string) ($payload['full_address'] ?? $payload['address'] ?? ''))
            ?: ($address?->full_address ?: $customer->full_address);

        $payload['customer_contact_id'] = $contact?->id;
        $payload['customer_address_id'] = $address?->id;
        $payload['customer_name'] = $customer->customer_name;
        $payload['customer_name_snapshot'] = $customer->customer_name;
        $payload['contact_name'] = $contactName ?: null;
        $payload['contact_phone'] = $contactPhone ?: null;
        $payload['customer_phone'] = $contactPhone ?: null;
        $payload['contact_name_snapshot'] = $contactName ?: null;
        $payload['contact_phone_snapshot'] = $contactPhone ?: null;
        $payload['full_address'] = $fullAddress ?: null;
        $payload['shipping_address_snapshot'] = [
            'address_id' => $address?->id,
            'receiver_name' => $contactName ?: $address?->receiver_name,
            'receiver_phone' => $contactPhone ?: $address?->receiver_phone,
            'province' => $address?->province,
            'city' => $address?->city,
            'district' => $address?->district,
            'detail_address' => $fullAddress ?: $address?->detail_address,
            'full_address' => $fullAddress ?: null,
        ];
        $payload['customer_snapshot'] = [
            'id' => $customer->id,
            'customer_code' => $customer->customer_code,
            'name' => $customer->customer_name,
            'customer_kind' => $customer->customer_kind,
            'source_platform' => $customer->source_platform,
            'platform_buyer_id' => $customer->platform_buyer_id,
            'contact' => [
                'id' => $contact?->id,
                'name' => $contactName ?: null,
                'phone' => $contactPhone ?: null,
            ],
            'address' => $payload['shipping_address_snapshot'],
        ];

        $channel = !empty($payload['sales_channel_id'])
            ? SalesChannel::query()->where('status', 'enabled')->findOrFail($payload['sales_channel_id'])
            : SalesChannel::query()->where('status', 'enabled')->where('is_default', true)->orderBy('sort')->firstOrFail();
        $policy = !empty($payload['funding_policy_id'])
            ? SalesFundingPolicy::query()->where('status', 'enabled')->findOrFail($payload['funding_policy_id'])
            : SalesFundingPolicy::query()
                ->where('status', 'enabled')
                ->where('policy_code', $channel->default_funding_policy_code)
                ->firstOrFail();
        $externalOrderNo = trim((string) ($payload['external_order_no'] ?? '')) ?: null;
        abort_if($channel->requires_external_order_no && !$externalOrderNo, 422, '当前成交渠道必须填写外部订单号');
        $payload['sales_channel_id'] = $channel->id;
        $payload['sales_channel_code_snapshot'] = $channel->channel_code;
        $payload['sales_channel_name_snapshot'] = $channel->channel_name;
        $payload['channel_type_snapshot'] = $channel->channel_type;
        $payload['transaction_mode'] = $payload['transaction_mode'] ?? $channel->transaction_mode;
        $payload['external_order_no'] = $externalOrderNo;
        $payload['channel_ordered_at'] = $payload['channel_ordered_at'] ?? $payload['order_time'] ?? now();
        $payload['funding_policy_id'] = $policy->id;
        $payload['funding_policy_snapshot'] = [
            'id' => $policy->id,
            'policy_code' => $policy->policy_code,
            'policy_name' => $policy->policy_name,
            'policy_type' => $policy->policy_type,
            'production_threshold_type' => $policy->production_threshold_type,
            'production_threshold_value' => (string) $policy->production_threshold_value,
            'shipment_requires_full_payment' => (bool) $policy->shipment_requires_full_payment,
        ];
        $payload['payment_terms_snapshot'] = is_array($payload['payment_terms_snapshot'] ?? null)
            ? $payload['payment_terms_snapshot']
            : ['policy_code' => $policy->policy_code, 'policy_name' => $policy->policy_name];

        $carrierId = $payload['default_carrier_id'] ?? $payload['carrier_id'] ?? null;
        $carrier = $carrierId
            ? DB::table('erp_sales_order_deliveries')->where('legacy_id', $carrierId)->where('enabled', true)->first()
            : null;
        $payload['carrier_id'] = $carrierId;
        $payload['default_carrier_id'] = $carrierId;
        $payload['default_carrier_name_snapshot'] = $carrier?->name
            ?: data_get($payload, 'shipping_snapshot.default_carrier_name');
        $payload['shipping_snapshot'] = array_merge(
            is_array($payload['shipping_snapshot'] ?? null) ? $payload['shipping_snapshot'] : [],
            [
                'default_carrier_id' => $carrierId,
                'default_carrier_name' => $payload['default_carrier_name_snapshot'],
            ]
        );

        return $payload;
    }

    /**
     * Resolve a selected master customer or create/update one from the order's
     * platform identity. This is intentionally here, before snapshots are
     * frozen, so master-data dedupe and order facts use the same identity.
     */
    private function resolveAndUpsertCustomer(array &$payload): SalesCustomer
    {
        $requested = !empty($payload['customer_id'])
            ? SalesCustomer::query()->where('status', '<>', 'disabled')->findOrFail($payload['customer_id'])
            : null;
        $kind = in_array($payload['customer_kind'] ?? null, ['enterprise', 'individual'], true)
            ? $payload['customer_kind']
            : ($requested?->customer_kind ?: 'individual');
        $name = trim((string) ($payload['customer_name'] ?? $requested?->customer_name ?? ''));
        abort_if($name === '', 422, '请选择客户或填写客户名称。');

        $sourcePlatform = $this->sourcePlatform($payload) ?: $requested?->source_platform;
        $buyerId = trim((string) ($payload['platform_buyer_id'] ?? '')) ?: $requested?->platform_buyer_id;
        $identityKey = $this->platformIdentityKey($sourcePlatform, $buyerId);
        $fullAddress = trim((string) ($payload['full_address'] ?? $payload['address'] ?? ''));
        $nameKey = $this->dedupeKey($name);
        $addressKey = $this->dedupeKey($fullAddress);
        // A platform buyer id is stronger than a manually selected name: the
        // same buyer can use aliases or virtual phone numbers on later orders.
        $identityCustomer = $identityKey
            ? SalesCustomer::query()->where('platform_identity_key', $identityKey)->first()
            : null;
        $customer = $identityCustomer ?: $requested ?: $this->findDuplicateCustomer($kind, $identityKey, $nameKey, $addressKey);
        abort_if($customer?->status === 'blacklisted', 422, '该客户已在黑名单，不能创建销售订单。');

        $contactName = trim((string) ($payload['contact_name'] ?? ''));
        if ($contactName === '' && $kind === 'individual') $contactName = $name;
        $contactPhone = trim((string) ($payload['contact_phone'] ?? $payload['customer_phone'] ?? '')) ?: null;
        $master = array_filter([
            'customer_name' => $name,
            'customer_kind' => $kind,
            'source_platform' => $sourcePlatform,
            'platform_buyer_id' => $buyerId,
            'platform_identity_key' => $identityKey,
            'dedupe_name_key' => $nameKey,
            'dedupe_address_key' => $addressKey,
            'contact_name' => $contactName ?: null,
            'contact_phone' => $contactPhone,
            'full_address' => $fullAddress ?: null,
            'status' => 'enabled',
        ], static fn ($value) => $value !== null && $value !== '');
        if ($customer) {
            $customer->update($master);
        } else {
            $customer = SalesCustomer::create([
                'customer_code' => 'CUST-'.now()->format('YmdHis').'-'.random_int(100, 999),
                ...$master,
            ]);
        }

        // Explicit selected contacts/addresses retain precedence. For direct
        // platform input, create/update a default master contact and address.
        if (empty($payload['customer_contact_id']) && ($contactName !== '' || $contactPhone)) {
            $contact = $customer->contacts()->where('status', 'enabled')->orderByDesc('is_default')->first();
            $contactData = array_filter([
                'contact_name' => $contactName ?: $customer->customer_name,
                'mobile' => $contactPhone,
                'phone' => $contactPhone,
                'is_default' => true,
                'status' => 'enabled',
            ], static fn ($value) => $value !== null && $value !== '');
            if ($contact) $contact->update($contactData);
            else $contact = $customer->contacts()->create($contactData);
            $payload['customer_contact_id'] = $contact->id;
        }
        if (empty($payload['customer_address_id']) && $fullAddress !== '') {
            $address = $customer->addresses()->where('status', 'enabled')->orderByDesc('is_default')->first();
            $addressData = [
                'receiver_name' => $contactName ?: $customer->customer_name,
                'receiver_phone' => $contactPhone,
                'detail_address' => $fullAddress,
                'full_address' => $fullAddress,
                'is_default' => true,
                'status' => 'enabled',
            ];
            if ($address) $address->update($addressData);
            else $address = $customer->addresses()->create($addressData);
            $payload['customer_address_id'] = $address->id;
        }
        $payload['customer_id'] = $customer->id;
        return $customer->fresh();
    }

    private function findDuplicateCustomer(string $kind, ?string $identityKey, ?string $nameKey, ?string $addressKey): ?SalesCustomer
    {
        if ($kind === 'individual' && $identityKey) {
            return SalesCustomer::query()->where('platform_identity_key', $identityKey)->first();
        }
        if ($kind === 'enterprise' && $nameKey) {
            return SalesCustomer::query()->where('customer_kind', 'enterprise')->where('dedupe_name_key', $nameKey)->first();
        }
        if ($kind === 'individual' && $nameKey && $addressKey) {
            return SalesCustomer::query()->where('customer_kind', 'individual')
                ->where('dedupe_name_key', $nameKey)->where('dedupe_address_key', $addressKey)->first();
        }
        return null;
    }

    private function sourcePlatform(array $payload): ?string
    {
        $parts = array_filter([
            trim((string) ($payload['platform'] ?? '')),
            trim((string) ($payload['platform2'] ?? '')),
        ]);
        return $parts ? implode(':', $parts) : null;
    }

    private function dedupeKey(?string $value): ?string
    {
        $value = preg_replace('/[\s\p{P}\p{S}]+/u', '', mb_strtolower(trim((string) $value)));
        return $value === '' ? null : mb_substr($value, 0, 191);
    }

    private function platformIdentityKey(?string $sourcePlatform, ?string $buyerId): ?string
    {
        return $sourcePlatform && $buyerId ? hash('sha256', $sourcePlatform."\0".$buyerId) : null;
    }

    private function contact(SalesCustomer $customer, ?int $id): ?SalesCustomerContact
    {
        if ($id) {
            return SalesCustomerContact::query()
                ->where('customer_id', $customer->id)
                ->where('status', 'enabled')
                ->findOrFail($id);
        }

        return $customer->contacts()->where('status', 'enabled')->orderByDesc('is_default')->first();
    }

    private function address(SalesCustomer $customer, ?int $id): ?SalesCustomerAddress
    {
        if ($id) {
            return SalesCustomerAddress::query()
                ->where('customer_id', $customer->id)
                ->where('status', 'enabled')
                ->findOrFail($id);
        }

        return $customer->addresses()->where('status', 'enabled')->orderByDesc('is_default')->first();
    }
}
