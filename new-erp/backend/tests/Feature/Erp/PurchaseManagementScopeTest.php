<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\{InventoryAlert, Item, PurchaseOrder, PurchasePlan, PurchaseReceipt, PurchaseRequest, PurchaseRequestItem, SalesOrder, Supplier, Unit, Warehouse};
use App\Services\Erp\{AuthContextService, DocumentNumberService, InventoryAlertApplicationService, PurchaseRequestCreationApplicationService, PurchaseWorkflowApplicationService, SalesOrderPurchaseLinkApplicationService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchaseManagementScopeTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;
    private Unit $unit;
    private Item $factory;
    private Item $office;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'PSC-'.Str::upper(Str::random(8));
        $this->unit = Unit::create(['unit_code' => $this->code('U'), 'unit_name' => '件', 'unit_type' => 'quantity',
            'status' => 'enabled', 'is_legacy' => false, 'decimal_places' => 0]);
        $this->factory = $this->item('factory');
        $this->office = $this->item('office');
        $this->supplier = Supplier::create(['supplier_code' => $this->code('S'), 'supplier_name' => $this->code('供应商'),
            'status' => 'enabled', 'approval_status' => 'approved']);
        $this->mock(AuthContextService::class, function ($mock): void {
            $mock->shouldReceive('currentUser')->andReturn((object) ['legacy_id' => 99, 'nickname' => '范围测试员', 'username' => 'purchase-scope']);
            $mock->shouldReceive('currentLegacyId')->andReturn(99);
            $mock->shouldReceive('isSuperAdmin')->andReturn(true);
            $mock->shouldReceive('permissionCodes')->andReturn(['purchase.request.create', 'purchase.request.edit',
                'purchase.plan.create', 'purchase.plan.edit', 'purchase.plan.approve', 'purchase.order.approve']);
        });
        $this->mock(DocumentNumberService::class, fn ($mock) => $mock->shouldReceive('next')->andReturnUsing(fn () => $this->code('N')));
    }

    public function test_old_clients_infer_the_complete_document_scope_for_all_four_draft_types(): void
    {
        foreach (['requests', 'plans', 'orders', 'receipts'] as $kind) {
            foreach ([$this->office, $this->factory] as $item) {
                $document = $this->create($kind, $item);
                $this->assertSame($item->management_scope, $document['management_scope']);
                if ($kind === 'receipts') $this->assertSame($item->management_scope, $document['items'][0]['management_scope_snapshot']);
            }
        }
    }

    public function test_explicit_empty_invalid_and_cross_scope_headers_are_rejected_without_drafts_or_logs(): void
    {
        foreach (['requests', 'plans', 'orders', 'receipts'] as $kind) {
            $before = DB::table($this->table($kind))->count();
            $logs = DB::table('erp_purchase_logs')->count();
            foreach ([null, '', 'unknown', 'factory', ['office']] as $scope) {
                $this->postJson($this->url($kind), [...$this->payload($kind, $this->office), 'management_scope' => $scope])->assertUnprocessable();
            }
            $this->assertSame($before, DB::table($this->table($kind))->count());
            $this->assertSame($logs, DB::table('erp_purchase_logs')->count());
        }
    }

    public function test_a_mixed_material_document_cannot_be_created_even_when_the_header_is_omitted(): void
    {
        foreach (['requests', 'plans', 'orders', 'receipts'] as $kind) {
            $payload = $this->payload($kind, $this->office);
            $payload['items'][] = $this->payload($kind, $this->factory)['items'][0];
            $before = DB::table($this->table($kind))->count();
            $this->postJson($this->url($kind), $payload)->assertUnprocessable()->assertJsonValidationErrors('management_scope');
            $this->assertSame($before, DB::table($this->table($kind))->count());
        }
    }

    public function test_existing_scope_cannot_be_changed_by_replacing_items_or_an_explicit_header(): void
    {
        foreach (['requests', 'plans', 'orders', 'receipts'] as $kind) {
            $document = $this->create($kind, $this->office);
            $before = DB::table($this->lineTable($kind))->where($this->foreignKey($kind), $document['id'])->get()->toJson();
            $replacement = $this->payload($kind, $this->factory);
            $this->putJson($this->url($kind).'/'.$document['id'], $replacement)->assertUnprocessable();
            $this->putJson($this->url($kind).'/'.$document['id'], [...$replacement, 'management_scope' => 'factory'])->assertUnprocessable();
            $this->assertDatabaseHas($this->table($kind), ['id' => $document['id'], 'management_scope' => 'office']);
            $this->assertSame($before, DB::table($this->lineTable($kind))->where($this->foreignKey($kind), $document['id'])->get()->toJson());
        }
    }

    public function test_lists_apply_scope_before_pagination_and_omitted_filter_keeps_both_scopes(): void
    {
        $outsideCode = 'UNRELATED-'.Str::upper(Str::random(10));
        $outside = Item::create(['item_code' => $outsideCode, 'item_name' => '无关工厂物料', 'management_scope' => 'factory',
            'item_type' => 'raw_material', 'unit_id' => $this->unit->id, 'is_purchase_item' => true, 'is_stock_item' => false, 'status' => 'enabled']);
        $outsideSupplier = Supplier::create(['supplier_code' => $outsideCode, 'supplier_name' => '无关供应商', 'status' => 'enabled', 'approval_status' => 'approved']);
        foreach (['requests', 'plans', 'orders', 'receipts'] as $kind) {
            $office = $this->create($kind, $this->office);
            $factory = $this->create($kind, $this->factory);
            $outsidePayload = $this->payload($kind, $outside);
            $numberField = match ($kind) { 'requests' => 'request_no', 'plans' => 'plan_no', 'orders' => 'purchase_order_no', 'receipts' => 'receipt_no' };
            $outsidePayload[$numberField] = $outsideCode;
            if (isset($outsidePayload['supplier_id'])) $outsidePayload['supplier_id'] = $outsideSupplier->id;
            $this->postJson($this->url($kind), $outsidePayload)->assertCreated();
            $query = '?keyword='.$this->prefix;
            $this->getJson($this->url($kind).$query.'&management_scope=office')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $office['id']);
            $this->getJson($this->url($kind).$query.'&management_scope=factory')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $factory['id']);
            $this->getJson($this->url($kind).$query)->assertOk()->assertJsonPath('total', 2);
            foreach (['', 'all', 'unknown'] as $scope) $this->getJson($this->url($kind).$query.'&management_scope='.$scope)->assertUnprocessable();
        }
    }

    public function test_same_supplier_receives_separate_office_and_factory_orders_through_the_real_request_plan_chain(): void
    {
        $orders = [];
        foreach ([$this->office, $this->factory] as $item) {
            $request = $this->create('requests', $item);
            $this->postJson($this->url('requests').'/'.$request['id'].'/submit')->assertOk();
            $plan = $this->postJson($this->url('requests').'/'.$request['id'].'/to-plan')->assertOk()->json('data');
            $this->assertSame($item->management_scope, $plan['management_scope']);
            $line = $plan['items'][0];
            $this->putJson($this->url('plans').'/'.$plan['id'], ['items' => [[...$line, 'splits' => [$this->split()]]]])->assertOk();
            $this->postJson($this->url('plans').'/'.$plan['id'].'/submit')->assertOk();
            app(PurchaseWorkflowApplicationService::class)->approvePlan($plan['id'], '范围测试员');
            $order = $this->postJson($this->url('plans').'/'.$plan['id'].'/generate-orders')->assertOk()->json('data.0');
            $this->assertSame($item->management_scope, $order['management_scope']);
            $this->assertSame($this->supplier->id, $order['supplier_id']);
            $this->assertSame($item->id, $order['items'][0]['item_id']);
            $orders[] = $order;
        }
        $this->assertNotSame($orders[0]['id'], $orders[1]['id']);
        $this->assertSame(2, PurchaseOrder::whereIn('id', array_column($orders, 'id'))->count());
    }

    public function test_production_and_sales_sourced_requests_cannot_include_office_items(): void
    {
        foreach (['production_material', 'sales_order', 'work_order'] as $source) {
            $this->postJson($this->url('requests'), [...$this->payload('requests', $this->office), 'source_type' => $source])->assertUnprocessable();
        }
        $request = app(PurchaseRequestCreationApplicationService::class)->create([
            'request_no' => $this->code('PROD'), 'source_type' => 'production_material', 'management_scope' => 'factory',
        ], $this->payload('requests', $this->factory)['items']);
        $this->assertSame('factory', $request->management_scope);
        $this->putJson($this->url('requests').'/'.$request->id, [...$this->payload('requests', $this->office), 'source_type' => 'manual'])->assertUnprocessable();
        $this->assertSame('production_material', $request->fresh()->source_type);
    }

    public function test_plan_sources_with_unresolved_scope_cannot_be_hidden_by_an_office_header(): void
    {
        $request = $this->create('requests', $this->office);
        PurchaseRequest::whereKey($request['id'])->update(['management_scope' => null]);
        $payload = $this->payload('plans', $this->office);
        $payload['management_scope'] = 'office';
        $payload['items'][0]['request_id'] = $request['id'];
        $payload['items'][0]['request_item_id'] = $request['items'][0]['id'];
        $before = PurchasePlan::count();
        $this->postJson($this->url('plans'), $payload)->assertUnprocessable();
        $this->assertSame($before, PurchasePlan::count());
    }

    public function test_request_confirmation_rechecks_the_current_locked_item_before_advancing(): void
    {
        $request = $this->create('requests', $this->factory);
        $this->factory->update(['management_scope' => 'office']);
        $logs = DB::table('erp_purchase_logs')->count();
        $this->postJson($this->url('requests').'/'.$request['id'].'/submit')->assertUnprocessable();
        $this->assertDatabaseHas('erp_purchase_requests', ['id' => $request['id'], 'request_status' => 'draft', 'management_scope' => 'factory']);
        $this->assertSame($logs, DB::table('erp_purchase_logs')->count());
    }

    public function test_plan_submit_approve_and_generate_recheck_material_scope_and_roll_back_status_changes(): void
    {
        $payload = $this->payload('plans', $this->office);
        $payload['items'][0]['splits'] = [$this->split()];
        $plan = $this->postJson($this->url('plans'), $payload)->assertCreated()->json('data');
        $this->office->update(['management_scope' => 'factory']);
        $this->postJson($this->url('plans').'/'.$plan['id'].'/submit')->assertUnprocessable();
        $this->assertSame('draft', PurchasePlan::findOrFail($plan['id'])->plan_status);
        $this->office->update(['management_scope' => 'office']);
        $this->postJson($this->url('plans').'/'.$plan['id'].'/submit')->assertOk();
        $this->office->update(['management_scope' => 'factory']);
        $this->postJson($this->url('plans').'/'.$plan['id'].'/approve')->assertUnprocessable();
        $this->assertSame('pending', PurchasePlan::findOrFail($plan['id'])->audit_status);
        $this->office->update(['management_scope' => 'office']);
        $this->postJson($this->url('plans').'/'.$plan['id'].'/approve')->assertOk();
        $this->office->update(['management_scope' => 'factory']);
        $this->postJson($this->url('plans').'/'.$plan['id'].'/generate-orders')->assertUnprocessable();
        $this->assertSame(0, PurchaseOrder::where('plan_id', $plan['id'])->count());
        $this->assertSame('not_ordered', PurchasePlan::findOrFail($plan['id'])->order_status);
    }

    public function test_order_submit_approve_and_receipt_generation_recheck_current_item_scope(): void
    {
        $document = $this->create('orders', $this->factory);
        $workflow = app(PurchaseWorkflowApplicationService::class);
        $this->factory->update(['management_scope' => 'office']);
        $this->postJson($this->url('orders').'/'.$document['id'].'/submit')->assertUnprocessable();
        $this->assertSame('draft', PurchaseOrder::findOrFail($document['id'])->purchase_status);
        $this->factory->update(['management_scope' => 'factory']);
        $workflow->submitOrder($document['id'], '范围测试员');
        $this->factory->update(['management_scope' => 'office']);
        $this->postJson($this->url('orders').'/'.$document['id'].'/approve')->assertUnprocessable();
        $this->assertSame('pending', PurchaseOrder::findOrFail($document['id'])->audit_status);
        $this->factory->update(['management_scope' => 'factory']);
        $workflow->approveOrder($document['id'], '范围测试员');
        $this->factory->update(['management_scope' => 'office']);
        $this->postJson($this->url('orders').'/'.$document['id'].'/to-receipt')->assertUnprocessable();
        $this->assertSame(0, PurchaseReceipt::where('order_id', $document['id'])->count());
    }

    public function test_receipt_inherits_order_scope_and_freezes_every_line_scope(): void
    {
        $order = $this->create('orders', $this->office);
        $workflow = app(PurchaseWorkflowApplicationService::class);
        $workflow->submitOrder($order['id'], '范围测试员');
        $workflow->approveOrder($order['id'], '范围测试员');
        $payload = $this->payload('receipts', $this->office);
        $payload['order_id'] = $order['id'];
        $payload['management_scope'] = 'factory';
        $payload['items'][0]['order_item_id'] = $order['items'][0]['id'];
        $this->postJson($this->url('receipts'), $payload)->assertUnprocessable();
        $receipt = $this->postJson($this->url('orders').'/'.$order['id'].'/to-receipt')->assertOk()->json('data');
        $this->assertSame('office', $receipt['management_scope']);
        $this->assertSame('office', $receipt['items'][0]['management_scope_snapshot']);
        $this->assertFalse((bool) $receipt['items'][0]['is_stock_item_snapshot']);
        $this->putJson($this->url('receipts').'/'.$receipt['id'], [...$this->payload('receipts', $this->office), 'order_id' => null])->assertUnprocessable();
        $this->assertSame($order['id'], PurchaseReceipt::findOrFail($receipt['id'])->order_id);
    }

    public function test_historical_mixed_request_remains_readable_and_cancellable_but_cannot_be_confirmed(): void
    {
        $request = $this->historicalMixedRequest();
        $this->getJson($this->url('requests').'/'.$request->id)->assertOk()->assertJsonPath('management_scope', null)->assertJsonCount(2, 'items');
        $this->postJson($this->url('requests').'/'.$request->id.'/submit')->assertUnprocessable();
        $this->postJson($this->url('requests').'/'.$request->id.'/cancel')->assertOk()->assertJsonPath('data.request_status', 'cancelled');
        $this->assertSame(2, $request->items()->count());
        $this->assertNull($request->fresh()->management_scope);
    }

    public function test_an_unresolved_draft_can_be_corrected_into_a_single_scope_without_defaulting_to_factory(): void
    {
        $request = $this->historicalMixedRequest();
        $this->putJson($this->url('requests').'/'.$request->id, $this->payload('requests', $this->office))->assertOk()
            ->assertJsonPath('data.management_scope', 'office')->assertJsonCount(1, 'data.items');
        $this->postJson($this->url('requests').'/'.$request->id.'/submit')->assertOk();
    }

    public function test_historical_null_order_cannot_advance_but_cancellation_preserves_its_original_lines(): void
    {
        $order = $this->create('orders', $this->office);
        PurchaseOrder::whereKey($order['id'])->update(['management_scope' => null]);
        $this->getJson($this->url('orders').'/'.$order['id'])->assertOk()->assertJsonPath('management_scope', null);
        $this->postJson($this->url('orders').'/'.$order['id'].'/submit')->assertUnprocessable();
        $this->postJson($this->url('orders').'/'.$order['id'].'/cancel')->assertOk()->assertJsonPath('data.purchase_status', 'cancelled');
        $this->assertNull(PurchaseOrder::findOrFail($order['id'])->management_scope);
        $this->assertDatabaseHas('erp_purchase_order_items', ['id' => $order['items'][0]['id'], 'item_id' => $this->office->id]);
    }

    public function test_wrong_scope_warehouses_are_rejected_during_request_plan_order_and_stock_receipt_saving(): void
    {
        $warehouse = Warehouse::create(['warehouse_code' => $this->code('WH'), 'warehouse_name' => '工厂仓', 'status' => 'active', 'management_scope' => 'factory']);
        foreach (['requests', 'plans', 'orders'] as $kind) {
            $payload = $this->payload($kind, $this->office);
            $payload['items'][0][$kind === 'orders' ? 'target_warehouse_id' : 'warehouse_id'] = $warehouse->id;
            $this->postJson($this->url($kind), $payload)->assertUnprocessable();
        }
        $stockItem = $this->item('office', true);
        $payload = $this->payload('receipts', $stockItem);
        $payload['items'][0]['warehouse_id'] = $warehouse->id;
        $this->postJson($this->url('receipts'), $payload)->assertUnprocessable();
    }

    public function test_editing_a_receipt_does_not_reclassify_its_frozen_nonstock_line(): void
    {
        $receipt = $this->create('receipts', $this->office);
        $before = DB::table('erp_purchase_receipt_items')->where('receipt_id', $receipt['id'])->get()->toJson();
        $this->office->update(['is_stock_item' => true]);
        $payload = $this->payload('receipts', $this->office);
        $payload['items'][0]['id'] = $receipt['items'][0]['id'];
        $this->putJson($this->url('receipts').'/'.$receipt['id'], $payload)->assertUnprocessable();
        $this->assertSame($before, DB::table('erp_purchase_receipt_items')->where('receipt_id', $receipt['id'])->get()->toJson());
    }

    public function test_defect_rows_return_frozen_document_scope_and_filter_without_reclassifying_history(): void
    {
        $documents = [];
        foreach ([$this->office, $this->factory] as $item) {
            $payload = $this->payload('receipts', $item);
            $payload['items'][0]['qualified_qty'] = 0;
            $payload['items'][0]['unqualified_qty'] = 2;
            $receipt = $this->postJson($this->url('receipts'), $payload)->assertCreated()->json('data');
            PurchaseReceipt::whereKey($receipt['id'])->update(['confirm_status' => 'confirmed']);
            $documents[] = $receipt;
        }
        $url = $this->url('defect-handlings').'?receipt_no='.$this->prefix;
        $this->getJson($url.'&management_scope=office')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.management_scope', 'office')->assertJsonPath('data.0.receipt_id', $documents[0]['id']);
        $this->getJson($url.'&management_scope=factory')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.management_scope', 'factory');
        PurchaseReceipt::whereKey($documents[0]['id'])->update(['management_scope' => null]);
        $rows = $this->getJson($url)->assertOk()->assertJsonPath('total', 2)->json('data');
        $unknown = collect($rows)->firstWhere('receipt_id', $documents[0]['id']);
        $this->assertNull($unknown['management_scope']);
        $this->assertSame('office', $unknown['management_scope_snapshot']);
        $this->getJson($url.'&management_scope=')->assertUnprocessable();
    }

    public function test_sales_purchase_attribution_rejects_office_orders_and_freezes_factory_scope(): void
    {
        $sales = SalesOrder::create(['sales_order_no' => $this->code('SO'), 'order_status' => 'confirmed', 'purchase_link_version' => 0]);
        $service = app(SalesOrderPurchaseLinkApplicationService::class);
        foreach ([$this->office, $this->factory] as $item) {
            $order = $this->create('orders', $item);
            app(PurchaseWorkflowApplicationService::class)->submitOrder($order['id'], '范围测试员');
            app(PurchaseWorkflowApplicationService::class)->approveOrder($order['id'], '范围测试员');
            $payload = ['purchase_order_item_id' => $order['items'][0]['id'], 'purchase_qty' => 1,
                'version' => 0, 'idempotency_key' => (string) Str::uuid(), 'reason' => '订单材料采购'];
            if ($item->management_scope === 'office') {
                try {
                    $service->add($sales->id, $payload, '范围测试员');
                    $this->fail('办公用品采购不得归属销售订单');
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey('purchase_order_item_id', $exception->errors());
                }
                $this->assertSame(0, DB::table('erp_sales_order_purchase_links')->where('sales_order_id', $sales->id)->count());
                $this->assertSame(0, (int) $sales->fresh()->purchase_link_version);
            } else {
                $link = $service->add($sales->id, $payload, '范围测试员');
                $this->assertSame('factory', $link->source_snapshot['management_scope']);
                $this->assertSame(1, (int) $sales->fresh()->purchase_link_version);
            }
        }
    }

    public function test_inventory_alert_creates_an_office_request_and_reuses_only_a_valid_scoped_source(): void
    {
        $item = $this->item('office', true);
        $warehouse = Warehouse::create(['warehouse_code' => $this->code('WH'), 'warehouse_name' => '办公仓', 'status' => 'enabled', 'management_scope' => 'office']);
        $alert = InventoryAlert::create(['item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'is_active' => true, 'suggested_replenishment_qty_snapshot' => 2]);
        $service = app(InventoryAlertApplicationService::class);
        $request = $service->createPurchaseRequestFromAlert($alert->id, 99);
        $this->assertSame('office', $request->management_scope);
        $this->assertSame($warehouse->id, $request->items->first()->warehouse_id);
        $this->assertSame($request->id, $service->createPurchaseRequestFromAlert($alert->id, 99)->id);
        $warehouse->update(['management_scope' => 'factory']);
        try {
            $service->createPurchaseRequestFromAlert($alert->id, 99);
            $this->fail('失配仓库不可继续沿用申购来源');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('warehouse_id', $exception->errors());
        }
        $this->assertSame(1, PurchaseRequest::where('source_type', 'inventory_alert')->where('source_id', (string) $alert->id)->count());
    }

    private function historicalMixedRequest(): PurchaseRequest
    {
        $request = PurchaseRequest::create(['request_no' => $this->code('HIST'), 'item_id' => $this->factory->id,
            'management_scope' => null, 'source_type' => 'manual', 'request_status' => 'draft', 'status' => 'draft', 'request_qty' => 2, 'planned_qty' => 0]);
        foreach ([$this->factory, $this->office] as $item) PurchaseRequestItem::create(['request_id' => $request->id,
            'item_id' => $item->id, 'unit_id' => $this->unit->id, 'request_qty' => 1, 'remaining_qty' => 1]);
        return $request;
    }

    private function create(string $kind, Item $item): array
    {
        return $this->postJson($this->url($kind), $this->payload($kind, $item))->assertCreated()->json('data');
    }

    private function payload(string $kind, Item $item): array
    {
        $line = ['item_id' => $item->id, 'purchase_unit_id' => $this->unit->id];
        return match ($kind) {
            'requests' => ['request_no' => $this->code('R'), 'items' => [[...$line, 'request_qty' => 2]]],
            'plans' => ['plan_no' => $this->code('P'), 'items' => [[...$line, 'required_qty' => 2]]],
            'orders' => ['purchase_order_no' => $this->code('O'), 'supplier_id' => $this->supplier->id, 'items' => [[...$line, 'order_qty' => 2, 'unit_price' => 10]]],
            'receipts' => ['receipt_no' => $this->code('C'), 'supplier_id' => $this->supplier->id, 'items' => [[...$line, 'receipt_qty' => 2, 'unit_price' => 10]]],
        };
    }

    private function split(): array { return ['supplier_id' => $this->supplier->id, 'purchase_qty' => 2, 'unit_price' => 10, 'purchase_unit_id' => $this->unit->id]; }
    private function url(string $kind): string { return '/api/v1/erp/purchase/'.$kind; }
    private function table(string $kind): string { return 'erp_purchase_'.$kind; }
    private function lineTable(string $kind): string { return 'erp_purchase_'.rtrim($kind, 's').'_items'; }
    private function foreignKey(string $kind): string { return rtrim($kind, 's').'_id'; }
    private function code(string $kind): string { return $this->prefix.'-'.$kind.'-'.Str::upper(Str::random(5)); }

    private function item(string $scope, bool $stock = false): Item
    {
        return Item::create(['item_code' => $this->code('I'), 'item_name' => $this->code('物料'), 'management_scope' => $scope,
            'item_type' => $scope === 'office' ? 'office_consumable' : 'raw_material', 'unit_id' => $this->unit->id,
            'is_purchase_item' => true, 'is_stock_item' => $stock, 'is_production_item' => $scope === 'factory', 'status' => 'enabled']);
    }
}
