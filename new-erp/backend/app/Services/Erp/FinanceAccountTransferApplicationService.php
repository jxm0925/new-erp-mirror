<?php

namespace App\Services\Erp;

use App\Domain\Finance\Money;
use App\Models\Erp\FinanceAccount;
use App\Models\Erp\FinanceAccountTransfer;
use App\Models\Erp\FinanceAccountMovement;
use App\Models\Erp\FinanceOperationLog;
use App\Models\Erp\FinancePlatformFee;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinanceAccountTransferApplicationService
{
    public function __construct(private readonly DocumentNumberService $numbers, private readonly FinanceExchangeRateService $rates, private readonly FinanceAccountLedgerService $ledger) {}

    public function create(array $data, ?int $operatorId): FinanceAccountTransfer
    {
        return $this->settle($data, $operatorId);
    }

    /** A read-only calculation. It deliberately creates no transfer, fee or ledger fact. */
    public function preview(array $data): array
    {
        return $this->calculate($data, false);
    }

    public function createDraft(array $data, ?int $operatorId): FinanceAccountTransfer
    {
        $data = $this->normalizeBusinessDate($data);

        return DB::transaction(function () use ($data, $operatorId): FinanceAccountTransfer {
            $facts = $this->calculate($data, true);
            $source = FinanceAccount::query()->findOrFail($facts['source_account_id']);
            $target = FinanceAccount::query()->findOrFail($facts['target_account_id']);
            $feeAccount = $this->resolveFeeAccount($data, $source, $target, true);
            $transfer = FinanceAccountTransfer::create($this->draftPayload($data, $facts, $source, $target, $feeAccount, $operatorId));
            FinanceOperationLog::create(['document_type' => 'account_transfer', 'document_id' => $transfer->id, 'action' => 'create', 'to_status' => 'draft', 'operator_id' => $operatorId, 'content' => '创建资金转账/换汇草稿']);
            return $transfer;
        }, 5);
    }

    public function updateDraft(int $id, array $data, ?int $operatorId): FinanceAccountTransfer
    {
        return DB::transaction(function () use ($id, $data, $operatorId): FinanceAccountTransfer {
            $transfer = FinanceAccountTransfer::query()->lockForUpdate()->findOrFail($id);
            if ($transfer->status !== 'draft') throw ValidationException::withMessages(['status' => '仅草稿换汇单允许编辑。']);
            $next = $this->normalizeBusinessDate([...$transfer->only(['source_account_id', 'target_account_id', 'source_amount', 'target_amount', 'fee_amount', 'fee_currency', 'fee_bearer', 'fee_account_id', 'business_date', 'remark']), ...$data]);
            $facts = $this->calculate($next, true);
            $source = FinanceAccount::query()->findOrFail($facts['source_account_id']);
            $target = FinanceAccount::query()->findOrFail($facts['target_account_id']);
            $feeAccount = $this->resolveFeeAccount($next, $source, $target, true);
            $transfer->update($this->draftPayload($next, $facts, $source, $target, $feeAccount));
            FinanceOperationLog::create(['document_type' => 'account_transfer', 'document_id' => $transfer->id, 'action' => 'update_draft', 'from_status' => 'draft', 'to_status' => 'draft', 'operator_id' => $operatorId, 'content' => '修改资金转账/换汇草稿']);
            return $transfer->fresh();
        }, 5);
    }

    public function confirm(int $id, ?int $operatorId, ?string $previewToken = null): FinanceAccountTransfer
    {
        return DB::transaction(function () use ($id, $operatorId, $previewToken): FinanceAccountTransfer {
            $draft = FinanceAccountTransfer::query()->lockForUpdate()->findOrFail($id);
            if ($draft->status !== 'draft') throw ValidationException::withMessages(['status' => '仅草稿换汇单允许确认入账，已确认单不得重复确认。']);
            $data = $this->normalizeBusinessDate($draft->only(['source_account_id', 'target_account_id', 'source_amount', 'target_amount', 'fee_amount', 'fee_currency', 'fee_bearer', 'fee_account_id', 'business_date', 'remark']));
            $fresh = $this->calculate($data, true);
            if (! $previewToken || ! hash_equals($fresh['preview_token'], $previewToken)) {
                throw ValidationException::withMessages(['preview_token' => '资金账户数据已发生变化，本次换汇的账面成本或预计汇兑损益已更新，请重新确认。']);
            }
            return $this->settle($data, $operatorId, $draft);
        }, 5);
    }

    private function calculate(array $data, bool $forUpdate): array
    {
        $data = $this->normalizeBusinessDate($data);

        $accounts = FinanceAccount::query()->where('status', 'enabled');
        if ($forUpdate) $accounts->lockForUpdate();
        $source = $accounts->findOrFail($data['source_account_id']);
        $target = FinanceAccount::query()->where('status', 'enabled')->when($forUpdate, fn ($q) => $q->lockForUpdate())->findOrFail($data['target_account_id']);
        if ($source->id === $target->id) throw ValidationException::withMessages(['target_account_id' => '转出和转入账户不能相同。']);
        $this->assertMode($data, $source, $target);
        $out = Money::normalize($data['source_amount']); $gross = Money::normalize($data['target_amount']);
        if (Money::compare($out, '0') <= 0 || Money::compare($gross, '0') <= 0) throw ValidationException::withMessages(['amount' => '金额必须大于零。']);
        $fee = Money::normalize($data['fee_amount'] ?? '0'); $bearer = $data['fee_bearer'] ?? 'source';
        $feeAccount = $this->resolveFeeAccount($data, $source, $target, $forUpdate);
        $feeCurrency = $fee === '0.0000' ? null : strtoupper($data['fee_currency'] ?? $feeAccount->currency);
        if ($feeCurrency && $feeCurrency !== $feeAccount->currency) throw ValidationException::withMessages(['fee_currency' => '手续费币种必须与扣费账户币种一致。']);
        $exchangeSource = $bearer === 'source' ? Money::sub($out, $fee) : $out;
        if (Money::compare($exchangeSource, '0') <= 0) throw ValidationException::withMessages(['fee_amount' => '转出账户承担手续费时，手续费必须小于实际扣款金额。']);
        $same = $source->currency === $target->currency;
        if ($same && Money::compare($exchangeSource, $gross) !== 0) throw ValidationException::withMessages(['target_amount' => '同币种转账的到账金额必须等于扣除转出方手续费后的实际转账金额。']);
        $net = $bearer === 'target' ? Money::sub($gross, $fee) : $gross;
        if (Money::compare($net, '0') <= 0) throw ValidationException::withMessages(['fee_amount' => '手续费不能大于等于换汇毛所得。']);
        $carrying = $this->ledger->carryingBalance($source->id, $forUpdate);
        $targetCarrying = $this->ledger->carryingBalance($target->id, $forUpdate);
        if ($bearer === 'source' && Money::compare($fee, '0') > 0) $this->ledger->outgoingCarryingAmount($source->id, $out, $forUpdate);
        if ($bearer === 'third_account' && Money::compare($fee, '0') > 0) $this->ledger->outgoingCarryingAmount($feeAccount->id, $fee, $forUpdate);
        $sourceBase = $this->ledger->outgoingCarryingAmount($source->id, $exchangeSource, $forUpdate);
        $targetRate = $same ? null : $this->rates->businessSnapshot($target->currency, $data['business_date']);
        $grossBase = $same ? $sourceBase : $this->rates->convert($gross, $targetRate['rate']);
        $reference = $same ? ['rate' => '1.0000000000', 'exchange_rate_id' => null] : $this->rates->businessSnapshot($source->currency, $data['business_date']);
        // Reference expected amount is always expressed in the target account's
        // original currency.  A base-currency result would be wrong for e.g.
        // USD -> EUR, even though the company ledger itself is in CNY.
        $referenceBase = $same ? $sourceBase : $this->rates->convert($exchangeSource, $reference['rate']);
        $referenceExpected = $same ? $exchangeSource : Money::normalize(bcdiv($referenceBase, $targetRate['rate'], 4));
        $actualRate = $same ? '1.0000000000' : bcdiv($gross, $exchangeSource, 10);
        $realized = $same ? '0.0000' : Money::sub($grossBase, $sourceBase);
        $preview = ['source_account_id'=>(int) $source->id, 'target_account_id'=>(int) $target->id, 'source_currency'=>$source->currency, 'target_currency'=>$target->currency, 'business_date'=>$data['business_date'], 'source_amount'=>$out, 'exchange_source_amount'=>$exchangeSource, 'gross_target_amount'=>$gross, 'gross_target_base_amount'=>$grossBase, 'fee_amount'=>$fee, 'fee_currency'=>$feeCurrency, 'fee_bearer'=>$bearer, 'fee_account_id'=>(int) $feeAccount->id, 'source_original_balance'=>$carrying['original_balance'], 'source_carrying_base_amount'=>$carrying['base_balance'], 'target_original_balance'=>$targetCarrying['original_balance'], 'target_carrying_base_amount'=>$targetCarrying['base_balance'], 'source_weighted_carrying_rate'=>Money::compare($carrying['original_balance'], '0') === 0 ? null : bcdiv($carrying['base_balance'], $carrying['original_balance'], 10), 'source_base_amount'=>$sourceBase, 'reference_exchange_rate'=>$reference['rate'], 'reference_exchange_rate_id'=>$reference['exchange_rate_id'], 'reference_expected_amount'=>$referenceExpected, 'actual_exchange_rate'=>$actualRate, 'reference_difference_amount'=>$same ? '0.0000' : Money::sub($gross, $referenceExpected), 'net_target_amount'=>$net, 'realized_fx_gain_loss'=>$realized, 'same_currency'=>$same];
        $preview['preview_token'] = hash_hmac('sha256', json_encode($preview, JSON_THROW_ON_ERROR), (string) config('app.key'));
        return $preview;
    }

    private function settle(array $data, ?int $operatorId, ?FinanceAccountTransfer $draft = null): FinanceAccountTransfer
    {
        $data = $this->normalizeBusinessDate($data);

        return DB::transaction(function () use ($data, $operatorId, $draft): FinanceAccountTransfer {
            $source = FinanceAccount::query()->where('status', 'enabled')->lockForUpdate()->findOrFail($data['source_account_id']);
            $target = FinanceAccount::query()->where('status', 'enabled')->lockForUpdate()->findOrFail($data['target_account_id']);
            if ($source->id === $target->id) throw ValidationException::withMessages(['target_account_id' => '转出和转入账户不能相同。']);
            $this->assertMode($data, $source, $target);
            $out = Money::normalize($data['source_amount']); $gross = Money::normalize($data['target_amount']);
            if (Money::compare($out, '0') <= 0 || Money::compare($gross, '0') <= 0) throw ValidationException::withMessages(['amount' => '金额必须大于零。']);
            $fee = Money::normalize($data['fee_amount'] ?? '0'); $bearer = $data['fee_bearer'] ?? 'source';
            if (!in_array($bearer, ['source', 'target', 'third_account'], true)) throw ValidationException::withMessages(['fee_bearer' => '手续费承担方式无效。']);
            $feeAccount = $this->resolveFeeAccount($data, $source, $target, true);
            $feeCurrency = $fee === '0.0000' ? null : strtoupper($data['fee_currency'] ?? $feeAccount->currency);
            if ($feeCurrency && $feeCurrency !== $feeAccount->currency) throw ValidationException::withMessages(['fee_currency' => '手续费币种必须与扣费账户币种一致。']);
            $exchangeSource = $bearer === 'source' ? Money::sub($out, $fee) : $out;
            if (Money::compare($exchangeSource, '0') <= 0) throw ValidationException::withMessages(['fee_amount' => '转出账户承担手续费时，手续费必须小于实际扣款金额。']);
            $same = $source->currency === $target->currency;
            if ($same && Money::compare($exchangeSource, $gross) !== 0) throw ValidationException::withMessages(['target_amount' => '同币种转账的到账金额必须等于扣除转出方手续费后的实际转账金额。']);
            $net = $bearer === 'target' ? Money::sub($gross, $fee) : $gross;
            if (Money::compare($net, '0') <= 0) throw ValidationException::withMessages(['fee_amount' => '手续费不能大于等于换汇毛所得。']);
            // The ledger relieves the historical carrying amount by the amount
            // actually exchanged, not by a source-borne platform fee.
            if ($bearer === 'source' && Money::compare($fee, '0') > 0) $this->ledger->outgoingCarryingAmount($source->id, $out, true);
            if ($bearer === 'third_account' && Money::compare($fee, '0') > 0) $this->ledger->outgoingCarryingAmount($feeAccount->id, $fee, true);
            $sourceBase = $this->ledger->outgoingCarryingAmount($source->id, $exchangeSource, true);
            $targetRate = $same ? null : $this->rates->businessSnapshot($target->currency, $data['business_date']);
            $grossBase = $same ? $sourceBase : $this->rates->convert($gross, $targetRate['rate']);
            // A target-borne fee is withheld from the gross incoming amount;
            // it must not require an opening balance in the target account.
            $feeBase = ! $feeCurrency ? '0.0000' : ($bearer === 'target'
                ? ($same ? Money::normalize(bcdiv(bcmul($grossBase, $fee, 8), $gross, 4)) : $this->rates->convert($fee, $targetRate['rate']))
                : $this->ledger->outgoingCarryingAmount($feeAccount->id, $fee));
            $actualRate = $same ? '1.0000000000' : bcdiv($gross, $exchangeSource, 10);
            $reference = $same ? ['rate' => '1.0000000000'] : $this->rates->businessSnapshot($source->currency, $data['business_date']);
            $referenceBase = $same ? $sourceBase : $this->rates->convert($exchangeSource, $reference['rate']);
            $referenceExpected = $same ? $exchangeSource : Money::normalize(bcdiv($referenceBase, $targetRate['rate'], 4));
            $realized = $same ? '0.0000' : Money::sub($grossBase, $sourceBase);
            $payload = ['source_account_id'=>$source->id,'target_account_id'=>$target->id,'source_currency'=>$source->currency,'source_amount'=>$out,'exchange_source_amount'=>$exchangeSource,'target_currency'=>$target->currency,'target_amount'=>$gross,'gross_target_amount'=>$gross,'net_target_amount'=>$net,'base_currency'=>$this->rates->baseCurrency()->currency_code,'actual_exchange_rate'=>$actualRate,'reference_exchange_rate'=>$reference['rate'],'reference_difference_amount'=>$same?'0.0000':Money::sub($gross,$referenceExpected),'source_base_amount'=>$sourceBase,'target_base_amount'=>$grossBase,'realized_fx_gain_loss'=>$realized,'fee_amount'=>$fee,'fee_currency'=>$feeCurrency,'fee_bearer'=>$bearer,'fee_account_id'=>$feeCurrency?$feeAccount->id:null,'fee_base_amount'=>$feeBase,'business_date'=>$data['business_date'],'status'=>'confirmed','remark'=>$data['remark']??null,'confirmed_by'=>$operatorId,'confirmed_at'=>now()];
            $transfer = $draft ? tap($draft)->update($payload)->fresh() : FinanceAccountTransfer::create(['transfer_no'=>$this->numbers->next('finance_transfer','FT'), ...$payload, 'created_by'=>$operatorId]);
            foreach ([[$source,'out',$exchangeSource,$sourceBase],[$target,'in',$gross,$grossBase]] as [$account,$direction,$amount,$base]) $this->ledger->append(['finance_account_id'=>$account->id,'movement_type'=>$direction==='in'?'transfer_in':'transfer_out','source_type'=>'account_transfer','source_id'=>$transfer->id,'direction'=>$direction,'currency'=>$account->currency,'original_amount'=>$amount,'base_currency'=>$transfer->base_currency,'base_amount'=>$base,'business_date'=>$data['business_date'],'status'=>'confirmed','created_by'=>$operatorId]);
            if ($feeCurrency) { $feeFact=FinancePlatformFee::create(['fee_no'=>$this->numbers->next('finance_platform_fee','FF'),'finance_account_id'=>$feeAccount->id,'transfer_id'=>$transfer->id,'currency'=>$feeCurrency,'amount'=>$fee,'base_currency'=>$transfer->base_currency,'exchange_rate'=>bcdiv($feeBase, $fee, 10),'base_amount'=>$feeBase,'fee_type'=>$data['fee_type']??'platform','business_date'=>$data['business_date'],'status'=>'confirmed','created_by'=>$operatorId]); $this->ledger->append(['finance_account_id'=>$feeAccount->id,'movement_type'=>'platform_fee','source_type'=>'platform_fee','source_id'=>$feeFact->id,'direction'=>'out','currency'=>$feeCurrency,'original_amount'=>$fee,'base_currency'=>$transfer->base_currency,'base_amount'=>$feeBase,'business_date'=>$data['business_date'],'status'=>'confirmed','created_by'=>$operatorId]); }
            FinanceOperationLog::create(['document_type'=>'account_transfer','document_id'=>$transfer->id,'action'=>$same?'confirm_transfer':'confirm_fx_settlement','to_status'=>'confirmed','fact_snapshot'=>['exchange_source_amount'=>$exchangeSource,'gross_target_amount'=>$gross,'net_target_amount'=>$net,'realized_fx_gain_loss'=>$transfer->realized_fx_gain_loss,'reference_difference_amount'=>$transfer->reference_difference_amount],'operator_id'=>$operatorId,'content'=>'资金转账/换汇确认']);
            return $transfer;
        },5);
    }

    /**
     * Confirmed transfers are never re-written.  A void only marks their
     * immutable facts as voided, so account balances remain a ledger result.
     */
    public function void(int $id, string $reason, ?int $operatorId): FinanceAccountTransfer
    {
        return DB::transaction(function () use ($id, $reason, $operatorId): FinanceAccountTransfer {
            $transfer = FinanceAccountTransfer::query()->lockForUpdate()->findOrFail($id);
            if ($transfer->status !== 'confirmed') {
                throw ValidationException::withMessages(['status' => '仅已确认的资金转账/换汇单允许作废。']);
            }

            $transfer->update(['status' => 'voided', 'remark' => trim((string) $transfer->remark."\n作废原因：{$reason}")]);
            FinanceAccountMovement::query()
                ->where('source_type', 'account_transfer')
                ->where('source_id', $transfer->id)
                ->where('status', 'confirmed')
                ->update(['status' => 'voided']);
            $feeIds = FinancePlatformFee::query()->where('transfer_id', $transfer->id)->where('status', 'confirmed')->pluck('id');
            FinancePlatformFee::query()->whereIn('id', $feeIds)->update(['status' => 'voided']);
            if ($feeIds->isNotEmpty()) {
                FinanceAccountMovement::query()
                    ->where('source_type', 'platform_fee')
                    ->whereIn('source_id', $feeIds)
                    ->where('status', 'confirmed')
                    ->update(['status' => 'voided']);
            }
            FinanceOperationLog::create(['document_type' => 'account_transfer', 'document_id' => $transfer->id, 'action' => 'void', 'from_status' => 'confirmed', 'to_status' => 'voided', 'operator_id' => $operatorId, 'content' => "资金转账/换汇作废：{$reason}"]);

            return $transfer->fresh();
        }, 5);
    }

    /** Keep draft, preview and confirmation on the same account/currency boundary. */
    private function assertMode(array $data, FinanceAccount $source, FinanceAccount $target): void
    {
        $sameCurrency = $source->currency === $target->currency;
        $mode = $data['mode'] ?? ($sameCurrency ? 'transfer' : 'exchange');
        if ($mode === 'transfer' && ! $sameCurrency) {
            throw ValidationException::withMessages(['mode' => '不同币种账户必须使用跨币种换汇。']);
        }
        if ($mode === 'exchange' && $sameCurrency) {
            throw ValidationException::withMessages(['mode' => '相同币种账户必须使用同币种转账。']);
        }
    }

    private function resolveFeeAccount(array $data, FinanceAccount $source, FinanceAccount $target, bool $forUpdate): FinanceAccount
    {
        $bearer = $data['fee_bearer'] ?? 'source';
        if (!in_array($bearer, ['source', 'target', 'third_account'], true)) {
            throw ValidationException::withMessages(['fee_bearer' => '手续费承担方式无效。']);
        }
        if ($bearer === 'source') return $source;
        if ($bearer === 'target') return $target;

        if (Money::compare(Money::normalize($data['fee_amount'] ?? '0'), '0') === 0) return $source;
        if (empty($data['fee_account_id'])) {
            throw ValidationException::withMessages(['fee_account_id' => '第三方账户承担手续费时必须选择扣费账户。']);
        }

        return FinanceAccount::query()->where('status', 'enabled')
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())
            ->findOrFail($data['fee_account_id']);
    }

    private function draftPayload(array $data, array $facts, FinanceAccount $source, FinanceAccount $target, FinanceAccount $feeAccount, ?int $operatorId = null): array
    {
        $payload = [
            'source_account_id' => $source->id,
            'target_account_id' => $target->id,
            'source_currency' => $source->currency,
            'source_amount' => $facts['source_amount'],
            'exchange_source_amount' => $facts['exchange_source_amount'],
            'target_currency' => $target->currency,
            // target_amount is the user's actual incoming gross amount on every state.
            'target_amount' => $facts['gross_target_amount'],
            'gross_target_amount' => $facts['gross_target_amount'],
            'net_target_amount' => $facts['net_target_amount'],
            'base_currency' => $this->rates->baseCurrency()->currency_code,
            'actual_exchange_rate' => $facts['actual_exchange_rate'],
            'reference_exchange_rate' => $facts['reference_exchange_rate'],
            'reference_difference_amount' => $facts['reference_difference_amount'],
            'source_base_amount' => $facts['source_base_amount'],
            'target_base_amount' => $facts['gross_target_base_amount'],
            'realized_fx_gain_loss' => $facts['realized_fx_gain_loss'],
            'fee_amount' => $facts['fee_amount'],
            'fee_currency' => $facts['fee_currency'],
            'fee_bearer' => $facts['fee_bearer'],
            'fee_account_id' => $facts['fee_currency'] ? $feeAccount->id : null,
            'business_date' => $facts['business_date'],
            'status' => 'draft',
            'remark' => $data['remark'] ?? null,
        ];
        if ($operatorId !== null) {
            $payload['transfer_no'] = $this->numbers->next('finance_transfer', 'FT');
            $payload['created_by'] = $operatorId;
        }

        return $payload;
    }

    private function normalizeBusinessDate(array $data): array
    {
        if (($data['business_date'] ?? null) instanceof \DateTimeInterface) {
            $data['business_date'] = $data['business_date']->format('Y-m-d');
        } elseif (! empty($data['business_date']) && str_contains((string) $data['business_date'], 'T')) {
            $data['business_date'] = now()->parse((string) $data['business_date'])->setTimezone(config('app.timezone'))->toDateString();
        }

        return $data;
    }
}
