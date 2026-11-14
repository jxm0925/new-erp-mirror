<?php

namespace App\Services\Erp;

use App\Domain\Finance\Money;
use App\Models\Erp\PurchaseOrder;
use App\Models\Erp\PurchaseOrderItem;
use App\Models\Erp\PurchaseExchangeOrder;
use App\Models\Erp\PurchaseReceipt;
use App\Models\Erp\PurchaseReceiptItem;
use App\Models\Erp\PurchaseReturnItem;

class PurchaseFinancialFactService
{
    public function commercialLine(float|string $quantity, float|string|null $amount, float|string $unitPrice = '0'): array
    {
        $quantity = $this->decimal($quantity, 8);
        abort_if(bccomp($quantity, '0', 8) <= 0, 422, '采购数量必须大于0');
        $amount = $amount === null
            ? Money::normalize(bcmul($quantity, $this->decimal($unitPrice, 8), 16), 2)
            : $this->decimal($amount, 2);
        abort_if(bccomp($amount, '0', 2) < 0, 422, '成交金额不能小于0');
        $price = bcdiv($amount, $quantity, 8);
        abort_if(bccomp($amount, '9999999999.99', 2) > 0 || bccomp($price, '9999999999.9999', 8) > 0, 422, '成交金额或反算单价超出系统允许范围');

        // 成交总额是权威事实，单价仅供参考，不能再乘数量覆盖人工录入的金额。
        return ['amount' => $amount, 'unit_price' => $price];
    }

    public function refreshOrder(PurchaseOrder $order, bool $freeze = false): void
    {
        $lines = $order->items()->orderBy('id')->lockForUpdate()->get();
        $remainingWeight = $lines->reduce(fn (string $sum, $line) => Money::add($sum, (string) $line->amount), '0.0000');
        $discount = $this->decimal($order->discount_amount ?? '0', 2);
        $freight = $this->decimal($order->freight_amount ?? '0', 2);
        abort_if(bccomp($discount, '0', 2) < 0 || bccomp($discount, $remainingWeight, 4) > 0, 422, '整单优惠不能超过明细成交金额合计');
        abort_if(bccomp($freight, '0', 2) < 0 || (bccomp($remainingWeight, '0', 4) === 0 && bccomp($freight, '0', 2) > 0), 422, '请先填写明细成交金额，再分摊运费');
        $remainingDiscount = $discount;
        $remainingFreight = $freight;
        $totalQty = '0.0000';
        $totalExcl = $totalTax = $totalIncl = '0.0000';
        foreach ($lines as $line) {
            $weight = (string) $line->amount;
            $discountShare = bccomp($weight, '0', 4) > 0
                ? Money::normalize(CuttingDecimal::share($remainingDiscount, $remainingWeight, $weight), 2) : '0.00';
            $freightShare = bccomp($weight, '0', 4) > 0
                ? Money::normalize(CuttingDecimal::share($remainingFreight, $remainingWeight, $weight), 2) : '0.00';
            $facts = $this->amountFacts(Money::sub($weight, $discountShare), (string) $line->tax_rate, (string) $order->tax_mode, 2);
            // 运费沿用原合同口径：直接加入应付，不按物料税率另计税，也不在成本中重复叠加。
            $facts['amount_excl_tax'] = Money::add((string) $facts['amount_excl_tax'], $freightShare);
            $facts['amount_incl_tax'] = Money::add((string) $facts['amount_incl_tax'], $freightShare);
            $line->update([
                'discount_allocated_amount' => $discountShare,
                'freight_allocated_amount' => $freightShare,
                'currency_snapshot' => $order->currency ?: 'CNY',
                'tax_mode_snapshot' => $order->tax_mode ?: 'tax_included',
                ...$facts,
                'contract_amount_snapshot' => $facts['amount_incl_tax'],
                ...($freeze ? ['commercial_snapshot_at' => now()] : []),
            ]);
            $remainingWeight = Money::sub($remainingWeight, $weight);
            $remainingDiscount = Money::sub($remainingDiscount, $discountShare);
            $remainingFreight = Money::sub($remainingFreight, $freightShare);
            $totalQty = bcadd($totalQty, (string) $line->order_qty, 4);
            $totalExcl = Money::add($totalExcl, (string) $facts['amount_excl_tax']);
            $totalTax = Money::add($totalTax, (string) $facts['tax_amount_snapshot']);
            $totalIncl = Money::add($totalIncl, (string) $facts['amount_incl_tax']);
        }
        abort_if(bccomp($totalIncl, '9999999999.9999', 4) > 0, 422, '订单含税总金额超出系统允许范围');
        $order->update([
            'discount_amount' => $discount, 'freight_amount' => $freight,
            'total_qty' => $totalQty, 'total_amount' => $totalIncl,
            'amount_excl_tax' => $totalExcl, 'tax_amount' => $totalTax, 'amount_incl_tax' => $totalIncl,
            'finance_fact_status' => $freeze ? 'frozen' : 'pending',
        ]);
    }

