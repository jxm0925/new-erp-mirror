<?php

namespace App\Services\Erp;

use App\Models\Erp\SalesOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 订单成交平台：主平台 + 子平台两级。主平台按订单贸易类型（domestic 国内 / foreign 国外）选择，
 * 主平台下有启用的子平台时须选到子平台（如“线下转款”选到具体线索来源）；没有子平台只选主平台。
 * 订单只存平台编号（legacy_id），名称按当前档案显示。
 */
final class SalesTradePlatformService
{
    private const TABLE = 'erp_sales_order_trade_platforms';
    public const TRADE_LABELS = ['domestic' => '国内交易', 'foreign' => '国外交易'];
    public const ORIGINAL_NUMBER_REQUIRED = '线上成交平台必须填写原始单号。';

    public static function originalOrderNumber(SalesOrder $order): ?string
    {
        $number = trim((string) $order->origin_order_no);
        if ($number === '' && $order->channel_type_snapshot === 'online_platform') {
            $number = trim((string) $order->external_order_no);
        }
        return $number === '' ? null : $number;
    }

    public function options(): array
    {
        $rows = DB::table(self::TABLE)->where('enabled', true)
            ->orderBy('parent_legacy_id')->orderBy('sort')->orderBy('legacy_id')
            ->get(['legacy_id as id', 'parent_legacy_id as pid', 'name', 'short_name', 'trade_type', 'legacy_payload'])->keyBy('id');
        return $rows->filter(fn ($row) => (int) $row->pid === 0 || $rows->has($row->pid))
            ->map(function ($row) use ($rows): array {
                $data = (array) $row;
                unset($data['legacy_payload']);
                $parent = $rows->get($row->pid);
                $data['payment_mode'] = TradePlatformApplicationService::paymentMode($row->legacy_payload)
                    ?? TradePlatformApplicationService::paymentMode($parent->legacy_payload ?? null);
                return $data;
            })->values()->all();
    }

    /** 线上线下由成交平台决定，子平台标记优先。旧单未标记时沿用原平台快照。 */
    public function orderPaymentFields(array $header, ?SalesOrder $current = null): array
    {
        $platform = trim((string) (array_key_exists('platform', $header) ? $header['platform'] : $current?->platform));
        $sub = trim((string) (array_key_exists('platform2', $header) ? $header['platform2'] : $current?->platform2));
        $ids = array_map('intval', array_filter([$platform, $sub], 'ctype_digit'));
        $rows = DB::table(self::TABLE)->whereIn('legacy_id', $ids)->get(['legacy_id', 'parent_legacy_id', 'legacy_payload'])->keyBy('legacy_id');
        $mode = $this->paymentMode($rows->get((int) $platform), $rows->get((int) $sub));
        $same = $current && $platform === trim((string) $current->platform) && $sub === trim((string) $current->platform2);
        $channelType = match ($mode) {
            'online' => 'online_platform',
            'offline' => 'offline_direct',
            default => $same ? $current->channel_type_snapshot : 'offline_direct',
        };
        return ['channel_type_snapshot' => $channelType] + self::originalNumberFields($header, $current, $channelType === 'online_platform', $same);
    }

    public static function originalNumberFields(array $header, ?SalesOrder $current, bool $online, bool $samePlatform = true): array
    {
        $submitted = array_key_exists('origin_order_no', $header);
        $number = trim((string) ($submitted ? $header['origin_order_no'] : $current?->origin_order_no));
        if (!$submitted && $number === '' && $online && $samePlatform && $current?->channel_type_snapshot === 'online_platform') {
            $number = trim((string) (array_key_exists('external_order_no', $header) ? $header['external_order_no'] : $current->external_order_no));
        }
        if ($online && $number === '') {
            throw ValidationException::withMessages(['origin_order_no' => self::ORIGINAL_NUMBER_REQUIRED]);
        }
        return $submitted ? ['origin_order_no' => $number === '' ? null : $number] : [];
    }

