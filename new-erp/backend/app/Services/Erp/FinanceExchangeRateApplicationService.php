<?php

namespace App\Services\Erp;

use App\Models\Erp\FinanceAllocation;
use App\Models\Erp\FinanceCashDocument;
use App\Models\Erp\FinanceExchangeRate;
use App\Models\Erp\FinancePlatformFee;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinanceExchangeRateApplicationService
{
    public function __construct(private readonly FinanceExchangeRateService $rates) {}

    public function create(array $data, ?int $operatorId): FinanceExchangeRate
    {
        return DB::transaction(function () use ($data, $operatorId): FinanceExchangeRate {
            if (($data['rate_type'] ?? null) === FinanceExchangeRateService::TYPE_SETTLEMENT) {
                throw ValidationException::withMessages(['rate_type' => '实际成交汇率只能由已确认的换汇事实生成，不允许作为汇率主数据维护。']);
            }
            $source = strtoupper(trim($data['source_currency']));
            $target = strtoupper(trim($data['target_currency']));
            $this->rates->assertEnabledCurrency($source);
            $this->rates->assertEnabledCurrency($target);
            if ($source === $target) {
                throw ValidationException::withMessages(['target_currency' => '同币种不需要维护汇率记录。']);
            }
            if (bccomp((string) $data['rate'], '0', 10) <= 0) {
                throw ValidationException::withMessages(['rate' => '汇率必须大于 0。']);
            }

            return FinanceExchangeRate::create([
                'source_currency' => $source,
                'target_currency' => $target,
                'rate' => $data['rate'],
                'rate_type' => $data['rate_type'],
                'source' => $data['source'],
                'effective_at' => $data['effective_at'],
                'status' => 'enabled',
                'remark' => $data['remark'] ?? null,
                'created_by' => $operatorId,
            ]);
        }, 5);
    }

    public function disable(int $id, ?int $operatorId): FinanceExchangeRate
    {
        return DB::transaction(function () use ($id, $operatorId): FinanceExchangeRate {
            $rate = FinanceExchangeRate::query()->lockForUpdate()->findOrFail($id);
            if (FinanceCashDocument::query()->where('exchange_rate_id', $rate->id)->exists()
                || FinanceAllocation::query()->where('exchange_rate_id', $rate->id)->exists()
                || FinancePlatformFee::query()->where('exchange_rate_id', $rate->id)->exists()) {
                throw ValidationException::withMessages(['status' => '汇率已被正式财务事实使用，不能停用或覆盖；请新增后续版本。']);
            }
            $rate->update(['status' => 'disabled', 'disabled_by' => $operatorId, 'disabled_at' => now()]);

            return $rate->fresh();
        }, 5);
    }
}
