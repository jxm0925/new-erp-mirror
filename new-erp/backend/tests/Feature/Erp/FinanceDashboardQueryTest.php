<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\{FinanceAccount, FinanceAccountMovement, FinanceAllocation, FinanceCashDocument, FinanceCurrency, Item, PurchaseReceipt, PurchaseReceiptItem, PurchaseReturn, PurchaseSettlementSource, Supplier, Unit};
use App\Services\Erp\{AuthContextService, FinanceDashboardQueryService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinanceDashboardQueryTest extends TestCase
{
    use DatabaseTransactions;

    private array $permissions = ['finance.view', 'finance.payable.view', 'finance.supplier-ledger.view'];
    private string $currency;
    private FinanceAccount $account;
    private int $movementSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        do { $this->currency = 'Q'.chr(random_int(65, 90)).chr(random_int(65, 90)); }
        while (FinanceCurrency::where('currency_code', $this->currency)->exists());
        FinanceCurrency::create(['currency_code' => $this->currency, 'currency_name' => '统计测试币种', 'status' => 'enabled', 'is_base' => false]);
        $this->account = $this->account();
        $this->mock(AuthContextService::class, function ($mock): void {
            $mock->shouldReceive('currentUser')->andReturn((object) ['legacy_id' => 1]);
            $mock->shouldReceive('isSuperAdmin')->andReturn(false);
            $mock->shouldReceive('permissionCodes')->andReturnUsing(fn () => $this->permissions);
        });
    }

    public function test_cash_charts_use_confirmed_business_dates_fill_gaps_and_compare_equal_periods(): void
    {
        $supplier = $this->supplier();
        $this->cash($supplier, '100', ['direction' => 'receipt', 'party_type' => 'customer', 'business_date' => '2026-10-01']);
        $this->cash($supplier, '50', ['business_date' => '2026-10-03']);
        $this->cash($supplier, '20', ['direction' => 'receipt', 'business_date' => '2026-10-04']);
        $this->cash($supplier, '10', ['party_type' => 'customer', 'business_date' => '2026-10-04']);
        $this->cash($supplier, '3', ['direction' => 'receipt', 'party_type' => 'other', 'business_date' => '2026-10-03']);
        $this->cash($supplier, '4', ['party_type' => 'other', 'business_date' => '2026-10-03']);
        $this->cash($supplier, '100', ['direction' => 'receipt', 'business_date' => '2026-09-27']);
        $this->cash($supplier, '20', ['business_date' => '2026-09-30']);
        foreach (['draft', 'voided'] as $status) $this->cash($supplier, '900', ['status' => $status]);
        $this->cash($supplier, '800', ['currency' => 'CNY']);
        $this->cash($supplier, '700', ['business_date' => '2026-10-05']);

        $data = $this->dashboard();
        $this->assertSame(['receipt_amount' => '123.0000', 'payment_amount' => '64.0000', 'net_amount' => '59.0000', 'receipt_count' => 3, 'payment_count' => 3], $data['cash']['summary']);
        $this->assertSame('100.0000', $data['cash']['previous_summary']['receipt_amount']);
        $this->assertSame('20.0000', $data['cash']['previous_summary']['payment_amount']);
        $this->assertSame(['start' => '2026-09-27', 'end' => '2026-09-30'], $data['comparison_period']);
        $this->assertSame(['amount' => '23.0000', 'percent' => '23.00'], $data['cash']['changes']['receipt_amount']);
        $this->assertSame(['amount' => '-21.0000', 'percent' => '-26.25'], $data['cash']['changes']['net_amount']);
        $this->assertCount(4, $data['cash']['trend']);
        $this->assertSame(['date' => '2026-10-02', 'receipt_amount' => '0.0000', 'payment_amount' => '0.0000', 'net_amount' => '0.0000'], $data['cash']['trend'][1]);
        $groups = collect($data['cash']['composition'])->keyBy('key');
        foreach (['customer_receipt' => '100.0000', 'supplier_refund' => '20.0000', 'other_receipt' => '3.0000', 'supplier_payment' => '50.0000', 'customer_refund' => '10.0000', 'other_payment' => '4.0000'] as $key => $amount) {
            $this->assertSame($amount, $groups[$key]['amount']);
            $this->assertSame(1, $groups[$key]['count']);
        }
    }

    public function test_current_account_balance_uses_all_ledger_movements_not_selected_cash_period(): void
    {
        $this->movement($this->account, 'in', '1000', ['business_date' => '2025-01-01']);
        $this->movement($this->account, 'out', '50');
        $this->movement($this->account, 'out', '80', ['movement_type' => 'transfer_out']);
        $this->movement($this->account, 'in', '10', ['movement_type' => 'cash_void']);
        $this->movement($this->account, 'out', '2', ['movement_type' => 'platform_fee']);
        $this->movement($this->account, 'in', '900', ['status' => 'draft']);
        $disabled = $this->account(['status' => 'disabled']);
        $this->movement($disabled, 'in', '80', ['movement_type' => 'transfer_in']);
        $zero = $this->account();
        $other = $this->account(['currency' => 'CNY']);
        $this->movement($other, 'in', '700', ['currency' => 'CNY']);
        // Cash without a ledger movement cannot silently become account money.
        $this->cash($this->supplier(), '10000', ['direction' => 'receipt']);
        $current = $this->dashboard()['accounts'];
        $later = $this->dashboard(['period_start' => '2027-01-01', 'period_end' => '2027-01-04'])['accounts'];
        $this->assertSame($current, $later);
        $this->assertSame('958.0000', $current['balance_amount']);
        $items = collect($current['items'])->keyBy('id');
        $this->assertSame('878.0000', $items[$this->account->id]['balance_amount']);
        $this->assertSame('80.0000', $items[$disabled->id]['balance_amount']);
        $this->assertSame('0.0000', $items[$zero->id]['balance_amount']);
        $this->assertFalse($items->has($other->id));
    }

    public function test_payables_and_net_prepayment_share_supplier_facts_and_are_not_date_truncated(): void
    {
        $supplier = $this->supplier();
        $source = $this->source($supplier, '100', ['ap_offset_amount' => '10', 'frozen_amount' => '7']);
        $payment = $this->cash($supplier, '100', ['business_date' => '2026-01-01']);
        $this->allocation($payment, $source, '40');
        $this->cash($supplier, '20', ['direction' => 'receipt']);
        $return = PurchaseReturn::create(['return_no' => $this->code('RET'), 'source_receipt_id' => $source->source_receipt_id,
            'supplier_id' => $supplier->id, 'return_scope' => 'posted_inventory', 'return_date' => '2026-10-02', 'return_status' => 'completed',
            'return_reason' => '统计回归', 'currency_snapshot' => $this->currency, 'settlement_effect_type' => 'SUPPLIER_REFUND', 'settlement_amount' => '10']);
        $refund = $this->cash($supplier, '10', ['direction' => 'receipt']);
        $this->allocation($refund, $source, '10', ['source_business_type' => 'purchase_return_supplier_refund', 'source_document_id' => $return->id, 'source_document_no' => $return->return_no]);
        $this->source($this->supplier(), '999', ['currency' => 'CNY']);
        $this->cash($supplier, '888', ['status' => 'draft']);
        $this->cash($supplier, '777', ['status' => 'voided']);
        $current = $this->dashboard();
        $later = $this->dashboard(['period_start' => '2027-01-01', 'period_end' => '2027-01-04']);
        $this->assertSame(['current_payable_amount' => '90.0000', 'unpaid_amount' => '50.0000', 'quality_frozen_amount' => '7.0000', 'paid_amount' => '40.0000'], $current['payables']['summary']);
        $this->assertSame('40.0000', $current['suppliers']['summary']['prepayment_balance_amount']);
        $this->assertSame('0.0000', $current['suppliers']['summary']['pending_refund_amount']);
        $this->assertSame($current['payables'], $later['payables']);
        $this->assertSame($current['suppliers'], $later['suppliers']);
    }

    public function test_supplier_ranking_returns_top_ten_and_complete_remaining_total(): void
    {
        $suppliers = [];
        for ($index = 1; $index <= 12; $index++) {
            $suppliers[$index] = $this->supplier();
            $this->source($suppliers[$index], (string) ($index * 10));
        }
        $data = $this->dashboard();
        $this->assertSame('780.0000', $data['payables']['summary']['unpaid_amount']);
        $this->assertSame(12, $data['suppliers']['summary']['supplier_count']);
        $this->assertCount(10, $data['suppliers']['payable_ranking']);
        $this->assertSame($suppliers[12]->id, $data['suppliers']['payable_ranking'][0]['supplier_id']);
        $this->assertSame('120.0000', $data['suppliers']['payable_ranking'][0]['unpaid_amount']);
        $this->assertSame('30.0000', $data['suppliers']['other_unpaid_amount']);
        $this->assertSame('780.0000', $data['suppliers']['total_unpaid_amount']);
    }

    public function test_endpoint_redacts_ungranted_balances_and_requires_finance_view(): void
    {
        $supplier = $this->supplier();
        $this->source($supplier, '100');
        $this->cash($supplier, '20');
        $url = '/api/v1/erp/finance/dashboard?currency='.$this->currency;
        $this->permissions = ['finance.view'];
        $response = $this->getJson($url)->assertOk()->assertJsonPath('data.payables.status', 'forbidden')
            ->assertJsonPath('data.payables.summary', null)->assertJsonPath('data.suppliers.status', 'forbidden')
            ->assertJsonPath('data.suppliers.summary', null)->assertJsonPath('data.suppliers.payable_ranking', [])
            ->assertJsonPath('data.suppliers.total_unpaid_amount', null)->assertJsonPath('data.suppliers.other_unpaid_amount', null);
        $this->assertStringNotContainsString($supplier->supplier_name, $response->getContent());
        $this->permissions[] = 'finance.payable.view';
        $this->getJson($url)->assertOk()->assertJsonPath('data.payables.summary.unpaid_amount', '100.0000')->assertJsonPath('data.suppliers.status', 'forbidden');
        $this->permissions = ['finance.view', 'finance.supplier-ledger.view'];
        $this->getJson($url)->assertOk()->assertJsonPath('data.payables.status', 'forbidden')->assertJsonPath('data.suppliers.summary.prepayment_balance_amount', '20.0000');
        $this->permissions = ['finance.payable.view', 'finance.supplier-ledger.view'];
        $this->getJson($url)->assertForbidden();
    }

    public function test_endpoint_defaults_to_base_currency_and_thirty_days_and_validates_period(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 5)->startOfDay());
        try {
            $base = FinanceCurrency::where('status', 'enabled')->where('is_base', true)->sole();
            $this->getJson('/api/v1/erp/finance/dashboard')->assertOk()->assertJsonPath('data.currency', $base->currency_code)
                ->assertJsonPath('data.timezone', config('app.timezone', 'UTC'))
                ->assertJsonPath('data.period.start', '2026-09-06')->assertJsonPath('data.period.end', '2026-10-05')->assertJsonPath('data.period.days', 30);
            $this->getJson('/api/v1/erp/finance/dashboard?period_end=2026-01-30')->assertOk()->assertJsonPath('data.period.start', '2026-01-01');
            $this->getJson('/api/v1/erp/finance/dashboard?period_start=2026-10-05&period_end=2026-10-01')->assertUnprocessable();
            $this->getJson('/api/v1/erp/finance/dashboard?period_start=2025-01-01&period_end=2026-10-01')->assertUnprocessable();
            $this->getJson('/api/v1/erp/finance/dashboard?period_start=2026-02-30')->assertUnprocessable();
            $this->cash($this->supplier(), '25');
            FinanceCurrency::where('currency_code', $this->currency)->update(['status' => 'disabled']);
            $this->getJson('/api/v1/erp/finance/dashboard?currency='.$this->currency)->assertOk()
                ->assertJsonPath('data.currency', $this->currency)->assertJsonPath('data.cash.summary.payment_amount', '25.0000');
            $this->getJson('/api/v1/erp/finance/dashboard?currency=ZZZZZZZZZZ')->assertUnprocessable();
        } finally { $this->travelBack(); }
    }

    public function test_empty_charts_are_zero_and_queries_only_read_without_locks_or_per_row_queries(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $data = $this->dashboard();
            $queries = DB::getQueryLog();
            $this->assertLessThanOrEqual(10, count($queries));
            foreach ($queries as $query) {
                $this->assertMatchesRegularExpression('/^select\s/i', ltrim($query['query']));
                $this->assertStringNotContainsString('for update', strtolower($query['query']));
            }
            $this->assertSame('0.0000', $data['cash']['summary']['net_amount']);
            $this->assertSame('0.0000', $data['accounts']['balance_amount']);
            $this->assertSame('0.0000', $data['payables']['summary']['unpaid_amount']);
            $this->assertNull($data['cash']['changes']['net_amount']['percent']);
            $this->assertSame([], $data['suppliers']['payable_ranking']);
            $this->assertSame('0.0000', $data['suppliers']['other_unpaid_amount']);
        } finally { DB::disableQueryLog(); }
    }

    private function dashboard(array $filters = []): array
    {
        return app(FinanceDashboardQueryService::class)->dashboard(['currency' => $this->currency, 'period_start' => '2026-10-01', 'period_end' => '2026-10-04', ...$filters], ['payables' => true, 'suppliers' => true]);
    }

    private function account(array $changes = []): FinanceAccount
    {
        return FinanceAccount::create(['account_no' => $this->code('ACC'), 'account_name' => '统计测试账户', 'account_type' => 'bank', 'currency' => $this->currency, 'status' => 'enabled', ...$changes]);
    }

    private function movement(FinanceAccount $account, string $direction, string $amount, array $changes = []): void
    {
        FinanceAccountMovement::create(['finance_account_id' => $account->id, 'movement_type' => 'cash_document', 'source_type' => 'dashboard_test',
            'source_id' => $account->id * 100 + ++$this->movementSequence, 'direction' => $direction, 'currency' => $this->currency,
            'original_amount' => $amount, 'base_currency' => 'CNY', 'base_amount' => $amount, 'business_date' => '2026-10-02', 'status' => 'confirmed', ...$changes]);
    }

    private function supplier(): Supplier
    {
        return Supplier::create(['supplier_code' => $this->code('SUP'), 'supplier_name' => $this->code('统计供应商'), 'supplier_type' => 'manufacturer', 'status' => 'enabled']);
    }

    private function cash(Supplier $supplier, string $amount, array $changes = []): FinanceCashDocument
    {
        return FinanceCashDocument::create(['direction' => 'payment', 'document_no' => $this->code('CASH'), 'party_type' => 'supplier', 'party_id' => $supplier->id,
            'party_name_snapshot' => $supplier->supplier_name, 'business_date' => '2026-10-02', 'finance_account_id' => $this->account->id,
            'currency' => $this->currency, 'amount' => $amount, 'payment_method' => 'bank_transfer', 'status' => 'confirmed', ...$changes]);
    }

    private function source(Supplier $supplier, string $amount, array $changes = []): PurchaseSettlementSource
    {
        $unit = Unit::create(['unit_code' => $this->code('UNIT'), 'unit_name' => '件', 'decimal_places' => 0, 'status' => 'enabled']);
        $item = Item::create(['item_code' => $this->code('ITEM'), 'item_name' => '统计物料', 'item_type' => 'consumable', 'unit_id' => $unit->id, 'is_stock_item' => false, 'status' => 'enabled']);
        $receipt = PurchaseReceipt::create(['receipt_no' => $this->code('RC'), 'supplier_id' => $supplier->id, 'receipt_date' => '2025-01-01',
            'receipt_status' => 'confirmed', 'confirm_status' => 'confirmed', 'stock_post_status' => 'not_required', 'settlement_mode' => 'normal', 'currency_snapshot' => $this->currency, 'settlement_amount' => $amount]);
        $line = PurchaseReceiptItem::create(['receipt_id' => $receipt->id, 'item_id' => $item->id, 'receipt_qty' => 1, 'unit_price' => $amount, 'receipt_cost' => $amount, 'amount_incl_tax' => $amount, 'settlement_amount' => $amount]);
        return PurchaseSettlementSource::create(['source_type' => 'purchase_receipt_qualified', 'source_document_type' => 'purchase_receipt', 'source_document_id' => $receipt->id,
            'source_document_no' => $receipt->receipt_no, 'source_receipt_id' => $receipt->id, 'source_line_id' => $line->id,
            'supplier_id' => $supplier->id, 'supplier_name_snapshot' => $supplier->supplier_name, 'currency' => $this->currency, 'business_date' => '2025-01-01',
            'original_amount' => $amount, 'eligible_amount' => $amount, 'unallocated_amount' => $amount, 'invoice_unmatched_amount' => $amount, 'status' => 'open', ...$changes]);
    }

    private function allocation(FinanceCashDocument $cash, PurchaseSettlementSource $source, string $amount, array $changes = []): void
    {
        FinanceAllocation::create(['cash_document_id' => $cash->id, 'source_business_type' => 'purchase_settlement_source', 'source_document_id' => $source->id,
            'source_document_no' => $source->source_document_no, 'party_type' => 'supplier', 'party_id' => $cash->party_id,
            'currency' => $cash->currency, 'source_amount_snapshot' => $source->original_amount, 'allocated_amount' => $amount,
            'status' => 'active', 'allocated_at' => '2026-10-03 08:00:00', 'idempotency_key' => $this->code('ALLOC'), ...$changes]);
    }

    private function code(string $prefix): string
    {
        return 'DASH-'.$prefix.'-'.Str::upper(Str::random(10));
    }
}