    public function amountFacts(float|string $commercialAmount, float|string $taxRate, string $taxMode, int $scale = 4): array
    {
        $commercialAmount = $this->decimal($commercialAmount, $scale);
        $taxRate = $this->decimal($taxRate);
        abort_if(bccomp($commercialAmount, '0', 4) < 0 || bccomp($taxRate, '0', 4) < 0, 422, '采购金额和税率不能小于0');

        if ($taxMode === 'tax_excluded') {
            $excl = $commercialAmount;
            $tax = Money::normalize(bcdiv(bcmul($excl, $taxRate, 12), '100', 12), $scale);
            $incl = Money::normalize(Money::add($excl, $tax), $scale);
        } else {
            $incl = $commercialAmount;
            $excl = bccomp($taxRate, '0', 4) > 0 ? Money::normalize(bcdiv(bcmul($incl, '100', 12), bcadd('100', $taxRate, 4), 12), $scale) : $incl;
            $tax = Money::normalize(Money::sub($incl, $excl), $scale);
        }

        return [
            'amount_excl_tax' => $excl,
            'tax_amount_snapshot' => $tax,
            'amount_incl_tax' => $incl,
        ];
    }

    private function decimal(float|string $value, int $scale = 4): string
    {
        if (is_float($value)) {
            abort_unless(is_finite($value), 422, '采购数量和金额必须是有限数值');
            $encoded = json_encode($value, JSON_PRESERVE_ZERO_FRACTION);
            // 普通小数保留原精度直接舍入，避免先截8位后再转分造成双重舍入。
            $value = str_contains(strtolower($encoded), 'e') ? sprintf('%.16F', $value) : $encoded;
        }
        $value = trim($value);
        abort_unless(preg_match('/^-?\d+(?:\.\d+)?$/', $value), 422, '采购数量和金额请填写十进制数值，不支持科学计数法');

        return Money::normalize($value, $scale);
    }

    public function assertUnitPrice(float|string $price): void
    {
        $price = $this->decimal($price, 8);
        abort_if(bccomp($price, '0', 8) < 0 || bccomp($price, '9999999999.99999999', 8) > 0, 422, '折算后的基本单价或库存单位成本超出系统允许范围，请核对数量和换算');
    }

