<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\{Bom, InventoryBalance, InventoryTransaction, Item, Location, Product, PurchaseLog, PurchaseOrder, PurchasePlan, PurchaseReceipt, PurchaseReceiptItem, PurchaseRequest, Sku, SkuItemRelation, Supplier, Unit, Warehouse};
use App\Services\Erp\{AuthContextService, ConsoleDashboardQueryService};
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConsoleDashboardQueryTest extends TestCase
{
    use DatabaseTransactions;

    private Unit $unit;
    private Item $item;
    private Supplier $supplier;
    private array $permissions = [];
    private bool $super = true;
    private bool $loggedIn = true;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-05 16:00:00', 'Asia/Shanghai'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-05 16:00:00', 'Asia/Shanghai'));
        $this->unit = Unit::create(['unit_code' => $this->code(), 'unit_name' => '件', 'status' => 'enabled']);
        $this->item = Item::create(['item_code' => $this->code(), 'item_name' => '控制台查询物料', 'unit_id' => $this->unit->id, 'status' => 'enabled', 'is_stock_item' => true]);
        $this->supplier = Supplier::create(['supplier_code' => $this->code(), 'supplier_name' => '控制台查询供应商', 'status' => 'enabled']);
        $this->mock(AuthContextService::class, function ($mock) {
            $mock->shouldReceive('currentUser')->andReturnUsing(fn () => $this->loggedIn ? (object) ['legacy_id' => 1] : null);
            $mock->shouldReceive('isSuperAdmin')->andReturnUsing(fn () => $this->super);
            $mock->shouldReceive('permissionCodes')->andReturnUsing(fn () => $this->permissions);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_drafts_do_not_become_approval_todos_and_receipt_counts_real_distinct_items(): void
    {
        $this->order(['purchase_status' => 'draft', 'audit_status' => 'pending']);
        $this->order(['purchase_status' => 'submitted', 'audit_status' => 'approved']);
        $submitted = $this->order(['purchase_status' => 'submitted', 'audit_status' => 'pending']);
        PurchasePlan::create(['plan_no' => $this->code(), 'plan_date' => '2026-10-05', 'plan_status' => 'draft', 'audit_status' => 'pending']);
        $plan = PurchasePlan::create(['plan_no' => $this->code(), 'plan_date' => '2026-10-05', 'plan_status' => 'submitted', 'audit_status' => 'pending']);
        $receipt = $this->receipt();
        $this->line($receipt, $this->item);
        $other = Item::create(['item_code' => $this->code(), 'item_name' => '第二种物料', 'unit_id' => $this->unit->id, 'status' => 'enabled']);
        $this->line($receipt, $other);
        $this->receipt(['confirm_status' => 'draft']);
        $this->receipt(['stock_post_status' => 'posted']);
        $this->receipt(['receipt_status' => 'cancelled']);
        $this->receipt([], ['final_stockable_base_qty' => 0]);
        PurchaseLog::create(['target_type' => 'purchase_receipt', 'target_id' => $receipt->id, 'action' => 'confirm', 'operator' => '库管', 'content' => '确认到货', 'created_at' => '2026-10-05 14:42:00']);
        $data = $this->getJson('/api/v1/erp/console/dashboard')->assertOk()->json('data');
        $this->assertSame(['requests' => 0, 'plans' => 1, 'orders' => 1, 'receipts' => 1, 'adjustments' => 0, 'boms' => 0], $data['counts']);
        $this->assertSame(3, $data['total_todo']);
        $this->assertSame(3, $data['todos']['total']);
        $rows = collect($data['todos']['data'])->keyBy('kind');
        $this->assertSame('2种物料等待库存过账', $rows['receipts']['summary']);
        $this->assertSame('库管', $rows['receipts']['owner']);
        $this->assertSame('2026-10-05T14:42:00+08:00', $rows['receipts']['time']);
        $this->assertSame($receipt->id, $rows['receipts']['to']['query']['receipt_id']);
        $this->assertSame("/purchase/orders/{$submitted->id}/detail", $rows['orders']['to']['path']);
        $this->assertSame("/purchase/plans/{$plan->id}/detail", $rows['plans']['to']['path']);
        $this->assertFalse(collect($data['warnings'])->contains('title', '到货长时间未过账'));
    }

    public function test_full_aggregation_pagination_priority_and_soft_deletion_remain_consistent(): void
    {
        $deleted = PurchaseRequest::create(['request_no' => $this->code(), 'request_date' => '2026-10-05', 'item_id' => $this->item->id, 'request_qty' => 1, 'request_status' => 'draft']);
        $deleted->delete();
        for ($i = 0; $i < 105; $i++) $this->order(['purchase_status' => 'submitted', 'audit_status' => 'pending']);
        $data = $this->getJson('/api/v1/erp/console/dashboard?type=orders&page=5&per_page=25')->assertOk()->json('data');
        $this->assertSame(105, $data['counts']['orders']);
        $this->assertSame(105, $data['total_todo']);
        $this->assertSame(0, $data['counts']['requests']);
        $this->assertSame(105, $data['todos']['total']);
        $this->assertCount(5, $data['todos']['data']);
        $this->assertSame(5, $data['todos']['current_page']);
        $this->assertSame(105, $data['purchase']['orders_month']);
        $this->assertSame(0, $data['purchase']['requests_month']);
        $this->assertSame(0, $this->getJson('/api/v1/erp/console/dashboard?priority=high')->assertOk()->json('data.todos.total'));
    }

    public function test_monthly_money_excludes_drafts_cancelled_and_previous_month_and_keeps_currencies_separate(): void
    {
        $this->order(['purchase_status' => 'approved', 'audit_status' => 'approved', 'total_amount' => '100', 'currency' => 'CNY']);
        $this->order(['purchase_status' => 'approved', 'audit_status' => 'approved', 'total_amount' => '50', 'currency' => 'USD', 'receipt_status' => 'partial']);
        $this->order(['purchase_status' => 'draft', 'audit_status' => 'pending', 'total_amount' => '900']);
        $this->order(['purchase_status' => 'cancelled', 'audit_status' => 'approved', 'total_amount' => '700']);
        $this->order(['purchase_status' => 'received', 'audit_status' => 'approved', 'receipt_status' => 'received', 'total_amount' => '88', 'created_at' => '2026-09-01 09:00:00']);
        PurchaseRequest::create(['request_no' => $this->code(), 'request_date' => '2026-09-30', 'item_id' => $this->item->id, 'request_qty' => 1, 'request_status' => 'planned', 'created_at' => '2026-09-30 23:55:00']);
        $data = $this->dashboard();
        $this->assertSame(0, $data['purchase']['requests_month']);
        $this->assertSame(4, $data['purchase']['orders_month']);
        $this->assertSame(2, $data['purchase']['awaiting_delivery']);
        $amounts = collect($data['purchase']['approved_amounts_month'])->pluck('amount', 'currency')->all();
        $this->assertSame(['CNY' => '100.0000', 'USD' => '50.0000'], $amounts);
        $this->assertCount(30, $data['trends']['purchase']);
        $this->assertSame(2, array_sum(array_column($data['trends']['purchase'], 'count')));
        $this->assertSame(0, array_sum(array_column($data['trends']['inventory'], 'count')));
    }

    public function test_priority_filter_uses_real_document_priority_and_does_not_invent_receipt_urgency(): void
    {
        foreach (['low', 'normal', 'high', 'urgent'] as $priority) PurchaseRequest::create(['request_no' => $this->code(), 'request_date' => '2026-10-05', 'item_id' => $this->item->id, 'request_qty' => 1, 'request_status' => 'draft', 'priority' => $priority]);
        $this->receipt();
        $data = $this->getJson('/api/v1/erp/console/dashboard?priority=high')->assertOk()->json('data');
        $this->assertSame(5, $data['total_todo']);
        $this->assertSame(2, $data['todos']['total']);
        $this->assertSame(['requests', 'requests'], array_column($data['todos']['data'], 'kind'));
        $this->assertEqualsCanonicalizing(['高', '紧急'], array_column($data['todos']['data'], 'priority'));
        $receipt = collect($this->dashboard()['todos']['data'])->firstWhere('kind', 'receipts');
        $this->assertSame('未设置', $receipt['priority']);
    }

    public function test_stock_units_business_day_and_recent_confirmation_are_real_facts(): void
    {
        $warehouse = Warehouse::create(['warehouse_code' => $this->code(), 'warehouse_name' => '查询仓库', 'status' => 'enabled']);
        $location = Location::create(['warehouse_id' => $warehouse->id, 'location_code' => $this->code(), 'location_name' => '查询库位', 'status' => 'enabled']);
        $kg = Unit::create(['unit_code' => $this->code(), 'unit_name' => 'kg', 'status' => 'enabled']);
        foreach ([$this->unit->id => 2, $kg->id => 3.5] as $unit => $qty) InventoryBalance::create(['item_id' => $this->item->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'batch_no' => $this->code(), 'unit_id' => $unit, 'quantity_on_hand' => $qty]);
        foreach (['2026-10-04 23:50:00', '2026-10-05 00:10:00'] as $index => $time) InventoryTransaction::create(['transaction_no' => $this->code(), 'transaction_type' => 'purchase_receipt_posting', 'source_type' => 'purchase_receipt', 'source_id' => 99 + $index, 'posting_status' => 'posted', 'posted_at' => $time, 'posted_by' => 1, 'transaction_date' => substr($time, 0, 10)]);
        InventoryTransaction::create(['transaction_no' => $this->code(), 'transaction_type' => 'purchase_receipt_posting', 'source_type' => 'purchase_receipt', 'source_id' => 101, 'posting_status' => 'draft', 'posted_at' => '2026-10-05 01:00:00', 'transaction_date' => '2026-10-05']);
        $receipt = $this->receipt();
        PurchaseLog::create(['target_type' => 'purchase_receipt', 'target_id' => $receipt->id, 'action' => 'confirm', 'operator' => '采购员', 'content' => '确认到货', 'created_at' => '2026-10-05 15:00:00']);
        $data = $this->dashboard();
        $quantities = collect($data['inventory']['quantity_by_unit'])->pluck('quantity', 'unit_name')->all();
        $this->assertEquals(['件' => 2, 'kg' => 3.5], $quantities);
        $this->assertSame(1, $data['inventory']['today_transactions']);
        $this->assertSame(1, $data['inventory']['today_in']);
        $this->assertSame(2, array_sum(array_column($data['trends']['inventory'], 'count')));
        $this->assertSame($receipt->receipt_no, $data['recent'][0]['object']);
        $this->assertSame('2026-10-05T15:00:00+08:00', $data['recent'][0]['time']);
    }

    public function test_permissions_are_enforced_server_side_and_unavailable_metrics_are_not_zero(): void
    {
        $this->order(['purchase_status' => 'submitted', 'audit_status' => 'pending']);
        $this->receipt();
        $this->super = false;
        $this->permissions = ['inventory.post.view'];
        $data = $this->getJson('/api/v1/erp/console/dashboard')->assertOk()->json('data');
        $this->assertNull($data['counts']['orders']);
        $this->assertSame(1, $data['counts']['receipts']);
        $this->assertSame(1, $data['total_todo']);
        $this->assertSame(['receipts'], array_column($data['todos']['data'], 'kind'));
        $this->assertNull($data['purchase']['approved_amounts_month']);
        $this->assertNull($data['master']['products']);
        $this->assertNull($data['trends']['purchase']);
        $this->permissions = ['master.product.view', 'master.sku.view', 'master.item.view'];
        $data = $this->getJson('/api/v1/erp/console/dashboard')->assertOk()->json('data');
        $this->assertIsInt($data['master']['items']);
        $this->assertSame(0, $data['todos']['total']);
        $this->assertNull($data['master']['relation_rate']);
        $this->loggedIn = false;
        $this->getJson('/api/v1/erp/console/dashboard')->assertUnauthorized();
    }

    public function test_bom_todos_and_valid_defaults_exclude_archived_and_expired_versions(): void
    {
        $baseline = $this->dashboard()['bom'];
        $product = Product::create(['product_code' => $this->code(), 'product_name' => '查询产品', 'unit_id' => $this->unit->id, 'status' => 'enabled']);
        $sku = Sku::create(['product_id' => $product->id, 'sku_code' => $this->code(), 'sku_name' => '实物SKU', 'sales_unit_id' => $this->unit->id, 'order_line_type' => 'physical', 'fulfillment_type' => 'physical', 'is_need_bom' => true, 'status' => 'enabled']);
        SkuItemRelation::create(['sku_id' => $sku->id, 'item_id' => $this->item->id, 'unit_id' => $this->unit->id, 'qty' => 1, 'status' => 'active', 'is_primary' => true]);
        $make = fn ($overrides) => Bom::create([...['bom_no' => $this->code(), 'bom_name' => '查询BOM', 'product_id' => $product->id, 'sku_id' => $sku->id, 'output_item_id' => $this->item->id, 'version' => 'V1', 'status' => 'draft', 'audit_status' => 'pending', 'is_default' => false], ...$overrides]);
        $make([]);
        $make(['submitted_at' => '2026-10-05 10:00:00']);
        $make(['status' => 'inactive', 'audit_status' => 'approved']);
        $make(['status' => 'archived', 'audit_status' => 'approved']);
        $make(['status' => 'active', 'audit_status' => 'approved', 'is_default' => true, 'expire_date' => '2026-10-04']);
        $valid = $make(['status' => 'active', 'audit_status' => 'approved', 'is_default' => true, 'expire_date' => '2026-10-10']);
        $data = $this->dashboard();
        $this->assertSame(2, $data['counts']['boms']);
        $this->assertSame(2, $data['todos']['total']);
        $this->assertSame($baseline['active'] + 1, $data['bom']['active']);
        $this->assertSame($baseline['expiring'] + 1, $data['bom']['expiring']);
        $this->assertSame($baseline['defaults'] + 1, $data['bom']['defaults']);
        $this->assertSame($baseline['missing_defaults'], $data['bom']['missing_defaults']);
        $valid->update(['expire_date' => '2026-10-04']);
        $this->assertSame($baseline['missing_defaults'] + 1, $this->dashboard()['bom']['missing_defaults']);
        $make(['sku_id' => null, 'product_id' => null, 'status' => 'active', 'audit_status' => 'approved', 'is_default' => true]);
        $this->assertSame($baseline['missing_defaults'], $this->dashboard()['bom']['missing_defaults']);
    }

    public function test_relation_rate_counts_physical_skus_once_and_excludes_inactive_and_secondary_bindings(): void
    {
        $before = $this->dashboard()['master'];
        $product = Product::create(['product_code' => $this->code(), 'product_name' => '关系产品', 'unit_id' => $this->unit->id, 'status' => 'enabled']);
        $make = fn ($type) => Sku::create(['product_id' => $product->id, 'sku_code' => $this->code(), 'sku_name' => '关系SKU', 'sales_unit_id' => $this->unit->id, 'order_line_type' => $type, 'fulfillment_type' => $type === 'service' ? 'virtual' : 'physical', 'status' => 'enabled']);
        $normal = $make('physical'); $abnormal = $make('physical'); $make('service');
        $bind = fn ($sku, $item) => SkuItemRelation::create(['sku_id' => $sku->id, 'item_id' => $item->id, 'unit_id' => $this->unit->id, 'qty' => 1, 'status' => 'active', 'is_primary' => true]);
        $bind($normal, $this->item);
        $other = Item::create(['item_code' => $this->code(), 'item_name' => '停用关系物料', 'unit_id' => $this->unit->id, 'status' => 'disabled']);
        $bind($abnormal, $other);
        SkuItemRelation::create(['sku_id' => $normal->id, 'item_id' => $other->id, 'unit_id' => $this->unit->id, 'qty' => 1, 'status' => 'inactive', 'is_primary' => true]);
        SkuItemRelation::create(['sku_id' => $abnormal->id, 'item_id' => $this->item->id, 'unit_id' => $this->unit->id, 'qty' => 1, 'status' => 'active', 'is_primary' => false]);
        $data = $this->dashboard()['master'];
        $this->assertSame($before['physical_skus'] + 2, $data['physical_skus']);
        $this->assertSame($before['configured_skus'] + 1, $data['configured_skus']);
        $this->assertSame($before['abnormal_skus'] + 1, $data['abnormal_skus']);
        $this->assertSame($before['missing_skus'], $data['missing_skus']);
    }

    private function dashboard(): array { return app(ConsoleDashboardQueryService::class)->dashboard([], [], true); }
    private function code(): string { return 'CON-'.Str::ulid(); }
    private function order(array $extra = []): PurchaseOrder
    {
        return PurchaseOrder::create([...['purchase_order_no' => $this->code(), 'supplier_id' => $this->supplier->id, 'order_date' => '2026-10-05', 'purchase_status' => 'draft', 'audit_status' => 'pending', 'receipt_status' => 'not_received', 'currency' => 'CNY', 'total_amount' => 100], ...$extra]);
    }
    private function receipt(array $extra = [], array $line = []): PurchaseReceipt
    {
        $receipt = PurchaseReceipt::create([...['receipt_no' => $this->code(), 'supplier_id' => $this->supplier->id, 'receipt_date' => '2026-10-05', 'receipt_status' => 'confirmed', 'confirm_status' => 'confirmed', 'stock_post_status' => 'pending'], ...$extra]);
        $this->line($receipt, $this->item, $line);
        return $receipt;
    }
    private function line(PurchaseReceipt $receipt, Item $item, array $extra = []): void
    {
        PurchaseReceiptItem::create([...['receipt_id' => $receipt->id, 'item_id' => $item->id, 'receipt_qty' => 1, 'qualified_qty' => 1, 'is_stock_item_snapshot' => true, 'final_stockable_base_qty' => 1], ...$extra]);
    }
}
