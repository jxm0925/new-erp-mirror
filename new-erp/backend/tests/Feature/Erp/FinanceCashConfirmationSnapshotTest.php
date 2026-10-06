<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\{FinanceAccount, FinanceAccountMovement, FinanceAllocation, FinanceCashDocument, FinanceCurrency, FinanceOperationLog, Item, PaymentMethod, PurchaseReceipt, PurchaseReceiptItem, PurchaseSettlementSource, SalesCustomer, SalesOrder, Supplier, Unit};
use App\Services\Erp\{FinanceAllocationApplicationService, FinanceCashConfirmationApplicationService, PurchaseSettlementSourceApplicationService};
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Real independent MySQL connections; A keeps its old RR snapshot while B commits. */
class FinanceCashConfirmationSnapshotTest extends TestCase
{
    private const WRITER = 'finance_snapshot_writer';
    private string $reader;
    private array $inserted = [];
    private bool $tracking = false;
    private FinanceAccount $account;
    private PaymentMethod $method;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reader = DB::getDefaultConnection();
        $config = config('database.connections.'.$this->reader);
        $this->assertStringEndsWith('_test', strtolower((string) $config['database']));
        $this->assertSame(0, DB::transactionLevel(), 'These fixtures must be visible to both connections.');
        config(['database.connections.'.self::WRITER => $config]);
        foreach ([$this->reader, self::WRITER] as $name) {
            $connection = DB::connection($name);
            $this->assertStringEndsWith('_test', strtolower((string) $connection->selectOne('SELECT DATABASE() AS name')->name));
            $connection->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->assertSame(1, (int) $connection->selectOne('SELECT @@auto_increment_increment AS step')->step);
        }
        $this->assertTrue(FinanceCurrency::where('currency_code', 'CNY')->where('is_base', true)->where('status', 'enabled')->exists(), 'Seed the official finance reference data in the test database.');
        $this->tracking = true;
        DB::listen(function ($query): void {
            if (! $this->tracking || ! in_array($query->connectionName, [$this->reader, self::WRITER], true)
                || ! preg_match('/^insert into `([a-z_]+)` .* values (.+)$/i', $query->sql, $match)
                || str_contains(strtolower($query->sql), 'on duplicate key')) return;
            // Record only exact IDs allocated by this test, on the connection that inserted them.
            $first = (int) $query->connection->getPdo()->lastInsertId();
            if ($first < 1 || ! Schema::connection($query->connectionName)->hasColumn($match[1], 'id')) return;
            $this->inserted[] = ['table' => $match[1], 'ids' => range($first, $first + substr_count($match[2], '), ('))];
        });
        $this->account = FinanceAccount::create([
            'account_no' => $this->code('ACC'), 'account_name' => '旧快照回归账户',
            'account_type' => 'bank', 'currency' => 'CNY', 'status' => 'enabled',
        ]);
        $this->method = PaymentMethod::create([
            'method_code' => $this->code('METHOD'), 'method_name' => '旧快照回归付款方式',
            'available_for_sales' => true, 'available_for_receipt' => true,
            'available_for_payment' => true, 'status' => 'enabled',
        ]);
    }

    protected function tearDown(): void
    {
        try {
            $this->tracking = false;
            if (isset($this->reader)) {
                DB::setDefaultConnection($this->reader);
                foreach ([$this->reader, self::WRITER] as $name) {
                    $connection = DB::connection($name);
                    if ($connection->transactionLevel() > 0) $connection->rollBack(0);
                }
                $connection = DB::connection($this->reader);
                if (! str_ends_with(strtolower((string) $connection->selectOne('SELECT DATABASE() AS name')->name), '_test')) {
                    throw new \RuntimeException('Refusing committed fixture cleanup outside a test database.');
                }
                $connection->statement('SET FOREIGN_KEY_CHECKS = 0');
                try {
                    foreach (array_reverse($this->inserted) as $row) $connection->table($row['table'])->whereIn('id', $row['ids'])->delete();
                } finally {
                    $connection->statement('SET FOREIGN_KEY_CHECKS = 1');
                }
                DB::purge(self::WRITER);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_old_snapshot_cannot_over_allocate_sales_source_after_another_cash_commits(): void
    {
        [$customer, $order] = $this->salesOrder();
        $first = $this->cash($customer, '60');
        $second = $this->cash($customer, '60');
        $this->withOldSnapshot(function () use ($first, $second, $order): void {
            $this->onWriter(fn () => $this->confirm($first->id, $this->row('sales_order', $order->id, '60')));
            $this->assertRejected(fn () => $this->confirm($second->id, $this->row('sales_order', $order->id, '60')), 'allocated_amount');
            $this->assertSame('draft', FinanceCashDocument::findOrFail($second->id)->status);
            $this->assertSame(0, FinanceAccountMovement::where('source_type', 'cash_document')->where('source_id', $second->id)->count());
            $this->assertSame(0, FinanceAllocation::where('cash_document_id', $second->id)->count());
        });
        $this->assertSame('60.0000', app(FinanceAllocationApplicationService::class)->activeTotalForSource('sales_order', $order->id));
        $this->assertSame('confirmed', $first->fresh()->status);
        $this->assertSame('draft', $second->fresh()->status);
    }

    public function test_old_snapshot_uses_latest_purchase_allocation_for_success_and_source_projection(): void
    {
        [$supplier, , , $source] = $this->purchaseSource();
        $first = $this->cash($supplier, '60');
        $second = $this->cash($supplier, '40');
        $this->withOldSnapshot(function () use ($first, $second, $source): void {
            $this->onWriter(fn () => $this->confirm($first->id, $this->row('purchase_settlement_source', $source->id, '60')));
            $result = $this->confirm($second->id, $this->row('purchase_settlement_source', $source->id, '40'));
            $this->assertSame('confirmed', $result->status);
            $this->assertCount(1, $result->allocations);
            $latest = PurchaseSettlementSource::query()->lockForUpdate()->findOrFail($source->id);
            $this->assertSame('100.0000', $latest->allocated_amount);
            $this->assertSame('0.0000', $latest->unallocated_amount);
            $this->assertSame('paid', $latest->status);
            $this->assertSame('100.0000', app(FinanceAllocationApplicationService::class)->activeTotalForSource('purchase_settlement_source', $source->id));
        });
        $this->assertSame('60.0000', $source->fresh()->allocated_amount);
        $this->assertSame('40.0000', $source->fresh()->unallocated_amount);
    }

    public function test_old_snapshot_cannot_restore_purchase_eligibility_removed_by_quality_update(): void
    {
        [$supplier, $receipt, $line, $source] = $this->purchaseSource();
        $cash = $this->cash($supplier, '60');
        $this->withOldSnapshot(function () use ($receipt, $line, $source, $cash): void {
            $this->onWriter(fn () => DB::transaction(function () use ($receipt, $line): void {
                PurchaseReceiptItem::whereKey($line->id)->update([
                    'qualified_qty' => 4, 'settlement_amount' => '40', 'qualified_payable_amount' => '40', 'quality_hold_amount' => '60',
                ]);
                app(PurchaseSettlementSourceApplicationService::class)->syncReceipt($receipt->id);
            }));
            $this->assertRejected(fn () => $this->confirm($cash->id, $this->row('purchase_settlement_source', $source->id, '60')), 'allocated_amount');
            $this->assertSame('draft', FinanceCashDocument::findOrFail($cash->id)->status);
            $this->assertSame(0, FinanceAccountMovement::where('source_type', 'cash_document')->where('source_id', $cash->id)->count());
        });
        $latest = $source->fresh();
        $this->assertSame('40.0000', $latest->eligible_amount);
        $this->assertSame('60.0000', $latest->frozen_amount);
        $this->assertSame('0.0000', $latest->allocated_amount);
        $this->assertSame('40.0000', $latest->unallocated_amount);
    }

    public function test_retry_from_old_snapshot_returns_current_cash_allocations_and_logs_without_reposting(): void
    {
        [$customer, $order] = $this->salesOrder();
        $cash = $this->cash($customer, '60');
        $item = $this->row('sales_order', $order->id, '60');
        $this->withOldSnapshot(function () use ($cash, $item): void {
            $posted = $this->onWriter(fn () => $this->confirm($cash->id, $item));
            $replayed = $this->confirm($cash->id, $item);
            $this->assertSame('confirmed', $replayed->status);
            $this->assertCount(1, $replayed->allocations);
            $this->assertSame($posted->allocations->first()->id, $replayed->allocations->first()->id);
            $this->assertSame($posted->confirmed_at->toDateTimeString(), $replayed->confirmed_at->toDateTimeString());
            $this->assertSame(1, $replayed->logs->where('action', 'confirm')->count());
            $this->assertSame(1, $replayed->logs->where('action', 'allocate')->count());
            $this->assertSame('60.0000', app(FinanceAllocationApplicationService::class)->activeTotalForDocument($cash->id));
        });
        $this->assertSame(1, FinanceAccountMovement::where('source_type', 'cash_document')->where('source_id', $cash->id)->count());
        $this->assertSame(1, FinanceAllocation::where('cash_document_id', $cash->id)->count());
        $this->assertSame(1, FinanceOperationLog::where('document_type', 'cash_document')->where('document_id', $cash->id)->where('action', 'confirm')->count());
    }

    public function test_reversal_from_old_snapshot_keeps_other_cash_committed_allocation_in_source_projection(): void
    {
        [$supplier, , , $source] = $this->purchaseSource();
        $first = $this->cash($supplier, '40');
        $second = $this->cash($supplier, '60');
        $posted = $this->confirm($first->id, $this->row('purchase_settlement_source', $source->id, '40'));
        $allocationId = $posted->allocations->first()->id;
        $this->withOldSnapshot(function () use ($second, $source, $allocationId): void {
            $this->onWriter(fn () => $this->confirm($second->id, $this->row('purchase_settlement_source', $source->id, '60')));
            app(FinanceAllocationApplicationService::class)->reverse($allocationId, '旧快照撤销回归', 1, '回归测试');
            $latest = PurchaseSettlementSource::query()->lockForUpdate()->findOrFail($source->id);
            $this->assertSame('60.0000', $latest->allocated_amount);
            $this->assertSame('40.0000', $latest->unallocated_amount);
            $this->assertSame('partially_paid', $latest->status);
            $this->assertSame('60.0000', app(FinanceAllocationApplicationService::class)->activeTotalForSource('purchase_settlement_source', $source->id));
        });
        // A is rolled back by the harness; both committed confirmations still remain.
        $this->assertSame('100.0000', $source->fresh()->allocated_amount);
    }

    private function withOldSnapshot(callable $callback): void
    {
        DB::beginTransaction();
        try {
            // This ordinary read deliberately establishes A's old InnoDB snapshot.
            $this->assertNotNull(FinanceAccount::find($this->account->id));
            $callback();
        } finally {
            if (DB::transactionLevel() > 0) DB::rollBack(0);
        }
    }

    private function onWriter(callable $callback): mixed
    {
        DB::setDefaultConnection(self::WRITER);
        try {
            return $callback();
        } finally {
            DB::setDefaultConnection($this->reader);
        }
    }

    private function assertRejected(callable $callback, string $field): void
    {
        try {
            $callback();
            $this->fail('A stale snapshot must not permit this allocation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    private function confirm(int $cashId, array $item): FinanceCashDocument
    {
        return app(FinanceCashConfirmationApplicationService::class)->confirm($cashId, [$item], 1, '旧快照回归测试');
    }

    private function row(string $type, int $id, string $amount): array
    {
        return ['source_business_type' => $type, 'source_document_id' => $id, 'allocated_amount' => $amount, 'idempotency_key' => (string) Str::uuid()];
    }

    private function cash(SalesCustomer|Supplier $party, string $amount): FinanceCashDocument
    {
        $supplier = $party instanceof Supplier;
        return FinanceCashDocument::create([
            'direction' => $supplier ? 'payment' : 'receipt', 'document_no' => $this->code('CASH'),
            'party_type' => $supplier ? 'supplier' : 'customer', 'party_id' => $party->id,
            'party_name_snapshot' => $supplier ? $party->supplier_name : $party->customer_name,
            'business_date' => now()->toDateString(), 'finance_account_id' => $this->account->id,
            'currency' => 'CNY', 'amount' => $amount, 'payment_method_id' => $this->method->id,
            'payment_method' => $this->method->method_code, 'status' => 'draft',
        ]);
    }

    private function salesOrder(): array
    {
        $customer = SalesCustomer::create(['customer_code' => $this->code('CUSTOMER'), 'customer_name' => '旧快照回归客户', 'status' => 'enabled']);
        $order = SalesOrder::create([
            'sales_order_no' => $this->code('SO'), 'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name, 'customer_name_snapshot' => $customer->customer_name,
            'order_status' => 'confirmed', 'confirm_status' => 'confirmed', 'total_amount' => '100', 'currency' => 'CNY',
            'funding_policy_snapshot' => ['policy_type' => 'full_prepay', 'shipment_requires_full_payment' => true],
        ]);
        return [$customer, $order];
    }

    private function purchaseSource(): array
    {
        $supplier = Supplier::create(['supplier_code' => $this->code('SUP'), 'supplier_name' => '旧快照回归供应商', 'supplier_type' => 'manufacturer', 'status' => 'enabled']);
        $unit = Unit::create(['unit_code' => $this->code('UNIT'), 'unit_name' => '件', 'decimal_places' => 0, 'status' => 'enabled']);
        $item = Item::create(['item_code' => $this->code('ITEM'), 'item_name' => '旧快照回归物料', 'unit_id' => $unit->id, 'item_type' => 'consumable', 'is_stock_item' => false, 'status' => 'enabled']);
        $receipt = PurchaseReceipt::create([
            'receipt_no' => $this->code('RECEIPT'), 'supplier_id' => $supplier->id, 'receipt_date' => now()->toDateString(),
            'receipt_status' => 'confirmed', 'confirm_status' => 'confirmed', 'stock_post_status' => 'not_required',
            'settlement_mode' => 'normal', 'currency_snapshot' => 'CNY', 'settlement_amount' => '100',
        ]);
        $line = PurchaseReceiptItem::create([
            'receipt_id' => $receipt->id, 'item_id' => $item->id, 'receipt_qty' => 10, 'qualified_qty' => 10,
            'unit_price' => 10, 'receipt_cost' => 100, 'amount_excl_tax' => 100, 'tax_amount_snapshot' => 0,
            'amount_incl_tax' => 100, 'settlement_amount' => 100, 'qualified_payable_amount' => 100,
            'quality_hold_amount' => 0, 'currency_snapshot' => 'CNY', 'finance_fact_status' => 'frozen',
        ]);
        $sources = DB::transaction(fn () => app(PurchaseSettlementSourceApplicationService::class)->syncReceipt($receipt->id));
        return [$supplier, $receipt, $line, $sources[0]];
    }

    private function code(string $prefix): string
    {
        return 'SNAPSHOT-'.$prefix.'-'.Str::upper(Str::random(10));
    }
}