    public function orderReceiptFacts(PurchaseReceipt $receipt, PurchaseOrderItem $orderLine, float|string $quantity, ?int $confirmingLineId = null): array
    {
        abort_if($orderLine->amount_incl_tax === null || $orderLine->tax_amount_snapshot === null, 422, '采购订单尚未冻结成交金额，请先核对来源订单');
        $quantity = $this->decimal($quantity, 8);
        $prior = PurchaseReceiptItem::query()
            ->join('erp_purchase_receipts as source_receipt', 'source_receipt.id', '=', 'erp_purchase_receipt_items.receipt_id')
            ->where('erp_purchase_receipt_items.order_item_id', $orderLine->id)
            ->where('source_receipt.settlement_mode', '!=', 'replacement_no_charge')
            ->where(function ($query) use ($receipt, $confirmingLineId) {
                $query->where('source_receipt.confirm_status', 'confirmed')
                    ->orWhere(function ($query) use ($receipt, $confirmingLineId) {
                        $query->where('source_receipt.id', $receipt->id);
                        if ($confirmingLineId) $query->where('erp_purchase_receipt_items.finance_fact_status', 'frozen')
                            ->where('erp_purchase_receipt_items.id', '!=', $confirmingLineId);
                    });
            })
            ->lockForUpdate()->get(['erp_purchase_receipt_items.receipt_qty', 'erp_purchase_receipt_items.receipt_cost',
                'erp_purchase_receipt_items.tax_amount_snapshot', 'erp_purchase_receipt_items.freight_allocated_amount']);
        $usedQty = $prior->reduce(fn (string $sum, $line) => bcadd($sum, (string) $line->receipt_qty, 8), '0.00000000');
        $remainingQty = bcsub((string) ($orderLine->purchase_qty ?: $orderLine->order_qty), $usedQty, 8);
        abort_if(bccomp($quantity, '0', 8) <= 0 || bccomp($quantity, $remainingQty, 8) > 0, 422, '到货数量超过采购订单剩余可到货数量');
        $taxMode = (string) ($orderLine->tax_mode_snapshot ?: $receipt->order?->tax_mode ?: 'tax_included');
        $commercial = Money::sub((string) $orderLine->amount_incl_tax, (string) $orderLine->freight_allocated_amount);
        if ($taxMode === 'tax_excluded') $commercial = Money::sub($commercial, (string) $orderLine->tax_amount_snapshot);
        $totals = ['receipt_cost' => $commercial, 'tax_amount_snapshot' => (string) $orderLine->tax_amount_snapshot,
            'freight_allocated_amount' => (string) $orderLine->freight_allocated_amount];
        $scale = collect($totals)->contains(fn (string $amount) => Money::compare($amount, Money::normalize($amount, 2)) !== 0) ? 4 : 2;
        $facts = [];
        foreach ($totals as $field => $total) {
            $used = $prior->reduce(fn (string $sum, $line) => Money::add($sum, (string) ($line->{$field} ?? '0')), '0.0000');
            $remaining = Money::sub($total, $used);
            abort_if(Money::compare($remaining, '0') < 0, 422, '已确认到货金额超过订单冻结金额，请核对来源单据');
            $facts[$field] = Money::normalize(CuttingDecimal::share($remaining, $remainingQty, $quantity), $scale);
        }
        $incl = Money::add($facts['receipt_cost'], $facts['freight_allocated_amount']);
        if ($taxMode === 'tax_excluded') $incl = Money::add($incl, $facts['tax_amount_snapshot']);
        $price = bcdiv($facts['receipt_cost'], $quantity, 8);
        abort_if(bccomp($price, '9999999999.9999', 8) > 0, 422, '本批到货金额反算单价超出系统范围，请调整本次到货数量');

        return [
            ...$facts, 'unit_price' => $price, 'tax_rate' => $orderLine->tax_rate,
            'amount_excl_tax' => Money::sub($incl, $facts['tax_amount_snapshot']), 'amount_incl_tax' => $incl,
        ];
    }

