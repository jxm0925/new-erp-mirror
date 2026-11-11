<?php

namespace App\Services\Erp;

use App\Domain\Finance\Money;
use App\Models\Erp\SalesCustomer;
use App\Models\Erp\SalesCustomerAddress;
use App\Models\Erp\SalesCustomerContact;
use App\Models\Erp\SalesChannel;
use App\Models\Erp\SalesFundingPolicy;
use App\Models\Erp\SalesOrder;
use Illuminate\Support\Facades\DB;

class SalesOrderSnapshotService
{
    public function __construct(
        private readonly PaymentMethodApplicationService $paymentMethods,
        private readonly SalesCustomerOrderDetailsService $customerDetails,
    ) {}

    public function lock(array $payload, ?SalesOrder $current = null): array
    {
        // 约定定金不是订单表字段，只随付款资金策略快照保存，先从表头载荷中取出。
        $agreedDeposit = SalesOrderFundingGateService::normalizeAgreedDeposit($payload['agreed_deposit_amount'] ?? null);
        unset($payload['agreed_deposit_amount']);
        $addressSupplied = array_key_exists('full_address', $payload) || array_key_exists('address', $payload);
        $enteredAddress = trim((string) (array_key_exists('full_address', $payload) ? $payload['full_address'] : ($payload['address'] ?? '')));
        if ($addressSupplied) {
            // 显式清空只清订单地址；兼容字段同步后不会被客户整理流程当成新地址写回主档。
            $payload['full_address'] = $enteredAddress ?: null;
            $payload['address'] = $enteredAddress ?: null;
        }
        $customer = $this->resolveAndUpsertCustomer($payload, $current);
        abort_if($customer->status === 'blacklisted', 422, '该客户已在黑名单，不能创建销售订单');

        $contact = $this->contact($customer, $payload['customer_contact_id'] ?? null);
        $address = $addressSupplied && $enteredAddress === '' ? null : $this->address($customer, $payload['customer_address_id'] ?? null);

        $contactName = trim((string) ($payload['contact_name'] ?? ''))
            ?: ($contact?->contact_name ?: $customer->contact_name);
        $contactPhone = trim((string) ($payload['contact_phone'] ?? $payload['customer_phone'] ?? ''))
            ?: ($contact?->mobile ?: $contact?->phone ?: $customer->contact_phone);
        $fullAddress = $addressSupplied ? $enteredAddress : ($address?->full_address ?: $customer->full_address);

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
            'detail_address' => $fullAddress ?: null,
            'full_address' => $fullAddress ?: null,
        ];
        $payload['customer_snapshot'] = [
            'id' => $customer->id,
            'customer_code' => $customer->customer_code,
            'name' => $customer->customer_name,
            'customer_kind' => $customer->customer_kind,
            'source_platform' => $customer->source_platform,
            // 本单买家ID记在订单快照里；客户主档不再被订单覆盖。
            'platform_buyer_id' => trim((string) ($payload['platform_buyer_id'] ?? '')) ?: $customer->platform_buyer_id,
            'contact' => [
                'id' => $contact?->id,
                'name' => $contactName ?: null,
                'phone' => $contactPhone ?: null,
            ],
            'address' => $payload['shipping_address_snapshot'],
        ];

        $channel = !empty($payload['sales_channel_id'])
            ? SalesChannel::query()->where('status', 'enabled')->findOrFail($payload['sales_channel_id'])
            : ($current?->salesChannel ?? SalesChannel::query()->where('status', 'enabled')->where('is_default', true)->orderBy('sort')->firstOrFail());
        $policy = !empty($payload['funding_policy_id'])
            ? SalesFundingPolicy::query()->where('status', 'enabled')->findOrFail($payload['funding_policy_id'])
            : SalesFundingPolicy::query()
                ->where('status', 'enabled')
                ->where('policy_type', 'full_prepay')
                ->orderByRaw('CASE WHEN policy_code = ? THEN 0 ELSE 1 END', ['FULL_PREPAY'])
                ->orderBy('id')
                ->firstOrFail();
        $paymentMethodValue = $payload['payment_method_id'] ?? $payload['pay_type'] ?? null;
        abort_if(blank($paymentMethodValue), 422, '请选择正式付款方式');
        $paymentMethod = $this->paymentMethods->resolveForSales($paymentMethodValue);
        $channelFields = $this->channelFields($channel, $payload['external_order_no'] ?? $current?->external_order_no);
        $channelFields['transaction_mode'] = $payload['transaction_mode'] ?? $current?->transaction_mode ?? $channelFields['transaction_mode'];
        $paymentFields = app(SalesTradePlatformService::class)->orderPaymentFields($payload, $current);
        $payload = array_replace($payload, $channelFields, $paymentFields);
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
        if ($agreedDeposit !== null && SalesOrderFundingGateService::supportsAgreedDeposit($policy->policy_type)) {
            $payload['funding_policy_snapshot']['agreed_deposit_amount'] = $agreedDeposit;
        }
        $payload['payment_method_id'] = $paymentMethod->id;
        $payload['payment_method_snapshot'] = $this->paymentMethods->snapshot($paymentMethod);
        // pay_type is retained as the compact display/search field, but its
        // value is now the stable code from the same formal master used by finance.
        $payload['pay_type'] = $paymentMethod->method_code;
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

