<?php

namespace App\Services\Erp;

use App\Domain\Finance\{FinanceConstants as F, Money};
use App\Models\Erp\{SalesCancellation, SalesOrder, FinanceCashDocument, FinanceOperationLog, CancellationProductionDisposition};
use Illuminate\Support\Facades\DB;

/** Cancelled contracts retain their original cash facts; disposal is a separate ledger. */
final class SalesCancellationService
{
    public function amounts(SalesOrder $order): array
    {
        $balances = app(FinanceAllocationBalanceQueryService::class);
        $received = $balances->forSource(F::SOURCE_SALES_ORDER, $order->id, F::DIRECTION_RECEIPT);
        $refunded = $balances->forSource(F::SOURCE_SALES_ORDER_REFUND, $order->id, F::DIRECTION_PAYMENT);
        $case = SalesCancellation::where('sales_order_id', $order->id)->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->first();
        $retained = (string) ($case?->retained_amount ?? '0');
        $remaining = Money::maxZero(Money::sub(Money::sub($received, $refunded), $retained));
        return ['received' => $received, 'refunded' => $refunded, 'retained' => $retained, 'remaining' => $remaining,
            'finance_status' => Money::compare($remaining, '0') > 0 ? 'pending' : 'settled',
            'finance_confirmed' => (bool) $case?->finance_confirmed_at, 'case' => $case];
    }

    public function present(SalesOrder $order, bool $amounts = true): array
    {
        $rows = CancellationProductionDisposition::where('sales_order_id', $order->id)->orderBy('id')->get();
        $pending = $rows->whereNull('resolved_at');
        $result = ['production_pending' => $pending->count(), 'production_overdue' => $pending->filter(fn ($r) => $r->due_at->isPast())->count(),
            'production' => $rows->map(function ($r) {
                $wo = DB::table('erp_mfg_work_orders')->find($r->work_order_id);
                return [...$r->toArray(), 'work_order_no' => $wo?->work_order_no, 'item_name' => $wo?->item_name,
                    'owner_name' => $wo->owner_name ?? null, 'overdue' => !$r->resolved_at && $r->due_at->isPast()];
            })->all()];
        $a = $this->amounts($order);
        $result['finance_status'] = $a['finance_status'];
        if ($amounts) $result += $a;
        return $result;
    }

    public function request(SalesOrder $order, array $data, string $operator): void
    {
        $order->assertCurrentBusiness();
        $a = $this->amounts($order);
        $paid = Money::compare($a['remaining'], '0') > 0;
        abort_if($paid && !in_array($data['funds_method'] ?? null, ['refund', 'partial'], true), 422, '订单有未处理收款，请选择全额退款或部分退款、部分留存。');
        $keep = ($data['funds_method'] ?? 'refund') === 'partial' ? Money::normalize((string) ($data['retained_amount'] ?? 0)) : '0.0000';
        abort_if(Money::compare($keep, '0') < 0 || Money::compare($keep, $a['remaining']) > 0, 422, '申请留存金额必须在待处理收款余额以内。');
        SalesCancellation::create(['sales_order_id' => $order->id, 'method' => $data['funds_method'] ?? 'refund',
            'requested_refund' => Money::sub($a['remaining'], $keep), 'requested_retained' => $keep, 'retained_amount' => 0,
            'reason' => $data['reason'], 'history' => [['action' => 'requested', 'operator' => $operator, 'at' => now()->toISOString(),
                'received' => $a['received'], 'refunded' => $a['refunded'], 'requested_retained' => $keep]]]);
    }

