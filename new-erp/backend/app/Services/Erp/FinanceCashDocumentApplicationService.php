<?php

namespace App\Services\Erp;

use App\Domain\Finance\FinanceConstants;
use App\Domain\Finance\Money;
use App\Models\Erp\FinanceAccount;
use App\Models\Erp\FinanceCashDocument;
use App\Models\Erp\FinanceAccountMovement;
use App\Models\Erp\FinanceOperationLog;
use App\Models\Erp\FinancePlatformFee;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinanceCashDocumentApplicationService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly FinancePartyResolver $parties,
        private readonly FinanceExchangeRateService $rates,
        private readonly FinanceAccountLedgerService $ledger,
    ) {}

    public function create(string $direction, array $data, ?int $operatorId, ?string $operatorName): FinanceCashDocument
    {
        if (!in_array($direction, FinanceConstants::directions(), true)) throw ValidationException::withMessages(['direction' => '资金方向无效。']);
        return DB::transaction(function () use ($direction, $data, $operatorId, $operatorName): FinanceCashDocument {
            $account = FinanceAccount::query()->whereKey($data['finance_account_id'])->where('status', 'enabled')->lockForUpdate()->firstOrFail();
            $party = $this->parties->resolve($data['party_type'], (int) $data['party_id']);
            $amount = Money::normalize((string) $data['amount']);
            $currency = $this->rates->assertEnabledCurrency($data['currency'] ?? 'CNY')->currency_code;
            if (Money::compare($amount, '0') <= 0) throw ValidationException::withMessages(['amount' => '收付金额必须大于 0。']);
            if ((string) $account->currency !== $currency) throw ValidationException::withMessages(['currency' => '资金账户币种与单据币种不一致。']);
            $fee = Money::normalize((string) ($data['platform_fee_amount'] ?? '0'));
            if (Money::compare($fee, '0') < 0 || Money::compare($fee, $amount) >= 0) throw ValidationException::withMessages(['platform_fee_amount' => '平台手续费必须大于等于零且小于收付款金额。']);
            $feeAccount = null;
            if (Money::compare($fee, '0') > 0) {
                $feeAccount = FinanceAccount::query()->whereKey($data['platform_fee_account_id'] ?? $account->id)->where('status', 'enabled')->lockForUpdate()->firstOrFail();
                if ((string) $feeAccount->currency !== $currency) throw ValidationException::withMessages(['platform_fee_account_id' => '平台手续费账户币种必须与收付款币种一致。']);
            }
            $exchange = $this->rates->businessSnapshot($currency, $data['business_date']);
            $baseAmount = $this->rates->convert($amount, $exchange['rate']);
            $type = $direction === FinanceConstants::DIRECTION_RECEIPT ? 'finance_receipt' : 'finance_payment';
            $number = $this->numbers->reservedNumber($data['reservation_token'], $type, $operatorId, $data['creation_session_id'] ?? null);
            $document = FinanceCashDocument::create([
                'direction' => $direction, 'document_no' => $number, ...$party,
                'business_date' => $data['business_date'], 'finance_account_id' => $account->id,
                'currency' => $currency,
                'base_currency' => $exchange['target_currency'],
                'exchange_rate_id' => $exchange['exchange_rate_id'],
                'business_exchange_rate' => $exchange['rate'],
                'exchange_rate_date' => $exchange['rate_date'],
                'exchange_rate_source' => $exchange['rate_source'],
                'amount' => $amount,
                'platform_fee_amount' => $fee,
                'platform_fee_currency' => $feeAccount?->currency,
                'platform_fee_account_id' => $feeAccount?->id,
                'platform_fee_base_amount' => $feeAccount ? $this->rates->convert($fee, $exchange['rate']) : '0.0000',
                'platform_fee_type' => $feeAccount ? ($data['platform_fee_type'] ?? 'platform') : null,
                'base_amount' => $baseAmount,
                'payment_method' => $data['payment_method'], 'external_reference_no' => $data['external_reference_no'] ?? null,
                'operator_id' => $operatorId, 'operator_name_snapshot' => $operatorName,
                'remark' => $data['remark'] ?? null, 'status' => FinanceConstants::STATUS_DRAFT,
                'idempotency_key' => $data['idempotency_key'] ?? null,
            ]);
            $this->numbers->consume($data['reservation_token'], $type, $number, $operatorId, $type, $document->id);
            $this->log($document, 'create', null, FinanceConstants::STATUS_DRAFT, $operatorId, $operatorName, '创建资金单据草稿');
            return $document->fresh(['account', 'allocations', 'attachments', 'logs']);
        }, 5);
    }

    public function updateDraft(int $id, array $data, ?int $operatorId, ?string $operatorName): FinanceCashDocument
    {
        return DB::transaction(function () use ($id, $data, $operatorId, $operatorName): FinanceCashDocument {
            $document = FinanceCashDocument::query()->lockForUpdate()->findOrFail($id);
            if ($document->status !== FinanceConstants::STATUS_DRAFT) throw ValidationException::withMessages(['status' => '只有草稿资金单可以编辑。']);
            $changes = collect($data)->only(['business_date', 'finance_account_id', 'amount', 'payment_method', 'external_reference_no', 'remark', 'platform_fee_amount', 'platform_fee_account_id', 'platform_fee_type'])->all();
            if (isset($changes['amount'])) {
                $changes['amount'] = Money::normalize((string) $changes['amount']);
                if (Money::compare($changes['amount'], '0') <= 0) throw ValidationException::withMessages(['amount' => '收付金额必须大于 0。']);
            }
            $currency = $this->rates->assertEnabledCurrency((string) $document->currency)->currency_code;
            if (isset($changes['finance_account_id'])) {
                $account = FinanceAccount::query()->whereKey($changes['finance_account_id'])->where('status', 'enabled')->lockForUpdate()->firstOrFail();
                if ((string) $account->currency !== (string) $document->currency) {
                    throw ValidationException::withMessages(['finance_account_id' => '资金账户币种与单据币种不一致。']);
                }
            }
            if (array_key_exists('business_date', $changes) || array_key_exists('amount', $changes)) {
                $exchange = $this->rates->businessSnapshot($currency, (string) ($changes['business_date'] ?? $document->business_date->toDateString()));
                $changes = [
                    ...$changes,
                    'base_currency' => $exchange['target_currency'],
                    'exchange_rate_id' => $exchange['exchange_rate_id'],
                    'business_exchange_rate' => $exchange['rate'],
                    'exchange_rate_date' => $exchange['rate_date'],
                    'exchange_rate_source' => $exchange['rate_source'],
                    'base_amount' => $this->rates->convert((string) ($changes['amount'] ?? $document->amount), $exchange['rate']),
                ];
            }
            $fee = Money::normalize((string) ($changes['platform_fee_amount'] ?? $document->platform_fee_amount));
            $amount = Money::normalize((string) ($changes['amount'] ?? $document->amount));
            if (Money::compare($fee, '0') < 0 || Money::compare($fee, $amount) >= 0) throw ValidationException::withMessages(['platform_fee_amount' => '平台手续费必须大于等于零且小于收付款金额。']);
            if (Money::compare($fee, '0') > 0) {
                $feeAccount = FinanceAccount::query()->whereKey($changes['platform_fee_account_id'] ?? $document->platform_fee_account_id ?? $document->finance_account_id)->where('status', 'enabled')->lockForUpdate()->firstOrFail();
                if ((string) $feeAccount->currency !== $currency) throw ValidationException::withMessages(['platform_fee_account_id' => '平台手续费账户币种必须与收付款币种一致。']);
                $rate = $changes['business_exchange_rate'] ?? $document->business_exchange_rate;
                $changes = [...$changes, 'platform_fee_currency' => $feeAccount->currency, 'platform_fee_account_id' => $feeAccount->id, 'platform_fee_base_amount' => $this->rates->convert($fee, (string) $rate)];
            } else {
                $changes = [...$changes, 'platform_fee_currency' => null, 'platform_fee_account_id' => null, 'platform_fee_base_amount' => '0.0000', 'platform_fee_type' => null];
            }
            $document->update($changes);
            $this->log($document, 'update_draft', FinanceConstants::STATUS_DRAFT, FinanceConstants::STATUS_DRAFT, $operatorId, $operatorName, '修改资金单据草稿');
            return $document->fresh(['account', 'allocations', 'attachments', 'logs']);
        }, 5);
    }

    public function confirm(int $id, ?int $operatorId, ?string $operatorName): FinanceCashDocument
    {
        return DB::transaction(function () use ($id, $operatorId, $operatorName): FinanceCashDocument {
            $document = FinanceCashDocument::query()->with('account')->lockForUpdate()->findOrFail($id);
            if ($document->status !== FinanceConstants::STATUS_DRAFT) throw ValidationException::withMessages(['status' => '只有草稿资金单可以确认。']);
            if ($document->account?->status !== 'enabled') throw ValidationException::withMessages(['finance_account_id' => '资金账户已停用。']);
            // The draft rate is only a preview. Confirmation fixes the final
            // historical snapshot so later rate changes cannot recalculate it.
            $exchange = $this->rates->businessSnapshot((string) $document->currency, $document->business_date->toDateString());
            $baseAmount = $this->rates->convert((string) $document->amount, $exchange['rate']);
            $document->update([
                'base_currency' => $exchange['target_currency'], 'exchange_rate_id' => $exchange['exchange_rate_id'],
                'business_exchange_rate' => $exchange['rate'], 'exchange_rate_date' => $exchange['rate_date'],
                'exchange_rate_source' => $exchange['rate_source'], 'base_amount' => $baseAmount,
                'status' => FinanceConstants::STATUS_CONFIRMED, 'confirmed_by' => $operatorId, 'confirmed_at' => now(), 'lock_version' => DB::raw('lock_version + 1'),
            ]);
            $this->ledger->append([
                'finance_account_id' => $document->finance_account_id, 'movement_type' => 'cash_document', 'source_type' => 'cash_document', 'source_id' => $document->id,
                'direction' => $document->direction === FinanceConstants::DIRECTION_RECEIPT ? 'in' : 'out', 'currency' => $document->currency,
                'original_amount' => $document->amount, 'base_currency' => $exchange['target_currency'], 'base_amount' => $baseAmount,
                'business_date' => $document->business_date, 'status' => 'confirmed', 'created_by' => $operatorId,
            ]);
            if (Money::compare((string) $document->platform_fee_amount, '0') > 0) {
                $feeAccount = FinanceAccount::query()->whereKey($document->platform_fee_account_id ?: $document->finance_account_id)->where('status', 'enabled')->lockForUpdate()->firstOrFail();
                if ((string) $feeAccount->currency !== (string) $document->currency) throw ValidationException::withMessages(['platform_fee_account_id' => '平台手续费账户币种必须与收付款币种一致。']);
                $feeBase = $this->rates->convert((string) $document->platform_fee_amount, $exchange['rate']);
                $fee = FinancePlatformFee::create(['fee_no' => $this->numbers->next('finance_platform_fee', 'FF'), 'finance_account_id' => $feeAccount->id, 'cash_document_id' => $document->id, 'currency' => $feeAccount->currency, 'amount' => $document->platform_fee_amount, 'base_currency' => $exchange['target_currency'], 'exchange_rate_id' => $exchange['exchange_rate_id'], 'exchange_rate' => $exchange['rate'], 'base_amount' => $feeBase, 'fee_type' => $document->platform_fee_type ?: 'platform', 'business_date' => $document->business_date, 'status' => 'confirmed', 'created_by' => $operatorId]);
                $this->ledger->append(['finance_account_id' => $feeAccount->id, 'movement_type' => 'platform_fee', 'source_type' => 'platform_fee', 'source_id' => $fee->id, 'direction' => 'out', 'currency' => $feeAccount->currency, 'original_amount' => $fee->amount, 'base_currency' => $exchange['target_currency'], 'base_amount' => $feeBase, 'business_date' => $document->business_date, 'status' => 'confirmed', 'created_by' => $operatorId]);
                $document->update(['platform_fee_base_amount' => $feeBase]);
            }
            $this->log($document, 'confirm', FinanceConstants::STATUS_DRAFT, FinanceConstants::STATUS_CONFIRMED, $operatorId, $operatorName, '确认真实资金事实');
            return $document->fresh(['account', 'allocations', 'attachments', 'logs']);
        }, 5);
    }

    public function void(int $id, string $reason, ?int $operatorId, ?string $operatorName): FinanceCashDocument
    {
        return DB::transaction(function () use ($id, $reason, $operatorId, $operatorName): FinanceCashDocument {
            $document = FinanceCashDocument::query()->with('allocations')->lockForUpdate()->findOrFail($id);
            if ($document->status !== FinanceConstants::STATUS_CONFIRMED) throw ValidationException::withMessages(['status' => '只有已确认资金单可以作废。']);
            if ($document->allocations->where('status', FinanceConstants::ALLOCATION_ACTIVE)->isNotEmpty()) {
                throw ValidationException::withMessages(['allocations' => '资金单仍有有效核销，必须先撤销核销再作废。']);
            }
            if (trim($reason) === '') throw ValidationException::withMessages(['void_reason' => '作废必须填写原因。']);
            $document->update(['status' => FinanceConstants::STATUS_VOIDED, 'voided_by' => $operatorId, 'voided_at' => now(), 'void_reason' => $reason, 'lock_version' => DB::raw('lock_version + 1')]);
            FinanceAccountMovement::query()->where('source_type', 'cash_document')->where('source_id', $document->id)->where('status', 'confirmed')->update(['status' => 'voided']);
            $feeIds = FinancePlatformFee::query()->where('cash_document_id', $document->id)->where('status', 'confirmed')->pluck('id');
            FinancePlatformFee::query()->whereIn('id', $feeIds)->update(['status' => 'voided']);
            FinanceAccountMovement::query()->where('source_type', 'platform_fee')->whereIn('source_id', $feeIds)->where('status', 'confirmed')->update(['status' => 'voided']);
            $this->log($document, 'void', FinanceConstants::STATUS_CONFIRMED, FinanceConstants::STATUS_VOIDED, $operatorId, $operatorName, $reason);
            return $document->fresh(['account', 'allocations', 'attachments', 'logs']);
        }, 5);
    }

    private function log(FinanceCashDocument $document, string $action, ?string $from, ?string $to, ?int $operatorId, ?string $operatorName, string $content): void
    {
        FinanceOperationLog::create([
            'document_type' => 'cash_document', 'document_id' => $document->id, 'action' => $action,
            'from_status' => $from, 'to_status' => $to,
            'fact_snapshot' => ['document_no' => $document->document_no, 'direction' => $document->direction, 'amount' => (string) $document->amount, 'currency' => $document->currency],
            'operator_id' => $operatorId, 'operator_name' => $operatorName, 'content' => $content,
        ]);
    }
}