    /**
     * 返回问题描述，无问题返回 null。
     * $current 为订单原值：未改动的已停用平台允许原样保存，避免平台停用后旧订单无法编辑。
     * $requireComplete=false（草稿）时允许暂未选择平台或子平台。
     */
    public function problem(?string $tradeType, mixed $platform, mixed $platform2, ?array $current = null, bool $requireComplete = true): ?string
    {
        $platform = trim((string) $platform);
        $platform2 = trim((string) $platform2);
        if ($platform === '') {
            if ($platform2 !== '') return '请先选择成交平台的主平台。';
            return $requireComplete ? '请选择成交平台。' : null;
        }
        $unchanged = $current !== null && trim((string) ($current['platform'] ?? '')) === $platform
            && trim((string) ($current['platform2'] ?? '')) === $platform2
            && (string) ($current['trade_type'] ?? '') === (string) $tradeType;
        $ids = array_values(array_filter([$platform, $platform2], fn ($v) => ctype_digit($v)));
        $rows = $ids ? DB::table(self::TABLE)->whereIn('legacy_id', array_map('intval', $ids))->get()->keyBy('legacy_id') : collect();
        $main = ctype_digit($platform) ? $rows->get((int) $platform) : null;
        if (! $main || (int) $main->parent_legacy_id !== 0) return '所选成交平台不存在或不是主平台，请重新选择。';
        if (! $main->enabled && ! $unchanged) return "成交平台“{$main->name}”已停用，请重新选择。";
        if (isset(self::TRADE_LABELS[$tradeType ?? '']) && isset(self::TRADE_LABELS[$main->trade_type ?? '']) && $main->trade_type !== $tradeType && ! $unchanged) {
            return "成交平台“{$main->name}”属于".self::TRADE_LABELS[$main->trade_type].'，与订单的贸易类型不一致，请重新选择。';
        }
        if ($platform2 !== '') {
            $sub = ctype_digit($platform2) ? $rows->get((int) $platform2) : null;
            if (! $sub || (int) $sub->parent_legacy_id !== (int) $main->legacy_id) return "所选子平台不属于“{$main->name}”，请重新选择。";
            if (! $sub->enabled && ! $unchanged) return "子平台“{$sub->name}”已停用，请重新选择。";
            return null;
        }
        if ($requireComplete && ! $unchanged
            && DB::table(self::TABLE)->where('parent_legacy_id', $main->legacy_id)->where('enabled', true)->exists()) {
            return "“{$main->name}”下有子平台，请继续选择子平台。";
        }
        return null;
    }

    public function assert(?string $tradeType, mixed $platform, mixed $platform2, ?array $current = null, bool $requireComplete = true): void
    {
        $problem = $this->problem($tradeType, $platform, $platform2, $current, $requireComplete);
        if ($problem !== null) throw ValidationException::withMessages([trim((string) $platform) === '' ? 'platform' : (str_contains($problem, '子平台') ? 'platform2' : 'platform') => $problem]);
    }

    /** 给订单补充 platform_name / platform2_name / platform_label 供列表和详情显示。 */
    public function decorate(iterable $orders): void
    {
        $orders = collect($orders);
        $ids = $orders->flatMap(fn ($o) => [$o->platform, $o->platform2])->filter(fn ($v) => ctype_digit(trim((string) $v)))->map(fn ($v) => (int) $v)->unique()->values();
        $rows = $ids->isEmpty() ? collect() : DB::table(self::TABLE)->whereIn('legacy_id', $ids)
            ->get(['legacy_id', 'parent_legacy_id', 'name', 'legacy_payload'])->keyBy('legacy_id');
        foreach ($orders as $order) {
            $main = ctype_digit(trim((string) $order->platform)) ? $rows->get((int) $order->platform) : null;
            $sub = ctype_digit(trim((string) $order->platform2)) ? $rows->get((int) $order->platform2) : null;
            $order->setAttribute('platform_name', $main?->name);
            $order->setAttribute('platform2_name', $sub?->name);
            $order->setAttribute('platform_label', $main ? ($sub ? $main->name.' / '.$sub->name : $main->name) : null);
            $order->setAttribute('platform_payment_mode', $this->paymentMode($main, $sub));
        }
    }

    private function paymentMode(?object $main, ?object $child): ?string
    {
        if (!$main || (int) $main->parent_legacy_id !== 0) return null;
        $childMode = $child && (int) $child->parent_legacy_id === (int) $main->legacy_id
            ? TradePlatformApplicationService::paymentMode($child->legacy_payload) : null;
        return $childMode ?? TradePlatformApplicationService::paymentMode($main->legacy_payload);
    }
}
