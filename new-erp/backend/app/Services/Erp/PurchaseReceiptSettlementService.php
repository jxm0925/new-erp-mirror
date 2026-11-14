<?php

namespace App\Services\Erp;

use App\Domain\Finance\Money;
use App\Models\Erp\PurchaseDefectHandling;
use App\Models\Erp\PurchaseExchangeOrder;
use App\Models\Erp\PurchaseReceipt;
use App\Models\Erp\PurchaseReceiptItem;

class PurchaseReceiptSettlementService
{
    public function refresh(int|PurchaseReceipt $receipt): PurchaseReceipt
    {
        $receipt = $receipt instanceof PurchaseReceipt ? $receipt : PurchaseReceipt::findOrFail($receipt);
        $receipt->load(['items.defectHandlings']);
        $replacementNoCharge = $receipt->settlement_mode === 'replacement_no_charge';

        foreach ($receipt->items as $line) $this->refreshLine($line, $replacementNoCharge, $receipt->stock_post_status === 'posted');

        $hasStockItems = $receipt->items()->where('is_stock_item_snapshot', true)->exists();
        $stockableBase = (float) $receipt->items()->sum('final_stockable_base_qty');
        $qualityPending = $receipt->items()->where('settlement_status', 'quality_hold')->exists();
        $state = $this->fulfillmentState($receipt, $hasStockItems, $stockableBase, $qualityPending);

        $receipt->update([
            'total_amount' => $receipt->items->reduce(fn (string $sum, PurchaseReceiptItem $line) => Money::add($sum, $line->effectiveAmount()), '0.0000'),
            'amount_excl_tax' => round((float) $receipt->items()->sum('amount_excl_tax'), 4),
            'tax_amount_snapshot' => round((float) $receipt->items()->sum('tax_amount_snapshot'), 4),
            'amount_incl_tax' => round((float) $receipt->items()->sum('amount_incl_tax'), 4),
            'qualified_payable_amount' => round((float) $receipt->items()->sum('qualified_payable_amount'), 4),
            'quality_hold_amount' => round((float) $receipt->items()->sum('quality_hold_amount'), 4),
            'rejected_claim_amount' => round((float) $receipt->items()->sum('rejected_claim_amount'), 4),
            'settlement_amount' => round((float) $receipt->items()->sum('settlement_amount'), 4),
            'inventory_cost_amount' => round((float) $receipt->items()->sum('inventory_cost_amount'), 4),
            ...$state,
        ]);

        return $receipt->fresh(['items.item', 'items.defectHandlings']);
    }

    private function fulfillmentState(
        PurchaseReceipt $receipt,
        bool $hasStockItems,
        float $stockableBase,
        bool $qualityPending,
    ): array {
        if ($receipt->confirm_status !== 'confirmed') {
            return [];
        }

        if ($receipt->stock_post_status === 'posted') {
            return ['fulfillment_status' => $qualityPending ? 'pending_quality' : 'fulfilled'];
        }

        if (! $hasStockItems) {
            return ['stock_post_status' => 'not_required', 'fulfillment_status' => 'fulfilled'];
        }

        if ($qualityPending) {
            return ['stock_post_status' => 'pending', 'fulfillment_status' => 'pending_quality'];
        }

        if ($stockableBase > 0.00000001) {
            return ['stock_post_status' => 'pending', 'fulfillment_status' => 'pending_inventory_posting'];
        }

        return ['stock_post_status' => 'not_required', 'fulfillment_status' => 'quality_resolved_no_stock'];
    }

