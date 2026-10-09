<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\{Item, Unit, Warehouse, InventoryAlert, PurchaseRequest, PurchasePlan, PurchasePlanItem, PurchaseLog};
use App\Services\Erp\{AuthContextService, DocumentNumberService, InventoryAlertApplicationService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchaseRequestLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    private Item $item;
    private array $permissions = ['purchase.request.view', 'purchase.request.edit', 'purchase.request.delete'];

    protected function setUp(): void
    {
        parent::setUp();
        $unit = Unit::create(['unit_code' => 'LIFE-'.Str::random(10), 'unit_name' => '个', 'decimal_places' => 0, 'status' => 'enabled']);
        $this->item = Item::create(['item_code' => 'LIFE-'.Str::random(10), 'item_name' => '需求生命周期测试', 'unit_id' => $unit->id, 'status' => 'enabled']);
        $this->mock(AuthContextService::class, function ($mock) {
            $mock->shouldReceive('currentUser')->andReturn((object) ['legacy_id' => 1, 'nickname' => '需求核查员', 'username' => 'request-test']);
            $mock->shouldReceive('isSuperAdmin')->andReturn(false);
            $mock->shouldReceive('permissionCodes')->andReturnUsing(fn () => $this->permissions);
        });
        $this->mock(DocumentNumberService::class, fn ($mock) => $mock->shouldReceive('next')->andReturnUsing(fn () => 'LIFE-'.Str::random(16)));
    }

    public function test_soft_delete_retains_details_and_is_only_visible_in_deleted_list(): void
    {
        $record = $this->createRequest();
        PurchaseRequest::findOrFail($record['id'])->update(['request_status' => 'closed', 'status' => 'closed', 'confirmed_at' => now(), 'closed_at' => now()]);
        $url = '/api/v1/erp/purchase/requests/'.$record['id'];
        $this->getJson($url)->assertOk()->assertJsonPath('can_edit', true);
        $this->deleteJson($url)->assertOk();
        $this->assertSoftDeleted('erp_purchase_requests', ['id' => $record['id']]);
        $this->assertDatabaseHas('erp_purchase_request_items', ['id' => $record['items'][0]['id'], 'request_id' => $record['id']]);
        $this->getJson('/api/v1/erp/purchase/requests?keyword='.$record['request_no'])->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/erp/purchase/requests?deleted=only&keyword='.$record['request_no'])->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.can_delete', false);
        $detail = $this->getJson($url.'?deleted=only')->assertOk()->assertJsonPath('can_edit', false)->json();
        $this->assertSame('需求核查员', $detail['deleted_by']);
        $this->assertEquals($record['items'][0]['purchase_conversion_snapshot'], $detail['items'][0]['purchase_conversion_snapshot']);
        $this->getJson($url)->assertNotFound();
        $this->putJson($url, $this->payload())->assertNotFound();
        $this->postJson($url.'/submit')->assertNotFound();
        $this->postJson($url.'/to-plan')->assertNotFound();
        $this->postJson('/api/v1/erp/purchase/plans', ['items' => [['item_id' => $this->item->id, 'request_id' => $record['id'], 'request_item_id' => $record['items'][0]['id'], 'purchase_quantity' => 2]]])->assertStatus(422);
        $this->deleteJson($url)->assertOk();
        $this->assertSame(1, PurchaseLog::where('target_type', 'purchase_request')->where('target_id', $record['id'])->where('action', 'soft_delete')->count());
    }

    public function test_unplanned_confirmed_closed_and_cancelled_requests_edit_back_to_draft(): void
    {
        foreach (['confirmed', 'closed', 'cancelled'] as $status) {
            $record = $this->createRequest();
            PurchaseRequest::findOrFail($record['id'])->update(['request_status' => $status, 'status' => $status, 'confirmed_by' => '原确认人', 'confirmed_at' => now(), 'closed_at' => now(), 'cancelled_at' => now()]);
            $url = '/api/v1/erp/purchase/requests/'.$record['id'];
            $this->putJson($url, $this->payload(3))->assertOk()->assertJsonPath('data.request_status', 'draft')->assertJsonPath('data.confirmed_at', null)->assertJsonPath('data.closed_at', null)->assertJsonPath('data.cancelled_at', null);
            $this->postJson($url.'/to-plan')->assertStatus(422);
            $this->postJson($url.'/submit')->assertOk();
            $this->getJson($url)->assertOk()->assertJsonPath('request_status', 'confirmed');
            $this->assertDatabaseHas('erp_purchase_logs', ['target_id' => $record['id'], 'action' => 'reopen_for_edit']);
        }
    }

    public function test_plan_line_reference_blocks_edit_and_delete_even_when_header_status_is_stale(): void
    {
        $record = $this->createRequest();
        $plan = PurchasePlan::create(['management_scope' => 'factory', 'plan_no' => 'LIFE-'.Str::random(10), 'plan_status' => 'draft']);
        PurchasePlanItem::create(['plan_id' => $plan->id, 'request_item_id' => $record['items'][0]['id'], 'item_id' => $this->item->id, 'plan_qty' => 1, 'required_qty' => 1]);
        $url = '/api/v1/erp/purchase/requests/'.$record['id'];
        $this->getJson($url)->assertOk()->assertJsonPath('can_edit', false)->assertJsonPath('can_delete', false);
        $this->putJson($url, $this->payload())->assertStatus(422);
        $this->deleteJson($url)->assertStatus(422);
        $this->assertDatabaseHas('erp_purchase_requests', ['id' => $record['id'], 'deleted_at' => null]);
    }

    public function test_deleted_records_remain_paginated_and_permissions_are_enforced(): void
    {
        $records = array_map(fn () => $this->createRequest(), range(1, 6));
        foreach ($records as $record) $this->deleteJson('/api/v1/erp/purchase/requests/'.$record['id'])->assertOk();
        $this->getJson('/api/v1/erp/purchase/requests?deleted=only&per_page=5&keyword=LIFE-')->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('total', 6);
        $this->getJson('/api/v1/erp/purchase/requests?deleted=only&per_page=5&page=2&keyword=LIFE-')->assertOk()->assertJsonCount(1, 'data');
        $this->permissions = [];
        $url = '/api/v1/erp/purchase/requests/'.$records[0]['id'];
        $this->getJson('/api/v1/erp/purchase/requests?deleted=only')->assertForbidden();
        $this->getJson($url.'?deleted=only')->assertForbidden();
        $this->putJson($url, $this->payload())->assertForbidden();
        $this->deleteJson($url)->assertForbidden();
    }

    public function test_active_inventory_alert_can_recreate_a_deleted_request_without_reviving_it(): void
    {
        $warehouse = Warehouse::create(['management_scope' => 'factory', 'warehouse_code' => 'LIFE-'.Str::random(10), 'warehouse_name' => '需求生命周期测试仓', 'status' => 'enabled']);
        $alert = InventoryAlert::create(['item_id' => $this->item->id, 'warehouse_id' => $warehouse->id, 'is_active' => true, 'suggested_replenishment_qty_snapshot' => 2]);
        $service = app(InventoryAlertApplicationService::class);
        $original = $service->createPurchaseRequestFromAlert($alert->id, 1);
        $this->deleteJson('/api/v1/erp/purchase/requests/'.$original->id)->assertOk();
        $replacement = $service->createPurchaseRequestFromAlert($alert->id, 1);
        $this->assertNotSame($original->id, $replacement->id);
        $this->assertSoftDeleted('erp_purchase_requests', ['id' => $original->id]);
        $this->assertSame($replacement->id, $service->createPurchaseRequestFromAlert($alert->id, 1)->id);
        $this->assertEquals($replacement->id, $alert->fresh()->purchase_request_id);
    }

    public function test_production_procurement_enters_existing_request_edit_and_plan_flow_with_source_audit(): void
    {
        $this->permissions = [...$this->permissions, 'production.material_procurement.create'];
        $this->item->update(['is_purchase_item' => true]);
        $payload = ['client_command_id' => 'procure-life-'.Str::random(20), 'remark' => '仓库缺料申购', 'items' => [['item_id' => $this->item->id, 'request_qty' => '2']]];
        $record = $this->postJson('/api/v1/erp/production/material-procurement-requests', $payload)->assertCreated()->assertJsonPath('data.request_status', 'confirmed')->json('data');
        $this->postJson('/api/v1/erp/production/material-procurement-requests', $payload)->assertCreated()->assertJsonPath('data.id', $record['id']);
        $oldLine = PurchaseRequest::findOrFail($record['id'])->items()->first();
        $url = '/api/v1/erp/purchase/requests/'.$record['id'];
        $this->getJson($url)->assertOk()->assertJsonPath('request_status', 'confirmed');
        $this->putJson($url, $this->payload(3))->assertOk()->assertJsonPath('data.request_status', 'draft');
        $newLine = PurchaseRequest::findOrFail($record['id'])->items()->first();
        $this->assertNotSame($oldLine->id, $newLine->id);
        $this->assertDatabaseHas('erp_material_procurement_sources', ['request_id' => $record['id'], 'request_item_id' => $newLine->id, 'component_item_id' => $this->item->id, 'requested_base_qty' => 2]);
        $this->postJson($url.'/submit')->assertOk();
        $this->postJson($url.'/to-plan')->assertOk();
        $this->assertDatabaseHas('erp_purchase_plan_items', ['request_id' => $record['id'], 'request_item_id' => $newLine->id, 'item_id' => $this->item->id, 'required_qty' => 3]);
    }

    private function createRequest(): array
    {
        return $this->postJson('/api/v1/erp/purchase/requests', $this->payload())->assertCreated()->json('data');
    }

    private function payload(int $qty = 2): array
    {
        return ['items' => [['item_id' => $this->item->id, 'purchase_quantity' => $qty, 'spec_model' => '保留自定义规格']]];
    }
}
