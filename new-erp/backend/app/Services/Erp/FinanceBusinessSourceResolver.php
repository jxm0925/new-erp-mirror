<?php

namespace App\Services\Erp;

use App\Domain\Finance\FinanceConstants;
use App\Domain\Finance\Money;
use App\Models\Erp\PurchaseExchangeOrder;
use App\Models\Erp\FinanceAllocation;
use App\Models\Erp\PurchaseReceipt;
use App\Models\Erp\PurchaseReturn;
use App\Models\Erp\PurchaseSettlementSource;
use App\Models\Erp\SalesOrder;
use Illuminate\Validation\ValidationException;

class FinanceBusinessSourceResolver
{
    public function __construct(
        private readonly PurchaseSettlementSourceApplicationService $purchaseSettlementSources,
    ) {
    }

    public function resolve(string $type, int $id, string $purpose = 'payment'): array
    {
        return match ($type) {
            FinanceConstants::SOURCE_SALES_ORDER,
            FinanceConstants::SOURCE_SALES_ORDER_REFUND => $this->salesOrder($type, $id),
            FinanceConstants::SOURCE_PURCHASE_RECEIPT => $this->purchaseReceipt($id),
            FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE => $this->purchaseSettlementSource($id, $purpose),
            FinanceConstants::SOURCE_PURCHASE_RETURN_AP_OFFSET,
            FinanceConstants::SOURCE_PURCHASE_RETURN_SUPPLIER_REFUND => $this->purchaseReturn($type, $id),
            FinanceConstants::SOURCE_PURCHASE_EXCHANGE => $this->purchaseExchange($id),
            default => throw ValidationException::withMessages(['source_business_type' => '不支持的财务业务来源。']),
        };
    }

    private function salesOrder(string $type, int $id): array
    {
        $order = SalesOrder::query()->lockForUpdate()->findOrFail($id);
        if (!$order->customer_id) throw ValidationException::withMessages(['source_document_id' => '销售订单尚未绑定客户。']);
        $amount = Money::normalize((string) $order->total_amount);
        if ($type === FinanceConstants::SOURCE_SALES_ORDER_REFUND) {
            $amount = Money::normalize((string) FinanceAllocation::query()
                ->where('source_business_type', FinanceConstants::SOURCE_SALES_ORDER)
                ->where('source_document_id', $order->id)
                ->where('status', FinanceConstants::ALLOCATION_ACTIVE)
                ->whereHas('cashDocument', fn ($q) => $q->where('status', FinanceConstants::STATUS_CONFIRMED))
                ->sum('allocated_amount'));
        }
        return $this->fact($type, $order->id, $order->sales_order_no, FinanceConstants::PARTY_CUSTOMER,
            (int) $order->customer_id, (string) ($order->customer_name_snapshot ?: $order->customer_name),
            (string) ($order->currency ?: 'CNY'), $amount);
    }

    private function purchaseReceipt(int $id): array
    {
        $receipt = PurchaseReceipt::query()->with('supplier')->lockForUpdate()->findOrFail($id);
        if ($receipt->confirm_status !== 'confirmed') {
            throw ValidationException::withMessages(['source_document_id' => '只有已确认到货才能作为付款核销来源。']);
        }
        if ($receipt->settlement_mode === 'replacement_no_charge') {
            throw ValidationException::withMessages(['source_document_id' => '换货补发新增应付为 0，不能作为付款来源。']);
        }
        $offset = PurchaseReturn::query()
            ->where('source_receipt_id', $receipt->id)
            ->where('settlement_effect_type', FinanceConstants::PURCHASE_EFFECT_AP_OFFSET)
            ->whereNotIn('return_status', ['draft', 'submitted', 'cancelled'])
            ->lockForUpdate()
            ->get(['settlement_amount'])
            ->reduce(fn (string $sum, PurchaseReturn $row): string => Money::add($sum, (string) $row->settlement_amount), '0.0000');
        $amount = Money::maxZero(Money::sub((string) $receipt->settlement_amount, $offset));
        return $this->fact(FinanceConstants::SOURCE_PURCHASE_RECEIPT, $receipt->id, $receipt->receipt_no,
            FinanceConstants::PARTY_SUPPLIER, (int) $receipt->supplier_id, (string) $receipt->supplier?->supplier_name,
            (string) ($receipt->currency_snapshot ?: 'CNY'), $amount);
    }

    private function purchaseReturn(string $type, int $id): array
    {
        $return = PurchaseReturn::query()->with('supplier')->lockForUpdate()->findOrFail($id);
        $expected = $type === FinanceConstants::SOURCE_PURCHASE_RETURN_AP_OFFSET
            ? FinanceConstants::PURCHASE_EFFECT_AP_OFFSET
            : FinanceConstants::PURCHASE_EFFECT_SUPPLIER_REFUND;
        if ($return->settlement_effect_type !== $expected || in_array($return->return_status, ['draft', 'submitted', 'cancelled'], true)) {
            throw ValidationException::withMessages(['source_document_id' => "采购退货尚未形成 {$expected} 正式财务语义。"]);
        }
        return $this->fact($type, $return->id, $return->return_no, FinanceConstants::PARTY_SUPPLIER,
            (int) $return->supplier_id, (string) $return->supplier?->supplier_name,
            (string) ($return->currency_snapshot ?: 'CNY'), Money::normalize((string) $return->settlement_amount));
    }

    private function purchaseSettlementSource(int $id, string $purpose): array
    {
        // Refresh from purchasing facts before exposing a source to finance.
        // This prevents a stale quality/return state from being allocated.
        $this->purchaseSettlementSources->refresh($id);
        $source = PurchaseSettlementSource::query()->with('supplier')->lockForUpdate()->findOrFail($id);
        if ($source->currency !== 'CNY') {
            throw ValidationException::withMessages(['currency' => '采购财务联动 V1 当前仅允许 CNY 结算来源。']);
        }
        if ($purpose === 'payment' && ! in_array($source->status, ['open', 'partially_paid'], true)) {
            throw ValidationException::withMessages(['source_document_id' => '该采购结算来源当前不可付款：'.$source->status]);
        }
        $amount = Money::maxZero(Money::sub((string) $source->eligible_amount, (string) $source->ap_offset_amount));
        if (Money::compare($amount, '0') <= 0) {
            throw ValidationException::withMessages(['source_document_id' => '该采购结算来源没有可付款金额。']);
        }
        return $this->fact(FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE, $source->id, $source->source_document_no,
            FinanceConstants::PARTY_SUPPLIER, (int) $source->supplier_id, (string) $source->supplier_name_snapshot,
            (string) $source->currency, $amount);
    }

    private function purchaseExchange(int $id): array
    {
        $exchange = PurchaseExchangeOrder::query()->with('supplier')->lockForUpdate()->findOrFail($id);
        if (Money::compare((string) $exchange->replacement_payable_amount, '0') !== 0) {
            throw ValidationException::withMessages(['source_document_id' => '换货补发的新增应付必须为 0。']);
        }
        return $this->fact(FinanceConstants::SOURCE_PURCHASE_EXCHANGE, $exchange->id, $exchange->exchange_no,
            FinanceConstants::PARTY_SUPPLIER, (int) $exchange->supplier_id, (string) $exchange->supplier?->supplier_name,
            (string) ($exchange->currency_snapshot ?: 'CNY'), '0.0000');
    }

    private function fact(string $type, int $id, string $no, string $partyType, int $partyId, string $partyName, string $currency, string $amount): array
    {
        return compact('type', 'id', 'no', 'partyType', 'partyId', 'partyName', 'currency', 'amount');
    }
}
