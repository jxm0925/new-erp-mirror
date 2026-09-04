<?php

namespace App\Services\Erp;

use App\Models\Erp\PurchaseDefectHandling;
use App\Models\Erp\PurchaseReceipt;
use App\Models\Erp\PurchaseReceiptItem;

class PurchaseReceiptSettlementService
{
    public function refresh(int|PurchaseReceipt $receipt): PurchaseReceipt
    {
        $receipt = $receipt instanceof PurchaseReceipt ? $receipt : PurchaseReceipt::findOrFail($receipt);
        $receipt->load(['items.defectHandlings']);
        $replacementNoCharge = $receipt->settlement_mode === 'replacement_no_charge';

        foreach ($receipt->items as $line) $this->refreshLine($line, $replacementNoCharge);

        $hasStockItems = $receipt->items()->where('is_stock_item_snapshot', true)->exists();
        $stockableBase = (float) $receipt->items()->sum('final_stockable_base_qty');
        $qualityHoldAmount = (float) $receipt->items()->sum('quality_hold_amount');
        $state = $this->fulfillmentState($receipt, $hasStockItems, $stockableBase, $qualityHoldAmount);

        $receipt->update([
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
        float $qualityHoldAmount,
    ): array {
        if ($receipt->confirm_status !== 'confirmed' || $receipt->stock_post_status === 'posted') {
            return [];
        }

        if (! $hasStockItems) {
            return ['stock_post_status' => 'not_required', 'fulfillment_status' => 'fulfilled'];
        }

        if ($qualityHoldAmount > 0.0001) {
            return ['stock_post_status' => 'pending', 'fulfillment_status' => 'pending_quality'];
        }

        if ($stockableBase > 0.00000001) {
            return ['stock_post_status' => 'pending', 'fulfillment_status' => 'pending_inventory_posting'];
        }

        return ['stock_post_status' => 'not_required', 'fulfillment_status' => 'quality_resolved_no_stock'];
    }

    private function refreshLine(PurchaseReceiptItem $line, bool $replacementNoCharge): void
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

        $amountIncl = (float) ($line->amount_incl_tax ?? $line->receipt_cost);
        $amountExcl = (float) ($line->amount_excl_tax ?? $line->receipt_cost);
        $amountPerBaseIncl = $base > 0 ? $amountIncl / $base : 0;
        $amountPerBaseExcl = $base > 0 ? $amountExcl / $base : 0;

        if ($replacementNoCharge) {
            $payable = 0;
            $hold = 0;
            $claim = 0;
            $inventoryCost = (bool) $line->is_stock_item_snapshot
                ? round($stockableBase * $amountPerBaseExcl, 4)
                : 0;
            $status = 'replacement_no_charge';
        } else {
            $payable = round($payableBase * $amountPerBaseIncl, 4);
            $hold = round($holdBase * $amountPerBaseIncl, 4);
            $claim = round($rejectedBase * $amountPerBaseIncl, 4);
            $inventoryCost = round($stockableBase * $amountPerBaseExcl, 4);
            $status = $hold > 0.0001 ? 'quality_hold'
                : ($claim >= $amountIncl - 0.0001 ? 'rejected'
                    : ($claim > 0.0001 ? 'partially_rejected'
                        : ($payable > 0.0001 ? 'qualified_payable' : 'pending_inspection')));
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
