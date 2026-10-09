<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\{Item, ItemCategory, PurchaseOrder, PurchaseOrderItem, PurchasePlan, PurchasePlanItem, PurchaseRequest, PurchaseRequestItem, SalesCustomer, SalesOrder, Supplier, Unit};
use App\Services\Erp\AuthContextService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesOrderPurchaseCandidateScopeTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;
    private Unit $unit;
    private Supplier $supplier;
    private SalesOrder $sales;
    private array $permissions = ['sales_order.view', 'sales_order.amount.view', 'finance.view', 'purchase.order.edit'];
    private string $dataScope = 'all';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringEndsWith('_test', strtolower((string) DB::connection()->getDatabaseName()));
        $this->prefix = 'SOCS-'.Str::upper(Str::random(8));
        $this->unit = Unit::create(['unit_code' => $this->code('UNIT'), 'unit_name' => '件', 'decimal_places' => 8, 'status' => 'enabled']);
        $this->supplier = Supplier::create(['supplier_code' => $this->code('SUPPLIER'), 'supplier_name' => $this->code('供应商'),
            'supplier_type' => 'manufacturer', 'status' => 'enabled', 'approval_status' => 'approved']);
        $this->sales = $this->salesOrder();
        $this->mock(AuthContextService::class, function ($mock): void {
            $mock->shouldReceive('currentUser')->andReturn((object) ['legacy_id' => 71, 'nickname' => '销售范围测试员']);
            $mock->shouldReceive('isSuperAdmin')->andReturn(false);
            $mock->shouldReceive('permissionCodes')->andReturnUsing(fn () => $this->permissions);
            $mock->shouldReceive('dataScope')->andReturnUsing(fn () => $this->dataScope);
            $mock->shouldReceive('departmentUserIds')->andReturn([71, 72]);
        });
    }

    public function test_approved_office_and_unknown_historical_orders_are_excluded_before_pagination(): void
    {
        $factoryA = $this->purchase('factory', $this->item('factory'));
        $factoryB = $this->purchase('factory', $this->item('factory'));
        $office = $this->purchase('office', $this->item('office'));
        $unknown = $this->purchase(null, $this->item('factory'));
        $mixed = $this->purchase(null, $this->item('factory'));
        $mixedOffice = $this->line($mixed->order, $this->item('office'));
        $cancelled = $this->purchase('factory', $this->item('factory'), ['purchase_status' => 'cancelled']);
        $unapproved = $this->purchase('factory', $this->item('factory'), ['audit_status' => 'pending']);

        $this->getJson($this->candidateUrl(['keyword' => $this->prefix, 'per_page' => 1]))->assertOk()
            ->assertJsonPath('total', 2)->assertJsonPath('per_page', 1)->assertJsonPath('data.0.id', $factoryB->id)
            ->assertJsonPath('data.0.available_purchase_qty', '10.00000000');
        $this->getJson($this->candidateUrl(['keyword' => $this->prefix, 'per_page' => 1, 'page' => 2]))->assertOk()
            ->assertJsonPath('total', 2)->assertJsonPath('data.0.id', $factoryA->id);
        foreach ([$office, $unknown, $mixed, $mixedOffice, $cancelled, $unapproved] as $excluded) {
            $this->getJson($this->candidateUrl(['purchase_order_item_id' => $excluded->id]))->assertOk()->assertJsonPath('total', 0);
        }
        $this->assertNull($unknown->order->fresh()->management_scope);
        $this->assertNull($mixed->order->fresh()->management_scope);
        $this->assertSame('office', $office->order->fresh()->management_scope);
    }

    public function test_a_factory_header_with_a_changed_office_line_excludes_the_whole_order(): void
    {
        $first = $this->purchase('factory', $this->item('factory'));
        $changed = $this->line($first->order, $this->item('factory'));
        $changed->item->update(['management_scope' => 'office']);

        $this->getJson($this->candidateUrl(['keyword' => $this->prefix]))->assertOk()->assertJsonPath('total', 0);
        foreach ([$first, $changed] as $line) {
            $this->getJson($this->candidateUrl(['purchase_order_item_id' => $line->id]))->assertOk()->assertJsonPath('total', 0);
        }
        $this->assertSame('factory', $first->order->fresh()->management_scope);
        $this->assertSame('office', $changed->item->fresh()->management_scope);
    }

    public function test_category_tree_and_descendant_filter_keep_only_factory_categories(): void
    {
        $factoryRoot = $this->category('factory');
        $factoryChild = $this->category('factory', $factoryRoot);
        $officeRoot = $this->category('office');
        $officeChild = $this->category('office', $officeRoot);
        $factory = $this->purchase('factory', $this->item('factory', $factoryChild));
        $this->purchase('office', $this->item('office', $officeChild));
        // A historical bad category reference must not make an office category
        // a valid filter in the factory-only sales picker.
        $this->purchase('factory', $this->item('factory', $officeChild));

        $categories = $this->getJson($this->url().'/purchase-categories')->assertOk()->json('data');
        $ids = collect($categories)->pluck('id')->all();
        $this->assertContains($factoryRoot->id, $ids);
        $this->assertContains($factoryChild->id, $ids);
        $this->assertNotContains($officeRoot->id, $ids);
        $this->assertNotContains($officeChild->id, $ids);
        $this->getJson($this->candidateUrl(['keyword' => $this->prefix, 'category_id' => $factoryRoot->id]))->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $factory->id);
        foreach ([$officeRoot->id, $officeChild->id, 999999999] as $excludedCategory) {
            $this->getJson($this->candidateUrl(['keyword' => $this->prefix, 'category_id' => $excludedCategory]))->assertOk()->assertJsonPath('total', 0);
        }
    }

    public function test_factory_orders_with_unknown_request_or_plan_sources_are_excluded(): void
    {
        $item = $this->item('factory');
        $factoryRequest = $this->requestLine('factory', $item);
        $unknownRequest = $this->requestLine(null, $item);
        $factoryPlan = $this->planLine('factory', $item, $factoryRequest);
        $unknownPlan = $this->planLine(null, $item);
        $unknownRequestPlan = $this->planLine('factory', $item, $unknownRequest);

        $valid = $this->purchase('factory', $item, ['plan_id' => $factoryPlan->plan_id]);
        $valid->update(['plan_item_id' => $factoryPlan->id]);
        $fromUnknownRequest = $this->purchase('factory', $item);
        $fromUnknownRequest->update(['request_item_id' => $unknownRequest->id]);
        $fromUnknownPlan = $this->purchase('factory', $item);
        $fromUnknownPlan->update(['plan_item_id' => $unknownPlan->id]);
        $fromUnknownAncestor = $this->purchase('factory', $item, ['plan_id' => $unknownRequestPlan->plan_id]);
        $fromUnknownAncestor->update(['plan_item_id' => $unknownRequestPlan->id]);

        $this->getJson($this->candidateUrl(['keyword' => $this->prefix, 'per_page' => 1]))->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $valid->id);
        foreach ([$fromUnknownRequest, $fromUnknownPlan, $fromUnknownAncestor] as $excluded) {
            $this->getJson($this->candidateUrl(['purchase_order_item_id' => $excluded->id]))->assertOk()->assertJsonPath('total', 0);
        }
        $this->assertNull($unknownRequest->request->fresh()->management_scope);
        $this->assertNull($unknownPlan->plan->fresh()->management_scope);
    }

    public function test_candidate_and_category_endpoints_keep_existing_permission_and_sales_visibility_guards(): void
    {
        $factory = $this->purchase('factory', $this->item('factory'));
        $hidden = $this->salesOrder(99);
        $this->dataScope = 'self';
        foreach (['purchase-candidates', 'purchase-categories'] as $endpoint) {
            $this->getJson($this->url($hidden).'/'.$endpoint)->assertNotFound();
        }
        $this->getJson($this->candidateUrl(['purchase_order_item_id' => $factory->id]))->assertOk()->assertJsonPath('total', 1);
        $this->permissions = ['sales_order.view', 'sales_order.amount.view', 'finance.view'];
        foreach (['purchase-candidates', 'purchase-categories'] as $endpoint) {
            $this->getJson($this->url().'/'.$endpoint)->assertForbidden();
        }
    }

    private function purchase(?string $scope, Item $item, array $extra = []): PurchaseOrderItem
    {
        $order = PurchaseOrder::create([
            'management_scope' => $scope, 'purchase_order_no' => $this->code('PO'), 'supplier_id' => $this->supplier->id,
            'order_date' => now()->toDateString(), 'purchase_status' => 'processing', 'audit_status' => 'approved',
            'currency' => 'CNY', 'total_amount' => '100',
            ...$extra,
        ]);
        return $this->line($order, $item);
    }

    private function line(PurchaseOrder $order, Item $item): PurchaseOrderItem
    {
        return PurchaseOrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'purchase_unit_id' => $this->unit->id,
            'base_unit_id' => $this->unit->id, 'purchase_qty' => '10', 'order_qty' => '10', 'amount' => '100',
            'contract_amount_snapshot' => '100', 'currency_snapshot' => 'CNY']);
    }

    private function item(string $scope, ?ItemCategory $category = null): Item
    {
        return Item::create(['item_code' => $this->code('ITEM'), 'item_name' => $this->code('物料'), 'management_scope' => $scope,
            'item_type' => $scope === 'office' ? 'office_consumable' : 'raw_material', 'unit_id' => $this->unit->id,
            'category_id' => $category?->id, 'is_purchase_item' => true, 'is_stock_item' => false, 'status' => 'enabled']);
    }

    private function category(string $scope, ?ItemCategory $parent = null): ItemCategory
    {
        return ItemCategory::create(['category_code' => $this->code('CATEGORY'), 'category_name' => $this->code('类目'),
            'category_type' => 'item', 'management_scope' => $scope, 'parent_id' => $parent?->id, 'status' => 'enabled']);
    }

    private function requestLine(?string $scope, Item $item): PurchaseRequestItem
    {
        $request = PurchaseRequest::create(['request_no' => $this->code('REQUEST'), 'management_scope' => $scope,
            'item_id' => $item->id, 'request_qty' => '10', 'source_type' => 'manual', 'request_status' => 'confirmed']);
        return PurchaseRequestItem::create(['request_id' => $request->id, 'item_id' => $item->id, 'request_qty' => '10',
            'remaining_qty' => '10', 'unit_id' => $this->unit->id]);
    }

    private function planLine(?string $scope, Item $item, ?PurchaseRequestItem $request = null): PurchasePlanItem
    {
        $plan = PurchasePlan::create(['plan_no' => $this->code('PLAN'), 'management_scope' => $scope,
            'plan_status' => 'confirmed', 'audit_status' => 'approved']);
        return PurchasePlanItem::create(['plan_id' => $plan->id, 'item_id' => $item->id, 'plan_qty' => '10',
            'request_item_id' => $request?->id, 'unit_id' => $this->unit->id]);
    }

    private function salesOrder(int $owner = 71): SalesOrder
    {
        $customer = SalesCustomer::create(['customer_code' => $this->code('CUSTOMER'), 'customer_name' => $this->code('客户'), 'status' => 'enabled']);
        return SalesOrder::create(['sales_order_no' => $this->code('SO'), 'customer_id' => $customer->id, 'customer_name' => $customer->customer_name,
            'sales_user_legacy_id' => $owner, 'order_status' => 'confirmed', 'confirm_status' => 'confirmed', 'currency' => 'CNY',
            'total_amount' => '1000', 'order_date' => now()->toDateString()]);
    }

    private function url(?SalesOrder $order = null): string { return '/api/v1/erp/sales/orders/'.($order ?? $this->sales)->id.'/finance'; }
    private function candidateUrl(array $filters): string { return $this->url().'/purchase-candidates?'.http_build_query($filters); }
    private function code(string $suffix): string { return $this->prefix.'-'.$suffix.'-'.Str::upper(Str::random(6)); }
}
