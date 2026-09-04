<?php

namespace App\Services\Erp;

use App\Domain\Finance\Money;
use App\Models\Erp\FinanceAccountMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinanceAccountLedgerService
{
    /**
     * The account ledger is append-only. It exposes the original-currency
     * balance and its historical carrying amount, which is needed when a
     * foreign-currency holding is actually settled.
     */
    public function carryingBalance(int $accountId, bool $forUpdate = false): array
    {
        $query = FinanceAccountMovement::query()
            ->where('finance_account_id', $accountId)
            ->where('status', 'confirmed');
        // Aggregate SELECT ... FOR UPDATE is not reliable across MySQL query
        // plans. Lock the source rows before aggregating when a confirmation
        // needs a stable carrying balance.
        if ($forUpdate) {
            $query->lockForUpdate()->get(['id']);
        }
        $row = FinanceAccountMovement::query()
            ->where('finance_account_id', $accountId)
            ->where('status', 'confirmed')
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN original_amount ELSE -original_amount END), 0) as original_balance")
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN base_amount ELSE -base_amount END), 0) as base_balance")
            ->first();

        return [
            'original_balance' => Money::normalize((string) ($row?->original_balance ?? 0)),
            'base_balance' => Money::normalize((string) ($row?->base_balance ?? 0)),
        ];
    }

    public function outgoingCarryingAmount(int $accountId, string $amount, bool $forUpdate = false): string
    {
        $balance = $this->carryingBalance($accountId, $forUpdate);
        if (Money::compare($balance['original_balance'], '0') <= 0
            || Money::compare($amount, $balance['original_balance']) > 0) {
            throw ValidationException::withMessages([
                'source_amount' => '转出金额超过该资金账户可用原币余额，不能通过转账/换汇形成负余额。',
            ]);
        }

        return bcdiv(bcmul($balance['base_balance'], $amount, 8), $balance['original_balance'], 4);
    }

    public function append(array $data): FinanceAccountMovement
    {
        return FinanceAccountMovement::create($data);
    }

    public function valuation(int $accountId, string $currency, string $valuationDate, FinanceExchangeRateService $rates): array
    {
        $carrying = $this->carryingBalance($accountId);
        $snapshot = $rates->valuationSnapshot($currency, $valuationDate);
        $currentBase = $rates->convert($carrying['original_balance'], $snapshot['rate']);

        return [
            'original_balance' => $carrying['original_balance'],
            'carrying_base_amount' => $carrying['base_balance'],
            'valuation_currency' => $snapshot['target_currency'],
            'valuation_rate' => $snapshot['rate'],
            'valuation_rate_id' => $snapshot['exchange_rate_id'],
            'valuation_date' => $snapshot['rate_date'],
            'current_base_valuation' => $currentBase,
            'unrealized_fx_gain_loss' => Money::sub($currentBase, $carrying['base_balance']),
        ];
    }
}
