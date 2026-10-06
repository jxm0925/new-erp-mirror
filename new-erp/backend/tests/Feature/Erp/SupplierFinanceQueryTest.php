<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\FinanceAccount;
use App\Models\Erp\FinanceAllocation;
use App\Models\Erp\FinanceCashDocument;
use App\Models\Erp\Item;
use App\Models\Erp\PurchaseReceipt;
use App\Models\Erp\PurchaseReceiptItem;
use App\Models\Erp\PurchaseReturn;
use App\Models\Erp\PurchaseSettlementSource;
use App\Models\Erp\Supplier;
use App\Models\Erp\Unit;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\PurchasePayableQueryService;
use App\Services\Erp\SupplierFinanceQueryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupplierFinanceQueryTest extends TestCase
{
    use DatabaseTransactions;

    private array $permissions = ['finance.supplier-ledger.view', 'finance.view'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(AuthContextService::class, function ($mock): void {
            $mock->shouldReceive('currentUser')->andReturn((object) ['legacy_id' => 1]);
            $mock->shouldReceive('isSuperAdmin')->andReturn(false);
            $mock->shouldReceive('permissionCodes')->andReturnUsing(fn () => $this->permissions);
        });
    }

    public function test_cash_only_supplier_is_included_and_drafts_and_voids_do_not_create_prepayment(): void
    {
        $supplier = $this->supplier();
        $this->cash($supplier, '125.0000');
        $this->cash($supplier, '900.0000', ['status' => 'draft']);
        $this->cash($supplier, '800.0000', ['status' => 'voided', 'voided_at' => '2026-10-04 12:00:00']);
        $result = app(PurchasePayableQueryService::class)->supplierLedgers(['supplier_id' => $supplier->id], 20);
        $this->assertSame(1, $result['total']);
        $this->assertSame(0, $result['data'][0]['source_count']);
        $this->assertSame('125.0000', $result['data'][0]['prepayment_balance_amount']);
        $this->assertSame('0.0000', $result['data'][0]['current_payable_amount']);
        $this->assertSame('125.0000', $result['data'][0]['period_payment_amount']);
        $this->assertSame(1, app(SupplierFinanceQueryService::class)->paginate(['supplier_id' => $supplier->id, 'only_prepayment' => true], 20)['total']);
    }

    public function test_supplier_name_snapshots_do_not_split_identity_and_currency_totals_never_mix(): void
    {
        $supplier = $this->supplier();
        $this->source($supplier, '100', '2026-09-01', ['supplier_name_snapshot' => '旧供应商名称']);
        $this->source($supplier, '200', '2026-10-01', ['supplier_name_snapshot' => '另一历史名称']);
        $this->cash($supplier, '30', ['currency' => 'USD']);
        $result = $this->report($supplier);
        $this->assertSame(2, $result['total']);
        $this->assertNull($result['summary']);
        $byCurrency = collect($result['data'])->keyBy('currency');
        $this->assertSame('300.0000', $byCurrency['CNY']['current_payable_amount']);
        $this->assertSame(2, $byCurrency['CNY']['source_count']);
        $this->assertSame($supplier->supplier_name, $byCurrency['CNY']['supplier_name']);
        $this->assertSame('30.0000', $byCurrency['USD']['prepayment_balance_amount']);
        $this->assertCount(2, $result['summary_by_currency']);
        $onlyUsd = $this->report($supplier, ['currency' => 'USD']);
        $this->assertSame(1, $onlyUsd['total']);
        $this->assertSame('USD', $onlyUsd['summary']['currency']);
    }

    public function test_full_filtered_summary_is_independent_of_pagination(): void
    {
        $prefix = $this->code('GROUP');
        foreach ([11, 22, 33] as $amount) $this->cash($this->supplier($prefix.'-'.$amount), (string) $amount);
        $query = app(SupplierFinanceQueryService::class);
        $first = $query->paginate(['supplier_keyword' => $prefix, 'page' => 1], 1);
        $second = $query->paginate(['supplier_keyword' => $prefix, 'page' => 2], 1);
        $this->assertCount(1, $first['data']);
        $this->assertSame(3, $first['total']);
        $this->assertSame('66.0000', $first['summary_by_currency'][0]['prepayment_balance_amount']);
        $this->assertSame(3, $first['summary_by_currency'][0]['supplier_count']);
        $this->assertSame($first['summary_by_currency'], $second['summary_by_currency']);
        $this->assertNotSame($first['data'][0]['supplier_id'], $second['data'][0]['supplier_id']);
    }

    public function test_period_changes_flows_but_never_turns_prior_allocated_cash_into_prepayment(): void
    {
        $supplier = $this->supplier();
        $september = $this->source($supplier, '100', '2026-09-01');
        $october = $this->source($supplier, '200', '2026-10-01');
        $priorCash = $this->cash($supplier, '100', ['business_date' => '2026-09-02']);
        $currentCash = $this->cash($supplier, '70', ['business_date' => '2026-10-02']);
        $this->allocation($priorCash, $september, '60', ['allocated_at' => '2026-09-03 08:00:00']);
        $this->allocation($currentCash, $october, '20', ['allocated_at' => '2026-10-03 08:00:00']);
        $sep = $this->report($supplier, ['period_start' => '2026-09-01', 'period_end' => '2026-09-30'])['data'][0];
        $oct = $this->report($supplier, ['period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'payment_status' => 'partial'])['data'][0];
        foreach ([$sep, $oct] as $row) {
            $this->assertSame('300.0000', $row['current_payable_amount']);
            $this->assertSame('80.0000', $row['paid_amount']);
            $this->assertSame('220.0000', $row['unpaid_amount']);
            $this->assertSame('90.0000', $row['prepayment_balance_amount']);
        }
        $this->assertSame('100.0000', $sep['period_payment_amount']);
        $this->assertSame('100.0000', $sep['period_receipt_amount']);
        $this->assertSame('60.0000', $sep['period_allocated_amount']);
        $this->assertSame('70.0000', $oct['period_payment_amount']);
        $this->assertSame('200.0000', $oct['period_receipt_amount']);
        $this->assertSame('20.0000', $oct['period_allocated_amount']);
        $this->assertSame(0, $this->report($supplier, ['only_prepayment' => true])['total']);
    }

    public function test_refund_obligation_cash_receipt_and_offset_are_separate_and_only_completed_returns_count(): void
    {
        $supplier = $this->supplier();
        $source = $this->source($supplier, '200', '2026-10-01');
        $due = $this->purchaseReturn($supplier, $source, '100', 'SUPPLIER_REFUND');
        $this->purchaseReturn($supplier, $source, '30', 'AP_OFFSET');
        $this->purchaseReturn($supplier, $source, '900', 'SUPPLIER_REFUND', ['return_status' => 'pending_outbound']);
        $this->purchaseReturn($supplier, $source, '800', 'SUPPLIER_REFUND', ['return_status' => 'cancelled']);
        $refund = $this->cash($supplier, '25', ['direction' => 'receipt']);
        $this->allocation($refund, $source, '15', ['source_business_type' => 'purchase_return_supplier_refund', 'source_document_id' => $due->id, 'source_document_no' => $due->return_no]);
        $row = $this->report($supplier)['data'][0];
        $this->assertSame('85.0000', $row['pending_refund_amount']);
        $this->assertSame('25.0000', $row['period_refund_amount']);
        $this->assertSame('100.0000', $row['period_refund_due_amount']);
        $this->assertSame('30.0000', $row['period_offset_amount']);
        $this->assertSame('15.0000', $row['period_refund_allocated_amount']);
        $this->assertSame('0.0000', $row['paid_amount']);
    }

    public function test_returned_deposit_reduces_prepayment_but_allocated_return_refund_is_not_deducted_twice(): void
    {
        $supplier = $this->supplier();
        $source = $this->source($supplier, '100', '2026-09-01');
        $payment = $this->cash($supplier, '100', ['business_date' => '2026-09-02']);
        $this->allocation($payment, $source, '60', ['allocated_at' => '2026-09-03 08:00:00']);
        $this->assertSame('40.0000', $this->report($supplier)['data'][0]['prepayment_balance_amount']);

        // The returned deposit has no formal return obligation to allocate.
        $this->cash($supplier, '20', ['direction' => 'receipt']);
        $this->assertSame('20.0000', $this->report($supplier)['data'][0]['prepayment_balance_amount']);
        $due = $this->purchaseReturn($supplier, $source, '20', 'SUPPLIER_REFUND');
        $refund = $this->cash($supplier, '20', ['direction' => 'receipt']);
        $this->allocation($refund, $source, '20', ['source_business_type' => 'purchase_return_supplier_refund', 'source_document_id' => $due->id, 'source_document_no' => $due->return_no]);
        $this->cash($supplier, '70', ['direction' => 'receipt', 'status' => 'draft']);
        $this->cash($supplier, '80', ['direction' => 'receipt', 'status' => 'voided', 'voided_at' => '2026-10-04 12:00:00']);

        $sep = $this->report($supplier, ['period_start' => '2026-09-01', 'period_end' => '2026-09-30'])['data'][0];
        $oct = $this->report($supplier, ['period_start' => '2026-10-01', 'period_end' => '2026-10-31'])['data'][0];
        foreach ([$sep, $oct] as $row) {
            $this->assertSame('20.0000', $row['prepayment_balance_amount']);
            $this->assertSame('60.0000', $row['paid_amount']);
            $this->assertSame('0.0000', $row['pending_refund_amount']);
        }
        $this->assertSame('100.0000', $sep['period_payment_amount']);
        $this->assertSame('0.0000', $sep['period_refund_amount']);
        $this->assertSame('0.0000', $sep['period_refund_allocated_amount']);
        $this->assertSame('0.0000', $oct['period_payment_amount']);
        $this->assertSame('40.0000', $oct['period_refund_amount']);
        $this->assertSame('20.0000', $oct['period_refund_allocated_amount']);
        $this->assertSame('20.0000', app(PurchasePayableQueryService::class)->supplierLedgers(['supplier_id' => $supplier->id], 20)['summary']['prepayment_balance_amount']);
    }

    public function test_refund_adjusted_prepayment_summary_is_paginated_and_scoped_per_supplier_and_currency(): void
    {
        $prefix = $this->code('REFUND-GROUP');
        $firstSupplier = $this->supplier($prefix.'-A');
        $source = $this->source($firstSupplier, '100', '2026-10-01');
        $payment = $this->cash($firstSupplier, '100');
        $this->allocation($payment, $source, '60');
        $this->cash($firstSupplier, '20', ['direction' => 'receipt']);
        $this->cash($firstSupplier, '40', ['currency' => 'USD']);
        $this->cash($firstSupplier, '5', ['currency' => 'USD', 'direction' => 'receipt']);
        $secondSupplier = $this->supplier($prefix.'-B');
        $this->cash($secondSupplier, '30');
        $this->cash($secondSupplier, '10', ['direction' => 'receipt']);
        // Historical excess refunds cannot consume another supplier's balance.
        $thirdSupplier = $this->supplier($prefix.'-C');
        $this->cash($thirdSupplier, '10');
        $this->cash($thirdSupplier, '25', ['direction' => 'receipt']);

        $query = app(SupplierFinanceQueryService::class);
        $filters = ['supplier_keyword' => $prefix, 'currency' => 'CNY', 'period_start' => '2026-11-01', 'period_end' => '2026-11-30'];
        $first = $query->paginate([...$filters, 'page' => 1], 1);
        $second = $query->paginate([...$filters, 'page' => 2], 1);
        $third = $query->paginate([...$filters, 'page' => 3], 1);
        $this->assertSame(3, $first['total']);
        $this->assertCount(1, $first['data']);
        $this->assertSame('20.0000', $first['data'][0]['prepayment_balance_amount']);
        $this->assertSame('20.0000', $second['data'][0]['prepayment_balance_amount']);
        $this->assertSame('0.0000', $third['data'][0]['prepayment_balance_amount']);
        $this->assertSame('40.0000', $first['summary']['prepayment_balance_amount']);
        $this->assertSame('0.0000', $first['summary']['period_refund_amount']);
        $this->assertSame($first['summary_by_currency'], $second['summary_by_currency']);
        $this->assertSame($first['summary_by_currency'], $third['summary_by_currency']);
        $this->assertSame(1, $query->paginate([...$filters, 'only_prepayment' => true], 20)['total']);

        $allCurrencies = $query->paginate(['supplier_keyword' => $prefix], 1);
        $summaries = collect($allCurrencies['summary_by_currency'])->keyBy('currency');
        $this->assertNull($allCurrencies['summary']);
        $this->assertSame('40.0000', $summaries['CNY']['prepayment_balance_amount']);
        $this->assertSame('55.0000', $summaries['CNY']['period_refund_amount']);
        $this->assertSame('35.0000', $summaries['USD']['prepayment_balance_amount']);
        $this->assertSame('5.0000', $summaries['USD']['period_refund_amount']);
    }

    public function test_void_and_reversal_history_remains_paginated_without_affecting_current_balance(): void
    {
        $supplier = $this->supplier();
        $source = $this->source($supplier, '100', '2026-09-01');
        $cash = $this->cash($supplier, '80', ['status' => 'voided', 'business_date' => '2026-09-02', 'voided_at' => '2026-10-04 09:00:00']);
        $original = $this->allocation($cash, $source, '50', ['status' => 'reversed', 'allocated_at' => '2026-09-03 09:00:00', 'reversed_at' => '2026-10-03 09:00:00']);
        $this->allocation($cash, $source, '-50', ['status' => 'reversal', 'reversal_of_id' => $original->id, 'allocated_at' => '2026-10-03 09:00:00']);
        $query = app(SupplierFinanceQueryService::class);
        $report = $this->report($supplier, ['period_start' => '2026-10-01', 'period_end' => '2026-10-31']);
        $this->assertSame('100.0000', $report['data'][0]['unpaid_amount']);
        $this->assertSame('0.0000', $report['data'][0]['prepayment_balance_amount']);
        $this->assertSame('0.0000', $report['data'][0]['period_payment_amount']);
        $this->assertSame('50.0000', $report['data'][0]['period_reversed_allocation_amount']);
        $all = $query->entries($supplier->id, [], 2, true);
        $this->assertSame(5, $all['total']);
        $this->assertCount(2, $all['data']);
        $later = $query->entries($supplier->id, ['page' => 2], 2, true);
        $this->assertNotSame($all['data'][0]['event_key'], $later['data'][0]['event_key']);
        $october = $query->entries($supplier->id, ['period_start' => '2026-10-01', 'period_end' => '2026-10-31'], 20, true);
        $this->assertSame(2, $october['total']);
        $this->assertEqualsCanonicalizing(['void', 'allocation'], array_column($october['data'], 'event_family'));
        $this->assertSame(1, $query->entries($supplier->id, ['event_family' => 'void', 'keyword' => $cash->document_no], 20, true)['total']);
    }

    public function test_entries_require_supplier_permission_and_cash_records_are_redacted_without_finance_view(): void
    {
        $supplier = $this->supplier();
        $source = $this->source($supplier, '100', '2026-10-01');
        $cash = $this->cash($supplier, '60');
        $this->allocation($cash, $source, '20');
        $this->permissions = ['finance.supplier-ledger.view'];
        $url = '/api/v1/erp/finance/supplier-finance/'.$supplier->id.'/entries';
        $response = $this->getJson($url)->assertOk()->assertJsonPath('cash_details_visible', false)->assertJsonPath('total', 1);
        $this->assertStringNotContainsString($cash->document_no, $response->getContent());
        $this->getJson($url.'?event_family=cash')->assertForbidden();
        $this->permissions[] = 'finance.view';
        $this->getJson($url)->assertOk()->assertJsonPath('cash_details_visible', true)->assertJsonPath('total', 3);
        $this->permissions = ['finance.view'];
        $this->getJson($url)->assertForbidden();
        $this->getJson('/api/v1/erp/finance/supplier-finance/statistics')->assertForbidden();
    }

    public function test_query_endpoints_validate_dates_and_services_issue_no_mutation_or_row_lock(): void
    {
        $supplier = $this->supplier();
        $this->cash($supplier, '45');
        $this->getJson('/api/v1/erp/finance/supplier-finance/statistics?period_start=2026-10-31&period_end=2026-10-01')->assertUnprocessable();
        $this->getJson('/api/v1/erp/finance/supplier-finance/statistics?currency=CNY&supplier_id='.$supplier->id.'&per_page=1')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('summary_by_currency.0.prepayment_balance_amount', '45.0000');
        DB::flushQueryLog(); DB::enableQueryLog();
        try {
            $this->report($supplier);
            app(SupplierFinanceQueryService::class)->entries($supplier->id, [], 20, true);
            foreach (DB::getQueryLog() as $query) {
                $this->assertMatchesRegularExpression('/^select\s/i', ltrim($query['query']));
                $this->assertStringNotContainsString('for update', strtolower($query['query']));
            }
        } finally { DB::disableQueryLog(); }
    }

    private function report(Supplier $supplier, array $filters = []): array
    {
        return app(SupplierFinanceQueryService::class)->paginate(['supplier_id' => $supplier->id, ...$filters], 20);
    }

    private function supplier(?string $name = null): Supplier
    {
        return Supplier::create(['supplier_code' => $this->code('SUP'), 'supplier_name' => $name ?: $this->code('供应商'), 'supplier_type' => 'manufacturer', 'status' => 'enabled']);
    }

    private function source(Supplier $supplier, string $amount, string $date, array $changes = []): PurchaseSettlementSource
    {
        $unit = Unit::create(['unit_code' => $this->code('U'), 'unit_name' => '台', 'unit_type' => 'count', 'decimal_places' => 0, 'is_base' => true, 'status' => 'enabled']);
        $item = Item::create(['item_code' => $this->code('ITEM'), 'item_name' => '供应商统计测试物料', 'item_type' => 'consumable', 'unit_id' => $unit->id, 'is_purchase_item' => true, 'is_stock_item' => false, 'status' => 'enabled']);
        $receipt = PurchaseReceipt::create(['receipt_no' => $this->code('RC'), 'supplier_id' => $supplier->id, 'receipt_date' => $date, 'receipt_status' => 'confirmed', 'confirm_status' => 'confirmed', 'stock_post_status' => 'not_required', 'settlement_mode' => 'normal', 'currency_snapshot' => 'CNY', 'settlement_amount' => $amount]);
        $line = PurchaseReceiptItem::create(['receipt_id' => $receipt->id, 'item_id' => $item->id, 'receipt_qty' => 1, 'unit_price' => $amount, 'receipt_cost' => $amount, 'amount_incl_tax' => $amount, 'settlement_amount' => $amount]);
        return PurchaseSettlementSource::create([
            'source_type' => 'purchase_receipt_qualified', 'source_document_type' => 'purchase_receipt', 'source_document_id' => $receipt->id,
            'source_document_no' => $receipt->receipt_no, 'source_receipt_id' => $receipt->id, 'source_line_id' => $line->id,
            'supplier_id' => $supplier->id, 'supplier_name_snapshot' => $supplier->supplier_name, 'currency' => 'CNY', 'business_date' => $date,
            'original_amount' => $amount, 'eligible_amount' => $amount, 'unallocated_amount' => $amount, 'invoice_unmatched_amount' => $amount, 'status' => 'open', ...$changes,
        ]);
    }

    private function cash(Supplier $supplier, string $amount, array $changes = []): FinanceCashDocument
    {
        $account = FinanceAccount::create(['account_no' => $this->code('ACC'), 'account_name' => '供应商统计测试账户', 'account_type' => 'bank', 'currency' => $changes['currency'] ?? 'CNY', 'status' => 'enabled']);
        return FinanceCashDocument::create([
            'direction' => 'payment', 'document_no' => $this->code('PAY'), 'party_type' => 'supplier', 'party_id' => $supplier->id,
            'party_name_snapshot' => $supplier->supplier_name, 'business_date' => '2026-10-02', 'finance_account_id' => $account->id,
            'currency' => 'CNY', 'amount' => $amount, 'payment_method' => 'bank_transfer', 'status' => 'confirmed', 'confirmed_at' => '2026-10-02 08:00:00', ...$changes,
        ]);
    }

    private function allocation(FinanceCashDocument $cash, PurchaseSettlementSource $source, string $amount, array $changes = []): FinanceAllocation
    {
        return FinanceAllocation::create([
            'cash_document_id' => $cash->id, 'source_business_type' => 'purchase_settlement_source', 'source_document_id' => $source->id,
            'source_document_no' => $source->source_document_no, 'party_type' => 'supplier', 'party_id' => $cash->party_id,
            'currency' => $cash->currency, 'source_amount_snapshot' => $source->original_amount, 'allocated_amount' => $amount,
            'status' => 'active', 'allocated_at' => '2026-10-03 08:00:00', 'idempotency_key' => $this->code('ALLOC'), ...$changes,
        ]);
    }

    private function purchaseReturn(Supplier $supplier, PurchaseSettlementSource $source, string $amount, string $effect, array $changes = []): PurchaseReturn
    {
        return PurchaseReturn::create(['return_no' => $this->code('RET'), 'source_receipt_id' => $source->source_receipt_id,
            'supplier_id' => $supplier->id, 'return_scope' => 'posted_inventory', 'return_date' => '2026-10-03', 'return_status' => 'completed',
            'return_reason' => '统计测试', 'currency_snapshot' => 'CNY', 'settlement_effect_type' => $effect, 'settlement_amount' => $amount, ...$changes]);
    }

    private function code(string $prefix): string
    {
        return $prefix.'-'.substr((string) Str::ulid(), -12);
    }
}
