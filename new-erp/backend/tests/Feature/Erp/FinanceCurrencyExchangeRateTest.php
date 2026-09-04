<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\FinanceExchangeRate;
use App\Models\Erp\FinanceAccount;
use App\Models\Erp\FinanceCashDocument;
use App\Services\Erp\FinanceCurrencyApplicationService;
use App\Services\Erp\FinanceExchangeRateApplicationService;
use App\Services\Erp\FinanceExchangeRateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinanceCurrencyExchangeRateTest extends TestCase
{
    use DatabaseTransactions;

    public function test_business_and_valuation_rates_are_versioned_and_do_not_use_a_fake_default(): void
    {
        $service = app(FinanceExchangeRateService::class);
        $this->assertSame('CNY', $service->baseCurrency()->currency_code);

        try {
            $service->businessSnapshot('USD', '2026-08-17');
            $this->fail('缺少业务汇率时不得使用 1:1 或 0。');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('exchange_rate', $exception->errors());
        }

        FinanceExchangeRate::create([
            'source_currency' => 'USD', 'target_currency' => 'CNY', 'rate' => '7.2000000000',
            'rate_type' => FinanceExchangeRateService::TYPE_BUSINESS, 'source' => 'manual',
            'effective_at' => '2026-08-17 09:00:00', 'status' => 'enabled',
        ]);
        FinanceExchangeRate::create([
            'source_currency' => 'USD', 'target_currency' => 'CNY', 'rate' => '7.2500000000',
            'rate_type' => FinanceExchangeRateService::TYPE_VALUATION, 'source' => 'external',
            'effective_at' => '2026-08-17 09:00:00', 'status' => 'enabled',
        ]);

        $business = $service->businessSnapshot('USD', '2026-08-17');
        $valuation = $service->valuationSnapshot('USD', '2026-08-17');
        $this->assertSame('7.2000000000', $business['rate']);
        $this->assertSame('7.2500000000', $valuation['rate']);
        $this->assertSame('144.0000', $service->convert('20.0000', $business['rate']));
        $this->assertSame('145.0000', $service->convert('20.0000', $valuation['rate']));
    }

    public function test_referenced_currency_and_rate_cannot_be_disabled(): void
    {
        $usd = \App\Models\Erp\FinanceCurrency::query()->where('currency_code', 'USD')->firstOrFail();
        FinanceAccount::create(['account_no' => 'CUR-LOCK-'.uniqid(), 'account_name' => 'USD 账户', 'account_type' => 'bank', 'currency' => 'USD', 'status' => 'enabled']);
        try {
            app(FinanceCurrencyApplicationService::class)->update($usd->id, ['status' => 'disabled'], 1);
            $this->fail('被账户或业务事实引用的币种不可停用。');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $rate = FinanceExchangeRate::create([
            'source_currency' => 'USD', 'target_currency' => 'CNY', 'rate' => '7.2000000000', 'rate_type' => 'business',
            'source' => 'manual', 'effective_at' => '2026-08-17 00:00:00', 'status' => 'enabled',
        ]);
        FinanceCashDocument::create([
            'direction' => 'receipt', 'document_no' => 'RATE-LOCK-'.uniqid(), 'party_type' => 'customer', 'party_id' => 1,
            'party_name_snapshot' => '汇率锁定测试', 'business_date' => '2026-08-17', 'finance_account_id' => FinanceAccount::first()->id,
            'currency' => 'CNY', 'base_currency' => 'CNY', 'exchange_rate_id' => $rate->id, 'amount' => '1.0000', 'base_amount' => '1.0000',
            'payment_method' => 'bank_transfer', 'status' => 'confirmed',
        ]);
        try {
            app(FinanceExchangeRateApplicationService::class)->disable($rate->id, 1);
            $this->fail('被资金事实使用的汇率不可停用或覆盖。');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }
    }
}
