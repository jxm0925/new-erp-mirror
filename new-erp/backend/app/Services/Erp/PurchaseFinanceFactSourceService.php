<?php

namespace App\Services\Erp;

use App\Models\Erp\InventoryTransactionItem;
use App\Models\Erp\PurchaseExchangeOrder;
use App\Models\Erp\PurchaseOrderItem;
use App\Models\Erp\PurchaseReceiptItem;
use App\Models\Erp\PurchaseReturnItem;

class PurchaseFinanceFactSourceService
{
    public function purchaseOrder(PurchaseOrderItem $line): array
    {
        if (! $line->relationLoaded('order')) $line->load('order');

        return $this->fact(
            'PURCHASE_ORDER', $line->order_id, $line->order?->purchase_order_no, $line->id,
            $line->order?->supplier_id, $line->order?->order_date,
            (float) $line->amount_excl_tax, (float) $line->tax_amount_snapshot, (float) $line->amount_incl_tax,
            (string) ($line->currency_snapshot ?: 'CNY'), 0, 0, 'COMMITMENT', null, null,
        );
    }

    public function purchaseReceipt(PurchaseReceiptItem $line): array
    {
        if (! $line->relationLoaded('receipt')) $line->load('receipt');

        return $this->fact(
            'PURCHASE_RECEIPT', $line->receipt_id, $line->receipt?->receipt_no, $line->id,
            $line->receipt?->supplier_id, $line->receipt?->receipt_date,
            (float) $line->amount_excl_tax, (float) $line->tax_amount_snapshot, (float) $line->amount_incl_tax,
            (string) ($line->currency_snapshot ?: 'CNY'), (float) $line->settlement_amount,
            (float) $line->inventory_cost_amount, 'RECEIPT', $line->order_item_id, null,
        );
    }

    public function purchaseReturn(PurchaseReturnItem $line): array
    {
        if (! $line->relationLoaded('purchaseReturn')) $line->load('purchaseReturn');

        return $this->fact(
            'PURCHASE_RETURN', $line->return_id, $line->purchaseReturn?->return_no, $line->id,
            $line->purchaseReturn?->supplier_id, $line->purchaseReturn?->return_date,
            -(float) $line->return_amount_excl_tax, -(float) $line->return_tax_amount, -(float) $line->return_amount_incl_tax,
            (string) ($line->currency_snapshot ?: 'CNY'), -(float) $line->settlement_amount,
            -(float) $line->inventory_cost_amount, 'REVERSAL', $line->source_receipt_item_id, null,
        );
    }

    public function purchaseExchange(PurchaseExchangeOrder $order): array
    {
        return $this->fact(
            'PURCHASE_EXCHANGE', $order->id, $order->exchange_no, null,
            $order->supplier_id, $order->created_at,
            0, 0, (float) $order->replacement_payable_amount,
            (string) ($order->currency_snapshot ?: 'CNY'), (float) $order->replacement_payable_amount,
            (float) $order->replacement_inventory_cost, 'REPLACEMENT', $order->source_receipt_item_id, null,
        );
    }

    public function inventoryPosting(InventoryTransactionItem $line): array
    {
        if (! $line->relationLoaded('transaction')) $line->load('transaction');

        return $this->fact(
            'INVENTORY_TRANSACTION', $line->transaction_id, $line->transaction?->transaction_no, $line->id,
            null, $line->transaction?->transaction_date,
            (float) $line->purchase_amount_snapshot, 0, (float) $line->purchase_amount_snapshot,
            'CNY', 0, (float) $line->cost_amount,
            (float) $line->change_qty >= 0 ? 'INVENTORY_IN' : 'INVENTORY_OUT', $line->source_item_id, null,
        );
    }

    private function fact(
        string $type,
        mixed $documentId,
        mixed $documentNo,
        mixed $lineId,
        mixed $counterpartyId,
        mixed $businessDate,
        float $amountExclTax,
        float $taxAmount,
        float $amountInclTax,
        string $currency,
        float $settlementAmount,
        float $costAmount,
        string $direction,
        mixed $originalSourceId,
        mixed $reversalSourceId,
    ): array {
        return [
            'source_business_type' => $type,
            'source_document_id' => $documentId,
            'source_document_no' => $documentNo,
            'source_line_id' => $lineId,
            'counterparty_type' => $counterpartyId ? 'SUPPLIER' : null,
            'counterparty_id' => $counterpartyId,
            'business_date' => $businessDate,
            'amount_excl_tax' => round($amountExclTax, 4),
            'tax_amount' => round($taxAmount, 4),
            'amount_incl_tax' => round($amountInclTax, 4),
            'currency' => $currency,
            'settlement_amount' => round($settlementAmount, 4),
            'cost_amount' => round($costAmount, 4),
            'direction' => $direction,
            'original_source_id' => $originalSourceId,
            'reversal_source_id' => $reversalSourceId,
        ];
    }
}
