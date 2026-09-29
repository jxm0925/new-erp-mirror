<?php

namespace Tests\Feature\Erp;

use App\DTO\Erp\WorkOrderDto;
use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{Bom, BomItem, Item, ProductionQuantityOperation, ProductionRouting, ProductionRoutingOperation, ProductionUnit, ProductionUnitOperation, Unit, WorkOrder};
use App\Services\Erp\{ProductionUnitTraceService, ProductionWorkOrderOperationQueryService, ProductionWorkOrderQueryService, ReleaseGateApplicationService, ShopfloorOptionQueryService, StockPrebuildTargetService, WorkOrderApplicationService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MiniProgramWorkOrderR1Test extends TestCase
{
    use DatabaseTransactions;

    private const PERMISSIONS = ['production.work_order.view', 'production.work_order.create', 'production.work_order.edit',
        'production.work_order.submit', 'production.work_order.publish', 'production.work_order.gate.view', 'production.unit.view', 'production.task.view'];

    public function test_both_modes_create_submit_publish_and_read_without_master_order(): void
    {
        foreach (['unit', 'quantity'] as $mode) {
            $f = $this->fixture($mode);
            $order = $this->publish($f);
            $this->assertNull($order->production_master_order_id);
            $detail = app(ProductionWorkOrderQueryService::class)->workOrder($order->id, $f['user'], self::PERMISSIONS, true);
            $dto = WorkOrderDto::fromModel($detail, self::PERMISSIONS, true);
            $this->assertSame($mode, $dto['execution_mode']);
            $this->assertSame('common_inventory', $dto['stocking_purpose']);
            $this->assertSame(2.0, $dto['execution_summary']['quantity']['planned_qty']);
            if ($mode === 'unit') {
                $page = app(ProductionUnitTraceService::class)->units($order->id, ['per_page' => 1, 'page' => 2], $f['user'], self::PERMISSIONS, true);
                $this->assertSame(2, $page->total());
                $this->assertCount(1, $page->items());
                $this->assertTrue($page->items()[0]['actions']['view_task']);
                $this->assertNotNull($page->items()[0]['execution']['current_task']['id']);
                $this->assertSame('NOT_REQUIRED', $page->items()[0]['execution']['previous_handover']['status']);
                $this->assertSame(1, $page->items()[0]['execution']['current_operation']['position']);
            } else {
                $page = app(ProductionWorkOrderOperationQueryService::class)->paginate($order->id, [], $f['user'], self::PERMISSIONS, true);
                $this->assertSame(1, $page->total());
                $this->assertTrue($page->items()[0]['actions']['view_task']);
                $this->assertSame(2.0, $page->items()[0]['quantity']['planned_qty']);
                $this->assertNotNull($page->items()[0]['task']['id']);
            }
        }
    }

    public function test_unit_display_filter_runs_before_pagination_and_raw_status_is_preserved(): void
    {
        $f = $this->fixture('unit');
        $order = $this->publish($f, 3);
        $units = ProductionUnit::where('work_order_id', $order->id)->orderBy('id')->get();
        foreach (['WAIT_MATERIAL', 'WAIT_HANDOVER', 'REWORK'] as $i => $state) {
            ProductionUnitOperation::where('production_unit_id', $units[$i]->id)->update(['status' => $state]);
        }
        $service = app(ProductionUnitTraceService::class);
        foreach (['WAIT_MATERIAL', 'WAIT_HANDOVER', 'EXCEPTION'] as $i => $state) {
            $page = $service->units($order->id, ['display_status' => $state, 'per_page' => 1], $f['user'], self::PERMISSIONS, true);
            $this->assertSame(1, $page->total());
            $this->assertSame($units[$i]->id, $page->items()[0]['id']);
            $this->assertSame($state, $page->items()[0]['display_status']);
        }
        $this->assertSame(3, $service->units($order->id, ['status' => $units[0]->status], $f['user'], self::PERMISSIONS, true)->total());
    }

    public function test_quantity_progress_is_terminal_output_and_task_permission_is_optional(): void
    {
        $f = $this->fixture('quantity');
        $order = $this->publish($f);
        $operation = ProductionQuantityOperation::where('work_order_id', $order->id)->firstOrFail();
        $operation->update(['completed_base_qty' => 1, 'remaining_base_qty' => 1, 'unqualified_base_qty' => 0.25, 'scrapped_base_qty' => 0.5]);
        DB::table('erp_production_tasks')->where('work_order_id', $order->id)->update(['status' => 'COMPLETED']);
        $detail = app(ProductionWorkOrderQueryService::class)->workOrder($order->id, $f['user'], ['production.work_order.view'], true);
        $this->assertSame(1.0, $detail->execution_summary['quantity']['completed_qty']);
        $this->assertSame(1, $detail->execution_summary['tasks']['completed']);
        // Give a real view-only role, so task scope is denied independently of WO scope.
        $this->grantView($f['user']->legacy_id);
        $rows = app(ProductionWorkOrderOperationQueryService::class)->paginate($order->id, [], $f['user'], ['production.work_order.view'], false)->items();
        $this->assertNull($rows[0]['task']);
        $this->assertFalse($rows[0]['actions']['view_task']);
        $this->assertSame(0.25, $rows[0]['quantity']['unqualified_qty']);
        $this->assertSame(0.5, $rows[0]['quantity']['scrapped_qty']);
        $this->assertSame($order->id, $detail->id);
    }

    public function test_list_summary_scope_source_search_and_batch_queries_agree(): void
    {
        $f = $this->fixture('quantity');
        $order = $this->publish($f);
        foreach (['DRAFT', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'] as $status) {
            $copy = $order->replicate();
            $copy->work_order_no = $this->code('COPY');
            $copy->origin_command_id = null;
            $copy->status = $status;
            $copy->save();
        }
        $service = app(ProductionWorkOrderQueryService::class);
        $filters = ['source_type' => 'stock_prebuild', 'keyword' => $f['item']->item_code];
        $summary = $service->workOrderSummary($filters, $f['user'], self::PERMISSIONS, true);
        $this->assertSame(5, $summary['total']);
        foreach (['WAIT_CONDITION' => 'wait_condition', 'IN_PROGRESS' => 'in_progress', 'COMPLETED' => 'completed', 'EXCEPTION' => 'exception'] as $state => $key) {
            $page = $service->workOrders($filters + ['display_status' => $state, 'per_page' => 1], $f['user'], self::PERMISSIONS, true);
            $this->assertSame($summary[$key], $page->total());
            foreach ($page as $row) $this->assertSame($state, $row->display_status_projection);
        }
        DB::enableQueryLog(); DB::flushQueryLog();
        $service->workOrders($filters + ['per_page' => 1], $f['user'], self::PERMISSIONS, true);
        $one = count(DB::getQueryLog()); DB::flushQueryLog();
        $service->workOrders($filters + ['per_page' => 5], $f['user'], self::PERMISSIONS, true);
        $five = count(DB::getQueryLog()); DB::disableQueryLog();
        $this->assertLessThanOrEqual($one + 1, $five, 'WO summaries must be fetched by page, not per row.');
        $this->assertSame(0, $service->workOrders(['source_type' => 'sales_order', 'keyword' => $f['item']->item_code], $f['user'], self::PERMISSIONS, true)->total());
    }

    public function test_reserved_candidates_and_commands_use_actual_endpoint_output_in_both_modes(): void
    {
        foreach (['unit', 'quantity'] as $mode) {
            $target = $this->fixture($mode);
            $targetOrder = $this->publish($target);
            $source = $this->fixture('quantity');
            $source['node']->update(['output_item_id' => $target['component']->id]);
            $unit = ProductionUnit::where('work_order_id', $targetOrder->id)->first();
            $filters = ['output_item_id' => $source['item']->id, 'routing_id' => $source['route']->id,
                'target_routing_operation_id' => $source['node']->id, 'reserved_for_work_order_id' => $targetOrder->id,
                'reserved_for_production_unit_id' => $unit?->id, 'per_page' => 1];
            $options = app(ShopfloorOptionQueryService::class);
            $orders = $options->options('reserved_work_orders', $filters, self::PERMISSIONS, true, $source['user']);
            $this->assertSame(1, $orders->total());
            $this->assertSame($targetOrder->id, $orders->items()[0]->id);
            $this->assertSame(0, $options->options('reserved_work_orders', $filters + ['status' => 'IN_PROGRESS'], self::PERMISSIONS, true, $source['user'])->total());
            $this->assertSame(1, $options->options('reserved_work_orders', $filters + ['status' => 'RELEASED'], self::PERMISSIONS, true, $source['user'])->total());
            $nodes = $options->options('reserved_operations', $filters, self::PERMISSIONS, true, $source['user']);
            $this->assertSame(1, $nodes->total());
            $this->assertSame($target['node']->id, $nodes->items()[0]['id']);
            if ($unit) $this->assertSame(2, $options->options('reserved_units', $filters, self::PERMISSIONS, true, $source['user'])->total());
            $reserved = $this->publish($source, 2, ['stocking_purpose' => 'reserved_for_work_order',
                'reserved_for_work_order_id' => $targetOrder->id, 'reserved_for_production_unit_id' => $unit?->id,
                'reserved_for_target_operation_id' => $target['node']->id]);
            $this->assertSame('RELEASED', $reserved->status);
            $this->assertSame($target['component']->id, (int) $reserved->effective_output_item_id_snapshot);
        }
    }

    public function test_reserved_validation_rejects_missing_unit_scope_mismatch_ambiguous_and_finished_targets(): void
    {
        $f = $this->fixture('unit'); $order = $this->publish($f);
        $unit = ProductionUnit::where('work_order_id', $order->id)->firstOrFail();
        $service = app(StockPrebuildTargetService::class);
        $resolve = fn ($uid, $item, $permissions = self::PERMISSIONS, $super = true) => $service->resolve($order->id, $uid,
            $f['node']->id, $item, $f['user'], $permissions, $super);
        $this->rejects(fn () => $resolve(null, $f['component']->id), 'stock_prebuild_reserved_unit_required');
        $this->rejects(fn () => $resolve($unit->id, $f['item']->id), 'reserved_target_material_requirement_invalid');
        $this->rejects(fn () => $resolve($unit->id, $f['component']->id, self::PERMISSIONS, false), 'forbidden');
        $requirement = DB::table('erp_production_target_material_requirements')->where('work_order_id', $order->id)->first();
        $copy = (array) $requirement; unset($copy['id']); $copy['requirement_kind'] = 'supplement';
        $duplicateId = DB::table('erp_production_target_material_requirements')->insertGetId($copy);
        $this->rejects(fn () => $resolve($unit->id, $f['component']->id), 'reserved_target_material_requirement_invalid');
        DB::table('erp_production_target_material_requirements')->where('id', $duplicateId)->delete();
        foreach (['COMPLETED', 'CLOSED', 'CANCELLED', 'DRAFT'] as $status) {
            $order->update(['status' => $status]);
            $this->assertSame(0, $service->candidates($f['component']->id, $f['user'], self::PERMISSIONS, true)->count());
            $this->rejects(fn () => $resolve($unit->id, $f['component']->id), 'reserved_target_work_order_unavailable');
        }
    }

    public function test_saved_reserved_target_is_rechecked_on_edit_and_publish(): void
    {
        $target = $this->fixture('quantity'); $targetOrder = $this->publish($target);
        $source = $this->fixture('quantity'); $source['node']->update(['output_item_id' => $target['component']->id]);
        $service = app(WorkOrderApplicationService::class);
        $draft = $service->createDraft(array_replace($this->payload($source), ['stocking_purpose' => 'reserved_for_work_order',
            'reserved_for_work_order_id' => $targetOrder->id, 'reserved_for_target_operation_id' => $target['node']->id]), $source['user'], self::PERMISSIONS, true);
        $source['node']->update(['output_item_id' => $source['item']->id]);
        $this->rejects(fn () => $service->updateDraft($draft->id, ['client_command_id' => (string) Str::uuid(), 'expected_version' => 1,
            'target_qty' => 3], $source['user'], self::PERMISSIONS, true), 'reserved_target_material_requirement_invalid');
        $this->assertSame(1, (int) $draft->fresh()->business_version);
        $source['node']->update(['output_item_id' => $target['component']->id]);
        $waiting = $service->submit($draft->id, ['client_command_id' => (string) Str::uuid(), 'expected_version' => 1], $source['user'], self::PERMISSIONS, true);
        $targetOrder->update(['status' => 'COMPLETED']);
        $gate = app(ReleaseGateApplicationService::class)->evaluate($waiting->id, $source['user'], self::PERMISSIONS, true);
        $this->assertFalse($gate['allowed']);
        $this->assertContains('reserved_target_work_order_unavailable', array_column($gate['blockers'], 'reason_code'));
        $this->rejects(fn () => $service->publish($waiting->id, ['client_command_id' => (string) Str::uuid(), 'expected_version' => 2],
            $source['user'], self::PERMISSIONS, true), 'release_gate_blocked');
        $this->assertSame('WAIT_RELEASE', $waiting->fresh()->status);
    }

    public function test_http_read_options_pagination_and_immutable_reserved_fields(): void
    {
        $target = $this->fixture('unit'); $targetOrder = $this->publish($target, 3);
        $source = $this->fixture('quantity');
        $source['node']->update(['output_item_id' => $target['component']->id]);
        $this->grantView($source['user']->legacy_id, self::PERMISSIONS);
        $token = $this->code('r1-token');
        DB::table('erp_auth_tokens')->insert(['user_legacy_id' => $source['user']->legacy_id, 'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        $this->withToken($token)->getJson('/api/v1/erp/production/work-orders/'.$targetOrder->id)
            ->assertOk()->assertJsonPath('data.production_master_order_id', null)->assertJsonPath('data.execution_mode', 'unit');
        $filters = ['output_item_id' => $source['item']->id, 'routing_id' => $source['route']->id,
            'target_routing_operation_id' => $source['node']->id, 'reserved_for_work_order_id' => $targetOrder->id, 'per_page' => 1];
        $seen = [];
        for ($page = 1; $page <= 3; $page++) {
            $response = $this->withToken($token)->getJson('/api/v1/erp/shopfloor/options/reserved_units?'.http_build_query($filters + ['page' => $page]));
            $response->assertOk()->assertJsonPath('total', 3)->assertJsonPath('current_page', $page);
            $seen[] = $response->json('data.0.id');
        }
        $this->assertCount(3, array_unique($seen));
        $payload = array_replace($this->payload($source), ['stocking_purpose' => 'reserved_for_work_order',
            'reserved_for_work_order_id' => $targetOrder->id, 'reserved_for_target_operation_id' => $target['node']->id,
            'reserved_for_production_unit_id' => $seen[0]]);
        $payload['creation_session_id'] = (string) Str::uuid();
        $reservation = app(\App\Services\Erp\DocumentNumberService::class)->reserve('work_order', $payload['creation_session_id'],
            $source['user']->legacy_id, '/production/work-orders/create');
        $payload['reservation_token'] = $reservation->reservation_token;
        $created = $this->withToken($token)->postJson('/api/v1/erp/production/work-orders', $payload)->assertCreated();
        $id = $created->json('data.id');
        $this->withToken($token)->postJson('/api/v1/erp/production/work-orders', $payload)->assertCreated()->assertJsonPath('data.id', $id);
        $this->withToken($token)->putJson('/api/v1/erp/production/work-orders/'.$id, [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => 1, 'stocking_purpose' => 'common_inventory',
        ])->assertStatus(422);
        $this->withToken($token)->getJson('/api/v1/erp/production/work-orders/'.$targetOrder->id.'/units?display_status=INVALID')->assertStatus(422);
        $quantityOrder = $this->publish($source);
        $operation = ProductionQuantityOperation::where('work_order_id', $quantityOrder->id)->firstOrFail();
        foreach ([20, 30] as $sequence) {
            $copy = $operation->replicate(); $copy->sequence_no_snapshot = $sequence; $copy->routing_operation_id_snapshot = null; $copy->save();
        }
        $this->withToken($token)->getJson('/api/v1/erp/production/work-orders/'.$quantityOrder->id.'/operations?per_page=1&page=2')
            ->assertOk()->assertJsonPath('total', 3)->assertJsonPath('data.0.sequence', 20)->assertJsonPath('current_page', 2);
        $targetOrder->update(['status' => 'CANCELLED']);
        $this->withToken($token)->postJson('/api/v1/erp/production/work-orders/'.$id.'/submit', [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => 1, 'reason' => 'R1目标失效验证',
        ])->assertStatus(422)->assertJsonPath('error_code', 'reserved_target_work_order_unavailable');
        $this->withToken($token)->getJson('/api/v1/erp/production/work-orders/999999999')->assertNotFound();
    }

    public function test_sales_work_orders_in_one_master_remain_separate_across_pages(): void
    {
        $f = $this->fixture('quantity');
        $sales = \App\Models\Erp\SalesOrder::create(['sales_order_no' => $this->code('R1-SO'),
            'customer_name' => 'R1事务客户', 'order_status' => 'confirmed', 'total_amount' => 0,
            'final_receivable_amount' => 0, 'created_by_legacy_id' => $f['user']->legacy_id]);
        $master = \App\Models\Erp\ProductionMasterOrder::create(['master_order_no' => $this->code('R1-MWO'),
            'sales_order_id' => $sales->id, 'active_sales_order_id' => $sales->id,
            'sales_order_no_snapshot' => $sales->sales_order_no, 'customer_snapshot' => ['name' => 'R1事务客户']]);
        // Read fixtures are rolled back. Distinct WO identities must survive a shared MWO.
        $ids = [];
        foreach ([2, 3] as $qty) {
            $draft = app(WorkOrderApplicationService::class)->createDraft($this->payload($f, $qty), $f['user'], self::PERMISSIONS, true);
            $draft->update(['source_type' => 'sales_order', 'stocking_purpose' => null,
                'production_master_order_id' => $master->id, 'source_no_snapshot' => $sales->sales_order_no]);
            $ids[] = $draft->id;
        }
        $query = app(ProductionWorkOrderQueryService::class);
        $filters = ['source_type' => 'sales_order', 'keyword' => $f['item']->item_code, 'per_page' => 1];
        $seen = [];
        foreach ([1, 2] as $page) {
            $rows = $query->workOrders($filters + ['page' => $page], $f['user'], self::PERMISSIONS, true);
            $this->assertSame(2, $rows->total());
            $dto = WorkOrderDto::fromModel($rows->items()[0], self::PERMISSIONS, true);
            $this->assertSame($master->id, $dto['production_master_order_id']);
            $seen[] = $dto['id'];
        }
        $this->assertEqualsCanonicalizing($ids, $seen);
        $this->assertSame(2, $query->workOrderSummary($filters, $f['user'], self::PERMISSIONS, true)['total']);
    }

    public function test_routing_preview_includes_non_output_nodes_and_stops_at_selected_endpoint(): void
    {
        $f = $this->fixture('quantity');
        $start = $f['node']->replicate(); $start->sequence = 5; $start->output_item_id = null; $start->save();
        $later = $f['node']->replicate(); $later->sequence = 20; $later->save();
        $filters = ['output_item_id' => $f['item']->id, 'routing_id' => $f['route']->id,
            'target_routing_operation_id' => $f['node']->id];
        $service = app(ShopfloorOptionQueryService::class);
        $preview = $service->options('routing_preview', $filters, self::PERMISSIONS, true, $f['user']);
        $this->assertSame([$start->id, $f['node']->id], array_column($preview->items(), 'id'));
        $endpoints = $service->options('routing_operations', $filters, self::PERMISSIONS, true, $f['user']);
        $this->assertSame([$f['node']->id, $later->id], array_column($endpoints->items(), 'id'));
    }

    private function fixture(string $mode): array
    {
        $userId = random_int(800000, 899999);
        DB::table('erp_legacy_admin_users')->insert(['legacy_id' => $userId, 'username' => $this->code('R1-USER'),
            'nickname' => 'R1事务测试', 'status' => 'normal', 'auth_group_names' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        $user = DB::table('erp_legacy_admin_users')->where('legacy_id', $userId)->first();
        $unit = Unit::create(['unit_code' => $this->code('R1-U'), 'unit_name' => '件', 'unit_type' => 'quantity', 'decimal_places' => 4, 'is_base' => true, 'status' => 'enabled']);
        $item = Item::create(['item_code' => $this->code('R1-I'), 'item_name' => 'R1生产物料', 'spec' => 'R1规格', 'item_type' => 'semi_finished',
            'unit_id' => $unit->id, 'is_stock_item' => true, 'is_production_item' => true, 'production_execution_mode' => $mode, 'status' => 'enabled']);
        $component = Item::create(['item_code' => $this->code('R1-C'), 'item_name' => 'R1自制零件', 'item_type' => 'semi_finished',
            'unit_id' => $unit->id, 'is_stock_item' => true, 'is_production_item' => true, 'status' => 'enabled']);
        $operation = DB::table('erp_production_operations')->insertGetId(['operation_no' => $this->code('R1-OP'), 'operation_name' => 'R1加工',
            'status' => 'enabled', 'sort' => 10, 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $route = ProductionRouting::create(['routing_no' => $this->code('R1-RT'), 'routing_name' => 'R1路线', 'output_item_id' => $item->id,
            'version' => 1, 'status' => 'active', 'is_default' => true, 'default_scope_key' => $item->id, 'business_version' => 1]);
        $node = ProductionRoutingOperation::create(['routing_id' => $route->id, 'operation_id' => $operation, 'sequence' => 10,
            'output_item_id' => $item->id, 'output_mode' => 'warehouse_optional', 'quality_mode' => 'none', 'is_key_operation' => true]);
        $bom = Bom::create(['bom_no' => $this->code('R1-BOM'), 'bom_name' => 'R1用料', 'output_item_id' => $item->id,
            'bom_type' => 'standard', 'version' => 'V1.0', 'is_default' => true, 'status' => 'active', 'audit_status' => 'approved', 'effective_date' => '2026-01-01']);
        BomItem::create(['bom_id' => $bom->id, 'line_no' => 10, 'component_item_id' => $component->id,
            'component_item_code' => $component->item_code, 'component_item_name' => $component->item_name, 'qty' => 1, 'unit_id' => $unit->id]);
        DB::table('erp_routing_operation_material_supply_rules')->insert(['routing_operation_id' => $node->id,
            'component_item_id' => $component->id, 'target_routing_operation_id' => $node->id, 'required_qty_ratio' => 1,
            'supply_mode' => 'dedicated_delivery', 'requires_delivery' => true, 'participates_in_kitting' => true,
            'allow_partial_delivery' => false, 'delivery_location_type' => 'operation_station', 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        return compact('user', 'unit', 'item', 'component', 'route', 'node');
    }

    private function payload(array $f, int $qty = 2): array
    {
        return ['client_command_id' => (string) Str::uuid(), 'source_type' => 'stock_prebuild', 'stocking_purpose' => 'common_inventory',
            'output_item_id' => $f['item']->id, 'production_routing_id' => $f['route']->id,
            'target_routing_operation_id' => $f['node']->id, 'target_qty' => $qty, 'planned_date' => '2026-09-22', 'production_location_name' => 'R1事务测试车间'];
    }

    private function publish(array $f, int $qty = 2, array $extra = []): WorkOrder
    {
        $service = app(WorkOrderApplicationService::class);
        $payload = array_replace($this->payload($f, $qty), $extra);
        $draft = $service->createDraft($payload, $f['user'], self::PERMISSIONS, true);
        $this->assertSame($draft->id, $service->createDraft($payload, $f['user'], self::PERMISSIONS, true)->id);
        $waiting = $service->submit($draft->id, ['client_command_id' => (string) Str::uuid(), 'expected_version' => 1], $f['user'], self::PERMISSIONS, true);
        $gate = app(ReleaseGateApplicationService::class)->evaluate($waiting->id, $f['user'], self::PERMISSIONS, true);
        $this->assertTrue($gate['allowed'], json_encode($gate['blockers'], JSON_UNESCAPED_UNICODE));
        $command = ['client_command_id' => (string) Str::uuid(), 'expected_version' => 2];
        $published = $service->publish($draft->id, $command, $f['user'], self::PERMISSIONS, true);
        $this->assertSame($published->id, $service->publish($draft->id, $command, $f['user'], self::PERMISSIONS, true)->id);
        return $published;
    }

    private function grantView(int $userId, array $permissions = ['production.work_order.view']): void
    {
        $role = DB::table('erp_rbac_roles')->insertGetId(['code' => $this->code('r1-view'), 'name' => 'R1查看', 'data_scope' => 'all', 'enabled' => 1]);
        foreach (DB::table('erp_rbac_permissions')->whereIn('code', $permissions)->pluck('id') as $permission) {
            DB::table('erp_rbac_role_permissions')->insert(['role_id' => $role, 'permission_id' => $permission]);
        }
        DB::table('erp_rbac_user_roles')->insert(['user_legacy_id' => $userId, 'role_id' => $role]);
    }

    private function rejects(callable $action, string $code): void
    {
        try { $action(); $this->fail('Expected domain rejection: '.$code); }
        catch (WorkOrderDomainException $exception) { $this->assertSame($code, $exception->errorCode); }
    }

    private function code(string $prefix): string { return $prefix.'-'.Str::upper(Str::random(10)); }
}
