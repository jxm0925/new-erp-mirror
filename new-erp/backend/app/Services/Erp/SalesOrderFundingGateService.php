<?php

namespace App\Services\Erp;

use App\Domain\Finance\Money;
use App\Models\Erp\SalesOrder;
use Throwable;

final class SalesOrderFundingGateService
{
    private const AMOUNT_KEYS = [
        'contract_amount', 'received_amount', 'outstanding_amount',
        'available_prepayment_amount', 'refunded_amount', 'net_received_amount',
        'allocated_amount', 'unallocated_amount', 'receipt_ratio',
        'production_required_amount',
    ];

    public function __construct(private readonly SalesFinanceSettlementService $settlements) {}

    public function status(SalesOrder|int $order): array
    {
        $order = $order instanceof SalesOrder ? $order : SalesOrder::findOrFail($order);
        $settlement = $this->settlements->status($order);
        $receivable = $settlement['contract_amount'];
        $policy = $this->normalizePolicy((array) $order->funding_policy_snapshot, $receivable);
        $net = $settlement['net_received_amount'];
        $productionPassed = $policy['production_valid']
            && Money::compare($net, $policy['production_required_amount']) >= 0;
        $shipmentPassed = $policy['shipment_valid']
            && Money::compare($net, $receivable) >= 0;
        $productionReason = $productionPassed ? null : ($policy['production_block_reason'] ?: 'production_funds_insufficient');
        $shipmentReason = $shipmentPassed ? null : ($policy['shipment_block_reason'] ?: 'shipment_funds_insufficient');

        return array_merge($settlement, [
            'policy_configured' => $policy['configured'],
            'policy_valid' => $policy['production_valid'] && $policy['shipment_valid'],
            'policy_type' => $policy['policy_type'],
            'policy_name' => $policy['policy_name'],
            'shipment_requires_full_payment' => $policy['shipment_requires_full_payment'],
            'production_required_amount' => $policy['production_required_amount'],
            'production_funds_satisfied' => $productionPassed,
            'shipment_funds_satisfied' => $shipmentPassed,
            'production_block_reason' => $productionReason,
            'production_block_message' => $this->reasonMessage($productionReason),
            'shipment_block_reason' => $shipmentReason,
            'shipment_block_message' => $this->reasonMessage($shipmentReason),
            'payment_status' => Money::compare($net, '0') <= 0 ? 'unpaid' : ($shipmentPassed ? 'paid' : 'partially_paid'),
            'production_funding_status' => $productionPassed ? 'passed' : 'blocked',
            'shipment_funding_status' => $shipmentPassed ? 'passed' : 'blocked',
        ]);
    }

    /** Return decisions without leaking contract, receipt, refund or threshold amounts. */
    public function statusForPermissions(SalesOrder|int $order, array $permissions, bool $superAdmin = false): array
    {
        $status = $this->status($order);
        if ($superAdmin || in_array('sales_order.amount.view', $permissions, true)) return $status;
        return collect($status)->except(self::AMOUNT_KEYS)->all();
    }

    public function assertCanStartProduction(SalesOrder|int $order): void
    {
        $status = $this->status($order);
        if (! $status['production_funds_satisfied']) throw new \DomainException($status['production_block_message']);
    }

    public function assertCanShip(SalesOrder|int $order): void
    {
        $status = $this->status($order);
        if (! $status['shipment_funds_satisfied']) throw new \DomainException($status['shipment_block_message']);
    }

    public function refreshProjection(SalesOrder $order): array
    {
        $status = $this->status($order);
        $order->update([
            'payment_status' => $status['payment_status'],
            'production_funding_status' => $status['production_funding_status'],
            'shipment_funding_status' => $status['shipment_funding_status'],
            'final_receivable_amount' => $status['contract_amount'],
        ]);
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
            'shipment_requires_full_payment' => true,
            'production_required_amount' => $receivable,
            'production_valid' => false,
            'shipment_valid' => false,
            'production_block_reason' => 'funding_policy_missing',
            'shipment_block_reason' => 'funding_policy_missing',
        ];
        if (! $configured || $policyType === '') return $base;

        $shipmentFull = array_key_exists('shipment_requires_full_payment', $snapshot)
            ? (bool) $snapshot['shipment_requires_full_payment']
            : ($legacyFullPayment === null ? true : (bool) $legacyFullPayment);
        $base['shipment_requires_full_payment'] = $shipmentFull;
        $base['shipment_valid'] = $shipmentFull;
        $base['shipment_block_reason'] = $shipmentFull ? null : 'credit_policy_not_enabled';

        if ($policyType === 'full_prepay') {
            $base['production_valid'] = true;
            $base['production_block_reason'] = null;
            return $base;
        }
        if (! in_array($policyType, ['deposit_production', 'installment_contract', 'custom_threshold'], true)) {
            $base['production_block_reason'] = 'funding_policy_invalid';
            $base['shipment_valid'] = false;
            $base['shipment_block_reason'] = 'funding_policy_invalid';
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
            $base['production_block_reason'] = 'funding_policy_invalid';
            $base['shipment_valid'] = false;
            $base['shipment_block_reason'] = 'funding_policy_invalid';
            return $base;
        }
        $base['production_required_amount'] = Money::normalize($required);
        $base['production_valid'] = true;
        $base['production_block_reason'] = null;
        return $base;
    }

    private function reasonMessage(?string $reason): ?string
    {
        return match ($reason) {
            null => null,
            'funding_policy_missing' => '订单未冻结明确的付款资金策略，生产与发货均被阻断。',
            'funding_policy_invalid' => '订单冻结的付款资金策略无效，生产与发货均被阻断。',
            'credit_policy_not_enabled' => '当前系统未启用授信发货，必须全额收款后才能发货。',
            'production_funds_insufficient' => '当前有效净收款未达到生产资金门槛，不能进入生产。',
            'shipment_funds_insufficient' => '当前有效净收款未达到最终应收金额，不能发货。',
            default => '当前资金条件未满足。',
        };
    }
}
