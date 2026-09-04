<?php

namespace App\Services\Erp;

use App\Models\Erp\FinanceCurrency;
use App\Models\Erp\FinanceExchangeRate;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class FinanceExchangeRateService
{
    public const TYPE_BUSINESS = 'business';
    public const TYPE_VALUATION = 'valuation';
    public const TYPE_SETTLEMENT = 'settlement';

    public function baseCurrency(): FinanceCurrency
    {
        $currencies = FinanceCurrency::query()
            ->where('is_base', true)
            ->where('status', 'enabled')
            ->lockForUpdate()
            ->get();

        if ($currencies->count() !== 1) {
            throw ValidationException::withMessages(['base_currency' => '系统必须且只能维护一个启用的本位币。']);
        }

        return $currencies->first();
    }

    public function assertEnabledCurrency(string $currency): FinanceCurrency
    {
        $currency = strtoupper(trim($currency));
        $model = FinanceCurrency::query()
            ->where('currency_code', $currency)
            ->where('status', 'enabled')
            ->first();

        if (! $model) {
            throw ValidationException::withMessages(['currency' => "币种 {$currency} 不存在或已停用。"]);
        }

        return $model;
    }

    public function businessSnapshot(string $sourceCurrency, string $date): array
    {
        return $this->snapshot($sourceCurrency, $this->baseCurrency()->currency_code, $date, self::TYPE_BUSINESS);
    }

    public function valuationSnapshot(string $sourceCurrency, string $date): array
    {
        return $this->snapshot($sourceCurrency, $this->baseCurrency()->currency_code, $date, self::TYPE_VALUATION);
    }

    public function settlementSnapshot(string $sourceCurrency, string $date): array
    {
        return $this->snapshot($sourceCurrency, $this->baseCurrency()->currency_code, $date, self::TYPE_SETTLEMENT);
    }

    public function snapshot(string $sourceCurrency, string $targetCurrency, string $date, string $type): array
    {
        $sourceCurrency = strtoupper(trim($sourceCurrency));
        $targetCurrency = strtoupper(trim($targetCurrency));
        $this->assertEnabledCurrency($sourceCurrency);
        $this->assertEnabledCurrency($targetCurrency);
        $effectiveAt = Carbon::parse($date)->endOfDay();

        if ($sourceCurrency === $targetCurrency) {
            return [
                'exchange_rate_id' => null,
                'rate' => '1.0000000000',
                'rate_date' => $effectiveAt->toDateString(),
                'rate_source' => 'same_currency',
                'source_currency' => $sourceCurrency,
                'target_currency' => $targetCurrency,
                'rate_type' => $type,
            ];
        }

        $rate = FinanceExchangeRate::query()
            ->where('source_currency', $sourceCurrency)
            ->where('target_currency', $targetCurrency)
            ->where('rate_type', $type)
            ->where('status', 'enabled')
            ->where('effective_at', '<=', $effectiveAt)
            ->latest('effective_at')
            ->latest('id')
            ->first();

        if (! $rate) {
            throw ValidationException::withMessages([
                'exchange_rate' => "缺少 {$sourceCurrency} → {$targetCurrency} 的有效{$this->rateTypeLabel($type)}，不能使用 1:1、0 或过期值替代。",
            ]);
        }

        return [
            'exchange_rate_id' => $rate->id,
            'rate' => (string) $rate->rate,
            'rate_date' => $rate->effective_at->toDateString(),
            'rate_source' => $rate->source,
            'source_currency' => $sourceCurrency,
            'target_currency' => $targetCurrency,
            'rate_type' => $type,
        ];
    }

    public function convert(string $amount, string $rate, int $scale = 4): string
    {
        if (bccomp($rate, '0', 10) <= 0) {
            throw ValidationException::withMessages(['exchange_rate' => '汇率必须大于 0。']);
        }

        return $this->round(bcmul($amount, $rate, $scale + 8), $scale);
    }

    private function round(string $amount, int $scale): string
    {
        $negative = str_starts_with($amount, '-');
        $absolute = $negative ? substr($amount, 1) : $amount;
        $rounded = bcadd($absolute, '0.'.str_repeat('0', $scale).'5', $scale);

        return $negative && bccomp($rounded, '0', $scale) !== 0 ? '-'.$rounded : $rounded;
    }

    private function rateTypeLabel(string $type): string
    {
        return match ($type) {
            self::TYPE_BUSINESS => '业务汇率',
            self::TYPE_VALUATION => '估值参考汇率',
            self::TYPE_SETTLEMENT => '实际结算汇率',
            default => '汇率',
        };
    }
}