    private function refreshLine(PurchaseReceiptItem $line, bool $replacementNoCharge, bool $posted): void
    {
        $base = max(0, (float) ($line->original_received_base_qty
            ?: $line->actual_base_qty
            ?: $line->standard_base_qty
            ?: $line->receipt_qty));
        $originalQualified = min($base, max(0, (float) ($line->original_qualified_base_qty ?? $line->qualified_base_qty)));
        $originalUnqualified = min(max(0, $base - $originalQualified), max(0, (float) ($line->original_unqualified_base_qty ?? $line->unqualified_base_qty)));

        $completed = $line->defectHandlings->filter(
            fn (PurchaseDefectHandling $handling) => $handling->handling_status === 'completed'
        );
        $rework = $this->handled($completed, ['repair']);
        $concession = $this->handled($completed, ['concession']);
        $replacement = $this->handled($completed, ['exchange']);
        $rejected = $this->handled($completed, ['return_supplier']);
        $scrapped = $this->handled($completed, ['scrap']);

        $resolved = min($originalUnqualified, $rework + $concession + $replacement + $rejected + $scrapped);
        $holdBase = max(0, $originalUnqualified - $resolved);
        $acceptedBase = min($originalUnqualified, $rework + $concession + $replacement);
        $rejectedBase = min($originalUnqualified, $rejected + $scrapped);
        $payableBase = min($base, $originalQualified + $acceptedBase);
        $stockableBase = (bool) $line->is_stock_item_snapshot
            ? min($base, $originalQualified + $rework + $concession)
            : 0;

        $amountIncl = $line->effectiveAmount();
        $baseDecimal = number_format($base, 8, '.', '');
        $stockableDecimal = number_format($stockableBase, 8, '.', '');
        $costBasis = $amountIncl;
        // 零应付替换品延续原品重估后的成本，普通刷新不能把采购更正回退成旧快照。
        if ($replacementNoCharge) {
            $replacementCost = PurchaseExchangeOrder::query()->where('replacement_receipt_id', $line->receipt_id)->value('replacement_inventory_cost');
            if ($replacementCost !== null) $costBasis = (string) $replacementCost;
        }
        $legacyPostedCost = false;
        $previousStockable = (string) $line->final_stockable_base_qty;
        if ($posted && ! $replacementNoCharge && $line->finance_actual_amount === null && $base > 0 && bccomp($previousStockable, '0', 8) > 0) {
            $legacyBasis = (string) ($line->amount_excl_tax ?? $line->receipt_cost);
            $legacyCost = Money::normalize(bcdiv(bcmul($legacyBasis, $previousStockable, 16), $baseDecimal, 16));
            $grossCost = CuttingDecimal::share($amountIncl, $baseDecimal, $previousStockable);
            // 已按旧未税口径过账的成本只能通过正式财务更正补差，普通刷新继续沿用原入账口径。
            if (Money::compare((string) $line->inventory_cost_amount, $legacyCost) === 0
                && Money::compare((string) $line->inventory_cost_amount, $grossCost) !== 0) {
                $costBasis = $legacyBasis;
                $legacyPostedCost = true;
            }
        }
        // 库存按实际含税成交金额计成本；税额快照只供票税追溯，不能再从成本扣税。
        $inventoryCost = $base > 0 && $stockableBase > 0
            ? ($legacyPostedCost ? Money::normalize(bcdiv(bcmul($costBasis, $stockableDecimal, 16), $baseDecimal, 16))
                : CuttingDecimal::share($costBasis, $baseDecimal, $stockableDecimal)) : '0.0000';

        if ($replacementNoCharge) {
            $payable = $hold = $claim = '0.0000';
            $status = $holdBase > 0.00000001 ? 'quality_hold' : 'replacement_no_charge';
        } else {
            $scale = Money::compare($amountIncl, Money::normalize($amountIncl, 2)) === 0 ? 2 : 4;
            $payableQty = number_format($payableBase, 8, '.', '');
            $payable = $base > 0 && $payableBase > 0
                ? Money::normalize(CuttingDecimal::share($amountIncl, $baseDecimal, $payableQty), $scale) : '0.0000';
            $remaining = Money::sub($amountIncl, $payable);
            $remainingQty = bcsub($baseDecimal, $payableQty, 8);
            $hold = $holdBase > 0 && bccomp($remainingQty, '0', 8) > 0
                ? Money::normalize(CuttingDecimal::share($remaining, $remainingQty, number_format($holdBase, 8, '.', '')), $scale) : '0.0000';
            $claim = Money::sub($remaining, $hold);
            $status = $holdBase > 0.00000001 ? 'quality_hold'
                : ($rejectedBase >= $base - 0.00000001 ? 'rejected'
                    : ($rejectedBase > 0.00000001 ? 'partially_rejected'
                        : ($payableBase > 0.00000001 ? 'qualified_payable' : 'pending_inspection')));
        }

        $line->update([
            'rework_qualified_base_qty' => round($rework, 8),
            'concession_accepted_base_qty' => round($concession, 8),
            'replacement_qualified_base_qty' => round($replacement, 8),
            'rejected_base_qty' => round($rejected, 8),
            'scrapped_base_qty' => round($scrapped, 8),
            'final_stockable_base_qty' => round($stockableBase, 8),
            'qualified_payable_amount' => $payable,
            'quality_hold_amount' => $hold,
            'rejected_claim_amount' => $claim,
            'settlement_amount' => $payable,
            'inventory_cost_amount' => $inventoryCost,
            'settlement_status' => $status,
        ]);
    }

    private function handled($completed, array $methods): float
    {
        return max(0, (float) $completed
            ->filter(fn (PurchaseDefectHandling $handling) => in_array($handling->handling_method, $methods, true))
            ->sum('handling_qty'));
    }
}
