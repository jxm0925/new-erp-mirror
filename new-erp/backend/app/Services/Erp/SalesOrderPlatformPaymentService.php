<?php

namespace App\Services\Erp;

use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesOrderLog;
use Illuminate\Support\Str;

final class SalesOrderPlatformPaymentService
{
    public const FLAG = 'erp_platform_payment_verification';

    public function __construct(private readonly SalesFinanceSettlementService $settlements) {}

    public static function orderNumber(SalesOrder $order): ?string
    {
        if ($order->channel_type_snapshot !== 'online_platform' || blank($order->platform)) return null;
        return SalesTradePlatformService::originalOrderNumber($order);
    }

    public static function canConfirm(SalesOrder $order, int $actorId): bool
    {
        return !$order->isLegacyHistory() && self::isRecorder($order, $actorId)
            && in_array($order->order_status, ['draft', 'confirmed'], true)
            && self::orderNumber($order) !== null;
    }

    public static function isRecorder(SalesOrder $order, int $actorId): bool
    {
        return $actorId > 0 && $actorId === (int) $order->created_by_legacy_id;
    }

    public function status(SalesOrder $order, array $settlement): array
    {
        $basis = $this->basis($order, $settlement);
        $record = $order->order_flags[self::FLAG] ?? [];
        $recordBasis = (array) ($record['basis'] ?? []);
        ksort($recordBasis);
        $valid = $basis !== null && $recordBasis === $basis
            && (int) $order->created_by_legacy_id > 0
            && (int) ($record['confirmed_by_legacy_id'] ?? 0) === (int) $order->created_by_legacy_id
            && isset($record['confirmed_by'], $record['confirmed_at']);

        return [
            'platform_payment_eligible' => $basis !== null,
            'platform_payment' => $valid ? [
                'confirmed_by' => $record['confirmed_by'],
                'confirmed_at' => $record['confirmed_at'],
            ] : null,
            'platform_payment_receipt' => ($receipt = self::receiptSnapshot($order)) === null ? null : [
                'confirmed_by' => $receipt['confirmed_by'] ?? null,
                'confirmed_at' => $receipt['confirmed_at'] ?? null,
            ],
            'platform_payment_token' => $basis === null ? null : hash('sha256', json_encode([$basis, $record['revision'] ?? null], JSON_THROW_ON_ERROR)),
        ];
    }

    /** 收款核对事实与当前发货放行分开；旧记录只读兼容，不回填历史数据。 */
    public static function receiptSnapshot(SalesOrder $order): ?array
    {
        return self::receiptFromRecord((array) ($order->order_flags[self::FLAG] ?? []));
    }

    /** 调用方先锁定订单；确认事实和发货状态在同一事务内更新。 */
    public function update(SalesOrder $order, bool $confirmed, ?object $actor, ?string $token = null): void
    {
        $order->assertCurrentBusiness();
        abort_unless(self::isRecorder($order, (int) ($actor->legacy_id ?? 0)), 403, '只有录单人可以确认平台收款。');
        abort_unless(self::canConfirm($order, (int) $actor->legacy_id), 422, '只有已填写原始单号的线上平台订单可以确认平台收款。');
        $settlement = $this->settlements->status($order);
        $status = $this->status($order, $settlement);
        abort_if($token !== null && (!is_string($status['platform_payment_token']) || !hash_equals($status['platform_payment_token'], $token)), 409, '平台订单或收款确认已变化，请刷新后重新核对。');
        if ($confirmed ? $status['platform_payment'] !== null : $status['platform_payment_receipt'] === null) return;

        $flags = (array) $order->order_flags;
        $operator = (string) ($actor->nickname ?: $actor->username);
        $record = ['revision' => (string) Str::uuid()];
        if ($confirmed) $record += [
            'basis' => $this->basis($order, $settlement),
            'confirmed_by_legacy_id' => (int) $actor->legacy_id,
            'confirmed_by' => $operator,
            'confirmed_at' => now()->toISOString(),
        ];
        if ($confirmed) $record['receipt_snapshot'] = $record;
        $flags[self::FLAG] = $record;
        $order->update(['order_flags' => $flags]);
        SalesOrderLog::create([
            'sales_order_id' => $order->id,
            'order_no_snapshot' => $order->sales_order_no,
            'action' => $confirmed ? 'platform_payment_confirmed' : 'platform_payment_revoked',
            'before_status' => $order->order_status,
            'after_status' => $order->order_status,
            'operator' => $operator,
            'content' => $confirmed ? '录单人已核对平台全款。' : '录单人已撤销平台收款确认。',
            'payload' => $record + [
                'actor_legacy_id' => (int) $actor->legacy_id,
                'platform_order_no' => self::orderNumber($order),
            ],
        ]);
    }

    public function invalidate(array $flags): array
    {
        $receipt = self::receiptFromRecord((array) ($flags[self::FLAG] ?? []));
        $flags[self::FLAG] = ['revision' => (string) Str::uuid()];
        // 应收/退款变化仍取消原发货放行，但不能抹掉已经核对过的买家付款事实。
        if ($receipt !== null) $flags[self::FLAG]['receipt_snapshot'] = $receipt;
        return $flags;
    }

    private static function receiptFromRecord(array $record): ?array
    {
        $receipt = $record['receipt_snapshot'] ?? $record;
        return is_array($receipt) && isset($receipt['basis'], $receipt['confirmed_at']) ? $receipt : null;
    }

    private function basis(SalesOrder $order, array $settlement): ?array
    {
        $number = self::orderNumber($order);
        if ($number === null || $order->order_status === 'cancelled') return null;

        $basis = [
            'sales_channel_id' => (int) $order->sales_channel_id,
            'platform' => (string) $order->platform,
            'platform2' => (string) $order->platform2,
            'platform_order_no' => $number,
            'customer_id' => (int) $order->customer_id,
            'currency' => (string) $order->currency,
            'contract_amount' => $settlement['contract_amount'],
            'net_receivable_amount' => $settlement['net_receivable_amount'],
            'refunded_amount' => $settlement['refunded_amount'],
        ];
        ksort($basis);
        return $basis;
    }
}
