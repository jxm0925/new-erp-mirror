<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\{Item, ItemPurchaseConversion, PurchaseOrder, PurchasePlan, PurchasePlanSupplierSplit, Supplier, Unit};
use App\Services\Erp\{AuthContextService, DocumentNumberService, PurchaseConversionApplicationService, PurchaseDraftDeletionApplicationService, PurchasePlanningConversionService, PurchaseWorkflowApplicationService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchasePlanningConversionTest extends TestCase
{
    use DatabaseTransactions;

    private Item $item;
    private Unit $base;
    private Unit $root;
    private ItemPurchaseConversion $conversion;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = Unit::create(['unit_code' => $this->code('M'), 'unit_name' => '米', 'decimal_places' => 3, 'status' => 'enabled']);
        $this->root = Unit::create(['unit_code' => $this->code('ROOT'), 'unit_name' => '根', 'decimal_places' => 0, 'status' => 'enabled']);
        $this->item = Item::create(['item_code' => $this->code('ITEM'), 'item_name' => '按根采购测试管材', 'item_type' => 'raw_material', 'unit_id' => $this->base->id, 'is_purchase_item' => true, 'is_stock_item' => true, 'status' => 'enabled']);
        $this->conversion = ItemPurchaseConversion::create(['item_id' => $this->item->id, 'purchase_unit_id' => $this->root->id, 'base_unit_id' => $this->base->id, 'factor' => 6, 'is_default' => true, 'allow_actual_conversion' => false, 'status' => 'active', 'effective_from' => now()->subDay(), 'change_reason' => '测试']);
        $this->supplier = $this->supplier();
        $this->mock(AuthContextService::class, function ($mock) {
            $mock->shouldReceive('currentUser')->andReturn((object) ['legacy_id' => 1, 'nickname' => '采购测试', 'username' => 'purchase-test']);
            $mock->shouldReceive('isSuperAdmin')->andReturn(true);
            $mock->shouldReceive('permissionCodes')->andReturn(['purchase.request.create', 'purchase.request.edit', 'purchase.plan.create', 'purchase.plan.edit', 'purchase.plan.approve']);
        });
        $this->mock(DocumentNumberService::class, fn ($mock) => $mock->shouldReceive('next')->andReturnUsing(fn () => $this->code('DOC')));
    }

    public function test_requirement_rounds_up_without_inflating_original_need(): void
    {
        foreach ([[3, 1, 6, 3], [6, 1, 6, 0], [7, 2, 12, 5], [12, 2, 12, 0]] as [$need, $purchase, $base, $excess]) {
            $snapshot = $this->calculate($need);
            $this->assertSame((float) $need, $snapshot['required_base_qty']);
            $this->assertSame((float) $purchase, $snapshot['purchase_qty']);
            $this->assertSame((float) $base, $snapshot['planned_base_qty']);
            $this->assertSame((float) $excess, $snapshot['excess_base_qty']);
        }
        $this->conversion->update(['factor' => 0.1]);
        $this->assertSame(3.0, $this->calculate(0.3)['purchase_qty']);
    }

    public function test_request_plan_order_and_edit_keep_confirmed_conversion_after_master_changes(): void
    {
        $request = $this->postJson('/api/v1/erp/purchase/requests', ['request_no' => $this->code('PR'), 'items' => [['item_id' => $this->item->id, 'request_qty' => 3, 'purchase_conversion_snapshot' => ['conversion_factor_snapshot' => 999]]]])->assertCreated()->json('data');
        $this->assertSame(6.0, (float) $request['items'][0]['purchase_conversion_snapshot']['conversion_factor_snapshot']);
        $this->postJson("/api/v1/erp/purchase/requests/{$request['id']}/submit")->assertOk();
        $this->conversion->update(['factor' => 8]);
        $plan = $this->postJson("/api/v1/erp/purchase/requests/{$request['id']}/to-plan")->assertOk()->json('data');
        $this->postJson("/api/v1/erp/purchase/requests/{$request['id']}/to-plan")->assertStatus(422);
        $line = $plan['items'][0];
        $plan = $this->putJson("/api/v1/erp/purchase/plans/{$plan['id']}", ['items' => [[...$line, 'splits' => [$this->split(3)]]]])->assertOk()->json('data');
        $split = $plan['items'][0]['splits'][0];
        $this->assertSame(3.0, (float) $split['purchase_qty']);
        $this->assertSame(6.0, (float) $split['purchase_conversion_snapshot']['planned_base_qty']);
        $this->assertSame(12.0, (float) $split['amount']);
        $this->postJson("/api/v1/erp/purchase/plans/{$plan['id']}/submit")->assertOk();
        $this->postJson("/api/v1/erp/purchase/plans/{$plan['id']}/approve")->assertOk();
        $this->conversion->update(['factor' => 9, 'status' => 'inactive']);
        $preview = $this->getJson("/api/v1/erp/purchase/plans/{$plan['id']}/orders-preview")->assertOk()->json('data.0');
        $this->assertSame(12.0, (float) $preview['total_amount']);
        $order = $this->postJson("/api/v1/erp/purchase/plans/{$plan['id']}/generate-orders")->assertOk()->json('data.0');
        $orderLine = $order['items'][0];
        $this->assertSame(1.0, (float) $orderLine['order_qty']);
        $this->assertSame(6.0, (float) $orderLine['planned_base_qty']);
        $this->assertSame(3.0, (float) $orderLine['purchase_conversion_snapshot']['excess_base_qty']);
        $this->assertSame(12.0, (float) $order['total_amount']);
        $this->assertSame($request['id'], $orderLine['request_id']);
        $this->postJson("/api/v1/erp/purchase/plans/{$plan['id']}/generate-orders")->assertStatus(422);
        $this->assertSame(1, PurchaseOrder::where('plan_id', $plan['id'])->count());
        $edited = $this->putJson("/api/v1/erp/purchase/orders/{$order['id']}", ['supplier_id' => $this->supplier->id, 'items' => [[...$orderLine, 'unit_price' => 18]]])->assertOk()->json('data');
        $this->assertSame($orderLine['id'], $edited['items'][0]['id']);
        $this->assertSame($orderLine['plan_split_id'], $edited['items'][0]['plan_split_id']);
        $this->assertSame(6.0, (float) $edited['items'][0]['conversion_factor_snapshot']);
        $this->assertSame(18.0, (float) $edited['total_amount']);
        $this->putJson("/api/v1/erp/purchase/orders/{$order['id']}", ['supplier_id' => $this->supplier->id, 'items' => [[...$orderLine, 'order_qty' => 2]]])->assertStatus(422);
        $receipt = app(PurchaseConversionApplicationService::class)->receiptLineSnapshot(['order_item_id' => $orderLine['id'], 'item_id' => $this->item->id, 'receipt_qty' => 1, 'unit_price' => 18]);
        $this->assertSame(6.0, (float) $receipt['actual_base_qty']);
    }

    public function test_split_quantities_cover_demand_while_each_supplier_rounds_independently(): void
    {
        $second = $this->supplier();
        $plan = $this->createPlan(7, [$this->split(3), $this->split(4, $second->id)]);
        $this->assertSame(7.0, (float) $plan['items'][0]['allocated_qty']);
        $snapshots = array_column($plan['items'][0]['splits'], 'purchase_conversion_snapshot');
        $this->assertSame(12.0, (float) array_sum(array_column($snapshots, 'planned_base_qty')));
        $this->assertSame(5.0, (float) array_sum(array_column($snapshots, 'excess_base_qty')));
        $this->assertSame(24.0, (float) $plan['total_amount']);
        $this->postJson("/api/v1/erp/purchase/plans/{$plan['id']}/submit")->assertOk();
    }

    public function test_manual_plan_purchase_quantity_can_exceed_suggestion_but_not_undersupply_or_split_extra_demand(): void
    {
        $plan = $this->createPlan(3, [$this->split(3) + ['purchase_quantity' => 2]]);
        $snapshot = $plan['items'][0]['splits'][0]['purchase_conversion_snapshot'];
        $this->assertSame(9.0, (float) $snapshot['excess_base_qty']);
        $this->assertSame(24.0, (float) $plan['total_amount']);
        $this->postJson('/api/v1/erp/purchase/plans', ['items' => [['item_id' => $this->item->id, 'required_qty' => 7, 'splits' => [$this->split(7) + ['purchase_quantity' => 1]]]]])->assertStatus(422);
        $this->postJson('/api/v1/erp/purchase/plans', ['items' => [['item_id' => $this->item->id, 'required_qty' => 3, 'splits' => [$this->split(4)]]]])->assertStatus(422);
    }

    public function test_direct_order_keeps_unit_selection_and_rejects_half_root(): void
    {
        $payload = ['supplier_id' => $this->supplier->id, 'items' => [['item_id' => $this->item->id, 'purchase_unit_id' => $this->root->id, 'order_qty' => 0.5, 'unit_price' => 12]]];
        $this->postJson('/api/v1/erp/purchase/orders', $payload)->assertStatus(422);
        $payload['items'][0]['order_qty'] = 2;
        $order = $this->postJson('/api/v1/erp/purchase/orders', $payload)->assertCreated()->json('data');
        $this->assertNull($order['plan_id']);
        $this->assertSame(12.0, (float) $order['items'][0]['planned_base_qty']);
        $this->conversion->update(['factor' => 8]);
        $payload['items'][0]['id'] = $order['items'][0]['id'];
        $edited = $this->putJson("/api/v1/erp/purchase/orders/{$order['id']}", $payload)->assertOk()->json('data');
        $this->assertSame(6.0, (float) $edited['items'][0]['conversion_factor_snapshot']);
        $payload['items'][0]['purchase_unit_id'] = $this->base->id;
        $payload['items'][0]['order_qty'] = 3;
        $edited = $this->putJson("/api/v1/erp/purchase/orders/{$order['id']}", $payload)->assertOk()->json('data');
        $this->assertSame(1.0, (float) $edited['items'][0]['conversion_factor_snapshot']);
        $this->assertSame(3.0, (float) $edited['items'][0]['planned_base_qty']);
    }

    public function test_deleting_draft_order_releases_original_demand_and_regeneration_keeps_snapshot(): void
    {
        $plan = $this->createPlan(3, [$this->split(3)]);
        $this->postJson("/api/v1/erp/purchase/plans/{$plan['id']}/submit")->assertOk();
        app(PurchaseWorkflowApplicationService::class)->approvePlan($plan['id'], '测试');
        $order = $this->postJson("/api/v1/erp/purchase/plans/{$plan['id']}/generate-orders")->assertOk()->json('data.0');
        app(PurchaseDraftDeletionApplicationService::class)->deleteOrder($order['id'], '测试');
        $split = PurchasePlanSupplierSplit::where('plan_id', $plan['id'])->firstOrFail();
        $this->assertSame(0.0, (float) $split->ordered_qty);
        $this->assertSame(0.0, (float) $split->planItem->ordered_qty);
        $this->conversion->update(['factor' => 12]);
        $again = $this->postJson("/api/v1/erp/purchase/plans/{$plan['id']}/generate-orders")->assertOk()->json('data.0');
        $this->assertSame(6.0, (float) $again['items'][0]['planned_base_qty']);
    }

    public function test_historical_plan_without_snapshot_is_not_silently_converted(): void
    {
        $plan = $this->createPlan(3, [$this->split(3)]);
        PurchasePlanSupplierSplit::where('plan_id', $plan['id'])->update(['purchase_conversion_snapshot' => null]);
        PurchasePlan::whereKey($plan['id'])->update(['audit_status' => 'approved', 'plan_status' => 'approved']);
        $this->postJson("/api/v1/erp/purchase/plans/{$plan['id']}/generate-orders")->assertStatus(422);
        $this->assertSame(0, PurchaseOrder::where('plan_id', $plan['id'])->count());
    }

    public function test_preview_and_save_use_same_rule_and_ignore_client_snapshot(): void
    {
        $preview = $this->postJson('/api/v1/erp/purchase/conversion-preview', ['document_type' => 'plan', 'item_id' => $this->item->id, 'required_qty' => 7, 'purchase_unit_price' => 12])->assertOk()->json('data');
        $plan = $this->createPlan(7, [$this->split(7) + ['purchase_conversion_snapshot' => ['conversion_factor_snapshot' => 999, 'purchase_qty' => 0.1]]]);
        $saved = $plan['items'][0]['splits'][0]['purchase_conversion_snapshot'];
        foreach (['purchase_qty', 'planned_base_qty', 'excess_base_qty', 'amount', 'conversion_factor_snapshot'] as $key) $this->assertEquals($preview[$key], $saved[$key]);
    }

    public function test_changed_master_between_preview_and_save_requires_review(): void
    {
        $preview = $this->postJson('/api/v1/erp/purchase/conversion-preview', ['document_type' => 'request', 'item_id' => $this->item->id, 'required_qty' => 3])->assertOk()->json('data');
        $this->conversion->update(['factor' => 8]);
        $this->postJson('/api/v1/erp/purchase/requests', ['items' => [['item_id' => $this->item->id, 'request_qty' => 3, 'expected_conversion_fingerprint' => $preview['conversion_fingerprint']]]])->assertStatus(422);
        $this->postJson('/api/v1/erp/purchase/orders', ['supplier_id' => $this->supplier->id, 'items' => [['item_id' => $this->item->id, 'order_qty' => 1, 'unit_price' => 12, 'purchase_unit_id' => $this->root->id, 'expected_conversion_factor' => 6]]])->assertStatus(422);
    }

    public function test_request_draft_edit_preserves_unit_snapshot_and_original_quantity(): void
    {
        $request = $this->postJson('/api/v1/erp/purchase/requests', ['items' => [['item_id' => $this->item->id, 'request_qty' => 3]]])->assertCreated()->json('data');
        $this->conversion->update(['factor' => 8]);
        $edited = $this->putJson("/api/v1/erp/purchase/requests/{$request['id']}", ['items' => [[...$request['items'][0], 'request_qty' => 7]]])->assertOk()->json('data');
        $snapshot = $edited['items'][0]['purchase_conversion_snapshot'];
        $this->assertSame(7.0, (float) $edited['items'][0]['request_qty']);
        $this->assertSame(6.0, (float) $snapshot['conversion_factor_snapshot']);
        $this->assertSame(2.0, (float) $snapshot['purchase_qty']);
        $this->assertSame(5.0, (float) $snapshot['excess_base_qty']);
    }

    public function test_meter_purchase_into_integer_stock_preserves_exact_base_quantity(): void
    {
        $this->conversion->update(['status' => 'inactive']);
        $this->item->update(['unit_id' => $this->root->id]);
        ItemPurchaseConversion::create(['item_id' => $this->item->id, 'purchase_unit_id' => $this->base->id, 'base_unit_id' => $this->root->id, 'factor' => 0.2, 'is_default' => true, 'status' => 'active', 'effective_from' => now()->subDay(), 'change_reason' => '测试']);
        $snapshot = $this->calculate(3);
        $this->assertSame(15.0, $snapshot['purchase_qty']);
        $this->assertSame(3.0, $snapshot['planned_base_qty']);
        $this->postJson('/api/v1/erp/purchase/orders', ['supplier_id' => $this->supplier->id, 'items' => [['item_id' => $this->item->id, 'purchase_unit_id' => $this->base->id, 'order_qty' => 3, 'unit_price' => 12]]])->assertStatus(422);
    }

    public function test_direct_unit_entry_flows_from_request_to_plan_to_order_without_recalculation(): void
    {
        $input = ['item_id' => $this->item->id, 'purchase_unit_id' => $this->root->id, 'purchase_quantity' => 1];
        $preview = $this->postJson('/api/v1/erp/purchase/conversion-preview', ['document_type' => 'request', ...$input])->assertOk()->json('data');
        $this->assertSame(6.0, (float) $preview['planned_base_qty']);
        $this->assertSame('none', $preview['rounding_rule']);
        $request = $this->postJson('/api/v1/erp/purchase/requests', ['items' => [$input]])->assertCreated()->json('data');
        $this->assertSame(6.0, (float) $request['items'][0]['request_qty']);
        $this->assertSame(1.0, (float) $request['items'][0]['purchase_conversion_snapshot']['purchase_qty']);
        $this->conversion->update(['factor' => 8, 'status' => 'inactive']);
        $input['id'] = $request['items'][0]['id'];
        $input['purchase_quantity'] = 2;
        $request = $this->putJson("/api/v1/erp/purchase/requests/{$request['id']}", ['items' => [$input]])->assertOk()->json('data');
        $this->assertSame(12.0, (float) $request['items'][0]['request_qty']);
        $this->postJson("/api/v1/erp/purchase/requests/{$request['id']}/submit")->assertOk();
        $plan = $this->postJson("/api/v1/erp/purchase/requests/{$request['id']}/to-plan")->assertOk()->json('data');
        $this->assertEquals($request['items'][0]['purchase_conversion_snapshot'], $plan['items'][0]['purchase_conversion_snapshot']);
        $line = [...$plan['items'][0], 'purchase_quantity' => 2, 'purchase_unit_id' => $this->root->id,
            'splits' => [['supplier_id' => $this->supplier->id, 'purchase_quantity' => 2, 'purchase_unit_id' => $this->root->id, 'purchase_unit_price' => 12]]];
        $splitPreview = $this->postJson('/api/v1/erp/purchase/conversion-preview', ['document_type' => 'plan', 'document_id' => $plan['id'], 'line_id' => $line['id'], 'is_split' => true,
            'item_id' => $this->item->id, 'purchase_unit_id' => $this->root->id, 'purchase_quantity' => 1])->assertOk()->json('data');
        $this->assertSame(6.0, (float) $splitPreview['planned_base_qty']);
        $plan = $this->putJson("/api/v1/erp/purchase/plans/{$plan['id']}", ['items' => [$line]])->assertOk()->json('data');
        $this->postJson("/api/v1/erp/purchase/plans/{$plan['id']}/submit")->assertOk();
        $this->postJson("/api/v1/erp/purchase/plans/{$plan['id']}/approve")->assertOk();
        $order = $this->postJson("/api/v1/erp/purchase/plans/{$plan['id']}/generate-orders")->assertOk()->json('data.0');
        $this->assertSame(2.0, (float) $order['items'][0]['order_qty']);
        $this->assertSame(12.0, (float) $order['items'][0]['planned_base_qty']);
        $this->assertSame(24.0, (float) $order['total_amount']);
        $this->assertEquals($plan['items'][0]['splits'][0]['purchase_conversion_snapshot'], $order['items'][0]['purchase_conversion_snapshot']);
    }

    public function test_direct_plan_allocation_compares_base_quantities_across_purchase_units(): void
    {
        $payload = ['items' => [['item_id' => $this->item->id, 'purchase_unit_id' => $this->root->id, 'purchase_quantity' => 2,
            'splits' => [
                ['supplier_id' => $this->supplier->id, 'purchase_unit_id' => $this->root->id, 'purchase_quantity' => 1, 'purchase_unit_price' => 12],
                ['supplier_id' => $this->supplier()->id, 'purchase_unit_id' => $this->base->id, 'purchase_quantity' => 6, 'purchase_unit_price' => 2],
            ]]]];
        $plan = $this->postJson('/api/v1/erp/purchase/plans', $payload)->assertCreated()->json('data');
        $this->assertSame(12.0, (float) $plan['items'][0]['required_qty']);
        $this->assertSame(12.0, (float) $plan['items'][0]['allocated_qty']);
        $this->assertSame(24.0, (float) $plan['total_amount']);
        $this->postJson("/api/v1/erp/purchase/plans/{$plan['id']}/submit")->assertOk();
        $payload['items'][0]['splits'][1]['purchase_quantity'] = 7;
        $this->postJson('/api/v1/erp/purchase/plans', $payload)->assertStatus(422);
        $payload['items'][0]['splits'][1]['purchase_quantity'] = 5;
        $partial = $this->postJson('/api/v1/erp/purchase/plans', $payload)->assertCreated()->json('data');
        $this->postJson("/api/v1/erp/purchase/plans/{$partial['id']}/submit")->assertStatus(422);
        foreach (['requests', 'plans'] as $type) {
            $this->postJson('/api/v1/erp/purchase/'.$type, ['items' => [['item_id' => $this->item->id, 'purchase_unit_id' => $this->root->id, 'purchase_quantity' => 0.5]]])->assertStatus(422);
        }
        $payload['items'][0]['splits'][0]['purchase_quantity'] = 0.5;
        $this->postJson('/api/v1/erp/purchase/plans', $payload)->assertStatus(422);
    }

    public function test_source_need_and_additional_purchase_survive_supplier_split_order_and_delete(): void
    {
        $request = $this->postJson('/api/v1/erp/purchase/requests', ['items' => [['item_id' => $this->item->id, 'purchase_unit_id' => $this->base->id, 'purchase_quantity' => 3]]])->assertCreated()->json('data');
        $this->postJson("/api/v1/erp/purchase/requests/{$request['id']}/submit")->assertOk();
        $plan = $this->postJson("/api/v1/erp/purchase/requests/{$request['id']}/to-plan")->assertOk()->json('data');
        $split = ['supplier_id' => $this->supplier->id, 'purchase_unit_id' => $this->root->id, 'purchase_quantity' => 1, 'purchase_unit_price' => 12];
        $line = [...$plan['items'][0], 'purchase_unit_id' => $this->root->id, 'purchase_quantity' => 2, 'splits' => [$split, $split]];
        $plan = $this->putJson("/api/v1/erp/purchase/plans/{$plan['id']}", ['items' => [$line]])->assertOk()->json('data');
        $this->assertSame(3.0, (float) $plan['items'][0]['required_qty']);
        $this->assertSame([3.0, 0.0], array_map(fn ($s) => (float) $s['purchase_qty'], $plan['items'][0]['splits']));
        $this->postJson("/api/v1/erp/purchase/plans/{$plan['id']}/submit")->assertOk();
        $this->postJson("/api/v1/erp/purchase/plans/{$plan['id']}/approve")->assertOk();
        $order = $this->postJson("/api/v1/erp/purchase/plans/{$plan['id']}/generate-orders")->assertOk()->json('data.0');
        $this->assertCount(2, $order['items']);
        $this->assertSame(24.0, (float) $order['total_amount']);
        $this->postJson("/api/v1/erp/purchase/plans/{$plan['id']}/generate-orders")->assertStatus(422);
        app(PurchaseDraftDeletionApplicationService::class)->deleteOrder($order['id'], '测试');
        $this->conversion->update(['factor' => 8]);
        $again = $this->postJson("/api/v1/erp/purchase/plans/{$plan['id']}/generate-orders")->assertOk()->json('data.0');
        $this->assertCount(2, $again['items']);
        $this->assertSame(12.0, (float) array_sum(array_column($again['items'], 'planned_base_qty')));
    }

    private function calculate(float $required): array
    {
        return app(PurchasePlanningConversionService::class)->calculate(['item_id' => $this->item->id, 'required_qty' => $required]);
    }

    private function createPlan(float $required, array $splits): array
    {
        return $this->postJson('/api/v1/erp/purchase/plans', ['plan_no' => $this->code('PL'), 'items' => [['item_id' => $this->item->id, 'required_qty' => $required, 'splits' => $splits]]])->assertCreated()->json('data');
    }

    private function split(float $required, ?int $supplier = null): array
    {
        return ['supplier_id' => $supplier ?: $this->supplier->id, 'purchase_qty' => $required, 'purchase_unit_price' => 12, 'tax_rate' => 13];
    }

    private function supplier(): Supplier
    {
        return Supplier::create(['supplier_code' => $this->code('SUP'), 'supplier_name' => '换算测试供应商', 'status' => 'enabled', 'approval_status' => 'approved', 'is_blacklisted' => false, 'cooperation_status' => 'normal', 'purchase_restricted' => false, 'quality_status' => 'normal']);
    }

    private function code(string $prefix): string { return $prefix.'-'.Str::random(16); }
}
