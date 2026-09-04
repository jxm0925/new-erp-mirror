<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\FinanceAccount;
use App\Models\Erp\FinanceAccountMovement;
use App\Models\Erp\FinanceCashDocument;
use App\Models\Erp\FinanceExchangeRate;
use App\Models\Erp\FinancePlatformFee;
use App\Services\Erp\FinanceAccountLedgerService;
use App\Services\Erp\FinanceAccountTransferApplicationService;
use App\Services\Erp\FinanceCashDocumentApplicationService;
use App\Services\Erp\FinanceExchangeRateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinanceAccountTransferTest extends TestCase
{
    use DatabaseTransactions;

    public function test_same_currency_transfer_has_no_realized_fx_and_cross_currency_uses_actual_result(): void
    {
        FinanceExchangeRate::create([
            'source_currency' => 'USD', 'target_currency' => 'CNY', 'rate' => '7.2000000000',
            'rate_type' => FinanceExchangeRateService::TYPE_BUSINESS, 'source' => 'manual',
            'effective_at' => '2026-08-17 00:00:00', 'status' => 'enabled',
        ]);
        FinanceExchangeRate::create([
            'source_currency' => 'USD', 'target_currency' => 'CNY', 'rate' => '7.5000000000',
            'rate_type' => FinanceExchangeRateService::TYPE_VALUATION, 'source' => 'external',
            'effective_at' => '2026-08-17 00:00:00', 'status' => 'enabled',
        ]);
        $paypal = FinanceAccount::create(['account_no' => 'PAYPAL-USD-'.uniqid(), 'account_name' => 'PayPal USD', 'account_type' => 'platform', 'currency' => 'USD', 'status' => 'enabled']);
        $hk = FinanceAccount::create(['account_no' => 'HK-USD-'.uniqid(), 'account_name' => '香港银行 USD', 'account_type' => 'bank', 'currency' => 'USD', 'status' => 'enabled']);
        $cn = FinanceAccount::create(['account_no' => 'CN-CNY-'.uniqid(), 'account_name' => '国内银行 CNY', 'account_type' => 'bank', 'currency' => 'CNY', 'status' => 'enabled']);
        FinanceAccountMovement::create([
            'finance_account_id' => $paypal->id, 'movement_type' => 'cash_document', 'source_type' => 'seed', 'source_id' => $paypal->id,
            'direction' => 'in', 'currency' => 'USD', 'original_amount' => '21.0000', 'base_currency' => 'CNY', 'base_amount' => '151.2000',
            'business_date' => '2026-08-17', 'status' => 'confirmed',
        ]);
        $service = app(FinanceAccountTransferApplicationService::class);
        $same = $service->create([
            'source_account_id' => $paypal->id, 'target_account_id' => $hk->id, 'source_amount' => '10', 'target_amount' => '10', 'business_date' => '2026-08-17',
        ], 1);
        $this->assertSame('0.0000', (string) $same->realized_fx_gain_loss);

        $fx = $service->create([
            'source_account_id' => $hk->id, 'target_account_id' => $cn->id, 'source_amount' => '10', 'target_amount' => '73', 'business_date' => '2026-08-17',
        ], 1);
        $this->assertSame('7.3000000000', (string) $fx->actual_exchange_rate);
        $this->assertSame('1.0000', (string) $fx->realized_fx_gain_loss); // actual CNY 73 - USD carrying CNY 72
        $this->assertSame('11.0000', app(FinanceAccountLedgerService::class)->carryingBalance($paypal->id)['original_balance']);
        $this->assertSame('73.0000', app(FinanceAccountLedgerService::class)->carryingBalance($cn->id)['original_balance']);
        $valuation = app(FinanceAccountLedgerService::class)->valuation($paypal->id, 'USD', '2026-08-17', app(FinanceExchangeRateService::class));
        $this->assertSame('3.3000', $valuation['unrealized_fx_gain_loss']); // USD 11 at 7.5 versus carried 79.2
    }

    public function test_actual_fx_uses_weighted_carrying_amount_and_target_fee_is_an_independent_fact(): void
    {
        FinanceExchangeRate::create(['source_currency' => 'USD', 'target_currency' => 'CNY', 'rate' => '7.3000000000', 'rate_type' => 'business', 'source' => 'bank', 'effective_at' => '2026-08-17 00:00:00', 'status' => 'enabled']);
        FinanceExchangeRate::create(['source_currency' => 'USD', 'target_currency' => 'CNY', 'rate' => '7.3500000000', 'rate_type' => 'valuation', 'source' => 'external', 'effective_at' => '2026-08-17 00:00:00', 'status' => 'enabled']);
        $usd = FinanceAccount::create(['account_no' => 'USD-WA-'.uniqid(), 'account_name' => 'USD weighted', 'account_type' => 'platform', 'currency' => 'USD', 'status' => 'enabled']);
        $cny = FinanceAccount::create(['account_no' => 'CNY-WA-'.uniqid(), 'account_name' => 'CNY target', 'account_type' => 'bank', 'currency' => 'CNY', 'status' => 'enabled']);
        FinanceAccountMovement::create(['finance_account_id' => $usd->id, 'movement_type' => 'opening', 'source_type' => 'seed_weighted', 'source_id' => $usd->id, 'direction' => 'in', 'currency' => 'USD', 'original_amount' => '1000.0000', 'base_currency' => 'CNY', 'base_amount' => '7300.0000', 'business_date' => '2026-08-17', 'status' => 'confirmed']);

        $transfer = app(FinanceAccountTransferApplicationService::class)->create(['source_account_id' => $usd->id, 'target_account_id' => $cny->id, 'source_amount' => '20.0000', 'target_amount' => '145.7300', 'fee_amount' => '0.6000', 'fee_currency' => 'CNY', 'fee_bearer' => 'target', 'business_date' => '2026-08-17'], 1);

        $this->assertSame('20.0000', (string) $transfer->exchange_source_amount);
        $this->assertSame('146.0000', (string) $transfer->source_base_amount);
        $this->assertSame('7.2865000000', (string) $transfer->actual_exchange_rate);
        $this->assertSame('7.3000000000', (string) $transfer->reference_exchange_rate);
        $this->assertSame('-0.2700', (string) $transfer->reference_difference_amount);
        $this->assertSame('-0.2700', (string) $transfer->realized_fx_gain_loss);
        $this->assertSame('145.7300', (string) $transfer->gross_target_amount);
        $this->assertSame('145.1300', (string) $transfer->net_target_amount);
        $this->assertSame('145.1300', app(FinanceAccountLedgerService::class)->carryingBalance($cny->id)['original_balance']);
    }

    public function test_source_borne_fee_reduces_same_currency_arrival_without_creating_fx_gain(): void
    {
        FinanceExchangeRate::create(['source_currency' => 'USD', 'target_currency' => 'CNY', 'rate' => '7.3000000000', 'rate_type' => 'business', 'source' => 'bank', 'effective_at' => '2026-08-17 00:00:00', 'status' => 'enabled']);
        $paypal = FinanceAccount::create(['account_no' => 'PP-FEE-'.uniqid(), 'account_name' => 'PayPal source', 'account_type' => 'platform', 'currency' => 'USD', 'status' => 'enabled']);
        $hk = FinanceAccount::create(['account_no' => 'HK-FEE-'.uniqid(), 'account_name' => 'HK target', 'account_type' => 'bank', 'currency' => 'USD', 'status' => 'enabled']);
        FinanceAccountMovement::create(['finance_account_id' => $paypal->id, 'movement_type' => 'opening', 'source_type' => 'seed_fee', 'source_id' => $paypal->id, 'direction' => 'in', 'currency' => 'USD', 'original_amount' => '20.0000', 'base_currency' => 'CNY', 'base_amount' => '146.0000', 'business_date' => '2026-08-17', 'status' => 'confirmed']);

        $transfer = app(FinanceAccountTransferApplicationService::class)->create(['source_account_id' => $paypal->id, 'target_account_id' => $hk->id, 'source_amount' => '20.0000', 'target_amount' => '19.0000', 'fee_amount' => '1.0000', 'fee_currency' => 'USD', 'fee_bearer' => 'source', 'business_date' => '2026-08-17'], 1);

        $this->assertSame('19.0000', (string) $transfer->exchange_source_amount);
        $this->assertSame('0.0000', (string) $transfer->realized_fx_gain_loss);
        $this->assertSame('0.0000', app(FinanceAccountLedgerService::class)->carryingBalance($paypal->id)['original_balance']);
        $this->assertSame('19.0000', app(FinanceAccountLedgerService::class)->carryingBalance($hk->id)['original_balance']);
    }

    public function test_same_currency_draft_and_confirmation_do_not_need_a_business_rate_and_keep_gross_and_net_separate(): void
    {
        $source = FinanceAccount::create(['account_no' => 'SAME-DRAFT-SRC-'.uniqid(), 'account_name' => 'Same draft source', 'account_type' => 'platform', 'currency' => 'USD', 'status' => 'enabled']);
        $target = FinanceAccount::create(['account_no' => 'SAME-DRAFT-TGT-'.uniqid(), 'account_name' => 'Same draft target', 'account_type' => 'bank', 'currency' => 'USD', 'status' => 'enabled']);
        FinanceAccountMovement::create(['finance_account_id' => $source->id, 'movement_type' => 'opening', 'source_type' => 'same_draft_seed', 'source_id' => $source->id, 'direction' => 'in', 'currency' => 'USD', 'original_amount' => '20.0000', 'base_currency' => 'CNY', 'base_amount' => '146.0000', 'business_date' => '2026-08-17', 'status' => 'confirmed']);

        $service = app(FinanceAccountTransferApplicationService::class);
        $data = ['source_account_id' => $source->id, 'target_account_id' => $target->id, 'source_amount' => '20.0000', 'target_amount' => '20.0000', 'fee_amount' => '1.0000', 'fee_currency' => 'USD', 'fee_bearer' => 'target', 'mode' => 'transfer', 'business_date' => '2026-08-17'];
        $preview = $service->preview($data);
        $draft = $service->createDraft($data, 1);

        $this->assertSame('20.0000', (string) $draft->target_amount);
        $this->assertSame('20.0000', (string) $draft->gross_target_amount);
        $this->assertSame('19.0000', (string) $draft->net_target_amount);
        $confirmed = $service->confirm($draft->id, 1, $preview['preview_token']);
        $this->assertSame('20.0000', (string) $confirmed->target_amount);
        $this->assertSame('20.0000', (string) $confirmed->gross_target_amount);
        $this->assertSame('19.0000', (string) $confirmed->net_target_amount);
        $this->assertSame('0.0000', (string) $confirmed->realized_fx_gain_loss);
        $this->assertSame('19.0000', app(FinanceAccountLedgerService::class)->carryingBalance($target->id)['original_balance']);
    }

    public function test_cross_currency_reference_expected_and_difference_use_the_target_currency_not_the_base_currency(): void
    {
        FinanceExchangeRate::create(['source_currency' => 'USD', 'target_currency' => 'CNY', 'rate' => '7.2000000000', 'rate_type' => FinanceExchangeRateService::TYPE_BUSINESS, 'source' => 'bank', 'effective_at' => '2026-08-17 00:00:00', 'status' => 'enabled']);
        FinanceExchangeRate::create(['source_currency' => 'EUR', 'target_currency' => 'CNY', 'rate' => '8.0000000000', 'rate_type' => FinanceExchangeRateService::TYPE_BUSINESS, 'source' => 'bank', 'effective_at' => '2026-08-17 00:00:00', 'status' => 'enabled']);
        $usd = FinanceAccount::create(['account_no' => 'REF-USD-'.uniqid(), 'account_name' => 'Reference USD', 'account_type' => 'bank', 'currency' => 'USD', 'status' => 'enabled']);
        $eur = FinanceAccount::create(['account_no' => 'REF-EUR-'.uniqid(), 'account_name' => 'Reference EUR', 'account_type' => 'bank', 'currency' => 'EUR', 'status' => 'enabled']);
        FinanceAccountMovement::create(['finance_account_id' => $usd->id, 'movement_type' => 'opening', 'source_type' => 'reference_cross_seed', 'source_id' => $usd->id, 'direction' => 'in', 'currency' => 'USD', 'original_amount' => '20.0000', 'base_currency' => 'CNY', 'base_amount' => '144.0000', 'business_date' => '2026-08-17', 'status' => 'confirmed']);

        $preview = app(FinanceAccountTransferApplicationService::class)->preview(['source_account_id' => $usd->id, 'target_account_id' => $eur->id, 'source_amount' => '10.0000', 'target_amount' => '8.5000', 'mode' => 'exchange', 'business_date' => '2026-08-17']);

        $this->assertSame('9.0000', $preview['reference_expected_amount']);
        $this->assertSame('-0.5000', $preview['reference_difference_amount']);
        $this->assertSame('EUR', $preview['target_currency']);
    }

    public function test_foreign_currency_receipt_keeps_gross_receipt_and_records_platform_fee_separately(): void
    {
        $rate = FinanceExchangeRate::create(['source_currency' => 'USD', 'target_currency' => 'CNY', 'rate' => '7.3000000000', 'rate_type' => 'business', 'source' => 'platform', 'effective_at' => '2026-08-17 00:00:00', 'status' => 'enabled']);
        $account = FinanceAccount::create(['account_no' => 'RCPT-USD-'.uniqid(), 'account_name' => 'Platform USD', 'account_type' => 'platform', 'currency' => 'USD', 'status' => 'enabled']);
        $document = FinanceCashDocument::create(['direction' => 'receipt', 'document_no' => 'R-FEE-'.uniqid(), 'party_type' => 'customer', 'party_id' => 1, 'party_name_snapshot' => 'Receipt customer', 'business_date' => '2026-08-17', 'finance_account_id' => $account->id, 'currency' => 'USD', 'base_currency' => 'CNY', 'exchange_rate_id' => $rate->id, 'business_exchange_rate' => '7.3000000000', 'exchange_rate_date' => '2026-08-17', 'exchange_rate_source' => 'platform', 'amount' => '20.0000', 'base_amount' => '146.0000', 'platform_fee_amount' => '1.0000', 'platform_fee_currency' => 'USD', 'platform_fee_account_id' => $account->id, 'platform_fee_type' => 'platform', 'payment_method' => 'platform', 'status' => 'draft']);

        app(FinanceCashDocumentApplicationService::class)->confirm($document->id, 1, 'tester');

        $this->assertSame('19.0000', app(FinanceAccountLedgerService::class)->carryingBalance($account->id)['original_balance']);
        $fee = FinancePlatformFee::query()->where('cash_document_id', $document->id)->firstOrFail();
        $this->assertSame('1.0000', (string) $fee->amount);
        $this->assertSame('7.3000', (string) $fee->base_amount);
        $this->assertSame('confirmed', $fee->status);
    }

    public function test_fx_preview_uses_real_weighted_ledger_without_writing_any_financial_fact(): void
    {
        FinanceExchangeRate::create(['source_currency' => 'USD', 'target_currency' => 'CNY', 'rate' => '7.3000000000', 'rate_type' => 'business', 'source' => 'bank', 'effective_at' => '2026-08-17 00:00:00', 'status' => 'enabled']);
        $usd = FinanceAccount::create(['account_no' => 'PREVIEW-USD-'.uniqid(), 'account_name' => 'Preview USD', 'account_type' => 'platform', 'currency' => 'USD', 'status' => 'enabled']);
        $cny = FinanceAccount::create(['account_no' => 'PREVIEW-CNY-'.uniqid(), 'account_name' => 'Preview CNY', 'account_type' => 'bank', 'currency' => 'CNY', 'status' => 'enabled']);
        FinanceAccountMovement::create(['finance_account_id' => $usd->id, 'movement_type' => 'opening', 'source_type' => 'preview_seed', 'source_id' => $usd->id, 'direction' => 'in', 'currency' => 'USD', 'original_amount' => '60.0000', 'base_currency' => 'CNY', 'base_amount' => '438.0000', 'business_date' => '2026-08-17', 'status' => 'confirmed']);
        $movementsBefore = FinanceAccountMovement::count();
        $feesBefore = FinancePlatformFee::count();

        $preview = app(FinanceAccountTransferApplicationService::class)->preview([
            'source_account_id' => $usd->id, 'target_account_id' => $cny->id, 'source_amount' => '20.0000', 'target_amount' => '145.7300', 'fee_amount' => '0.6000', 'fee_currency' => 'CNY', 'fee_bearer' => 'target', 'business_date' => '2026-08-17',
        ]);

        $this->assertSame('60.0000', $preview['source_original_balance']);
        $this->assertSame('438.0000', $preview['source_carrying_base_amount']);
        $this->assertSame('7.3000000000', $preview['source_weighted_carrying_rate']);
        $this->assertSame('146.0000', $preview['source_base_amount']);
        $this->assertSame('7.2865000000', $preview['actual_exchange_rate']);
        $this->assertSame('-0.2700', $preview['realized_fx_gain_loss']);
        $this->assertSame('145.1300', $preview['net_target_amount']);
        $this->assertNotEmpty($preview['preview_token']);
        $this->assertSame($movementsBefore, FinanceAccountMovement::count());
        $this->assertSame($feesBefore, FinancePlatformFee::count());
    }

    public function test_stale_fx_preview_is_rejected_before_any_confirmation_fact_is_written(): void
    {
        FinanceExchangeRate::create(['source_currency' => 'USD', 'target_currency' => 'CNY', 'rate' => '7.3000000000', 'rate_type' => 'business', 'source' => 'bank', 'effective_at' => '2026-08-17 00:00:00', 'status' => 'enabled']);
        $usd = FinanceAccount::create(['account_no' => 'STALE-USD-'.uniqid(), 'account_name' => 'Stale USD', 'account_type' => 'platform', 'currency' => 'USD', 'status' => 'enabled']);
        $cny = FinanceAccount::create(['account_no' => 'STALE-CNY-'.uniqid(), 'account_name' => 'Stale CNY', 'account_type' => 'bank', 'currency' => 'CNY', 'status' => 'enabled']);
        FinanceAccountMovement::create(['finance_account_id' => $usd->id, 'movement_type' => 'opening', 'source_type' => 'stale_seed', 'source_id' => $usd->id, 'direction' => 'in', 'currency' => 'USD', 'original_amount' => '60.0000', 'base_currency' => 'CNY', 'base_amount' => '438.0000', 'business_date' => '2026-08-17', 'status' => 'confirmed']);
        $service = app(FinanceAccountTransferApplicationService::class);
        $data = ['source_account_id' => $usd->id, 'target_account_id' => $cny->id, 'source_amount' => '20.0000', 'target_amount' => '145.7300', 'fee_amount' => '0.6000', 'fee_currency' => 'CNY', 'fee_bearer' => 'target', 'business_date' => '2026-08-17'];
        $preview = $service->preview($data);
        $draft = $service->createDraft($data, 1);
        $freshDraftPreview = $service->preview($draft->only(['source_account_id', 'target_account_id', 'source_amount', 'target_amount', 'fee_amount', 'fee_currency', 'fee_bearer', 'fee_account_id', 'business_date', 'remark']));
        $this->assertSame($preview['preview_token'], $freshDraftPreview['preview_token']);
        FinanceAccountMovement::create(['finance_account_id' => $usd->id, 'movement_type' => 'cash_document', 'source_type' => 'concurrent_case', 'source_id' => $usd->id + 100000, 'direction' => 'in', 'currency' => 'USD', 'original_amount' => '1.0000', 'base_currency' => 'CNY', 'base_amount' => '7.4000', 'business_date' => '2026-08-17', 'status' => 'confirmed']);
        $movementsBeforeConfirm = FinanceAccountMovement::count();

        try {
            $service->confirm($draft->id, 1, $preview['preview_token']);
            $this->fail('陈旧预览不得确认入账。');
        } catch (ValidationException $exception) {
            $this->assertSame('资金账户数据已发生变化，本次换汇的账面成本或预计汇兑损益已更新，请重新确认。', $exception->errors()['preview_token'][0]);
        }

        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame($movementsBeforeConfirm, FinanceAccountMovement::count());
    }
}
