<?php

namespace App\Services\Erp;

use App\Domain\Finance\Money;
use App\Models\Erp\SalesOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class SalesOrderFundingGateService
{
    /** 分次付款条件允许销售按与客户的约定逐单填写定金。 */
    public const AGREED_DEPOSIT_POLICY_TYPES = ['deposit_production', 'installment_contract', 'custom_threshold'];

    public static function supportsAgreedDeposit(?string $policyType): bool
    {
        return in_array((string) $policyType, self::AGREED_DEPOSIT_POLICY_TYPES, true);
    }

    /** 统一校验销售填写的约定定金：空值表示按付款条件默认值计算。 */
    public static function normalizeAgreedDeposit(mixed $value): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) return null;
        try {
            $amount = Money::normalize(trim((string) $value));
        } catch (Throwable) {
            throw ValidationException::withMessages(['agreed_deposit_amount' => '约定定金必须是有效金额。']);
        }
        if (Money::compare($amount, '0') < 0) {
            throw ValidationException::withMessages(['agreed_deposit_amount' => '约定定金不能小于 0。']);
        }
        return $amount;
    }

    public function __construct(
        private readonly SalesFinanceSettlementService $settlements,
        private readonly SalesOrderPlatformPaymentService $platformPayments,
    ) {}

    public function status(SalesOrder|int $order): array
    {
        if (! $order instanceof SalesOrder) {
            $query = SalesOrder::query();
            if (DB::transactionLevel() > 0) $query->lockForUpdate();
            $order = $query->findOrFail($order);
        }
        $settlement = $this->settlements->status($order);
        // 退货退款冲减后的净应收用于计算约定定金及未收款。
        $receivable = $settlement['net_receivable_amount'];
        $policy = $this->normalizePolicy((array) $order->funding_policy_snapshot, $receivable);
        $net = $settlement['net_received_amount'];
        $productionPassed = $settlement['production_funds_satisfied'];
        $platform = $this->platformPayments->status($order, $settlement);
        $shipmentPassed = $settlement['shipment_funds_satisfied'] || $platform['platform_payment'] !== null;
        $platformPaymentRequired = $productionPassed && !$shipmentPassed && $order->channel_type_snapshot === 'online_platform';
        $productionReason = $productionPassed ? null : 'order_cancelled';
        $shipmentReason = match (true) {
            $shipmentPassed => null,
            !$productionPassed => 'order_cancelled',
            $platformPaymentRequired && !$platform['platform_payment_eligible'] => 'platform_payment_order_missing',
            $platformPaymentRequired => 'platform_payment_unconfirmed',
            default => 'shipment_funds_insufficient',
        };
        // 未收金额拆成“还差定金”和“定金之后的尾款”，避免把含定金的全部未收款显示成尾款。
        $depositOutstanding = Money::maxZero(Money::sub($policy['production_required_amount'], $net));
        $balanceOutstanding = Money::maxZero(Money::sub($settlement['outstanding_amount'], $depositOutstanding));

        return array_merge($settlement, $platform, [
            'policy_configured' => $policy['configured'],
            'policy_valid' => $policy['valid'],
            'policy_type' => $policy['policy_type'],
            'policy_name' => $policy['policy_name'],
            'shipment_requires_full_payment' => true,
            'production_required_amount' => $policy['production_required_amount'],
            'deposit_source' => $policy['deposit_source'],
            'agreed_deposit_supported' => self::supportsAgreedDeposit($policy['policy_type']),
            'policy_deposit_amount' => $policy['policy_deposit_amount'],
            'agreed_deposit_amount' => $policy['agreed_deposit_amount'],
            'deposit_outstanding_amount' => $depositOutstanding,
            'balance_outstanding_amount' => $balanceOutstanding,
            'production_funds_satisfied' => $productionPassed,
            'shipment_funds_satisfied' => $shipmentPassed,
            'production_block_reason' => $productionReason,
            'production_block_message' => $this->reasonMessage($productionReason),
            'shipment_block_reason' => $shipmentReason,
            'shipment_block_message' => $this->reasonMessage($shipmentReason),
            'platform_payment_required' => $platformPaymentRequired,
            'payment_status' => Money::compare($net, '0') <= 0 ? 'unpaid' : ($settlement['shipment_funds_satisfied'] ? 'paid' : 'partially_paid'),
            'production_funding_status' => $productionPassed ? 'passed' : 'blocked',
            'shipment_funding_status' => $shipmentPassed ? 'passed' : 'blocked',
        ]);
    }

    /** 能查看订单的人都可查看销售金额，调用方仍负责订单范围和操作权限。 */
    public function statusForPermissions(SalesOrder|int $order, array $permissions, bool $superAdmin = false): array
    {
        return $this->status($order);
    }

    public function assertCanShip(SalesOrder|int $order): void
    {
        $status = $this->status($order);
        if (! $status['shipment_funds_satisfied']) throw ValidationException::withMessages(['funding' => $status['shipment_block_message']]);
    }

    /** 只写资金放行投影；完成状态等后续步骤由 SalesOrderStateCoordinator 统一编排。 */
    public function writeProjection(SalesOrder $order): array
    {
        $status = $this->status($order);
        $updates = [
            'payment_status' => $status['payment_status'],
            'production_funding_status' => $status['production_funding_status'],
            'shipment_funding_status' => $status['shipment_funding_status'],
            'final_receivable_amount' => $status['contract_amount'],
        ];
        if ($status['platform_payment'] === null && isset($order->order_flags[SalesOrderPlatformPaymentService::FLAG]['basis'])) {
            $updates['order_flags'] = $this->platformPayments->invalidate((array) $order->order_flags);
        }
        $order->update($updates);
        if (isset($updates['order_flags'])) $status = array_replace($status, $this->platformPayments->status($order, $status));
        return $status;
    }

    private function normalizePolicy(array $snapshot, string $receivable): array
    {
        $legacyFullPayment = $snapshot['full_payment']
            ?? $snapshot['full_payment_required']
            ?? $snapshot['requires_full_payment']
            ?? null;
        $configured = $snapshot !== [];
        $policyType = trim((string) ($snapshot['policy_type'] ?? ''));
        if ($policyType === '' && $legacyFullPayment === true) $policyType = 'full_prepay';
        $policyName = trim((string) ($snapshot['policy_name'] ?? '')) ?: ($policyType === 'full_prepay' ? '全额预付' : '待配置');
        $base = [
            'configured' => $configured,
            'policy_type' => $policyType ?: 'unconfigured',
            'policy_name' => $policyName,
            'production_required_amount' => $receivable,
            'deposit_source' => 'policy',
            'policy_deposit_amount' => null,
            'agreed_deposit_amount' => null,
            'valid' => false,
        ];
        if (! $configured || $policyType === '') return $base;

        $shipmentFull = array_key_exists('shipment_requires_full_payment', $snapshot)
            ? (bool) $snapshot['shipment_requires_full_payment']
            : ($legacyFullPayment === null ? true : (bool) $legacyFullPayment);
        if ($policyType === 'full_prepay') {
            $base['deposit_source'] = 'full_prepay';
            $base['valid'] = $shipmentFull;
            return $base;
        }
        if (! in_array($policyType, ['deposit_production', 'installment_contract', 'custom_threshold'], true)) {
            return $base;
        }

        $thresholdType = (string) ($snapshot['production_threshold_type'] ?? '');
        $thresholdValue = (string) ($snapshot['production_threshold_value'] ?? '');
        try {
            $normalized = Money::normalize($thresholdValue);
            if ($thresholdType === 'ratio') {
                if (Money::compare($normalized, '0') < 0 || Money::compare($normalized, '1') > 0) throw new \InvalidArgumentException();
                $required = bcmul($receivable, $normalized, Money::SCALE);
            } elseif ($thresholdType === 'amount') {
                if (Money::compare($normalized, '0') < 0) throw new \InvalidArgumentException();
                $required = Money::compare($normalized, $receivable) > 0 ? $receivable : $normalized;
            } else {
                throw new \InvalidArgumentException();
            }
        } catch (Throwable) {
            return $base;
        }
        $base['policy_deposit_amount'] = Money::normalize($required);
        // 销售与客户谈定的定金优先于付款条件默认值；超过合同应收时按合同应收封顶。
        $agreed = $snapshot['agreed_deposit_amount'] ?? null;
        if ($agreed !== null && $agreed !== '') {
            try {
                $agreed = Money::normalize((string) $agreed);
            } catch (Throwable) {
                $agreed = null;
            }
            if ($agreed !== null && Money::compare($agreed, '0') >= 0) {
                $base['agreed_deposit_amount'] = $agreed;
                $base['deposit_source'] = 'agreed';
                $required = Money::compare($agreed, $receivable) > 0 ? $receivable : $agreed;
            }
        }
        $base['production_required_amount'] = Money::normalize($required);
        $base['valid'] = $shipmentFull;
        return $base;
    }

    private function reasonMessage(?string $reason): ?string
    {
        return match ($reason) {
            null => null,
            'order_cancelled' => '销售订单已取消，不能按原订单开工或发货。',
            'platform_payment_order_missing' => '请先补齐成交平台和原始单号，再由录单人核对平台全款。',
            'platform_payment_unconfirmed' => '请由录单人在订单表单核对平台全款并勾选确认，确认后才能发货。',
            'shipment_funds_insufficient' => '订单款项未收齐，暂不能发货。',
            default => '当前资金条件未满足。',
        };
    }
}
