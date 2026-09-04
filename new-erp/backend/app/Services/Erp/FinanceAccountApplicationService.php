<?php

namespace App\Services\Erp;

use App\Models\Erp\FinanceAccount;
use App\Models\Erp\FinanceAccountMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinanceAccountApplicationService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly FinanceExchangeRateService $rates,
    ) {}

    public function create(array $data, ?int $operatorId): FinanceAccount
    {
        return DB::transaction(function () use ($data, $operatorId): FinanceAccount {
            $currency = $this->rates->assertEnabledCurrency($data['currency'] ?? 'CNY')->currency_code;
            $number = $this->numbers->reservedNumber($data['reservation_token'], 'finance_account', $operatorId, $data['creation_session_id'] ?? null);
            $account = FinanceAccount::create([
                'account_no' => $number, 'account_name' => trim($data['account_name']),
                'account_type' => $data['account_type'], 'bank_name' => $data['bank_name'] ?? null,
                'bank_account_no' => $data['bank_account_no'] ?? null, 'currency' => $currency,
                'status' => $data['status'] ?? 'enabled', 'sort' => $data['sort'] ?? 0,
                'remark' => $data['remark'] ?? null, 'created_by' => $operatorId, 'updated_by' => $operatorId,
            ]);
            $this->numbers->consume($data['reservation_token'], 'finance_account', $number, $operatorId, 'finance_account', $account->id);
            return $account;
        }, 5);
    }

    public function update(int $id, array $data, ?int $operatorId): FinanceAccount
    {
        return DB::transaction(function () use ($id, $data, $operatorId): FinanceAccount {
            $account = FinanceAccount::query()->lockForUpdate()->findOrFail($id);
            if (array_key_exists('currency', $data)) {
                $data['currency'] = $this->rates->assertEnabledCurrency($data['currency'])->currency_code;
                if ($data['currency'] !== $account->currency
                    && FinanceAccountMovement::query()->where('finance_account_id', $account->id)->exists()) {
                    throw ValidationException::withMessages(['currency' => '已产生资金流水的账户不能变更币种；请新建对应币种的资金账户。']);
                }
            }
            $account->update(array_filter([
                'account_name' => $data['account_name'] ?? null, 'account_type' => $data['account_type'] ?? null,
                'bank_name' => $data['bank_name'] ?? null, 'bank_account_no' => $data['bank_account_no'] ?? null,
                'currency' => $data['currency'] ?? null, 'sort' => $data['sort'] ?? null,
                'remark' => $data['remark'] ?? null, 'updated_by' => $operatorId,
            ], fn ($value) => $value !== null));
            return $account->fresh();
        }, 5);
    }

    public function setStatus(int $id, string $status, ?int $operatorId): FinanceAccount
    {
        if (!in_array($status, ['enabled', 'disabled'], true)) throw ValidationException::withMessages(['status' => '账户状态无效。']);
        return DB::transaction(function () use ($id, $status, $operatorId): FinanceAccount {
            $account = FinanceAccount::query()->lockForUpdate()->findOrFail($id);
            $account->update(['status' => $status, 'updated_by' => $operatorId]);
            return $account->fresh();
        }, 5);
    }
}
