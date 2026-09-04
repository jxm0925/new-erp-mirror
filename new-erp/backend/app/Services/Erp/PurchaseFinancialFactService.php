<?php

namespace App\Services\Erp;

use App\Models\Erp\PurchaseReceipt;
use App\Models\Erp\PurchaseReceiptItem;

class PurchaseFinancialFactService
{
    public function amountFacts(float $commercialAmount, float $taxRate, string $taxMode): array
    {
        $commercialAmount = round(max(0, $commercialAmount), 4);
        $taxRate = max(0, $taxRate);

        if ($taxMode === 'tax_excluded') {
            $excl = $commercialAmount;
            $tax = round($excl * $taxRate / 100, 4);
            $incl = round($excl + $tax, 4);
        } else {
            $incl = $commercialAmount;
            $excl = $taxRate > 0 ? round($incl * 100 / (100 + $taxRate), 4) : $incl;
            $tax = round($incl - $excl, 4);
        }

        return [
            'amount_excl_tax' => $excl,
            'tax_amount_snapshot' => $tax,
            'amount_incl_tax' => $incl,
        ];
    }

    public function freezeReceiptLine(PurchaseReceipt $receipt, PurchaseReceiptItem $line): array
    {
        $currency = (string) ($receipt->order?->currency ?: $receipt->currency_snapshot ?: 'CNY');
        $taxMode = (string) ($receipt->order?->tax_mode ?: $receipt->tax_mode_snapshot ?: 'tax_included');

        return [
            'currency_snapshot' => $currency,
            'tax_mode_snapshot' => $taxMode,
            ...$this->amountFacts((float) $line->receipt_cost, (float) $line->tax_rate, $taxMode),
            'finance_fact_status' => 'frozen',
            'facts_frozen_at' => now(),
        ];
    }

    public function proportionalFacts(PurchaseReceiptItem $source, float $baseQuantity): array
    {
        $sourceBase = max(0, (float) ($source->original_received_base_qty
            ?: $source->actual_base_qty
            ?: $source->standard_base_qty
            ?: $source->receipt_qty));
        $ratio = $sourceBase > 0 ? min(1, max(0, $baseQuantity / $sourceBase)) : 0;
        $excl = round((float) $source->amount_excl_tax * $ratio, 4);
        $tax = round((float) $source->tax_amount_snapshot * $ratio, 4);
        $incl = round((float) $source->amount_incl_tax * $ratio, 4);

        return [
            'original_purchase_line_id' => $source->order_item_id,
            'currency_snapshot' => $source->currency_snapshot ?: 'CNY',
            'tax_mode_snapshot' => $source->tax_mode_snapshot ?: 'tax_included',
            'tax_rate_snapshot' => $source->tax_rate,
            'return_unit_price' => $baseQuantity > 0 ? round($excl / $baseQuantity, 8) : 0,
            'return_amount_excl_tax' => $excl,
            'return_tax_amount' => $tax,
            'return_amount_incl_tax' => $incl,
            'settlement_amount' => $incl,
            'finance_fact_status' => $source->finance_fact_status === 'frozen' ? 'frozen' : 'legacy_unknown',
        ];
    }
}
