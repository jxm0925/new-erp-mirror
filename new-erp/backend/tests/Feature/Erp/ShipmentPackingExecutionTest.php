<?php

namespace Tests\Feature\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{CuttingTask, InventoryBalance, InventoryReservation, InventorySerial, Item, Location, ProductionOperation, ProductionOutputRecord, ProductionQuantityOperation, ProductionRouting, ProductionTask, SalesOrder, SalesOrderFulfillment, SalesOrderLine, SalesShipment, ShipmentPackingOperation, Unit, Warehouse, WorkOrder};
use App\Services\Erp\{AuthContextService, InventoryAdjustmentApplicationService, InventoryService, ProductionLaborSessionService, ProductionMasterDataService, ProductionPerformanceScopeService, SalesShipmentApplicationService, ShipmentPackingApplicationService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ShipmentPackingExecutionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_packing_public_classification_uses_frozen_route_and_remains_stable_after_master_changes(): void
    {
        [$shipment, $line, , $owner] = $this->fixture(false);
        $master = ProductionOperation::create(['operation_no' => $this->key('public-packing'),
            'operation_name' => '公共包装', 'status' => 'enabled', 'is_public' => true]);
        $snapshot = $line->packing_routing_snapshot;
        $snapshot['operations'][0]['operation_id'] = $master->id;
        $snapshot['operations'][0]['is_public'] = true;
        $snapshot['operations'][1]['is_public'] = false;
        $snapshot['operations'][2]['operation_id'] = $master->id;
        $line->update(['packing_routing_snapshot' => $snapshot]);
        $snapshot = $line->fresh()->packing_routing_snapshot;
        $service = app(ShipmentPackingApplicationService::class);
        $service->configure($shipment->id, $this->plan($shipment, $line, [4, 6]), $owner);
        $master->update(['is_public' => false]);
        $operations = $shipment->packingOperations()->orderBy('sequence')->get();
        $this->assertSame([true, false, false], $operations->pluck('is_public_snapshot')->all());
        foreach ($operations as $index => $operation) {
            $this->assertSame($index === 0, $service->projection($operation, $owner->legacy_id)['is_public_snapshot']);
        }
        $this->assertSame($snapshot, $line->fresh()->packing_routing_snapshot);
        $this->assertFalse($master->fresh()->is_public);
        $this->assertSame(3, $operations->count());
    }

    public function test_mixed_sources_with_the_same_route_version_keep_public_facts_chains_and_reporting_scopes_separate(): void
    {
        [$shipment, $owner, $sources] = $this->mixedFrozenShipmentSources([false, true]);
        $service = app(ShipmentPackingApplicationService::class);
        $lines = $shipment->lines()->orderBy('id')->get();
        $this->assertSame([4.0, 6.0], $lines->pluck('base_qty')->map(fn ($qty) => (float) $qty)->all());
        $routes = $lines->pluck('packing_routing_snapshot');
        $this->assertSame($routes[0]['routing_id'], $routes[1]['routing_id']);
        $this->assertSame($routes[0]['version'], $routes[1]['version']);
        $this->assertSame($lines[0]->item_id, $lines[1]->item_id);
        $contents = $lines->map(fn ($line) => ['shipment_line_id' => $line->id, 'base_qty' => $line->base_qty,
            'packaging_scheme_id' => $line->packing_routing_snapshot['operations'][0]['packaging_scheme_id'],
            'inventory_serial_ids' => $line->serial_snapshot['inventory_serial_ids']])->all();
        $fingerprint = null;
        foreach ([$contents, array_reverse($contents)] as $rows) {
            $service->configure($shipment->id, ['client_command_id' => $this->key('mixed-frozen'),
                'expected_version' => $shipment->fresh()->packing_version, 'packages' => [['contents' => $rows]]], $owner);
            $chains = $shipment->packingOperations()->orderBy('planned_base_qty')->orderBy('sequence')->get()->groupBy('chain_key');
            $this->assertCount(2, $chains);
            $this->assertSame(1, $shipment->packages()->count());
            $this->assertSame(2, $shipment->packingContents()->count());
            $facts = [];
            foreach ($chains as $chain => $operations) {
                $content = $operations->first()->contents()->sole();
                $quantity = (int) (float) $content->base_qty;
                $expectedPublic = $quantity === 6;
                $this->assertCount(2, $operations);
                $this->assertSame(['READY', 'WAITING'], $operations->pluck('status')->all());
                foreach ($operations as $operation) {
                    $this->assertSame($expectedPublic, $operation->is_public_snapshot);
                    $this->assertSame([$content->id], $operation->packing_content_ids);
                    $this->assertSame((float) $quantity, (float) $operation->planned_base_qty);
                    $this->assertSame([$sources[$quantity]['output']->id], array_values(array_unique(array_column($content->source_snapshot, 'output_record_id'))));
                }
                $facts[$chain] = [$expectedPublic, $quantity, $operations->pluck('performance_rate_snapshot')->all()];
            }
            ksort($facts);
            if ($fingerprint !== null) $this->assertSame($fingerprint, $facts);
            $fingerprint = $facts;
        }
        $otherOwner = $this->employee('第二来源包装工人');
        $chains = $shipment->packingOperations()->orderBy('planned_base_qty')->orderBy('sequence')->get()->groupBy('chain_key');
        $this->travelTo(now()->startOfMinute());
        try {
            foreach ($chains->values() as $index => $operations) {
                $actor = $index === 0 ? $owner : $otherOwner;
                foreach ($operations as $operation) {
                    $this->act($operation, 'claim', $actor);
                    $this->act($operation, 'start', $actor);
                    $this->travel(60)->seconds();
                    $this->act($operation, 'complete', $actor, ['completed_base_qty' => $operation->planned_base_qty]);
                    $scope = app(ProductionPerformanceScopeService::class)->resolve('shipment_packing_operation', $operation->id);
                    $this->assertSame($operation->id, $scope['scope_id']);
                    $this->assertSame((int) $actor->legacy_id, $scope['owner_legacy_id']);
                    $this->assertSame([(int) $actor->legacy_id], array_column($scope['participants'], 'employee_legacy_id'));
                    $this->assertSame(1.0, (float) $operation->laborSessions()->sum('actual_labor_minutes'));
                    if ($index === 0) {
                        $this->assertSame('packing', $shipment->packages()->first()->package_status);
                        $this->assertSame(['READY', 'WAITING'], $chains->values()[1]->map(fn ($op) => $op->fresh()->status)->all());
                    }
                }
            }
        } finally {
            $this->travelBack();
        }
        $this->assertSame('packed', $shipment->packages()->first()->package_status);
        $this->assertSame(4, $shipment->packingOperations()->where('status', 'COMPLETED')->count());
    }

    public function test_full_frozen_node_rules_determine_chain_identity_and_identical_rules_still_share_one_chain(): void
    {
        [$shipment, $owner, $sources] = $this->mixedFrozenShipmentSources([false, false]);
        $lines = $shipment->lines()->orderBy('id')->get();
        $firstSnapshot = $lines[0]->packing_routing_snapshot;
        $different = $lines[1]->packing_routing_snapshot;
        $different['operations'][0]['unit_standard_minutes'] = 9;
        $sources[6]['order']->update(['routing_snapshot' => $different]);
        $lines[1]->update(['packing_routing_snapshot' => $different]);
        $service = app(ShipmentPackingApplicationService::class);
        $rows = $lines->map(fn ($line) => ['shipment_line_id' => $line->id, 'base_qty' => $line->base_qty,
            'packaging_scheme_id' => $line->packing_routing_snapshot['operations'][0]['packaging_scheme_id'],
            'inventory_serial_ids' => $line->serial_snapshot['inventory_serial_ids']])->all();
        $configure = fn () => $service->configure($shipment->id, ['client_command_id' => $this->key('frozen-rules'),
            'expected_version' => $shipment->fresh()->packing_version, 'packages' => [['contents' => $rows]]], $owner);
        $configure();
        $this->assertSame(2, $shipment->packingOperations()->distinct()->count('chain_key'));
        $this->assertSame([2.0, 9.0], $shipment->packingOperations()->where('sequence', 10)->orderBy('planned_base_qty')->get()
            ->pluck('unit_standard_minutes_snapshot')->map(fn ($minutes) => (float) $minutes)->all());
        $this->assertSame(0, $shipment->packingOperations()->where('is_public_snapshot', true)->count());
        $sources[6]['order']->update(['routing_snapshot' => array_reverse($firstSnapshot, true)]);
        $lines[1]->update(['packing_routing_snapshot' => array_reverse($firstSnapshot, true)]);
        $configure();
        $this->assertSame(1, $shipment->packingOperations()->distinct()->count('chain_key'));
        $this->assertSame(2, $shipment->packingOperations()->count());
        foreach ($shipment->packingOperations()->get() as $operation) {
            $this->assertCount(2, $operation->packing_content_ids);
            $this->assertSame(10.0, (float) $operation->planned_base_qty);
        }
    }

    public function test_same_product_can_split_wood_and_carton_without_recounting_quantity_or_replacing_frozen_rates(): void
    {
        [$shipment, $line, $material, $owner] = $this->fixture();
        $service = app(ShipmentPackingApplicationService::class);
        $plan = $this->plan($shipment, $line, [4, 6]);
        $response = $service->configure($shipment->id, $plan, $owner);
        $this->assertSame(2, $shipment->packingContents()->count());
        $this->assertSame(10.0, (float) $shipment->packingContents()->sum('base_qty'));
        $this->assertSame(3, $shipment->packingOperations()->count());
        $operations = $shipment->packingOperations()->orderBy('package_id')->orderBy('sequence')->get();
        $this->assertEquals(['READY', 'WAITING', 'READY'], $operations->pluck('status')->all());
        $this->assertEquals([0.01, 0.02, 0.005], $operations->pluck('performance_rate_snapshot')->map(fn ($value) => (float) $value)->all());
        $this->assertEquals([4.0, 4.0, 6.0], $operations->pluck('planned_base_qty')->map(fn ($value) => (float) $value)->all());
        $this->assertSame($response, $service->configure($shipment->id, $plan, $owner));
        $this->assertSame(3, $shipment->packingOperations()->count());
        $this->assertSame(0, DB::table('erp_inventory_transactions')->where('transaction_type', 'shipment_packing_material_outbound')->count());
    }

    public function test_real_http_configure_path_preserves_package_quantity_replays_once_and_requires_permission(): void
    {
        [$shipment, $line, , $owner] = $this->fixture();
        $permissions = ['sales_order.shipment.packing.view'];
        $this->mock(AuthContextService::class, function ($mock) use ($owner, &$permissions): void {
            $mock->shouldReceive('currentUser')->andReturn($owner);
            $mock->shouldReceive('isSuperAdmin')->andReturn(false);
            $mock->shouldReceive('permissionCodes')->andReturnUsing(function () use (&$permissions): array { return $permissions; });
            $mock->shouldReceive('dataScope')->andReturn('all');
        });
        $url = '/api/v1/erp/sales/shipments/'.$shipment->id.'/packing';
        $plan = $this->plan($shipment, $line, [4, 6]);

        $this->postJson($url, $plan)->assertForbidden();
        $this->assertSame(0, $shipment->packingContents()->count());
        $this->assertSame(0, $shipment->packingOperations()->count());
        $this->assertSame((int) $plan['expected_version'], (int) $shipment->fresh()->packing_version);
        $this->assertDatabaseMissing('erp_shipment_packing_commands', ['operator_legacy_id' => $owner->legacy_id,
            'client_command_id' => $plan['client_command_id']]);

        $permissions = ['sales_order.shipment.packing.configure'];
        $saved = $this->postJson($url, $plan)->assertOk()->assertJsonPath('data.shipment_id', $shipment->id)->json('data');
        $this->assertSame(2, $shipment->packages()->count());
        $this->assertSame(2, $shipment->packingContents()->count());
        $this->assertSame(10.0, (float) $shipment->packingContents()->sum('base_qty'));
        $this->assertSame(3, $shipment->packingOperations()->count());
        $packageIds = $shipment->packages()->orderBy('id')->pluck('id')->all();
        $contentIds = $shipment->packingContents()->orderBy('id')->pluck('id')->all();
        $operationIds = $shipment->packingOperations()->orderBy('id')->pluck('id')->all();

        $this->postJson($url, $plan)->assertOk()->assertJsonPath('data', $saved);
        $this->assertSame($packageIds, $shipment->packages()->orderBy('id')->pluck('id')->all());
        $this->assertSame($contentIds, $shipment->packingContents()->orderBy('id')->pluck('id')->all());
        $this->assertSame($operationIds, $shipment->packingOperations()->orderBy('id')->pluck('id')->all());
        $this->assertSame((int) $saved['packing_version'], (int) $shipment->fresh()->packing_version);
        $this->assertSame(1, DB::table('erp_shipment_packing_commands')->where('operator_legacy_id', $owner->legacy_id)
            ->where('client_command_id', $plan['client_command_id'])->count());
        $this->assertSame(1, DB::table('erp_shipment_packing_logs')->where('shipment_id', $shipment->id)->where('action', 'configure')->count());

        $permissions = ['sales_order.shipment.packing.view'];
        $this->postJson($url, $plan)->assertForbidden();
        $this->assertSame($operationIds, $shipment->packingOperations()->orderBy('id')->pluck('id')->all());
        $this->assertSame((int) $saved['packing_version'], (int) $shipment->fresh()->packing_version);
    }

    public function test_bad_package_coverage_and_foreign_scheme_leave_the_previous_plan_unchanged(): void
    {
        [$shipment, $line, , $owner] = $this->fixture();
        $service = app(ShipmentPackingApplicationService::class);
        $service->configure($shipment->id, $this->plan($shipment, $line, [4, 6]), $owner);
        $original = $shipment->packingOperations()->pluck('id')->all();
        foreach ([[4, 5], [4, 7]] as $quantities) {
            $bad = $this->plan($shipment->fresh(), $line, $quantities);
            $this->reject(fn () => $service->configure($shipment->id, $bad, $owner), '数量合计');
            $this->assertSame($original, $shipment->packingOperations()->pluck('id')->all());
        }
        $bad = $this->plan($shipment->fresh(), $line, [4, 6]);
        $bad['packages'][0]['contents'][0]['packaging_scheme_id'] = 99999999;
        $this->reject(fn () => $service->configure($shipment->id, $bad, $owner), '冻结的工艺流程');
        $this->assertSame($original, $shipment->packingOperations()->pluck('id')->all());
    }

    public function test_one_real_package_can_hold_two_products_and_waits_for_both_product_jobs(): void
    {
        [$firstShipment, $firstLine, , $owner, $firstBalance] = $this->fixture(false);
        $route = $firstLine->packing_routing_snapshot;
        $shipments = app(SalesShipmentApplicationService::class);
        $shipments->cancel($firstShipment, '重建多产品测试发货', '测试员');
        $item = Item::create(['item_code' => $this->key('second-product'), 'item_name' => '第二种包装产品', 'item_type' => 'finished_good',
            'unit_id' => $firstBalance->unit_id, 'is_stock_item' => true, 'status' => 'enabled']);
        $balance = $firstBalance->replicate();
        $balance->fill(['item_id' => $item->id, 'batch_no' => $this->key('second-batch'), 'quantity_on_hand' => 3, 'quantity_locked' => 3,
            'quantity_available' => 0, 'inventory_value' => 30])->save();
        $line = $firstLine->orderLine->replicate();
        $line->fill(['line_no' => 2, 'item_id' => $item->id, 'item_name' => $item->item_name, 'product_name' => $item->item_name,
            'order_qty' => 3, 'amount' => 300, 'item_base_required_qty' => 3])->save();
        $source = SalesOrderFulfillment::findOrFail($firstLine->sales_order_fulfillment_id)->replicate();
        $source->fill(['sales_order_line_id' => $line->id, 'fulfillment_qty' => 3, 'sales_qty' => 3, 'item_base_qty' => 3,
            'item_id' => $item->id, 'inventory_balance_id' => $balance->id, 'batch_no' => $balance->batch_no])->save();
        InventoryReservation::create(['source_type' => 'sales_order', 'source_order_id' => $line->sales_order_id, 'source_order_line_id' => $line->id,
            'sales_order_fulfillment_id' => $source->id, 'item_id' => $item->id, 'inventory_balance_id' => $balance->id,
            'warehouse_id' => $balance->warehouse_id, 'location_id' => $balance->location_id, 'batch_no' => $balance->batch_no,
            'reserved_qty' => 3, 'reservation_status' => 'active', 'reserved_at' => now(), 'idempotency_key' => $this->key('second-reservation')]);
        $shipment = $shipments->create($line->sales_order_id, ['lines' => [
            ['sales_order_fulfillment_id' => $firstLine->sales_order_fulfillment_id, 'base_qty' => 10],
            ['sales_order_fulfillment_id' => $source->id, 'base_qty' => 3],
        ]], '测试员');
        foreach ($shipment->lines as $shipmentLine) $shipmentLine->update(['packing_routing_snapshot' => $route]);
        $scheme = $route['operations'][2]['packaging_scheme_id'];
        app(ShipmentPackingApplicationService::class)->configure($shipment->id, ['client_command_id' => $this->key('multi-product'),
            'expected_version' => $shipment->packing_version, 'packages' => [['contents' => $shipment->lines->map(fn ($row) =>
                ['shipment_line_id' => $row->id, 'packaging_scheme_id' => $scheme, 'base_qty' => $row->base_qty, 'inventory_serial_ids' => []])->all()]]], $owner);
        $this->assertSame(1, $shipment->packages()->count());
        $this->assertSame(2, $shipment->packingContents()->count());
        $this->assertSame(13.0, (float) $shipment->packingContents()->sum('base_qty'));
        $this->assertSame(2, $shipment->packingOperations()->count());
        foreach ($shipment->packingOperations()->get() as $index => $operation) {
            $this->act($operation, 'claim', $owner); $this->act($operation, 'start', $owner);
            $this->act($operation, 'complete', $owner, ['completed_base_qty' => $operation->planned_base_qty]);
            if ($index === 0) $this->assertSame('packing', $shipment->packages()->first()->package_status);
        }
        $this->assertSame('packed', $shipment->packages()->first()->package_status);
    }

    public function test_owner_selects_collaborator_each_person_records_own_time_and_material_retry_posts_once(): void
    {
        [$shipment, $line, $material, $owner] = $this->fixture();
        $collaborator = $this->employee('协同工人');
        $outsider = $this->employee('未受邀工人');
        $service = app(ShipmentPackingApplicationService::class);
        $service->configure($shipment->id, $this->plan($shipment, $line, [4, 6]), $owner);
        $operation = $shipment->packingOperations()->orderBy('id')->first();
        $this->act($operation, 'claim', $owner);
        $this->reject(fn () => $this->act($operation, 'collaborators', $outsider, ['employee_legacy_ids' => [$outsider->legacy_id]]), '只有该工序接单人');
        $this->act($operation, 'collaborators', $owner, ['employee_legacy_ids' => [$collaborator->legacy_id]]);
        $this->reject(fn () => $this->act($operation, 'start', $outsider), '接单人选择');
        $this->travelTo(now()->startOfMinute());
        $this->act($operation, 'start', $owner);
        $this->travel(2)->minutes();
        $this->act($operation, 'start', $collaborator);
        $this->travel(1)->minutes();
        $materialsPayload = ['client_command_id' => $this->key('materials'), 'expected_version' => $operation->fresh()->business_version,
            'materials' => [['inventory_balance_id' => $material->id, 'base_qty' => 2]]];
        $posted = $service->action($operation->id, 'materials', $materialsPayload, $owner);
        $this->reject(fn () => $this->act($operation, 'complete', $owner, ['completed_base_qty' => 4]), '协同人须先暂停');
        $this->act($operation, 'pause', $collaborator);
        $this->assertEquals($posted, $service->action($operation->id, 'materials', $materialsPayload, $owner));
        $this->assertSame(18.0, (float) $material->fresh()->quantity_on_hand);
        $this->assertSame(6.0, (float) $operation->materials()->sum('cost_amount_snapshot'));
        $this->assertSame(1, DB::table('erp_inventory_transactions')->where('source_type', 'shipment_packing_material')
            ->whereIn('source_id', $operation->materials()->pluck('id'))->count());
        $this->act($operation, 'complete', $owner, ['completed_base_qty' => 4]);
        $this->assertSame('COMPLETED', $operation->fresh()->status);
        $this->assertSame(3.0, (float) $operation->laborSessions()->where('employee_legacy_id', $owner->legacy_id)->sum('actual_labor_minutes'));
        $this->assertSame(1.0, (float) $operation->laborSessions()->where('employee_legacy_id', $collaborator->legacy_id)->sum('actual_labor_minutes'));
        $this->assertSame('READY', $shipment->packingOperations()->where('sequence', 20)->first()->status);
        $this->reject(fn () => $service->assertReady($shipment->fresh()), '尚未全部完成');
        $this->reject(fn () => app(SalesShipmentApplicationService::class)->cancel($shipment, '取消', '测试员'), '包装作业');
    }

    public function test_optional_material_serials_allow_unnumbered_and_mixed_consumption_without_losing_numbered_stock(): void
    {
        [$shipment, $line, $material, $owner] = $this->fixture();
        Item::findOrFail($material->item_id)->update(['serial_tracking_mode' => 'optional']);
        $serials = collect();
        for ($index = 0; $index < 2; $index++) $serials->push(InventorySerial::create([
            'serial_no' => $this->key('packing-material'), 'inventory_balance_id' => $material->id, 'item_id' => $material->item_id,
            'warehouse_id' => $material->warehouse_id, 'location_id' => $material->location_id, 'batch_no' => $material->batch_no,
            'serial_status' => 'available',
        ]));
        $service = app(ShipmentPackingApplicationService::class);
        $service->configure($shipment->id, $this->plan($shipment, $line, [4, 6]), $owner);
        $operation = $shipment->packingOperations()->where('sequence', 10)->firstOrFail();
        $this->act($operation, 'claim', $owner);
        $this->act($operation, 'start', $owner);
        $payload = ['client_command_id' => $this->key('optional-material'), 'expected_version' => $operation->fresh()->business_version,
            'materials' => [['inventory_balance_id' => $material->id, 'base_qty' => 2, 'inventory_serial_ids' => []]]];
        $posted = $service->action($operation->id, 'materials', $payload, $owner);
        $this->assertEquals($posted, $service->action($operation->id, 'materials', $payload, $owner));
        $this->assertSame(18.0, (float) $material->fresh()->quantity_on_hand);
        $this->assertSame(18.0, (float) $material->fresh()->quantity_available);
        $this->assertSame(6.0, (float) $operation->materials()->sum('cost_amount_snapshot'));
        $this->assertSame(1, $operation->materials()->count());
        $this->assertSame(1, DB::table('erp_inventory_transactions')->where('source_type', 'shipment_packing_material')
            ->whereIn('source_id', $operation->materials()->pluck('id'))->count());
        $firstMaterial = $operation->materials()->firstOrFail();
        $firstHeader = DB::table('erp_inventory_transactions')->where('id', $firstMaterial->inventory_transaction_id)->first();
        $firstFact = DB::table('erp_inventory_transaction_items')->where('id', $firstMaterial->inventory_transaction_item_id)->first();
        $this->assertSame((int) $firstMaterial->id, (int) $firstHeader->source_id);
        $this->assertSame(2, InventorySerial::whereIn('id', $serials->pluck('id'))->where('serial_status', 'available')->count());

        $version = (int) $operation->fresh()->business_version;
        foreach ([[1, $serials->pluck('id')->all(), '超过实耗'], [17, [], '无编号可用数量不足']] as [$quantity, $ids, $message]) {
            $rejected = ['client_command_id' => $this->key('bad-optional-material'), 'expected_version' => $version,
                'materials' => [['inventory_balance_id' => $material->id, 'base_qty' => $quantity, 'inventory_serial_ids' => $ids]]];
            $this->reject(fn () => $service->action($operation->id, 'materials', $rejected, $owner), $message);
            $this->assertSame($version, (int) $operation->fresh()->business_version);
            $this->assertSame(1, $operation->materials()->count());
            $this->assertSame(18.0, (float) $material->fresh()->quantity_on_hand);
            $this->assertSame(2, InventorySerial::whereIn('id', $serials->pluck('id'))->where('serial_status', 'available')->count());
            $this->assertDatabaseMissing('erp_shipment_packing_commands', ['operator_legacy_id' => $owner->legacy_id,
                'client_command_id' => $rejected['client_command_id']]);
        }

        $this->act($operation, 'materials', $owner, ['materials' => [['inventory_balance_id' => $material->id,
            'base_qty' => 3, 'inventory_serial_ids' => [$serials->first()->id]]]]);
        $this->assertSame(15.0, (float) $material->fresh()->quantity_on_hand);
        $this->assertSame(15.0, (float) $material->fresh()->quantity_available);
        $this->assertSame(45.0, (float) $material->fresh()->inventory_value);
        $this->assertSame(15.0, (float) $operation->materials()->sum('cost_amount_snapshot'));
        $this->assertSame('packing_consumed', $serials->first()->fresh()->serial_status);
        $this->assertSame('available', $serials->last()->fresh()->serial_status);
        $this->assertSame(1, $serials->first()->events()->where('event_type', 'packing_material_consumed')->count());
        $this->assertSame(0, $serials->last()->events()->count());
        $this->assertSame(2, DB::table('erp_inventory_transactions')->where('source_type', 'shipment_packing_material')
            ->whereIn('source_id', $operation->materials()->pluck('id'))->count());
        $this->assertSame(2, $operation->materials()->distinct()->count('inventory_transaction_id'));
        $this->assertSame(2, DB::table('erp_inventory_transaction_items')->where('source_type', 'shipment_packing_operation')
            ->where('source_id', $operation->id)->count());
        $this->assertEquals($firstHeader, DB::table('erp_inventory_transactions')->where('id', $firstMaterial->inventory_transaction_id)->first());
        $this->assertEquals($firstFact, DB::table('erp_inventory_transaction_items')->where('id', $firstMaterial->inventory_transaction_item_id)->first());
    }

    public function test_physical_packing_material_posts_each_actual_plate_cost_and_preserves_other_plates_and_replay(): void
    {
        [$shipment, $line, $material, $owner] = $this->fixture();
        $plate = Item::create(['item_code' => $this->key('packing-plate'), 'item_name' => '包装整板', 'item_type' => 'raw_material',
            'unit_id' => $material->unit_id, 'is_stock_item' => true, 'status' => 'enabled',
            'material_management_mode' => 'physical', 'cutting_mode' => 'sheet', 'serial_tracking_mode' => 'none']);
        $balance = InventoryBalance::create(['item_id' => $plate->id, 'warehouse_id' => $material->warehouse_id,
            'location_id' => $material->location_id, 'batch_no' => $this->key('packing-plate-batch'), 'unit_id' => $material->unit_id,
            'quantity_on_hand' => 0, 'quantity_locked' => 0, 'quantity_available' => 0, 'quantity_defective' => 0,
            'quantity_pending' => 0, 'inventory_value' => 0, 'average_unit_cost' => 0]);
        // Official adjustment posting creates both identities and their warehouse holding.
        // Unequal per-plate costs make an accidental batch-average consumption observable.
        $adjustments = app(InventoryAdjustmentApplicationService::class);
        $adjustment = $adjustments->save(['reason' => '包装整板盘盈', 'items' => [[
            'item_id' => $plate->id, 'warehouse_id' => $balance->warehouse_id, 'location_id' => $balance->location_id,
            'batch_no' => $balance->batch_no, 'unit_id' => $plate->unit_id, 'change_qty' => 2,
            'physical_entries' => [
                ['dimensions' => ['length_mm' => '2440', 'width_mm' => '1220', 'thickness_mm' => '2'], 'total_cost' => '7.0001'],
                ['dimensions' => ['length_mm' => '2440', 'width_mm' => '1220', 'thickness_mm' => '2'], 'total_cost' => '11.0003'],
            ],
        ]]]);
        $adjustments->submit($adjustment->id);
        app(InventoryService::class)->postAdjustment($adjustment->id);
        $physicals = DB::table('erp_material_physicals')->where('item_id', $plate->id)->orderBy('id')->get();
        $this->assertCount(2, $physicals);
        $this->assertSame(['7.0001', '11.0003'], $physicals->pluck('total_cost')->map(fn ($cost) => (string) $cost)->all());
        $this->assertSame('18.0004', (string) $balance->fresh()->inventory_value);
        $this->assertSame(9.0002, (float) $balance->fresh()->average_unit_cost);
        $warehouseHoldingId = (int) $physicals->first()->current_holding_id;
        $this->assertSame($warehouseHoldingId, (int) $physicals->last()->current_holding_id);
        foreach ($physicals as $physical) $this->assertNotNull($physical->source_transaction_item_id);

        $snapshot = $line->packing_routing_snapshot;
        $snapshot['operations'][0]['packaging_materials'] = [['component_item_id' => $plate->id, 'base_qty_per_output_unit' => 0.5]];
        $line->update(['packing_routing_snapshot' => $snapshot]);
        $service = app(ShipmentPackingApplicationService::class);
        $service->configure($shipment->id, $this->plan($shipment, $line, [4, 6]), $owner);
        $operation = $shipment->packingOperations()->where('sequence', 10)->firstOrFail();
        $this->act($operation, 'claim', $owner);
        $this->act($operation, 'start', $owner);
        $payload = ['client_command_id' => $this->key('physical-packing'), 'expected_version' => $operation->fresh()->business_version,
            'materials' => [['inventory_balance_id' => $balance->id, 'base_qty' => 1, 'physical_material_ids' => [$physicals->first()->id]]]];
        $posted = $service->action($operation->id, 'materials', $payload, $owner);
        $this->assertEquals($posted, $service->action($operation->id, 'materials', $payload, $owner));
        $this->assertSame(1.0, (float) $balance->fresh()->quantity_on_hand);
        $this->assertSame(1.0, (float) $balance->fresh()->quantity_available);
        $this->assertSame('11.0003', (string) $balance->fresh()->inventory_value);
        $this->assertSame('7.0001', (string) $operation->materials()->firstOrFail()->cost_amount_snapshot);
        $remaining = DB::table('erp_material_physicals')->where('id', $physicals->last()->id)->first();
        $this->assertSame('AVAILABLE', $remaining->status);
        $this->assertSame($warehouseHoldingId, (int) $remaining->current_holding_id);
        $this->assertSame('ACTIVE', DB::table('erp_material_holdings')->where('id', $warehouseHoldingId)->value('status'));
        $this->assertSame(1, DB::table('erp_material_movements')->where('action', 'PACKING_CONSUME')
            ->whereIn('inventory_transaction_id', $operation->materials()->pluck('inventory_transaction_id'))->count());

        $this->act($operation, 'materials', $owner, ['materials' => [['inventory_balance_id' => $balance->id,
            'base_qty' => 1, 'physical_material_ids' => [$physicals->last()->id]]]]);
        $this->assertSame(0.0, (float) $balance->fresh()->quantity_on_hand);
        $this->assertSame(0.0, (float) $balance->fresh()->quantity_available);
        $this->assertSame('0.0000', (string) $balance->fresh()->inventory_value);
        $this->assertSame(18.0004, (float) $operation->materials()->sum('cost_amount_snapshot'));
        $this->assertSame(2, $operation->materials()->distinct()->count('inventory_transaction_id'));
        foreach ($physicals as $physical) {
            $consumed = DB::table('erp_material_physicals')->where('id', $physical->id)->first();
            $holding = DB::table('erp_material_holdings')->where('id', $consumed->current_holding_id)->first();
            $this->assertSame('CONSUMED', $consumed->status);
            $this->assertSame('SHIPMENT_PACKING', $holding->position_type);
            $this->assertSame('CONSUMED', $holding->status);
            $this->assertNull($holding->inventory_balance_id);
            $this->assertSame('1.00000000', (string) $holding->quantity);
            $this->assertSame((string) $physical->total_cost, (string) $holding->total_cost);
        }
        $this->assertSame(2, DB::table('erp_material_movements')->where('action', 'PACKING_CONSUME')
            ->whereIn('inventory_transaction_id', $operation->materials()->pluck('inventory_transaction_id'))->count());
    }

    public function test_package_quality_must_pass_before_dispatch_and_failed_quality_keeps_actual_work(): void
    {
        [$shipment, $line, , $owner] = $this->fixture(false);
        $snapshot = $line->packing_routing_snapshot;
        $snapshot['operations'][2]['quality_mode'] = 'required';
        $line->update(['packing_routing_snapshot' => $snapshot]);
        $service = app(ShipmentPackingApplicationService::class);
        $service->configure($shipment->id, $this->plan($shipment, $line, [0, 10]), $owner);
        $op = $shipment->packingOperations()->first();
        $this->act($op, 'claim', $owner);
        $this->travelTo(now()->startOfMinute());
        $this->act($op, 'start', $owner);
        $this->travel(1)->minutes();
        $this->act($op, 'complete', $owner, ['completed_base_qty' => 10]);
        $this->assertSame('WAIT_QUALITY', $op->fresh()->status);
        $this->reject(fn () => $service->assertReady($shipment), '尚未全部完成');
        $this->act($op, 'inspect', $owner, ['result' => 'failed', 'reason' => '包装松动']);
        $this->assertSame('READY', $op->fresh()->status);
        $this->act($op, 'start', $owner);
        $this->travel(1)->minutes();
        $this->act($op, 'complete', $owner, ['completed_base_qty' => 10]);
        $this->act($op, 'inspect', $owner, ['result' => 'passed']);
        $service->assertReady($shipment);
        $this->assertSame('packed', $shipment->packages()->first()->package_status);
        $this->assertSame(2.0, (float) $op->laborSessions()->sum('actual_labor_minutes'));
        $shipment->update(['shipment_status' => 'outbound_posted']);
        $this->assertSame('shipped', app(SalesShipmentApplicationService::class)->dispatch($shipment, '包装测试员')->shipment_status);
    }

    public function test_serial_package_split_cannot_reuse_a_device_or_discard_any_device(): void
    {
        [$shipment, $line, , $owner, $balance] = $this->fixture();
        $serials = [];
        for ($index = 0; $index < 10; $index++) $serials[] = InventorySerial::create(['serial_no' => $this->key('device'),
            'inventory_balance_id' => $balance->id, 'item_id' => $line->item_id,
            'warehouse_id' => $balance->warehouse_id, 'location_id' => $balance->location_id, 'batch_no' => $balance->batch_no, 'serial_status' => 'available'])->id;
        $line->update(['serial_snapshot' => ['inventory_serial_ids' => $serials]]);
        $plan = $this->plan($shipment, $line, [4, 6]);
        $plan['packages'][0]['contents'][0]['inventory_serial_ids'] = array_slice($serials, 0, 4);
        $plan['packages'][1]['contents'][0]['inventory_serial_ids'] = array_slice($serials, 3, 6);
        $service = app(ShipmentPackingApplicationService::class);
        $this->reject(fn () => $service->configure($shipment->id, $plan, $owner), '序列号重复');
        $plan['client_command_id'] = $this->key('plan-fixed');
        $plan['packages'][1]['contents'][0]['inventory_serial_ids'] = array_slice($serials, 4, 6);
        $service->configure($shipment->id, $plan, $owner);
        $this->assertCount(10, $shipment->packingContents()->get()->flatMap(fn ($row) => $row->serial_snapshot['inventory_serial_ids'])->unique());
    }

    public function test_creation_splits_real_serial_sources_and_keeps_unidentified_optional_quantity_and_reservations(): void
    {
        [$draft, $line, , , $balance] = $this->fixture(false);
        $shipments = app(SalesShipmentApplicationService::class);
        $shipments->cancel($draft, '重建来源分片测试', '测试员');
        Item::findOrFail($line->item_id)->update(['serial_tracking_mode' => 'optional']);
        $ids = [];
        foreach ([101, 101, 202, 202] as $sourceId) $ids[] = InventorySerial::create(['serial_no' => $this->key('optional-device'),
            'inventory_balance_id' => $balance->id, 'item_id' => $line->item_id, 'warehouse_id' => $balance->warehouse_id,
            'location_id' => $balance->location_id, 'batch_no' => $balance->batch_no, 'serial_status' => 'available',
            'source_document_type' => 'legacy_import', 'source_document_id' => $sourceId])->id;
        $shipment = $shipments->create($draft->sales_order_id, ['lines' => [['sales_order_fulfillment_id' => $line->sales_order_fulfillment_id,
            'base_qty' => 5, 'inventory_serial_ids' => $ids]]], '测试员');
        $pieces = $shipment->lines()->orderBy('id')->get();
        $this->assertEquals([2.0, 2.0, 1.0], $pieces->pluck('base_qty')->map(fn ($qty) => (float) $qty)->all());
        $this->assertSame(3, $pieces->pluck('inventory_reservation_id')->unique()->count());
        $this->assertEquals($ids, $pieces->flatMap(fn ($row) => $row->serial_snapshot['inventory_serial_ids'])->all());
        $this->assertSame([], $pieces->last()->serial_snapshot['inventory_serial_ids']);
        $this->assertSame(5.0, (float) InventoryReservation::whereIn('id', $pieces->pluck('inventory_reservation_id'))->sum('reserved_qty'));
        $this->assertSame(5.0, (float) InventoryReservation::where('source_order_id', $draft->sales_order_id)->where('reservation_status', 'active')->sum('reserved_qty'));
        $shipments->cancel($shipment, '撤销来源分片测试', '测试员');
        $this->assertSame(10.0, (float) InventoryReservation::where('source_order_id', $draft->sales_order_id)->where('reservation_status', 'active')->sum('reserved_qty'));
        $this->assertSame(10.0, (float) $balance->fresh()->quantity_locked);
        $this->assertSame(0.0, (float) $balance->fresh()->quantity_available);
    }

    public function test_worker_packing_and_warehouse_projections_never_serialize_rates_or_internal_snapshots(): void
    {
        [$shipment, $line, , $owner] = $this->fixture();
        app(ShipmentPackingApplicationService::class)->configure($shipment->id, $this->plan($shipment, $line, [4, 6]), $owner);
        $this->mock(AuthContextService::class, function ($mock) use ($owner): void {
            $mock->shouldReceive('currentUser')->andReturn($owner);
            $mock->shouldReceive('isSuperAdmin')->andReturn(false);
            $mock->shouldReceive('permissionCodes')->andReturn(['sales_order.shipment.packing.execute']);
            $mock->shouldReceive('dataScope')->andReturn('all');
        });
        $op = $shipment->packingOperations()->first();
        $response = $this->getJson('/api/v1/erp/production/shipment-packing/operations/'.$op->id)->assertOk();
        foreach (['performance_rate', 'cost_amount', 'unit_price', 'routing_snapshot', 'source_snapshot', 'credited_labor'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $response->getContent());
        }
        $this->assertArrayNotHasKey('packing_routing_snapshot', $line->toArray());
        $this->assertArrayNotHasKey('packing_source_snapshot', $line->toArray());
        $this->assertSame('0.01000000', $op->performance_rate_snapshot);
    }

    public function test_packing_timer_is_exclusive_across_jobs_and_only_the_exact_command_can_replay(): void
    {
        [$shipment, $line, , $owner] = $this->fixture(false);
        $service = app(ShipmentPackingApplicationService::class);
        $service->configure($shipment->id, $this->plan($shipment, $line, [4, 6]), $owner);
        $wood = $shipment->packingOperations()->where('sequence', 10)->first();
        $carton = $shipment->packingOperations()->where('sequence', 30)->first();
        $this->act($wood, 'claim', $owner); $this->act($carton, 'claim', $owner);
        $payload = ['client_command_id' => $this->key('exclusive-start'), 'expected_version' => $wood->fresh()->business_version];
        $response = $service->action($wood->id, 'start', $payload, $owner);
        $this->assertEquals($response, $service->action($wood->id, 'start', $payload, $owner));
        $this->assertSame(1, $wood->laborSessions()->where('status', 'ACTIVE')->count());
        $this->reject(fn () => $this->act($wood, 'start', $owner), '正在计时的包装作业');
        $this->reject(fn () => $this->act($carton, 'start', $owner), '正在计时的包装作业');
        $this->assertSame('READY', $carton->fresh()->status);
        $this->assertSame(0, $carton->laborSessions()->count());
        $this->act($wood, 'pause', $owner); $this->act($carton, 'start', $owner);
        $this->assertSame(1, $carton->laborSessions()->where('status', 'ACTIVE')->count());
        $this->assertSame(0, $wood->laborSessions()->where('status', 'ACTIVE')->count());
    }

    public function test_packing_and_regular_production_and_cutting_timers_exclude_each_other_in_both_directions(): void
    {
        [$shipment, $line, , $owner, $balance] = $this->fixture(false);
        app(ShipmentPackingApplicationService::class)->configure($shipment->id, $this->plan($shipment, $line, [0, 10]), $owner);
        $packing = $shipment->packingOperations()->first();
        $this->act($packing, 'claim', $owner); $this->act($packing, 'start', $owner);
        $workOrder = WorkOrder::create(['work_order_no' => $this->key('timer-work-order'), 'source_type' => 'stock_prebuild',
            'output_item_id' => $line->item_id, 'target_qty' => 1, 'target_base_qty' => 1, 'target_unit_id' => $balance->unit_id,
            'base_unit_id' => $balance->unit_id, 'status' => 'IN_PROGRESS', 'business_version' => 1]);
        $target = ProductionQuantityOperation::create(['work_order_id' => $workOrder->id, 'operation_code_snapshot' => 'ASSEMBLY',
            'operation_name_snapshot' => '装配', 'sequence_no_snapshot' => 10, 'status' => 'READY', 'planned_base_qty' => 1,
            'remaining_base_qty' => 1, 'work_mode_snapshot' => 'manual']);
        $task = ProductionTask::create(['task_no' => $this->key('timer-task'), 'work_order_id' => $workOrder->id, 'execution_mode' => 'quantity',
            'operation_code_snapshot' => 'ASSEMBLY', 'operation_name_snapshot' => '装配', 'sequence_no_snapshot' => 10,
            'status' => 'READY', 'assignee_user_legacy_id' => $owner->legacy_id, 'business_version' => 1]);
        $cuttingOrder = DB::table('erp_cutting_orders')->insertGetId(['cutting_order_no' => $this->key('timer-cutting-order'), 'status' => 'PUBLISHED',
            'responsible_user_legacy_id' => $owner->legacy_id, 'created_by_legacy_id' => $owner->legacy_id,
            'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $cutting = CuttingTask::create(['cutting_order_id' => $cuttingOrder, 'task_no' => $this->key('timer-cutting-task'), 'status' => 'READY',
            'assignee_user_legacy_id' => $owner->legacy_id, 'work_mode_snapshot' => 'manual']);
        $labor = app(ProductionLaborSessionService::class);
        foreach ([fn () => $labor->start($task, $target, 'quantity_operation', $owner->legacy_id, 'owner', 1, now()),
            fn () => $labor->startCutting($cutting, $owner->legacy_id, 'owner', 1, now()),
            fn () => $labor->assertStartAllowed('quantity_operation', $target->id, $owner->legacy_id, [])] as $start) {
            try { $start(); $this->fail('Active packing timer must block ordinary production and cutting starts.'); }
            catch (WorkOrderDomainException $exception) { $this->assertStringContainsString('先暂停原包装作业', $exception->getMessage()); }
        }
        $this->assertSame(0, DB::table('erp_production_labor_sessions')->where('employee_legacy_id', $owner->legacy_id)->count());
        $this->act($packing, 'pause', $owner);
        $labor->assertStartAllowed('quantity_operation', $target->id, $owner->legacy_id, []);
        $productionSession = $labor->start($task, $target, 'quantity_operation', $owner->legacy_id, 'owner', 1, now());
        $this->reject(fn () => $this->act($packing, 'start', $owner), '正在计时的生产或下料作业');
        $this->assertSame('ACTIVE', $productionSession->fresh()->status);
        $labor->end($task, $target, 'quantity_operation', $owner->legacy_id, 'paused', now(), true, false, false);
        $cuttingSession = $labor->startCutting($cutting, $owner->legacy_id, 'owner', 1, now());
        $this->reject(fn () => $this->act($packing, 'start', $owner), '正在计时的生产或下料作业');
        $this->assertSame('ACTIVE', $cuttingSession->fresh()->status);
        $labor->endCutting($cutting, $owner->legacy_id, 'paused', now(), true, false);
        $this->act($packing, 'start', $owner);
        $this->assertSame(1, $packing->laborSessions()->where('status', 'ACTIVE')->count());
        $this->assertSame(0, DB::table('erp_production_labor_sessions')->where('employee_legacy_id', $owner->legacy_id)->where('status', 'ACTIVE')->count());
    }

    private function mixedFrozenShipmentSources(array $publicValues): array
    {
        [$draft, $line, , $owner, $balance] = $this->fixture(false);
        $shipments = app(SalesShipmentApplicationService::class);
        $shipments->cancel($draft, '验证冻结来源混装', '测试员');
        Item::findOrFail($line->item_id)->update(['serial_tracking_mode' => 'required']);
        $master = ProductionOperation::create(['operation_no' => $this->key('mixed-master'),
            'operation_name' => '共用包装工序', 'status' => 'enabled', 'is_public' => false]);
        $routing = ProductionRouting::create(['routing_no' => $this->key('mixed-route'), 'routing_name' => '共用包装路线',
            'output_item_id' => $line->item_id, 'version' => 1, 'status' => 'active']);
        $route = $line->packing_routing_snapshot;
        $route['routing_id'] = $routing->id;
        $route['operations'] = array_slice($route['operations'], 0, 2);
        $sources = []; $serialIds = [];
        foreach ([4, 6] as $index => $quantity) {
            app(ProductionMasterDataService::class)->updateOperation($master->id, ['client_command_id' => $this->key('classify-source'),
                'expected_version' => $master->fresh()->business_version, 'is_public' => $publicValues[$index]], $owner, ['production.operation.edit'], false);
            $snapshot = $route;
            foreach ($snapshot['operations'] as &$node) { $node['operation_id'] = $master->id; $node['is_public'] = $publicValues[$index]; }
            unset($node);
            $order = WorkOrder::create(['work_order_no' => $this->key('source-work'), 'source_type' => 'stock_prebuild',
                'output_item_id' => $line->item_id, 'target_qty' => $quantity, 'target_base_qty' => $quantity,
                'target_unit_id' => $balance->unit_id, 'base_unit_id' => $balance->unit_id,
                'production_routing_id' => $routing->id, 'routing_version_snapshot' => 1, 'routing_snapshot' => $snapshot,
                'status' => 'COMPLETED', 'business_version' => 1]);
            $target = ProductionQuantityOperation::create(['work_order_id' => $order->id, 'sequence_no_snapshot' => 1,
                'operation_code_snapshot' => 'FINISHED', 'operation_name_snapshot' => '成品生产完成',
                'planned_base_qty' => $quantity, 'completed_base_qty' => $quantity, 'remaining_base_qty' => 0, 'status' => 'COMPLETED']);
            $output = ProductionOutputRecord::create(['output_no' => $this->key('source-output'), 'work_order_id' => $order->id,
                'source_target_type' => 'quantity_operation', 'source_target_id' => $target->id, 'output_item_id' => $line->item_id,
                'output_base_qty' => $quantity, 'output_mode_snapshot' => 'finished_goods', 'quality_mode_snapshot' => 'none',
                'status' => 'WAREHOUSED', 'created_by_legacy_id' => $owner->legacy_id, 'produced_at' => now()]);
            $sources[$quantity] = compact('order', 'output');
            for ($piece = 0; $piece < $quantity; $piece++) {
                $serialIds[] = InventorySerial::create(['serial_no' => $this->key('mixed-device'), 'inventory_balance_id' => $balance->id,
                    'item_id' => $line->item_id, 'warehouse_id' => $balance->warehouse_id, 'location_id' => $balance->location_id,
                    'batch_no' => $balance->batch_no, 'serial_status' => 'available',
                    'source_document_type' => 'production_output', 'source_document_id' => $output->id])->id;
            }
        }
        $shipment = $shipments->create($draft->sales_order_id, ['lines' => [['sales_order_fulfillment_id' => $line->sales_order_fulfillment_id,
            'base_qty' => 10, 'inventory_serial_ids' => $serialIds]]], '测试员');
        return [$shipment, $owner, $sources];
    }

    private function fixture(bool $needsMaterial = true): array
    {
        $unit = Unit::create(['unit_code' => $this->key('unit'), 'unit_name' => '件', 'unit_type' => 'quantity', 'decimal_places' => 0, 'is_base' => true, 'status' => 'enabled']);
        $warehouse = Warehouse::create(['warehouse_code' => $this->key('warehouse'), 'warehouse_name' => '包装测试仓', 'status' => 'enabled']);
        $location = Location::create(['warehouse_id' => $warehouse->id, 'location_code' => $this->key('location'), 'location_name' => '包装测试库位', 'status' => 'enabled']);
        $item = Item::create(['item_code' => $this->key('product'), 'item_name' => '包装测试产品', 'item_type' => 'finished_good', 'unit_id' => $unit->id, 'is_stock_item' => true, 'status' => 'enabled']);
        $balance = InventoryBalance::create(['item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'batch_no' => $this->key('product-batch'), 'unit_id' => $unit->id,
            'quantity_on_hand' => 10, 'quantity_locked' => 10, 'quantity_available' => 0, 'quantity_defective' => 0, 'quantity_pending' => 0, 'inventory_value' => 100, 'average_unit_cost' => 10]);
        $component = Item::create(['item_code' => $this->key('material'), 'item_name' => '包装材料', 'item_type' => 'raw_material', 'unit_id' => $unit->id, 'is_stock_item' => true, 'status' => 'enabled']);
        $material = InventoryBalance::create(['item_id' => $component->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'batch_no' => $this->key('material-batch'), 'unit_id' => $unit->id,
            'quantity_on_hand' => 20, 'quantity_locked' => 0, 'quantity_available' => 20, 'quantity_defective' => 0, 'quantity_pending' => 0, 'inventory_value' => 60, 'average_unit_cost' => 3]);
        $order = SalesOrder::create(['sales_order_no' => $this->key('order'), 'customer_name' => '包装测试客户', 'order_status' => 'confirmed', 'confirm_status' => 'confirmed', 'shipment_status' => 'not_shipped', 'total_amount' => 0, 'final_receivable_amount' => 0,
            'funding_policy_snapshot' => ['policy_type' => 'full_prepay', 'policy_name' => '全额预付']]);
        $orderLine = SalesOrderLine::create(['sales_order_id' => $order->id, 'line_no' => 1, 'item_id' => $item->id, 'item_name' => $item->item_name, 'product_name' => '包装测试产品', 'line_type' => 'physical',
            'order_qty' => 10, 'unit_price' => 100, 'amount' => 1000, 'fulfillment_factor_snapshot' => 1, 'item_base_unit_id' => $unit->id, 'item_base_required_qty' => 10, 'fulfillment_type' => 'inventory']);
        $fulfillment = SalesOrderFulfillment::create(['sales_order_id' => $order->id, 'sales_order_line_id' => $orderLine->id, 'fulfillment_type' => 'inventory', 'fulfillment_qty' => 10, 'sales_qty' => 10, 'item_base_qty' => 10,
            'fulfillment_factor_snapshot' => 1, 'item_id' => $item->id, 'inventory_balance_id' => $balance->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'batch_no' => $balance->batch_no, 'demand_status' => 'confirmed']);
        InventoryReservation::create(['source_type' => 'sales_order', 'source_order_id' => $order->id, 'source_order_line_id' => $orderLine->id, 'sales_order_fulfillment_id' => $fulfillment->id,
            'item_id' => $item->id, 'inventory_balance_id' => $balance->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'batch_no' => $balance->batch_no,
            'reserved_qty' => 10, 'reservation_status' => 'active', 'reserved_at' => now(), 'idempotency_key' => $this->key('reservation')]);
        $shipment = app(SalesShipmentApplicationService::class)->create($order->id, ['lines' => [['sales_order_fulfillment_id' => $fulfillment->id, 'base_qty' => 10]]], '包装测试员');
        $line = $shipment->lines()->first();
        $wood = (int) DB::table('erp_production_packaging_schemes')->where('code', 'WOODEN_CASE')->value('id');
        $carton = (int) DB::table('erp_production_packaging_schemes')->where('code', 'CARTON')->value('id');
        $nodes = [];
        foreach ([[10, '切包装板', $wood, '木箱', 0.01], [20, '钉箱装箱', $wood, '木箱', 0.02], [30, '纸箱打包', $carton, '纸箱', 0.005]] as [$sequence, $name, $schemeId, $schemeName, $rate]) {
            $nodes[] = ['routing_operation_id' => $sequence, 'operation_id' => $sequence, 'operation_name' => $name, 'sequence' => $sequence,
                'execution_context' => 'shipment', 'packaging_scheme_id' => $schemeId, 'packaging_scheme_name' => $schemeName,
                'performance_rate' => $rate, 'setup_standard_minutes' => 1, 'unit_standard_minutes' => 2, 'quality_mode' => 'none',
                'packaging_materials' => $needsMaterial && $sequence === 10 ? [['component_item_id' => $component->id, 'base_qty_per_output_unit' => 0.5]] : []];
        }
        $line->update(['packing_routing_snapshot' => ['routing_id' => 1, 'version' => 1, 'operations' => $nodes]]);
        return [$shipment, $line, $material, $this->employee('接单工人'), $balance];
    }

    private function plan(SalesShipment $shipment, object $line, array $quantities): array
    {
        $schemes = array_values(array_unique(array_column($line->packing_routing_snapshot['operations'], 'packaging_scheme_id')));
        $packages = [];
        foreach ($quantities as $index => $quantity) {
            if ($quantity <= 0) continue;
            $packages[] = ['package_no' => $shipment->shipment_no.'-'.$index, 'contents' => [['shipment_line_id' => $line->id,
                'base_qty' => $quantity, 'packaging_scheme_id' => $schemes[$index], 'inventory_serial_ids' => []]]];
        }
        return ['expected_version' => $shipment->fresh()->packing_version, 'client_command_id' => $this->key('plan'), 'packages' => $packages];
    }

    private function employee(string $name): object
    {
        $id = random_int(990000000, 999999999);
        $row = ['legacy_id' => $id, 'username' => $this->key('worker'), 'nickname' => $name, 'status' => 'normal',
            'auth_group_names' => '["Admin group"]', 'created_at' => now(), 'updated_at' => now()];
        DB::table('erp_legacy_admin_users')->insert($row);
        return (object) $row;
    }

    private function act(ShipmentPackingOperation $op, string $action, object $actor, array $payload = []): array
    {
        return app(ShipmentPackingApplicationService::class)->action($op->id, $action,
            ['client_command_id' => $this->key($action), 'expected_version' => $op->fresh()->business_version] + $payload, $actor);
    }

    private function reject(\Closure $action, string $message): void
    {
        try { $action(); $this->fail('Expected a packing validation rejection.'); }
        catch (ValidationException $exception) { $this->assertStringContainsString($message, implode(' ', array_merge(...array_values($exception->errors())))); }
    }

    private function key(string $prefix): string { return $prefix.'-'.bin2hex(random_bytes(6)); }
}
