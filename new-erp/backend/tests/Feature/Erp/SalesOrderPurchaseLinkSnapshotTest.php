<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\{Item, PurchaseOrder, PurchaseOrderItem, SalesCustomer, SalesOrder, SalesOrderLog, SalesOrderPurchaseLink, Supplier, Unit};
use App\Services\Erp\{SalesOrderFinanceQueryService, SalesOrderPurchaseLinkApplicationService};
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Independent MySQL sessions: committed B facts must override A's old RR snapshot. */
class SalesOrderPurchaseLinkSnapshotTest extends TestCase
{
    private const WRITER = 'sales_purchase_link_snapshot_writer';
    private string $reader;
    private array $inserted = [];
    private bool $connectionsReady = false;
    private bool $tracking = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reader = DB::getDefaultConnection();
        $config = config('database.connections.'.$this->reader);
        $this->assertSame('mysql', $config['driver']);
        $this->assertStringEndsWith('_test', strtolower((string) $config['database']));
        $this->assertSame(0, DB::transactionLevel(), 'Committed fixtures must be visible to both sessions.');
        config(['database.connections.'.self::WRITER => $config]);
        DB::purge(self::WRITER);
        $sessions = [];
        foreach ([$this->reader, self::WRITER] as $name) {
            $connection = DB::connection($name);
            $identity = $connection->selectOne('SELECT DATABASE() AS name, CONNECTION_ID() AS session_id');
            $this->assertStringEndsWith('_test', strtolower((string) $identity->name));
            $this->assertSame($config['database'], $identity->name);
            $this->assertSame(0, $connection->transactionLevel());
            $connection->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->assertSame(1, (int) $connection->selectOne('SELECT @@auto_increment_increment AS step')->step);
            $sessions[] = (int) $identity->session_id;
        }
        $this->assertNotSame($sessions[0], $sessions[1], 'B must be a real independent database session.');
        $this->connectionsReady = true;
        $this->tracking = true;
        DB::listen(function ($query): void {
            if (! $this->tracking || ! in_array($query->connectionName, [$this->reader, self::WRITER], true)
                || ! preg_match('/^insert into `([a-z_]+)` .* values (.+)$/i', $query->sql, $match)
                || str_contains(strtolower($query->sql), 'on duplicate key')) return;
            // Record exact generated IDs, including the link and audit inserted
            // by B. Never clean up by a broad name/date/table condition.
            $first = (int) $query->connection->getPdo()->lastInsertId();
            if ($first < 1 || ! Schema::connection($query->connectionName)->hasColumn($match[1], 'id')) return;
            $this->inserted[] = ['table' => $match[1], 'ids' => range($first, $first + substr_count($match[2], '), ('))];
        });
    }

    protected function tearDown(): void
    {
        try {
            $this->tracking = false;
            if (isset($this->reader)) DB::setDefaultConnection($this->reader);
            if ($this->connectionsReady) {
                foreach ([$this->reader, self::WRITER] as $name) {
                    $connection = DB::connection($name);
                    if (! str_ends_with(strtolower((string) $connection->selectOne('SELECT DATABASE() AS name')->name), '_test')) {
                        throw new \RuntimeException('Refusing committed fixture cleanup outside a test database.');
                    }
                    if ($connection->transactionLevel() > 0) $connection->rollBack(0);
                }
                $connection = DB::connection($this->reader);
                // Reverse insertion order preserves FK checks throughout cleanup.
                $connection->transaction(function () use ($connection): void {
                    foreach (array_reverse($this->inserted) as $row) {
                        $connection->table($row['table'])->whereIn('id', $row['ids'])->delete();
                    }
                });
            }
        } finally {
            if (isset($this->reader)) DB::purge(self::WRITER);
            parent::tearDown();
        }
    }

    public function test_old_snapshot_cannot_attribute_purchase_quantity_committed_to_another_sales_order(): void
    {
        $readerOrder = $this->sales();
        $writerOrder = $this->sales();
        $item = $this->purchase();
        $rejectedKey = (string) Str::uuid();
        $committedLink = null;
        DB::beginTransaction();
        try {
            // Ordinary SELECT deliberately establishes A's InnoDB snapshot.
            $this->assertSame(0, SalesOrderPurchaseLink::where('purchase_order_item_id', $item->id)->count());
            $this->assertSame('10.00000000', $this->candidate($item)['available_purchase_qty']);

            $committedLink = $this->onWriter(function () use ($writerOrder, $item): SalesOrderPurchaseLink {
                $link = $this->add($writerOrder->id, $item->id, '10', (string) Str::uuid());
                $this->assertSame(0, DB::transactionLevel(), 'B must commit before A attempts its write.');
                $this->assertSame(1, SalesOrderPurchaseLink::whereKey($link->id)->count());
                $this->assertSame(1, SalesOrderLog::where('sales_order_id', $writerOrder->id)->where('action', 'purchase_link_add')->count());
                return $link;
            });

            // Prove A still has the old view, rather than merely testing two
            // sequential writes without a repeatable-read snapshot.
            $this->assertSame(0, SalesOrderPurchaseLink::where('purchase_order_item_id', $item->id)->count());
            $this->assertSame('10.00000000', $this->candidate($item)['available_purchase_qty']);
            try {
                $this->add($readerOrder->id, $item->id, '1', $rejectedKey);
                $this->fail('A must reject quantity already attributed by committed B.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('purchase_qty', $exception->errors());
            }

            $currentLinks = SalesOrderPurchaseLink::where('purchase_order_item_id', $item->id)
                ->where('status', 'active')->lockForUpdate()->get();
            $this->assertCount(1, $currentLinks);
            $this->assertSame($committedLink->id, $currentLinks->first()->id);
            $assigned = $currentLinks->reduce(fn (string $sum, $link) => bcadd($sum, $link->purchase_qty, 8), '0.00000000');
            $this->assertSame('10.00000000', $assigned);
            $this->assertSame('0.00000000', bcsub((string) $item->purchase_qty, $assigned, 8));
            $this->assertSame('100.0000', $currentLinks->first()->contract_amount);
            $this->assertSame(0, SalesOrderPurchaseLink::where('sales_order_id', $readerOrder->id)->count());
            $this->assertSame(0, SalesOrderPurchaseLink::where('idempotency_key', $rejectedKey)->count());
            $this->assertSame(0, SalesOrderLog::where('sales_order_id', $readerOrder->id)->count());
            $this->assertSame(1, (int) SalesOrder::query()->lockForUpdate()->findOrFail($readerOrder->id)->purchase_link_version);
        } finally {
            if (DB::transactionLevel() > 0) DB::rollBack(0);
        }

        // B's result survives A's rollback and is also visible to the reporting
        // query after the snapshot ends. No failed A fact or audit was persisted.
        $this->assertSame('0.00000000', $this->candidate($item)['available_purchase_qty']);
        $this->assertSame(1, SalesOrderPurchaseLink::where('purchase_order_item_id', $item->id)->count());
        $this->assertSame($committedLink->id, SalesOrderPurchaseLink::where('purchase_order_item_id', $item->id)->sole()->id);
        $this->assertSame(0, SalesOrderPurchaseLink::where('sales_order_id', $readerOrder->id)->count());
        $this->assertSame(0, SalesOrderLog::where('sales_order_id', $readerOrder->id)->count());
        $this->assertSame(1, (int) $readerOrder->fresh()->purchase_link_version);
        $this->assertSame(2, (int) $writerOrder->fresh()->purchase_link_version);
        $this->assertSame(1, SalesOrderLog::where('sales_order_id', $writerOrder->id)->where('action', 'purchase_link_add')->count());
    }

    private function onWriter(callable $callback): mixed
    {
        DB::setDefaultConnection(self::WRITER);
        try { return $callback(); }
        finally { DB::setDefaultConnection($this->reader); }
    }

    private function add(int $orderId, int $itemId, string $quantity, string $key): SalesOrderPurchaseLink
    {
        return app(SalesOrderPurchaseLinkApplicationService::class)->add($orderId, [
            'version' => 1, 'purchase_order_item_id' => $itemId, 'purchase_qty' => $quantity,
            'idempotency_key' => $key, 'reason' => '旧快照采购数量归属回归',
        ], '双连接回归测试');
    }

    private function candidate(PurchaseOrderItem $item): array
    {
        return app(SalesOrderFinanceQueryService::class)->candidates(['keyword' => $item->order->purchase_order_no, 'per_page' => 10])
            ->getCollection()->firstWhere('id', $item->id);
    }

    private function sales(): SalesOrder
    {
        $customer = SalesCustomer::create(['customer_code' => $this->code('CUSTOMER'), 'customer_name' => '旧快照采购关联测试客户', 'status' => 'enabled']);
        return SalesOrder::create(['sales_order_no' => $this->code('SO'), 'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name, 'order_status' => 'confirmed', 'confirm_status' => 'confirmed',
            'currency' => 'CNY', 'total_amount' => '1000', 'purchase_link_version' => 1, 'order_date' => now()->toDateString()]);
    }

    private function purchase(): PurchaseOrderItem
    {
        $supplier = Supplier::create(['supplier_code' => $this->code('SUPPLIER'), 'supplier_name' => '旧快照采购关联测试供应商', 'supplier_type' => 'manufacturer', 'status' => 'enabled']);
        $unit = Unit::create(['unit_code' => $this->code('UNIT'), 'unit_name' => '根', 'decimal_places' => 8, 'status' => 'enabled']);
        $item = Item::create(['item_code' => $this->code('ITEM'), 'item_name' => '旧快照采购关联测试管材', 'spec' => '40x40x2', 'item_type' => 'raw_material', 'unit_id' => $unit->id, 'status' => 'enabled']);
        $purchase = PurchaseOrder::create(['purchase_order_no' => $this->code('PO'), 'supplier_id' => $supplier->id,
            'order_date' => now()->toDateString(), 'purchase_status' => 'processing', 'audit_status' => 'approved', 'currency' => 'CNY', 'total_amount' => '100']);
        return PurchaseOrderItem::create(['order_id' => $purchase->id, 'item_id' => $item->id, 'purchase_unit_id' => $unit->id, 'base_unit_id' => $unit->id,
            'purchase_qty' => '10', 'order_qty' => '10', 'amount' => '100', 'contract_amount_snapshot' => '100', 'currency_snapshot' => 'CNY']);
    }

    private function code(string $prefix): string { return 'SOLINK-RR-'.$prefix.'-'.Str::upper(Str::random(10)); }
}
