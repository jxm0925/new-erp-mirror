<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\{FinanceAccount, FinanceCashDocument, FinanceCashPurchaseAllocation, Item, ItemCategory, PurchaseOrder, PurchaseOrderItem, SalesCustomer, SalesOrder, SalesOrderLog, SalesOrderPurchaseLink, SalesShipment, Supplier, Unit};
use App\Services\Erp\AuthContextService;
use App\Services\Erp\SalesOrderFinanceQueryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesOrderFinanceLinkTest extends TestCase
{
    use DatabaseTransactions;
    private array $permissions = ['sales_order.view', 'sales_order.amount.view', 'finance.view', 'purchase.order.edit'];
    private string $scope = 'all';
    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'SOFIN-'.Str::upper(Str::random(10));
        $this->mock(AuthContextService::class, function ($mock): void {
            $mock->shouldReceive('currentUser')->andReturn((object) ['legacy_id' => 71, 'nickname' => '销售采购关联测试员']);
            $mock->shouldReceive('isSuperAdmin')->andReturn(false);
            $mock->shouldReceive('permissionCodes')->andReturnUsing(fn () => $this->permissions);
            $mock->shouldReceive('dataScope')->andReturnUsing(fn () => $this->scope);
            $mock->shouldReceive('departmentUserIds')->andReturn([71, 72]);
        });
    }

    public function test_several_procurements_link_to_one_order_and_one_item_can_split_without_double_counting(): void
    {
        $first = $this->sales(); $second = $this->sales(); $a = $this->purchase('10', '100'); $b = $this->purchase('5', '200');
        $firstLink = $this->add($first, $a, '4')->assertOk()->assertJsonMissingPath('data.contract_amount')->json('data.id');
        $secondLink = $this->add($second, $a, '6')->assertOk()->assertJsonMissingPath('data.contract_amount')->json('data.id');
        $this->assertSame('40.0000', SalesOrderPurchaseLink::findOrFail($firstLink)->contract_amount);
        $this->assertSame('60.0000', SalesOrderPurchaseLink::findOrFail($secondLink)->contract_amount);
        $this->add($first, $b, '5', 2)->assertOk();
        $this->getJson($this->url($first))->assertOk()->assertJsonPath('data.purchase_order_count', 2)
            ->assertJsonMissingPath('data.procurement_by_currency')
            ->assertJsonMissingPath('data.cost_facts')->assertJsonMissingPath('data.coverage_notes');
        $this->getJson($this->url($first).'/purchase-links')->assertOk()
            ->assertJsonMissingPath('data.0.contract_amount')->assertJsonMissingPath('data.0.source_snapshot.source_contract_amount')
            ->assertJsonMissingPath('data.0.source_snapshot.currency');
        $this->assertSame(3, SalesOrderLog::where('action', 'purchase_link_add')->count());
        $this->assertSame(0, DB::table('erp_finance_account_movements')->count());
    }

    public function test_over_assignment_stale_version_and_nonformal_orders_do_not_write(): void
    {
        $order = $this->sales(); $item = $this->purchase();
        $this->add($order, $item, '11')->assertUnprocessable()->assertJsonValidationErrors('purchase_qty');
        $this->add($order, $item, '4')->assertOk();
        $this->add($order, $item, '1')->assertUnprocessable()->assertJsonValidationErrors('version');
        $other = $this->sales(); $this->add($other, $item, '7')->assertUnprocessable()->assertJsonValidationErrors('purchase_qty');
        $other->update(['order_status' => 'draft']);
        $this->add($other, $item, '1')->assertUnprocessable()->assertJsonValidationErrors('sales_order_id');
        $item->order->update(['audit_status' => 'pending']);
        $this->add($order, $item, '1', 2)->assertUnprocessable()->assertJsonValidationErrors('purchase_order_item_id');
        $this->assertSame(1, SalesOrderPurchaseLink::count());
    }

    public function test_retry_and_reversal_are_idempotent_and_quantity_becomes_available_with_audit(): void
    {
        $order = $this->sales(); $other = $this->sales(); $item = $this->purchase(); $key = (string) Str::uuid();
        $link = $this->add($order, $item, '10', 1, $key)->assertOk()->json('data.id');
        $this->add($order, $item, '10.00000000', 1, $key)->assertOk()->assertJsonPath('data.id', $link);
        $this->add($order, $item, '9', 1, $key)->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $url = $this->url($order).'/purchase-links/'.$link.'/reverse';
        $this->postJson($url, ['version' => 2, 'reason' => '采购用途调整'])->assertOk();
        $this->postJson($url, ['version' => 2, 'reason' => '采购用途调整'])->assertOk();
        $this->postJson($url, ['version' => 3, 'reason' => '覆盖原原因'])->assertUnprocessable();
        $this->add($other, $item, '10')->assertOk();
        $this->assertSame(1, SalesOrderLog::where('action', 'purchase_link_reverse')->count());
        $this->assertSame('reversed', SalesOrderPurchaseLink::find($link)->status);
    }

    public function test_rounding_tail_is_assigned_once_and_unknown_price_is_not_zero(): void
    {
        $item = $this->purchase('3', '1');
        foreach (range(1, 3) as $i) $this->add($this->sales(), $item, '1')->assertOk();
        $this->assertSame('1.0000', SalesOrderPurchaseLink::where('purchase_order_item_id', $item->id)->sum('contract_amount'));
        $unknown = $this->purchase('10', null); $order = $this->sales();
        $linkId = $this->add($order, $unknown, '2')->assertOk()->assertJsonMissingPath('data.contract_amount')->json('data.id');
        $this->assertNull(SalesOrderPurchaseLink::findOrFail($linkId)->contract_amount);
        $this->getJson($this->url($order))->assertOk()->assertJsonMissingPath('data.procurement_by_currency');
    }

    public function test_money_permissions_and_personal_and_department_scope_apply_to_every_endpoint(): void
    {
        $own = $this->sales(); $hidden = $this->sales(99); $item = $this->purchase();
        $this->scope = 'self';
        foreach (['', '/purchase-links', '/purchase-payments', '/purchase-candidates', '/purchase-categories'] as $suffix) {
            $this->getJson($this->url($hidden).$suffix)->assertNotFound();
        }
        $this->add($hidden, $item, '1')->assertNotFound();
        $this->getJson('/api/v1/erp/finance/sales-order-statistics')->assertOk()->assertJsonPath('total', 1);
        $department = $this->sales(72); $this->scope = 'department';
        $this->getJson($this->url($department))->assertOk();
        $this->getJson($this->url($hidden))->assertNotFound();
        $this->permissions = ['sales_order.view', 'finance.view'];
        $this->getJson($this->url($own))->assertForbidden();
        $this->getJson('/api/v1/erp/finance/sales-order-statistics')->assertForbidden();
        $this->permissions = ['sales_order.view', 'finance.view', 'sales_order.amount.view'];
        $this->getJson($this->url($own))->assertOk();
        $this->add($own, $item, '1')->assertForbidden();
    }

    public function test_full_filtered_quantity_summary_is_not_page_total_and_exposes_no_procurement_amounts(): void
    {
        foreach (range(1, 3) as $i) {
            $order = $this->sales(); $item = $this->purchase('10', '100', $i === 3 ? 'USD' : 'CNY');
            $this->add($order, $item, '10')->assertOk();
        }
        $this->sales();
        $statisticsUrl = '/api/v1/erp/finance/sales-order-statistics?keyword='.$this->prefix;
        $response = $this->getJson($statisticsUrl.'&per_page=1&purchase_link_status=linked')->assertOk()->assertJsonPath('total', 3);
        $response->assertJsonMissingPath('summary_by_currency')->assertJsonPath('summary.order_count', 3)
            ->assertJsonPath('summary.linked_order_count', 3)->assertJsonPath('summary.purchase_order_count', 3)
            ->assertJsonPath('summary.link_count', 3)->assertJsonMissingPath('data.0.cost_facts')
            ->assertJsonMissingPath('data.0.procurement_by_currency');
        $this->getJson($statisticsUrl.'&purchase_link_status=unlinked')->assertOk()->assertJsonPath('total', 1);
        $this->getJson($statisticsUrl.'&order_date_start=2099-01-01')->assertOk()->assertJsonPath('total', 0)
            ->assertJsonPath('summary.order_count', 0)->assertJsonPath('summary.link_count', 0);
    }

    public function test_picker_search_category_and_pagination_use_real_purchase_units_and_available_quantities(): void
    {
        $order = $this->sales(); $item = $this->purchase();
        $category = ItemCategory::create(['category_type' => 'item', 'category_code' => $this->code('CAT'), 'category_name' => '管材', 'status' => 'enabled']);
        $child = ItemCategory::create(['category_type' => 'item', 'category_code' => $this->code('CHILD'), 'category_name' => '矩形管', 'parent_id' => $category->id, 'status' => 'enabled']);
        $item->item->update(['category_id' => $child->id]);
        $this->add($order, $item, '3')->assertOk();
        $this->getJson($this->url($order).'/purchase-candidates?keyword=管材&category_id='.$category->id.'&per_page=1')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.available_purchase_qty', '7.00000000')->assertJsonPath('data.0.purchase_unit_name', '根')
            ->assertJsonMissingPath('data.0.contract_amount')->assertJsonMissingPath('data.0.currency')
            ->assertJsonMissingPath('data.0.amount')->assertJsonMissingPath('data.0.unit_price');
        $this->getJson($this->url($order).'/purchase-candidates?keyword=不存在的规格')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_linked_order_cash_is_deduplicated_and_draft_or_superseded_cash_is_excluded(): void
    {
        $order = $this->sales(); $item = $this->purchase();
        $this->add($order, $item, '2')->assertOk(); $this->add($order, $item, '3', 2)->assertOk();
        $account = FinanceAccount::create(['account_no' => $this->code('ACCOUNT'), 'account_name' => '测试账户', 'account_type' => 'bank', 'currency' => 'CNY', 'status' => 'enabled']);
        foreach (['confirmed', 'draft', 'voided'] as $state) {
            $cash = FinanceCashDocument::create(['direction' => 'payment', 'document_no' => $this->code('CASH'), 'party_type' => 'supplier', 'party_id' => $item->order->supplier_id,
                'party_name_snapshot' => '测试供应商', 'finance_account_id' => $account->id, 'business_date' => now()->toDateString(), 'currency' => 'CNY', 'amount' => '100', 'payment_method' => 'bank', 'status' => $state]);
            FinanceCashPurchaseAllocation::create(['cash_document_id' => $cash->id, 'purchase_order_id' => $item->order_id, 'allocation_version' => 1, 'purpose_key' => 'order',
                'direction' => 'payment', 'currency' => 'CNY', 'amount' => '100', 'status' => 'active', 'purchase_order_no_snapshot' => $item->order->purchase_order_no]);
        }
        $payments = app(SalesOrderFinanceQueryService::class)->purchasePayments($order->id, 20);
        $this->assertSame(1, $payments->total());
        $this->assertSame('100.0000', $payments->items()[0]->amount);
        $this->getJson($this->url($order).'/purchase-payments')->assertNotFound();
        $this->getJson($this->url($order))->assertOk()->assertJsonMissingPath('data.cost_facts')
            ->assertJsonMissingPath('data.procurement_by_currency');
    }

    public function test_conflict_refresh_queries_exact_eligible_item_and_validates_its_identity(): void
    {
        $order = $this->sales(); $first = $this->purchase(); $second = $this->purchase();
        $url = $this->url($order).'/purchase-candidates?purchase_order_item_id=';
        $this->getJson($url.$first->id.'&per_page=1')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $first->id);
        $second->order->update(['purchase_status' => 'cancelled']);
        $this->getJson($url.$second->id)->assertOk()->assertJsonPath('total', 0);
        $this->getJson($url.'999999999')->assertOk()->assertJsonPath('total', 0);
        $this->getJson($url.'0')->assertUnprocessable()->assertJsonValidationErrors('purchase_order_item_id');
    }

    public function test_shipment_count_is_visible_while_internal_cost_facts_remain_stored(): void
    {
        $order = $this->sales();
        SalesShipment::create(['shipment_no' => $this->code('SHIP'), 'sales_order_id' => $order->id, 'shipment_status' => 'outbound_posted', 'actual_cost_amount' => 120, 'actual_freight_amount' => 10]);
        SalesShipment::create(['shipment_no' => $this->code('DRAFTSHIP'), 'sales_order_id' => $order->id, 'shipment_status' => 'draft', 'actual_cost_amount' => 999]);
        $this->getJson($this->url($order))->assertOk()->assertJsonPath('data.posted_shipment_count', 1)
            ->assertJsonMissingPath('data.cost_facts')->assertJsonMissingPath('data.work_order_count');
        $this->assertDatabaseHas('erp_sales_shipments', ['sales_order_id' => $order->id, 'actual_cost_amount' => 120, 'actual_freight_amount' => 10]);
    }

    private function sales(int $owner = 71): SalesOrder
    {
        $customer = SalesCustomer::create(['customer_code' => $this->code('CUSTOMER'), 'customer_name' => '成本关联测试客户', 'status' => 'enabled']);
        return SalesOrder::create(['sales_order_no' => $this->code('SO'), 'customer_id' => $customer->id, 'customer_name' => $customer->customer_name,
            'sales_user_legacy_id' => $owner, 'order_status' => 'confirmed', 'confirm_status' => 'confirmed', 'currency' => 'CNY', 'total_amount' => 1000, 'order_date' => now()->toDateString()]);
    }
    private function purchase(string $qty = '10', ?string $amount = '100', string $currency = 'CNY'): PurchaseOrderItem
    {
        $supplier = Supplier::create(['supplier_code' => $this->code('SUPPLIER'), 'supplier_name' => '测试供应商', 'supplier_type' => 'manufacturer', 'status' => 'enabled']);
        $unit = Unit::create(['unit_code' => $this->code('UNIT'), 'unit_name' => '根', 'decimal_places' => 8, 'status' => 'enabled']);
        $item = Item::create(['item_code' => $this->code('ITEM'), 'item_name' => '采购管材', 'spec' => '40x40x2', 'item_type' => 'raw_material', 'unit_id' => $unit->id, 'status' => 'enabled']);
        $purchase = PurchaseOrder::create(['purchase_order_no' => $this->code('PO'), 'supplier_id' => $supplier->id, 'management_scope' => 'factory', 'order_date' => now()->toDateString(), 'purchase_status' => 'processing', 'audit_status' => 'approved', 'currency' => $currency, 'total_amount' => $amount ?? 0]);
        return PurchaseOrderItem::create(['order_id' => $purchase->id, 'item_id' => $item->id, 'purchase_unit_id' => $unit->id, 'base_unit_id' => $unit->id,
            'purchase_qty' => $qty, 'order_qty' => $qty, 'amount' => $amount ?? 0, 'contract_amount_snapshot' => $amount, 'currency_snapshot' => $currency]);
    }
    private function add(SalesOrder $order, PurchaseOrderItem $item, string $qty, int $version = 1, ?string $key = null)
    {
        return $this->postJson($this->url($order).'/purchase-links', ['version' => $version, 'purchase_order_item_id' => $item->id, 'purchase_qty' => $qty,
            'idempotency_key' => $key ?? (string) Str::uuid(), 'reason' => '本批采购用于该销售订单']);
    }
    private function url(SalesOrder $order): string { return '/api/v1/erp/sales/orders/'.$order->id.'/finance'; }
    private function code(string $prefix): string { return $this->prefix.'-'.$prefix.'-'.Str::upper(Str::random(10)); }
}
