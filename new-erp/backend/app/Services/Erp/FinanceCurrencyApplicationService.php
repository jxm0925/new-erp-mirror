<?php

namespace App\Services\Erp;

use App\Models\Erp\FinanceAccount;
use App\Models\Erp\FinanceCurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinanceCurrencyApplicationService
{
    public function create(array $data, ?int $operatorId): FinanceCurrency
    {
        return DB::transaction(function () use ($data, $operatorId): FinanceCurrency {
            $code = strtoupper(trim($data['currency_code']));
            if (FinanceCurrency::query()->where('currency_code', $code)->exists()) {
                throw ValidationException::withMessages(['currency_code' => '币种代码已存在。']);
            }
            if (! empty($data['is_base']) && FinanceCurrency::query()->where('is_base', true)->exists()) {
                throw ValidationException::withMessages(['is_base' => '当前账套已有本位币，不能同时设置多个。']);
            }

            return FinanceCurrency::create([
                'currency_code' => $code,
                'currency_name' => trim($data['currency_name']),
                'symbol' => $data['symbol'] ?? null,
                'decimal_places' => $data['decimal_places'] ?? 2,
                'is_base' => (bool) ($data['is_base'] ?? false),
                'status' => $data['status'] ?? 'enabled',
                'sort' => $data['sort'] ?? 0,
                'remark' => $data['remark'] ?? null,
                'created_by' => $operatorId,
                'updated_by' => $operatorId,
            ]);
        }, 5);
    }

    public function update(int $id, array $data, ?int $operatorId): FinanceCurrency
    {
        return DB::transaction(function () use ($id, $data, $operatorId): FinanceCurrency {
            $currency = FinanceCurrency::query()->lockForUpdate()->findOrFail($id);
            if (array_key_exists('currency_code', $data) && strtoupper(trim($data['currency_code'])) !== $currency->currency_code) {
                throw ValidationException::withMessages(['currency_code' => '已建立的币种代码不可修改。']);
            }
            if (array_key_exists('is_base', $data) && (bool) $data['is_base'] !== (bool) $currency->is_base) {
                throw ValidationException::withMessages(['is_base' => '本位币变更必须通过账套迁移流程，不能直接修改。']);
            }
            $nextStatus = $data['status'] ?? $currency->status;
            if ($nextStatus === 'disabled' && $currency->is_base) {
                throw ValidationException::withMessages(['status' => '本位币不能停用。']);
            }
            if ($nextStatus === 'disabled'
                && FinanceAccount::query()->where('currency', $currency->currency_code)->where('status', 'enabled')->exists()) {
                throw ValidationException::withMessages(['status' => '仍有启用的资金账户使用该币种；请先停用或完成账户资金清理。']);
            }

            $currency->update([
                ...collect($data)->only(['currency_name', 'symbol', 'decimal_places', 'status', 'sort', 'remark'])->all(),
                'updated_by' => $operatorId,
            ]);

            return $currency->fresh();
        }, 5);
    }
}