    public function approve(int $orderId, array $data, object $actor): array
    {
        return DB::transaction(function () use ($orderId, $data, $actor) {
            $order = SalesOrder::whereKey($orderId)->lockForUpdate()->firstOrFail();
            $order->assertCurrentBusiness();
            abort_unless($order->order_status === 'cancelled', 422, '仅处理已取消订单。');
            $case = SalesCancellation::where('sales_order_id', $orderId)->lockForUpdate()->first();
            // Historical cancellations can enter finance without rewriting old cash records.
            if (!$case) {
                abort_unless((int) $data['expected_version'] === 0, 409, '处置记录已变化，请刷新。');
                $this->request($order, ['reason' => $order->cancel_reason ?: '历史取消订单款项处理', 'funds_method' => 'refund'], $actor->nickname ?: $actor->username);
                $case = SalesCancellation::where('sales_order_id', $orderId)->lockForUpdate()->firstOrFail();
            } else abort_unless((int) $case->business_version === (int) $data['expected_version'], 409, '处置记录已变化，请刷新。');
            $a = $this->amounts($order);
            $keep = Money::normalize((string) $data['retained_amount']);
            $unrefunded = Money::maxZero(Money::sub($a['received'], $a['refunded']));
            $reserved = $this->reservedRefunds($orderId);
            abort_if(Money::compare($keep, '0') < 0 || Money::compare(Money::add($keep, $reserved), $unrefunded) > 0, 422, '留存金额加正在办理的退款不能超过未退收款；请先处理现有退款单。');
            abort_if(trim($data['accounting_note']) === '', 422, '请填写财务确认依据；留存款须说明核算方式。');
            $name = $actor->nickname ?: $actor->username;
            $case->update(['retained_amount' => $keep, 'finance_confirmed_at' => now(), 'finance_confirmed_by' => $name,
                'accounting_note' => $data['accounting_note'], 'business_version' => $case->business_version + 1,
                'history' => [...($case->history ?? []), ['action' => 'finance_confirmed', 'operator' => $name, 'at' => now()->toISOString(), 'retained' => $keep, 'note' => $data['accounting_note']]]]);
            FinanceOperationLog::create(['document_type' => 'sales_cancellation', 'document_id' => $case->id, 'action' => 'confirm_disposition',
                'operator_id' => $actor->legacy_id, 'operator_name' => $name, 'content' => $data['accounting_note'],
                'fact_snapshot' => ['sales_order_id' => $orderId, 'retained_amount' => $keep, 'previous_retained_amount' => $a['retained']]]);
            return $this->present($order);
        }, 5);
    }

    public function refundCeiling(SalesOrder $order): string
    {
        $a = $this->amounts($order);
        if ($order->order_status === 'cancelled') {
            abort_unless($a['finance_confirmed'], 422, '已取消订单须先由财务确认退款和留存方案，再办理退款。');
        }
        return Money::maxZero(Money::sub($a['received'], $order->order_status === 'cancelled' ? $a['retained'] : '0'));
    }

    public function assertCovered(int $orderId): void
    {
        $order = SalesOrder::findOrFail($orderId);
        if ($order->order_status !== 'cancelled') return;
        $a = $this->amounts($order);
        abort_if(Money::compare(Money::add(Money::add($a['refunded'], $a['retained']), $this->reservedRefunds($orderId)), $a['received']) > 0,
            422, '撤销收款会使已退款、财务留存或办理中退款超过有效实收，请先更正对应处置。');
    }

    public function reservedRefunds(int $orderId, ?int $exceptCashId = null): string
    {
        $query = FinanceCashDocument::where('direction', 'payment')->where('status', 'draft')->where('id', '!=', $exceptCashId ?? 0)
            ->whereJsonContains('draft_allocation_items', ['source_business_type' => F::SOURCE_SALES_ORDER_REFUND]);
        if (DB::transactionLevel()) $query->lockForUpdate();
        return $query->orderBy('id')->get()->reduce(function ($sum, $doc) use ($orderId) {
            foreach ($doc->draft_allocation_items ?? [] as $row) if (($row['source_business_type'] ?? '') === F::SOURCE_SALES_ORDER_REFUND && (int) $row['source_document_id'] === $orderId) $sum = Money::add($sum, (string) $row['allocated_amount']);
            return $sum;
        }, '0.0000');
    }

    public function assertRefundIntents(FinanceCashDocument $doc, array $rows): void
    {
        $grouped = collect($rows)->where('source_business_type', F::SOURCE_SALES_ORDER_REFUND)->groupBy('source_document_id');
        foreach ($grouped as $orderId => $lines) {
            $order = SalesOrder::whereKey($orderId)->lockForUpdate()->firstOrFail();
            if ($order->order_status !== 'cancelled') continue;
            $a = $this->amounts($order); $this->refundCeiling($order);
            $wanted = $lines->reduce(fn ($sum, $r) => Money::add($sum, (string) $r['allocated_amount']), '0.0000');
            abort_if(Money::compare(Money::add($wanted, $this->reservedRefunds($orderId, $doc->id)), $a['remaining']) > 0, 422, '退款金额超过可退余额（含其他退款草稿占用），请刷新。');
        }
    }
}