    public function freezeReceiptLine(PurchaseReceipt $receipt, PurchaseReceiptItem $line, ?PurchaseOrderItem $orderLine = null): array
    {
        $currency = (string) ($receipt->order?->currency ?: $receipt->currency_snapshot ?: 'CNY');
        $taxMode = (string) ($receipt->order?->tax_mode ?: $receipt->tax_mode_snapshot ?: 'tax_included');
        if ($receipt->settlement_mode === 'replacement_no_charge') {
            $facts = ['amount_excl_tax' => $line->receipt_cost, 'tax_amount_snapshot' => '0.0000', 'amount_incl_tax' => $line->receipt_cost];
        } elseif ($orderLine) {
            $facts = $this->orderReceiptFacts($receipt, $orderLine, (string) $line->receipt_qty, $line->id);
        } else {
            $facts = $this->amountFacts((string) $line->receipt_cost, (string) $line->tax_rate, $taxMode, 2);
        }

        return [
            'currency_snapshot' => $currency,
            'tax_mode_snapshot' => $taxMode,
            ...$facts,
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
        $quantity = $this->decimal(min($sourceBase, max(0, $baseQuantity)), 8);
        $base = $this->decimal($sourceBase, 8);
        $incl = $sourceBase > 0 && bccomp($quantity, '0', 8) > 0 ? CuttingDecimal::share((string) ($source->amount_incl_tax ?? '0'), $base, $quantity) : '0.0000';
        $tax = $sourceBase > 0 && bccomp($quantity, '0', 8) > 0 ? CuttingDecimal::share((string) ($source->tax_amount_snapshot ?? '0'), $base, $quantity) : '0.0000';
        $excl = Money::sub($incl, $tax);
        $actual = $sourceBase > 0 && bccomp($quantity, '0', 8) > 0 ? CuttingDecimal::share($source->effectiveAmount(), $base, $quantity) : '0.0000';
        $unitPrice = bccomp($quantity, '0', 8) > 0 ? bcdiv($excl, $quantity, 8) : '0.00000000';
        $this->assertUnitPrice($unitPrice);

        return [
            'original_purchase_line_id' => $source->order_item_id,
            'currency_snapshot' => $source->currency_snapshot ?: 'CNY',
            'tax_mode_snapshot' => $source->tax_mode_snapshot ?: 'tax_included',
            'tax_rate_snapshot' => $source->tax_rate,
            'return_unit_price' => $unitPrice,
            'return_amount_excl_tax' => $excl,
            'return_tax_amount' => $tax,
            'return_amount_incl_tax' => $incl,
            'settlement_amount' => $actual,
            'inventory_cost_amount' => $actual,
            'finance_fact_status' => $source->finance_fact_status === 'frozen' ? 'frozen' : 'legacy_unknown',
        ];
    }

    public function dispositionFacts(PurchaseReceiptItem $source, float $baseQuantity): array
    {
        $source = PurchaseReceiptItem::query()->lockForUpdate()->findOrFail($source->id);
        $base = (string) ($source->original_received_base_qty ?: $source->actual_base_qty ?: $source->standard_base_qty ?: $source->receipt_qty);
        $returns = PurchaseReturnItem::query()->join('erp_purchase_returns as source_return', 'source_return.id', '=', 'erp_purchase_return_items.return_id')
            ->where('erp_purchase_return_items.source_receipt_item_id', $source->id)
            ->where(function ($query) {
                $query->whereNotIn('source_return.return_status', ['cancelled', 'closed'])
                    ->orWhere('source_return.stock_post_status', 'posted')
                    ->orWhere('erp_purchase_return_items.posted_base_qty', '>', 0);
            })
            ->lockForUpdate()->get(['erp_purchase_return_items.requested_base_qty', 'erp_purchase_return_items.settlement_amount']);
        $exchanges = PurchaseExchangeOrder::query()->where('source_receipt_item_id', $source->id)
            ->lockForUpdate()->get(['exchange_base_qty', 'original_payable_amount', 'replacement_inventory_cost']);
        $usedQty = $returns->reduce(fn (string $sum, $line) => bcadd($sum, (string) $line->requested_base_qty, 8), '0.00000000');
        $returnedQty = $usedQty;
        $usedQty = $exchanges->reduce(fn (string $sum, $line) => bcadd($sum, (string) $line->exchange_base_qty, 8), $usedQty);
        $usedAmount = $returns->reduce(fn (string $sum, $line) => Money::add($sum, (string) $line->settlement_amount), '0.0000');
        $usedAmount = $exchanges->reduce(fn (string $sum, $line) => Money::add($sum, (string) $line->original_payable_amount), $usedAmount);
        $remainingQty = bcsub($base, $usedQty, 8);
        $remainingAmount = Money::sub($source->effectiveAmount(), $usedAmount);
        $quantity = $this->decimal($baseQuantity, 8);
        abort_if(Money::compare($remainingAmount, '0') < 0 || bccomp($quantity, '0', 8) <= 0 || bccomp($quantity, $remainingQty, 8) > 0,
            422, '退换货数量或实际金额超过该到货明细剩余可处理范围');
        $scale = Money::compare($remainingAmount, Money::normalize($remainingAmount, 2)) === 0 ? 2 : 4;
        $actual = Money::normalize(CuttingDecimal::share($remainingAmount, $remainingQty, $quantity), $scale);
        // 换货成本按来源成交价值分配到四位，末份收尽余数；退货正式出库仍以当前库存余额冲回。
        $returnedValue = bccomp($returnedQty, '0', 8) > 0 ? CuttingDecimal::share($source->effectiveAmount(), $base, $returnedQty) : '0.0000';
        $exchangedValue = $exchanges->reduce(fn (string $sum, $line) => Money::add($sum, (string) $line->replacement_inventory_cost), '0.0000');
        $remainingValue = Money::sub(Money::sub($source->effectiveAmount(), $returnedValue), $exchangedValue);
        abort_if(Money::compare($remainingValue, '0') < 0, 422, '已退换货来源成本超过实际成交金额，请核对原单');
        $cost = CuttingDecimal::share($remainingValue, $remainingQty, $quantity);

        return [...$this->proportionalFacts($source, $baseQuantity), 'settlement_amount' => $actual, 'inventory_cost_amount' => $cost];
    }
}
