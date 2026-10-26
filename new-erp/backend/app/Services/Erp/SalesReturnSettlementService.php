<?php

namespace App\Services\Erp;

use App\Domain\Finance\Money;
use App\Models\Erp\SalesOrderLine;
use App\Models\Erp\SalesReturn;
use App\Models\Erp\SalesReturnItem;
use App\Models\Erp\SalesReturnItemCredit;
use App\Models\Erp\SalesReturnTerm;
use Illuminate\Validation\ValidationException;

/**
 * 销售退货结算口径：
 * - refund（退货退款）：按“实收且未退回客户”的数量 × 退货单价冲减订单应收；
 *   冲减后订单净收款超过净应收的部分即应退客户金额，由财务付款单核销“销售退款”来源办理。
 * - exchange（换货）：只做实物退回，不冲减应收；补发另走销售订单/返修。
 * 冲减金额是退货事实的投影，任何到货/处置后都按事实全量重算，保持幂等。
 */
class SalesReturnSettlementService
{
    public const TYPE_REFUND = 'refund';
    public const TYPE_EXCHANGE = 'exchange';
    public const TYPES = [self::TYPE_REFUND, self::TYPE_EXCHANGE];

    /** 订单上仍有效的退货冲减应收合计（草稿/取消不计；关闭单保留已认可部分）。 */
    public static function orderCreditAmount(int $salesOrderId): string
    {
        return Money::normalize((string) SalesReturnTerm::query()
            ->join('erp_sales_returns', 'erp_sales_returns.id', '=', 'erp_sales_return_terms.sales_return_id')
            ->where('erp_sales_returns.sales_order_id', $salesOrderId)
            ->whereNotIn('erp_sales_returns.return_status', ['draft', 'cancelled'])
            ->where('erp_sales_return_terms.return_type', self::TYPE_REFUND)
            ->sum('erp_sales_return_terms.credit_amount'));
    }

    public static function lineUnitPrice(SalesOrderLine $line): string
    {
        $qty = (float) $line->order_qty;
        if ($qty <= 0) return '0.0000';
        $amount = (float) ($line->amount_incl_tax ?: $line->amount);
        return Money::normalize(number_format($amount / $qty, 4, '.', ''));
    }

    public function initialize(SalesReturn $return, ?string $type): void
    {
        $type = $type ?: self::TYPE_REFUND;
        if (! in_array($type, self::TYPES, true)) throw ValidationException::withMessages(['return_type' => '退货类型无效。']);
        SalesReturnTerm::query()->updateOrCreate(['sales_return_id' => $return->id], ['return_type' => $type]);
    }

    /** 退货单价默认取原订单行含税净单价；允许折价退货，但不得高于原单价。 */
    public function initializeItem(SalesReturnItem $item, SalesOrderLine $line, mixed $unitPrice): void
    {
        $max = self::lineUnitPrice($line);
        $price = $unitPrice === null || $unitPrice === '' ? $max : Money::normalize((string) $unitPrice);
        if (Money::compare($price, '0') < 0 || Money::compare($price, $max) > 0) {
            throw ValidationException::withMessages(['items' => "退货单价必须在 0 到原订单单价 {$max} 之间。"]);
        }
        SalesReturnItemCredit::query()->updateOrCreate(['sales_return_item_id' => $item->id], ['unit_price_snapshot' => $price]);
    }

    /** 按当前到货/处置事实重算行与单据冲减金额；历史无口径记录的退货按“退货退款 + 原单价”补齐。 */
    public function refresh(SalesReturn $return): SalesReturnTerm
    {
        $return->loadMissing('items.orderLine');
        $terms = SalesReturnTerm::query()->lockForUpdate()->firstOrCreate(['sales_return_id' => $return->id], ['return_type' => self::TYPE_REFUND]);
        $total = '0.0000';
        foreach ($return->items as $item) {
            $credit = SalesReturnItemCredit::query()->lockForUpdate()->firstOrCreate(
                ['sales_return_item_id' => $item->id],
                ['unit_price_snapshot' => $item->orderLine ? self::lineUnitPrice($item->orderLine) : '0'],
            );
            $factor = (float) data_get($item->fulfillment_snapshot, 'fulfillment_factor_snapshot', 1) ?: 1.0;
            $acceptedBase = max(0, (float) $item->received_base_qty - (float) $item->rejected_base_qty);
            $salesQty = $terms->return_type === self::TYPE_REFUND ? round($acceptedBase / $factor, 8) : 0.0;
            $amount = Money::normalize(number_format($salesQty * (float) $credit->unit_price_snapshot, 2, '.', ''));
            $credit->update(['credited_sales_qty' => $salesQty, 'credit_amount' => $amount]);
            $total = Money::add($total, $amount);
        }
        $terms->update(['credit_amount' => $total]);
        return $terms->fresh();
    }

    /** 退货单详情的结算摘要：本单冲减、订单净应收、净收款与当前应退客户金额。 */
    public function summary(SalesReturn $return): array
    {
        $terms = $return->terms ?: new SalesReturnTerm(['return_type' => self::TYPE_REFUND, 'credit_amount' => 0]);
        $status = app(SalesFinanceSettlementService::class)->status((int) $return->sales_order_id);
        return [
            'return_type' => $terms->return_type,
            'credit_amount' => Money::normalize((string) $terms->credit_amount),
            'order_contract_amount' => $status['contract_amount'],
            'order_return_credit_amount' => $status['return_credit_amount'],
            'order_net_receivable_amount' => $status['net_receivable_amount'],
            'order_net_received_amount' => $status['net_received_amount'],
            'order_refunded_amount' => $status['refunded_amount'],
            'refund_due_amount' => $status['available_prepayment_amount'],
            'outstanding_amount' => $status['outstanding_amount'],
        ];
    }
}