    public function channelFields(SalesChannel $channel, ?string $externalOrderNo): array
    {
        $number = trim((string) $externalOrderNo);

        return [
            'sales_channel_id' => $channel->id,
            'sales_channel_code_snapshot' => $channel->channel_code,
            'sales_channel_name_snapshot' => $channel->channel_name,
            'channel_type_snapshot' => $channel->channel_type,
            'transaction_mode' => $channel->transaction_mode,
            'external_order_no' => $number === '' ? null : $number,
        ];
    }

    /** Freeze the currently selected master records again at formal confirmation. */
    public function freezeFinancialTermsForConfirmation(SalesOrder $order): SalesOrder
    {
        $updates = [];
        if ($order->payment_method_id) {
            $method = $this->paymentMethods->resolveForSales((int) $order->payment_method_id);
            $updates['payment_method_snapshot'] = $this->paymentMethods->snapshot($method);
            $updates['pay_type'] = $method->method_code;
        }
        if ($order->funding_policy_id) {
            $policy = SalesFundingPolicy::query()->where('status', 'enabled')->findOrFail($order->funding_policy_id);
            $updates['funding_policy_snapshot'] = [
                'id' => $policy->id,
                'policy_code' => $policy->policy_code,
                'policy_name' => $policy->policy_name,
                'policy_type' => $policy->policy_type,
                'production_threshold_type' => $policy->production_threshold_type,
                'production_threshold_value' => (string) $policy->production_threshold_value,
                'shipment_requires_full_payment' => (bool) $policy->shipment_requires_full_payment,
                'receivable_amount' => (string) ($order->final_receivable_amount ?: $order->total_amount),
                'frozen_at' => now()->toISOString(),
            ];
            // 正式确认重新冻结付款条件时，保留销售已与客户约定的定金，不被默认比例覆盖。
            $agreedDeposit = data_get($order->funding_policy_snapshot, 'agreed_deposit_amount');
            if ($agreedDeposit !== null && $agreedDeposit !== '' && SalesOrderFundingGateService::supportsAgreedDeposit($policy->policy_type)) {
                $updates['funding_policy_snapshot']['agreed_deposit_amount'] = Money::normalize((string) $agreedDeposit);
            }
            $updates['payment_terms_snapshot'] = $updates['funding_policy_snapshot'];
        }
        if ($updates !== []) $order->update($updates);
        // 无负责销售的客户在首个有效订单确认时自动归属该订单销售（用户确认的“谁先用归谁”）。
        if ($order->customer_id) {
            app(\App\Services\Erp\Crm\CrmCustomerApplicationService::class)
                ->claimIfUnowned((int) $order->customer_id, $order->sales_user_legacy_id ? (int) $order->sales_user_legacy_id : null, '有效订单确认');
        }
        return $order->fresh();
    }

    /**
     * 客户识别统一交给 CRM 共用解析器：不覆盖客户主资料、买家ID冲突拒绝、不可见客户需协作。
     * 编辑已有订单时原客户保持可用，避免可见范围调整后旧单无法保存。
     */
    private function resolveAndUpsertCustomer(array &$payload, ?SalesOrder $current = null): SalesCustomer
    {
        return app(\App\Services\Erp\Crm\CrmOrderCustomerResolver::class)->resolve($payload, $current?->customer_id);
    }

    private function contact(SalesCustomer $customer, ?int $id): ?SalesCustomerContact
    {
        if ($id) {
            return SalesCustomerContact::query()
                ->where('customer_id', $customer->id)
                ->where('status', 'enabled')
                ->lockForUpdate()
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
                ->lockForUpdate()
                ->findOrFail($id);
        }

        return $customer->addresses()->where('status', 'enabled')->orderByDesc('is_default')->first();
    }
}
