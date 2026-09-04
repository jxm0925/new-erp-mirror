<?php

namespace App\Services\Erp;

use App\Domain\Finance\Money;
use App\Models\Erp\SalesOrder;

class SalesOrderFundingGateService
{
    public function __construct(private readonly SalesFinanceSettlementService $settlements) {}

    public function status(SalesOrder|int $order): array
    {
        $order = $order instanceof SalesOrder ? $order : SalesOrder::findOrFail($order);
        $settlement = $this->settlements->status($order);
        $policy = (array) $order->funding_policy_snapshot;
        $policyType = $policy['policy_type'] ?? 'full_prepay';
        $thresholdType = $policy['production_threshold_type'] ?? 'ratio';
        $thresholdValue = (string) ($policy['production_threshold_value'] ?? '1');
        $receivable = Money::normalize((string) ($order->final_receivable_amount ?: $order->total_amount));
        $productionRequired = $thresholdType === 'amount'
            ? Money::normalize($thresholdValue)
            : bcmul($receivable, Money::normalize($thresholdValue), Money::SCALE);
        if ($policyType === 'full_prepay') $productionRequired = $receivable;
        $net = $settlement['net_received_amount'];
        $productionPassed = Money::compare($net, $productionRequired) >= 0;
        $shipmentPassed = Money::compare($net, $receivable) >= 0;
        // array union (`+`) keeps the left-hand keys, which silently retained
        // SalesFinanceSettlementService's generic production flag and ignored
        // the policy threshold calculated above. The funding policy is the
        // authoritative gate, so its derived fields must overwrite the generic
        // settlement projection.
        return array_merge($settlement, [
            'policy_type' => $policyType,
            'policy_name' => $policy['policy_name'] ?? '全额预付',
            'production_required_amount' => $productionRequired,
            'production_funds_satisfied' => $productionPassed,
            'shipment_funds_satisfied' => $shipmentPassed,
            'payment_status' => Money::compare($net, '0') <= 0 ? 'unpaid' : ($shipmentPassed ? 'paid' : 'partially_paid'),
            'production_funding_status' => $productionPassed ? 'passed' : 'blocked',
            'shipment_funding_status' => $shipmentPassed ? 'passed' : 'blocked',
        ]);
    }

    public function assertCanStartProduction(SalesOrder|int $order): void
    {
        $status = $this->status($order);
        if (!$status['production_funds_satisfied']) throw new \DomainException('当前有效净收款未达到生产资金门槛，不能进入生产。');
    }

    public function assertCanShip(SalesOrder|int $order): void
    {
        $status = $this->status($order);
        if (!$status['shipment_funds_satisfied']) throw new \DomainException('当前有效净收款未达到最终应收金额，不能发货。');
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
}
