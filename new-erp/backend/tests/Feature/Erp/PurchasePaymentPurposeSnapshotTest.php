<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\{FinanceAccount, FinanceAccountMovement, FinanceAllocation, FinanceCashDocument, FinanceCashPurchaseAllocation, FinanceCashPurchaseRevision, FinanceCurrency, Item, PaymentMethod, PurchaseOrder, PurchaseReceipt, PurchaseReceiptItem, PurchaseSettlementSource, Supplier, Unit};
use App\Services\Erp\{FinanceAllocationApplicationService, FinanceCashConfirmationApplicationService, PurchasePaymentPlanQueryService, PurchaseSettlementSourceApplicationService};
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Independent committed connections expose stale RR reads without timing races. */
class PurchasePaymentPurposeSnapshotTest extends TestCase
{
    private const WRITER = 'purchase_purpose_snapshot_writer';
    private string $reader;
    private array $connections = [];
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
        $this->assertSame(0, DB::transactionLevel(), 'Independent connection fixtures require committed setup.');
        config(['database.connections.'.self::WRITER => $config]);
        foreach ([$this->reader, self::WRITER] as $name) {
            $connection = DB::connection($name);
            $this->connections[] = $name;
            $this->assertStringEndsWith('_test', strtolower((string) $connection->selectOne('SELECT DATABASE() AS name')->name));
            $connection->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->assertSame(1, (int) $connection->selectOne('SELECT @@auto_increment_increment AS step')->step);
        }
        $this->assertTrue(FinanceCurrency::where('currency_code', 'CNY')->where('is_base', true)->where('status', 'enabled')->exists());
        $this->tracking = true;
        DB::listen(function ($query): void {
            if (! $this->tracking || ! in_array($query->connectionName, $this->connections, true)
                || ! preg_match('/^insert into `([a-z_]+)` .* values (.+)$/i', $query->sql, $match)
                || str_contains(strtolower($query->sql), 'on duplicate key')) return;
            $first = (int) $query->connection->getPdo()->lastInsertId();
            if ($first < 1 || ! Schema::connection($query->connectionName)->hasColumn($match[1], 'id')) return;
            $this->inserted[] = ['table' => $match[1], 'ids' => range($first, $first + substr_count($match[2], '), ('))];
        });
        $this->account = FinanceAccount::create(['account_no' => $this->code('ACC'), 'account_name' => '预付款旧快照测试账户', 'account_type' => 'bank', 'currency' => 'CNY', 'status' => 'enabled']);
        $this->method = PaymentMethod::create(['method_code' => $this->code('METHOD'), 'method_name' => '预付款旧快照测试方式',
            'available_for_receipt' => true, 'available_for_payment' => true, 'available_for_sales' => true, 'status' => 'enabled']);
    }

    protected function tearDown(): void
    {
        try {
            $this->tracking = false;
            if (isset($this->reader)) {
                DB::setDefaultConnection($this->reader);
                foreach ($this->connections as $name) if (DB::connection($name)->transactionLevel() > 0) DB::connection($name)->rollBack(0);
                $connection = DB::connection($this->reader);
                if (! str_ends_with(strtolower((string) $connection->selectOne('SELECT DATABASE() AS name')->name), '_test')) throw new \RuntimeException('Unsafe committed fixture cleanup target.');
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

    public function test_old_snapshot_cannot_spend_deposit_refund_committed_by_another_connection(): void
    {
        [$order, $source] = $this->purchaseSource();
        $payment = $this->cash($order, '100');
        $this->confirm($payment->id, [$this->allocation($source->id, '60')]);
        $refund = $this->cash($order, '20', 'receipt');
        $this->withOldSnapshot(function () use ($order, $source, $payment, $refund): void {
            $this->onWriter(fn () => $this->confirm($refund->id));
            // The connection demonstrably still sees its pre-refund snapshot.
            $this->assertSame('draft', FinanceCashDocument::findOrFail($refund->id)->status);
            $this->assertPurposeRejected(fn () => app(FinanceAllocationApplicationService::class)->allocate($payment->id,
                [$this->allocation($source->id, '40')], 1, '旧快照付款核销'));
        });
        $summary = app(PurchasePaymentPlanQueryService::class)->forOrder($order->id);
        $this->assertSame('20.0000', $summary['refund_amount']);
        $this->assertSame('60.0000', $summary['allocated_payment_amount']);
        $this->assertSame('20.0000', $summary['prepaid_amount']);
        $this->assertSame(1, FinanceAllocation::where('cash_document_id', $payment->id)->count());
        $this->assertSame(1, FinanceAccountMovement::where('source_type', 'cash_document')->where('source_id', $refund->id)->count());
    }

    public function test_old_snapshot_cannot_refund_deposit_already_consumed_by_another_connection(): void
    {
        [$order, $source] = $this->purchaseSource();
        $payment = $this->cash($order, '100');
        $this->confirm($payment->id, [$this->allocation($source->id, '60')]);
        $refund = $this->cash($order, '40', 'receipt');
        $this->withOldSnapshot(function () use ($source, $payment, $refund): void {
            $this->onWriter(fn () => app(FinanceAllocationApplicationService::class)->allocate($payment->id,
                [$this->allocation($source->id, '20')], 1, '先提交付款核销'));
            $this->assertSame('60.0000', PurchaseSettlementSource::findOrFail($source->id)->allocated_amount);
            $this->assertPurposeRejected(fn () => $this->confirm($refund->id));
        });
        $summary = app(PurchasePaymentPlanQueryService::class)->forOrder($order->id);
        $this->assertSame('80.0000', $summary['allocated_payment_amount']);
        $this->assertSame('0.0000', $summary['refund_amount']);
        $this->assertSame('20.0000', $summary['prepaid_amount']);
        $this->assertSame('draft', $refund->fresh()->status);
        $this->assertSame(0, FinanceCashPurchaseAllocation::where('cash_document_id', $refund->id)->count());
        $this->assertSame(0, FinanceCashPurchaseRevision::where('cash_document_id', $refund->id)->count());
        $this->assertSame(0, FinanceAccountMovement::where('source_type', 'cash_document')->where('source_id', $refund->id)->count());
    }

    private function withOldSnapshot(callable $callback): void
    {
        DB::beginTransaction();
        try {
            $this->assertNotNull(FinanceAccount::find($this->account->id));
            $callback();
        } finally {
            if (DB::transactionLevel() > 0) DB::rollBack(0);
        }
    }

    private function onWriter(callable $callback): mixed
    {
        DB::setDefaultConnection(self::WRITER);
        try { return $callback(); } finally { DB::setDefaultConnection($this->reader); }
    }

    private function assertPurposeRejected(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Old snapshots must not allow refunded or already consumed deposits to be used again.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('purchase_order_allocations', $exception->errors());
        }
    }

    private function confirm(int $cashId, array $allocations = []): FinanceCashDocument
    {
        return app(FinanceCashConfirmationApplicationService::class)->confirm($cashId, $allocations, 1, '预付款快照回归');
    }

    private function allocation(int $sourceId, string $amount): array
    {
        return ['source_business_type' => 'purchase_settlement_source', 'source_document_id' => $sourceId,
            'allocated_amount' => $amount, 'idempotency_key' => (string) Str::uuid()];
    }

    private function cash(PurchaseOrder $order, string $amount, string $direction = 'payment'): FinanceCashDocument
    {
        return FinanceCashDocument::create(['document_no' => $this->code('CASH'), 'direction' => $direction, 'party_type' => 'supplier',
            'party_id' => $order->supplier_id, 'party_name_snapshot' => '预付款快照供应商', 'business_date' => '2026-10-05',
            'finance_account_id' => $this->account->id, 'currency' => 'CNY', 'amount' => $amount,
            'payment_method_id' => $this->method->id, 'payment_method' => $this->method->method_code, 'status' => 'draft',
            'purchase_order_allocations' => [['purchase_order_id' => $order->id, 'payment_plan_id' => null, 'amount' => $amount]]]);
    }

    private function purchaseSource(): array
    {
        $supplier = Supplier::create(['supplier_code' => $this->code('SUP'), 'supplier_name' => '预付款快照供应商', 'supplier_type' => 'manufacturer', 'status' => 'enabled']);
        $order = PurchaseOrder::create(['purchase_order_no' => $this->code('PO'), 'supplier_id' => $supplier->id, 'order_date' => '2026-10-05',
            'currency' => 'CNY', 'tax_mode' => 'tax_included', 'total_amount' => '100', 'amount_incl_tax' => '100',
            'purchase_status' => 'processing', 'audit_status' => 'approved', 'receipt_status' => 'not_received', 'finance_fact_status' => 'frozen']);
        $unit = Unit::create(['unit_code' => $this->code('UNIT'), 'unit_name' => '件', 'decimal_places' => 0, 'status' => 'enabled']);
        $item = Item::create(['item_code' => $this->code('ITEM'), 'item_name' => '预付款快照物料', 'unit_id' => $unit->id, 'item_type' => 'consumable', 'is_stock_item' => false, 'status' => 'enabled']);
        $receipt = PurchaseReceipt::create(['receipt_no' => $this->code('RECEIPT'), 'supplier_id' => $supplier->id, 'order_id' => $order->id,
            'receipt_date' => '2026-10-05', 'receipt_status' => 'confirmed', 'confirm_status' => 'confirmed', 'stock_post_status' => 'not_required',
            'settlement_mode' => 'normal', 'currency_snapshot' => 'CNY', 'settlement_amount' => '100']);
        PurchaseReceiptItem::create(['receipt_id' => $receipt->id, 'item_id' => $item->id, 'receipt_qty' => 10, 'qualified_qty' => 10,
            'unit_price' => 10, 'receipt_cost' => 100, 'amount_excl_tax' => 100, 'tax_amount_snapshot' => 0, 'amount_incl_tax' => 100,
            'settlement_amount' => 100, 'qualified_payable_amount' => 100, 'quality_hold_amount' => 0, 'currency_snapshot' => 'CNY', 'finance_fact_status' => 'frozen']);
        $source = DB::transaction(fn () => app(PurchaseSettlementSourceApplicationService::class)->syncReceipt($receipt->id)[0]);
        return [$order, $source];
    }

    private function code(string $prefix): string
    {
        return 'PP-SNAP-'.$prefix.'-'.Str::upper(Str::random(9));
    }
}
