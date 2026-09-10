<?php

namespace Tests\Feature\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\Bom;
use App\Models\Erp\BomItem;
use App\Models\Erp\InventoryBalance;
use App\Models\Erp\Item;
use App\Models\Erp\Location;
use App\Models\Erp\Unit;
use App\Models\Erp\Warehouse;
use App\Models\Erp\WorkOrder;
use App\Models\Erp\WorkOrderMaterialRequirement;
use App\Models\Erp\SalesOrder;
use App\Services\Erp\ProductionPreparationOrderService;
use App\Services\Erp\ProductionDeliveryWaveService;
use App\Services\Erp\ProductionMaterialExecutionService;
use App\Services\Erp\ProductionKittingService;
use App\Services\Erp\ProductionMaterialReturnService;
use App\Services\Erp\ProductionMaterialSupplementService;
use App\Services\Erp\ProductionHandoverService;
use App\Services\Erp\ProductionInternalIssueService;
use App\Services\Erp\ProductionOutputService;
use App\Services\Erp\ProductionExecutionActionService;
use App\Services\Erp\WorkOrderCompletionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductionMaterialExecutionTest extends TestCase
{
    use DatabaseTransactions;

    private const PERMISSIONS = [
        'production.material_requirement.view',
        'production.material_picking.view', 'production.material_picking.create', 'production.material_picking.assign', 'production.material_picking.pick', 'production.material_picking.cancel',
        'production.material_delivery.view', 'production.material_delivery.create', 'production.material_delivery.dispatch', 'production.material_delivery.confirm',
        'production.material_delivery.cancel',
        'production.material_receipt.view', 'production.material_receipt.confirm',
        'production.kitting.view', 'production.kitting.confirm',
        'production.material_supplement.request', 'production.material_supplement.approve',
        'production.material_return.create', 'production.material_return.receive', 'production.material_return.quality',
    ];

    public function test_warehouse_creates_picking_task_from_system_preparation_demand_without_retyping_production_requirement(): void
    {
        [$user, $workOrder, $requirement, $balance] = $this->fixture();
        $service = app(ProductionMaterialExecutionService::class);
        $demand = DB::table('erp_production_target_material_requirements')
            ->where('work_order_id', $workOrder->id)->first();
        DB::table('erp_production_target_material_requirements')->where('id', $demand->id)->update(['status' => 'WAIT_PREPARE']);

        $page = $service->paginatePreparationDemands(['work_order_id' => $workOrder->id], $user, self::PERMISSIONS, true);
        $this->assertSame(1, $page->total());
        $this->assertSame($demand->id, (int) $page->items()[0]->id);
        $this->assertSame('WAIT_PREPARE', $page->items()[0]->status);

        $task = $service->createPickingTask([
            'client_command_id' => $this->id('from-system-demand'),
            'work_order_id' => $workOrder->id,
            'expected_version' => 1,
            'warehouse_id' => $balance->warehouse_id,
            'lines' => [[
                'target_material_requirement_id' => $demand->id,
                'inventory_balance_id' => $balance->id,
                'planned_pick_qty' => 10,
            ]],
        ], $user, self::PERMISSIONS, true);

        $line = $task->lines->first();
        $this->assertSame($requirement->id, (int) $line->material_requirement_id);
        $this->assertSame((int) $demand->material_supply_rule_snapshot_id, (int) $line->material_supply_rule_snapshot_id);
        $this->assertSame($demand->target_type, $line->production_target_type);
        $this->assertSame((int) $demand->target_id, (int) $line->production_target_id);
        $this->assertSame('PREPARING', DB::table('erp_production_target_material_requirements')->where('id', $demand->id)->value('status'));
        $this->assertSame(0, $service->paginatePreparationDemands(['work_order_id' => $workOrder->id], $user, self::PERMISSIONS, true)->total());
    }

    public function test_delivery_wave_slices_quantity_and_serials_between_pool_claim_and_dispatcher_tasks_with_separate_receipts(): void
    {
        [$user, $workOrder, $requirement, $balance] = $this->fixture();
        $salesOrder = SalesOrder::create([
            'sales_order_no' => $this->id('dw-so'), 'customer_name' => '配送波次客户',
            'order_status' => 'confirmed', 'confirm_status' => 'confirmed', 'production_confirm_status' => 'confirmed',
        ]);
        $masterId = DB::table('erp_production_master_orders')->insertGetId([
            'master_order_no' => $this->id('MWO'), 'sales_order_id' => $salesOrder->id, 'active_sales_order_id' => $salesOrder->id,
            'sales_order_no_snapshot' => $salesOrder->sales_order_no, 'customer_snapshot' => json_encode(['name' => '配送波次客户'], JSON_UNESCAPED_UNICODE),
            'status' => 'WAIT_CONDITION', 'business_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('erp_work_orders')->where('id', $workOrder->id)->update(['production_master_order_id' => $masterId, 'updated_at' => now()]);
        $workOrder->setAttribute('production_master_order_id', $masterId);
        $preparation = app(ProductionPreparationOrderService::class)->syncFromPublishedWorkOrder($workOrder->fresh('materialRequirements'), $user);

        $material = app(ProductionMaterialExecutionService::class);
        $pick = $material->createPickingTask([
            'client_command_id' => $this->id('dw-pick'), 'work_order_id' => $workOrder->id,
            'expected_version' => 1, 'warehouse_id' => $balance->warehouse_id,
            'lines' => [$this->pickLine($workOrder, $requirement, $balance, 6)],
        ], $user, self::PERMISSIONS, true);
        $pick = $material->assignPickingTask($pick->id, [
            'client_command_id' => $this->id('dw-pick-assign'), 'expected_version' => 1, 'assigned_picker_legacy_id' => $user->legacy_id,
        ], $user, self::PERMISSIONS, true);
        $pick = $material->startPickingTask($pick->id, [
            'client_command_id' => $this->id('dw-pick-start'), 'expected_version' => $pick->business_version,
        ], $user, self::PERMISSIONS, true);
        $pick = $material->confirmPickingTask($pick->id, [
            'client_command_id' => $this->id('dw-pick-confirm'), 'expected_version' => $pick->business_version,
            'lines' => [['picking_task_line_id' => $pick->lines->first()->id, 'actual_pick_qty' => 6]],
        ], $user, self::PERMISSIONS, true);
        $pickLine = $pick->lines->first();
        $serialIds = [910001, 910002, 910003, 910004, 910005, 910006];
        $pickLine->update(['serial_control_type' => 'unit_serial', 'serial_snapshot' => ['inventory_serial_ids' => $serialIds]]);

        $first = $material->createDelivery([
            'client_command_id' => $this->id('dt-first'), 'picking_task_id' => $pick->id,
            'expected_version' => $pick->business_version,
            'lines' => [['picking_task_line_id' => $pickLine->id, 'delivery_qty' => 2, 'serial_ids' => array_slice($serialIds, 0, 2)]],
        ], $user, self::PERMISSIONS, true);
        $this->expectDomain('delivery_serial_already_allocated', fn () => $material->createDelivery([
            'client_command_id' => $this->id('dt-duplicate-serial'), 'picking_task_id' => $pick->id,
            'expected_version' => $pick->fresh()->business_version,
            'lines' => [['picking_task_line_id' => $pickLine->id, 'delivery_qty' => 1, 'serial_ids' => [$serialIds[0]]]],
        ], $user, self::PERMISSIONS, true), 409);
        $second = $material->createDelivery([
            'client_command_id' => $this->id('dt-second'), 'picking_task_id' => $pick->id,
            'expected_version' => $pick->fresh()->business_version,
            'lines' => [['picking_task_line_id' => $pickLine->id, 'delivery_qty' => 4, 'serial_ids' => array_slice($serialIds, 2)]],
        ], $user, self::PERMISSIONS, true);
        $dispatcherUserId = $user->legacy_id + 1;
        DB::table('erp_legacy_admin_users')->insert([
            'legacy_id' => $dispatcherUserId, 'username' => $this->id('courier'), 'nickname' => '调度配送员',
            'status' => 'normal', 'auth_group_names' => '[]', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $wave = app(ProductionDeliveryWaveService::class)->create([
            'client_command_id' => $this->id('dw-create'),
            'production_preparation_order_id' => $preparation->id,
            'tasks' => [
                ['delivery_id' => $first->id, 'assignment_mode' => 'pool_claim', 'zone_pool_code' => 'ZONE-A'],
                ['delivery_id' => $second->id, 'assignment_mode' => 'dispatcher_assign', 'delivery_user_legacy_id' => $dispatcherUserId],
            ],
        ], $user, self::PERMISSIONS, true);
        $this->assertCount(2, $wave->deliveryTasks);
        $this->assertEqualsCanonicalizing([2.0, 4.0], $wave->deliveryTasks->map(fn ($delivery) => (float) $delivery->lines->sum('delivery_qty'))->all());
        $this->assertEqualsCanonicalizing($serialIds, $wave->deliveryTasks->flatMap(fn ($delivery) => $delivery->lines->flatMap(fn ($line) => $line->serial_snapshot['inventory_serial_ids'] ?? []))->all());
        $this->assertSame('pool_claim', $first->fresh()->assignment_mode);
        $this->assertSame('dispatcher_assign', $second->fresh()->assignment_mode);
        $this->assertSame($dispatcherUserId, (int) $second->fresh()->delivery_user_legacy_id);

        $waveService = app(ProductionDeliveryWaveService::class);
        $first = $waveService->poolClaim($first->id, ['expected_version' => $first->fresh()->business_version], $user, self::PERMISSIONS, true);
        $this->assertSame($user->legacy_id, (int) $first->delivery_user_legacy_id);

        foreach ([$first->fresh(), $second->fresh()] as $delivery) {
            $delivery = $material->dispatchDelivery($delivery->id, [
                'client_command_id' => $this->id('dt-dispatch-'.$delivery->id), 'expected_version' => $delivery->business_version,
            ], $user, self::PERMISSIONS, true);
            $delivery = $material->deliverDelivery($delivery->id, [
                'client_command_id' => $this->id('dt-deliver-'.$delivery->id), 'expected_version' => $delivery->business_version,
            ], $user, self::PERMISSIONS, true);
            $material->receiveDelivery($delivery->id, [
                'client_command_id' => $this->id('dt-receive-'.$delivery->id), 'expected_version' => $delivery->business_version,
                'lines' => $delivery->lines->map(fn ($line) => [
                    'delivery_line_id' => $line->id, 'accepted_qty' => (float) $line->delivery_qty, 'rejected_qty' => 0,
                ])->all(),
            ], $user, self::PERMISSIONS, true);
        }
        $this->assertSame(2, DB::table('erp_material_receipts')->whereIn('delivery_id', [$first->id, $second->id])->count());
    }

    public function test_reserved_stock_prebuild_direct_handover_completes_only_after_target_owner_accepts(): void
    {
        [$user, $targetWorkOrder, $requirement] = $this->fixture();
        $source = $this->reservedSource($user, $targetWorkOrder, $requirement, 'flow_only');
        $completion = app(WorkOrderCompletionService::class)->submit($source['workOrder']->id, [
            'client_command_id' => $this->id('direct-completion'), 'expected_version' => 1,
            'output_record_ids' => [$source['output']->id],
        ], $user, ['production.completion.create'], true);
        app(WorkOrderCompletionService::class)->review($completion['completion_id'], [
            'client_command_id' => $this->id('direct-review'), 'expected_version' => 1, 'decision' => 'approve',
        ], $user, ['production.completion.review'], true);

        $handover = DB::table('erp_production_operation_handovers')->where('output_record_id', $source['output']->id)->first();
        $this->assertNotNull($handover);
        $this->assertSame('HANDED_OVER', $source['output']->fresh()->status);
        $this->assertNotSame('COMPLETED', $source['workOrder']->fresh()->status);
        app(ProductionHandoverService::class)->accept($handover->id, [
            'client_command_id' => $this->id('direct-accept'), 'expected_version' => 1,
        ], $user, ['production.handover.receive']);
        $this->assertSame('COMPLETED', $source['workOrder']->fresh()->status);
        $this->assertSame(2.0, (float) DB::table('erp_production_target_material_requirements')
            ->where('id', $source['targetRequirementId'])->value('satisfied_base_qty'));
    }

    public function test_reserved_stock_prebuild_warehouse_receipt_is_not_common_available_and_only_target_issue_can_consume_it(): void
    {
        [$user, $targetWorkOrder, $requirement, $balance] = $this->fixture();
        $source = $this->reservedSource($user, $targetWorkOrder, $requirement, 'warehouse_required');
        $completion = app(WorkOrderCompletionService::class)->submit($source['workOrder']->id, [
            'client_command_id' => $this->id('reserved-completion'), 'expected_version' => 1,
            'output_record_ids' => [$source['output']->id],
        ], $user, ['production.completion.create'], true);
        app(WorkOrderCompletionService::class)->review($completion['completion_id'], [
            'client_command_id' => $this->id('reserved-review'), 'expected_version' => 1, 'decision' => 'approve',
        ], $user, ['production.completion.review'], true);
        $beforeAvailable = (float) $balance->fresh()->quantity_available;
        $posted = app(ProductionOutputService::class)->warehouse($source['output']->id, [
            'client_command_id' => $this->id('reserved-warehouse'), 'expected_version' => 3,
            'warehouse_id' => $balance->warehouse_id, 'location_id' => $balance->location_id,
            'batch_no' => $balance->batch_no, 'posted_base_qty' => 2,
        ], $user, ['production.output.warehouse']);

        $this->assertNotNull($posted['production_inventory_reservation_id']);
        $this->assertNotNull($posted['internal_issue_task_id']);
        $this->assertSame($beforeAvailable, (float) $balance->fresh()->quantity_available,
            '保留入库只增加在库与锁定量，不得增加通用可用量');
        $this->assertSame(2.0, (float) $balance->fresh()->quantity_locked);
        $this->assertSame('COMPLETED', $source['workOrder']->fresh()->status);
        $issue = DB::table('erp_production_internal_issue_tasks')->where('id', $posted['internal_issue_task_id'])->first();
        app(ProductionInternalIssueService::class)->dispatch($issue->id, [
            'client_command_id' => $this->id('reserved-dispatch'), 'expected_version' => 1,
        ], $user, ['production.output.issue']);
        app(ProductionInternalIssueService::class)->receive($issue->id, [
            'client_command_id' => $this->id('reserved-receive'), 'expected_version' => 2,
        ], $user, ['production.output.receive']);
        $this->assertSame(0.0, (float) $balance->fresh()->quantity_locked);
        $this->assertSame($beforeAvailable, (float) $balance->fresh()->quantity_available);
        $this->assertDatabaseHas('erp_production_inventory_reservations', [
            'id' => $posted['production_inventory_reservation_id'], 'status' => 'CONSUMED',
        ]);
    }

    public function test_common_inventory_stock_prebuild_only_completes_after_real_available_inventory_receipt(): void
    {
        [$user, $targetWorkOrder, $requirement, $balance] = $this->fixture();
        $source = $this->reservedSource($user, $targetWorkOrder, $requirement, 'warehouse_required');
        $source['workOrder']->update([
            'stocking_purpose' => 'common_inventory', 'reserved_for_work_order_id' => null,
            'reserved_for_production_unit_id' => null, 'reserved_for_target_operation_id' => null,
        ]);
        $completion = app(WorkOrderCompletionService::class)->submit($source['workOrder']->id, [
            'client_command_id' => $this->id('common-completion'), 'expected_version' => 1,
            'output_record_ids' => [$source['output']->id],
        ], $user, ['production.completion.create'], true);
        app(WorkOrderCompletionService::class)->review($completion['completion_id'], [
            'client_command_id' => $this->id('common-review'), 'expected_version' => 1, 'decision' => 'approve',
        ], $user, ['production.completion.review'], true);
        $this->assertNotSame('COMPLETED', $source['workOrder']->fresh()->status);
        $beforeAvailable = (float) $balance->fresh()->quantity_available;
        $posted = app(ProductionOutputService::class)->warehouse($source['output']->id, [
            'client_command_id' => $this->id('common-warehouse'), 'expected_version' => 3,
            'warehouse_id' => $balance->warehouse_id, 'location_id' => $balance->location_id,
            'batch_no' => $balance->batch_no, 'posted_base_qty' => 2,
        ], $user, ['production.output.warehouse']);
        $this->assertNull($posted['production_inventory_reservation_id']);
        $this->assertSame($beforeAvailable + 2, (float) $balance->fresh()->quantity_available);
        $this->assertSame('COMPLETED', $source['workOrder']->fresh()->status);
    }

    public function test_reserved_serialized_prebuild_output_keeps_parent_to_final_output_lineage(): void
    {
        [$user, $targetWorkOrder, $requirement, $balance] = $this->fixture();
        Item::query()->whereKey($requirement->component_item_id)->update([
            'is_serial_managed' => true, 'serial_tracking_mode' => 'required', 'serial_number_prefix' => 'SEMI',
        ]);
        DB::table('erp_production_quantity_operations')->where('id', $targetWorkOrder->test_target_id)->update([
            'planned_base_qty' => 1, 'completed_base_qty' => 0, 'remaining_base_qty' => 1,
            'kitting_required' => false, 'output_item_id_snapshot' => $targetWorkOrder->output_item_id,
            'output_mode_snapshot' => 'warehouse_required', 'updated_at' => now(),
        ]);
        $source = $this->reservedSource($user, $targetWorkOrder, $requirement, 'warehouse_required', 1);
        $completion = app(WorkOrderCompletionService::class)->submit($source['workOrder']->id, [
            'client_command_id' => $this->id('serial-completion'), 'expected_version' => 1,
            'output_record_ids' => [$source['output']->id],
        ], $user, ['production.completion.create'], true);
        app(WorkOrderCompletionService::class)->review($completion['completion_id'], [
            'client_command_id' => $this->id('serial-review'), 'expected_version' => 1, 'decision' => 'approve',
        ], $user, ['production.completion.review'], true);
        $posted = app(ProductionOutputService::class)->warehouse($source['output']->id, [
            'client_command_id' => $this->id('serial-warehouse'), 'expected_version' => 3,
            'warehouse_id' => $balance->warehouse_id, 'location_id' => $balance->location_id,
            'batch_no' => $balance->batch_no, 'posted_base_qty' => 1,
        ], $user, ['production.output.warehouse']);
        $parentSerialId = $source['output']->fresh()->inventory_serial_id;
        $this->assertNotNull($parentSerialId);
        $this->assertDatabaseHas('erp_inventory_serials', ['id' => $parentSerialId, 'serial_status' => 'continuation_reserved']);

        $issue = DB::table('erp_production_internal_issue_tasks')->where('id', $posted['internal_issue_task_id'])->first();
        app(ProductionInternalIssueService::class)->dispatch($issue->id, [
            'client_command_id' => $this->id('serial-dispatch'), 'expected_version' => 1,
        ], $user, ['production.output.issue']);
        $received = app(ProductionInternalIssueService::class)->receive($issue->id, [
            'client_command_id' => $this->id('serial-receive'), 'expected_version' => 2,
        ], $user, ['production.output.receive']);
        $task = DB::table('erp_production_tasks')->where('id', $issue->target_task_id)->first();
        $execution = app(ProductionExecutionActionService::class);
        $started = $execution->start($task->id, 'quantity_operation', $issue->target_id, [
            'client_command_id' => $this->id('serial-target-start'), 'expected_version' => $received['target_business_version'],
        ], $user, ['production.task.start']);
        $completed = $execution->complete($task->id, 'quantity_operation', $issue->target_id, [
            'client_command_id' => $this->id('serial-target-complete'), 'expected_version' => $started['target_business_version'],
            'completed_base_qty' => 1, 'scrapped_base_qty' => 0,
        ], $user, ['production.task.complete']);
        $this->assertDatabaseHas('erp_production_output_lineage_links', [
            'parent_output_record_id' => $source['output']->id,
            'child_output_record_id' => $completed['output_record_id'],
            'parent_inventory_serial_id' => $parentSerialId,
            'relation_type' => 'internal_issue',
        ]);
    }

    public function test_material_selector_filters_before_pagination_and_preserves_target_scope(): void
    {
        [$user, $workOrder, $requirement] = $this->fixture();
        $task = DB::table('erp_production_tasks')->where('work_order_id', $workOrder->id)->first();
        $parent = \App\Models\Erp\ItemCategory::create(['category_code' => $this->id('parent'), 'category_name' => '电气元件']);
        $child = \App\Models\Erp\ItemCategory::create(['category_code' => $this->id('child'), 'category_name' => '接触器', 'parent_id' => $parent->id]);
        $item = Item::findOrFail($requirement->component_item_id);
        $item->update(['category_id' => $child->id, 'spec' => '精确规格25A']);
        $source = DB::table('erp_production_target_material_requirements')->where('target_id', $workOrder->test_target_id)->where('target_type', 'quantity_operation')->first();
        for ($i = 0; $i < 24; $i++) {
            $copy = $item->replicate(); $copy->item_code = $this->id('search-item-'.$i); $copy->spec = '其他规格'; $copy->save();
            $material = $requirement->replicate(); $material->component_item_id = $copy->id; $material->line_no = 100 + $i; $material->save();
            $supply = (array) DB::table('erp_work_order_material_supply_rules')->where('id', $source->material_supply_rule_snapshot_id)->first();
            unset($supply['id']); $supply['material_requirement_id'] = $material->id; $supply['component_item_id'] = $copy->id;
            $supplyId = DB::table('erp_work_order_material_supply_rules')->insertGetId($supply);
            $row = (array) $source; unset($row['id']); $row['component_item_id'] = $copy->id; $row['material_requirement_id'] = $material->id; $row['material_supply_rule_snapshot_id'] = $supplyId;
            DB::table('erp_production_target_material_requirements')->insert($row);
        }
        $service = app(ProductionKittingService::class);
        $args = [$task->id, 'quantity_operation', $workOrder->test_target_id];
        $first = $service->materialOptions(...[...$args, ['mode' => 'supplement', 'category_id' => $parent->id], $user, self::PERMISSIONS]);
        $second = $service->materialOptions(...[...$args, ['mode' => 'supplement', 'page' => 2], $user, self::PERMISSIONS]);
        $this->assertSame(25, $first['total']); $this->assertCount(20, $first['data']); $this->assertCount(5, $second['data']);
        $this->assertEmpty(array_intersect(array_column($first['data'], 'key'), array_column($second['data'], 'key')));
        $this->assertContains($parent->id, array_column($first['categories'], 'id'));
        $match = $service->materialOptions(...[...$args, ['mode' => 'supplement', 'keyword' => '精确规格25A'], $user, self::PERMISSIONS]);
        $this->assertSame(1, $match['total']); $this->assertSame($item->id, $match['data'][0]['component_item_id']);
        $none = $service->materialOptions(...[...$args, ['mode' => 'supplement', 'category_id' => 0], $user, self::PERMISSIONS]);
        $this->assertSame(0, $none['total']);
        $this->expectDomain('responsible_user_required', fn () => $service->materialOptions(...[...$args, ['mode' => 'supplement'], (object) ['legacy_id' => -1], self::PERMISSIONS]), 403);
        $this->expectDomain('permission_denied', fn () => $service->materialOptions(...[...$args, ['mode' => 'supplement'], $user, []]), 403);
    }

    public function test_return_selector_counts_only_real_remaining_receipt_sources(): void
    {
        [$user, $workOrder, $requirement, $balance] = $this->fixture();
        $task = DB::table('erp_production_tasks')->where('work_order_id', $workOrder->id)->first();
        $service = app(ProductionKittingService::class);
        $args = [$task->id, 'quantity_operation', $workOrder->test_target_id, ['mode' => 'return'], $user, self::PERMISSIONS];
        $this->assertSame(0, $service->materialOptions(...$args)['total']);
        $this->deliverQuantity($user, $workOrder, $requirement, $balance, 5, 'selector');
        $page = $service->materialOptions(...$args);
        $this->assertSame(1, $page['total']);
        $this->assertSame(5.0, (float) $page['data'][0]['returnable_base_qty']);
        $this->assertSame($balance->warehouse_id, $page['data'][0]['warehouse_id']);
        app(ProductionMaterialReturnService::class)->create([
            'client_command_id' => $this->id('selector-return'), 'expected_version' => 1,
            'task_id' => $task->id, 'target_type' => 'quantity_operation', 'target_id' => $workOrder->test_target_id,
            'return_type' => 'normal_return', 'reason' => '全部余料退回',
            'lines' => [['material_requirement_id' => $requirement->id, 'warehouse_id' => $balance->warehouse_id,
                'location_id' => $balance->location_id, 'batch_no' => $balance->batch_no, 'return_base_qty' => 5]],
        ], $user, self::PERMISSIONS);
        $this->assertSame(0, $service->materialOptions(...$args)['total']);
    }

    public function test_formal_requirements_drive_partial_picking_delivery_and_receipt_with_one_inventory_fact(): void
    {
        [$user, $workOrder, $requirement, $balance] = $this->fixture();
        $service = app(ProductionMaterialExecutionService::class);

        $task = $service->createPickingTask([
            'client_command_id' => $this->id('create-pick'), 'work_order_id' => $workOrder->id,
            'expected_version' => 1, 'warehouse_id' => $balance->warehouse_id,
            'lines' => [$this->pickLine($workOrder, $requirement, $balance, 6)],
        ], $user, self::PERMISSIONS, true);
        $this->assertSame('WAIT_PICK', $task->status);
        $this->assertSame(1, $task->lines->count());

        $assigned = $service->assignPickingTask($task->id, ['client_command_id' => $this->id('assign'), 'expected_version' => 1, 'assigned_picker_legacy_id' => $user->legacy_id], $user, self::PERMISSIONS, true);
        $picking = $service->startPickingTask($task->id, ['client_command_id' => $this->id('start'), 'expected_version' => $assigned->business_version], $user, self::PERMISSIONS, true);
        $confirmedPayload = ['client_command_id' => $this->id('confirm'), 'expected_version' => $picking->business_version, 'lines' => [['picking_task_line_id' => $task->lines->first()->id, 'actual_pick_qty' => 6]]];
        $picked = $service->confirmPickingTask($task->id, $confirmedPayload, $user, self::PERMISSIONS, true);
        $replay = $service->confirmPickingTask($task->id, $confirmedPayload, $user, self::PERMISSIONS, true);

        $this->assertSame($picked->id, $replay->id);
        $this->assertSame('PICKED', $picked->status);
        $this->assertSame(1, DB::table('erp_inventory_transactions')->where('source_type', 'material_picking_task')->where('source_id', $task->id)->count());
        $this->assertSame(14.0, (float) $balance->fresh()->quantity_available);
        $this->assertSame(6.0, (float) $requirement->fresh()->picked_qty);
        $this->assertSame(6.0, (float) $requirement->fresh()->issued_qty);
        $this->assertSame(4.0, (float) $requirement->fresh()->remaining_qty);

        $delivery = $service->createDelivery([
            'client_command_id' => $this->id('delivery'), 'picking_task_id' => $task->id,
            'expected_version' => $picked->business_version,
            'lines' => [['picking_task_line_id' => $task->lines->first()->id, 'delivery_qty' => 4]],
        ], $user, self::PERMISSIONS, true);
        $inTransit = $service->dispatchDelivery($delivery->id, ['client_command_id' => $this->id('dispatch'), 'expected_version' => 1, 'delivery_user_legacy_id' => $user->legacy_id], $user, self::PERMISSIONS, true);
        $delivered = $service->deliverDelivery($delivery->id, ['client_command_id' => $this->id('deliver'), 'expected_version' => $inTransit->business_version], $user, self::PERMISSIONS, true);
        $line = $delivered->lines->first();
        $receipt = $service->receiveDelivery($delivery->id, [
            'client_command_id' => $this->id('receive-one'), 'expected_version' => $delivered->business_version,
            'lines' => [['delivery_line_id' => $line->id, 'accepted_qty' => 3, 'rejected_qty' => 0]],
        ], $user, self::PERMISSIONS, true);
        $settled = $service->receiveDelivery($delivery->id, [
            'client_command_id' => $this->id('receive-two'), 'expected_version' => $receipt->delivery->business_version,
            'lines' => [['delivery_line_id' => $line->id, 'accepted_qty' => 1, 'rejected_qty' => 0]],
        ], $user, self::PERMISSIONS, true);

        $this->assertSame(2, DB::table('erp_material_receipts')->where('delivery_id', $delivery->id)->count());
        $this->assertSame('RECEIVED', $settled->delivery->fresh()->status);
        $this->assertSame(4.0, (float) $requirement->fresh()->delivered_qty);
        $this->assertSame(4.0, (float) $requirement->fresh()->received_qty);
        $this->assertSame(5, DB::table('erp_production_material_events')->where('aggregate_type', 'delivery')->where('aggregate_id', $delivery->id)->count());
    }

    public function test_rejects_over_pick_stale_version_and_direct_cancel_after_inventory_posting(): void
    {
        [$user, $workOrder, $requirement, $balance] = $this->fixture();
        $service = app(ProductionMaterialExecutionService::class);

        $this->expectDomain('pick_quantity_exceeded', fn () => $service->createPickingTask([
            'client_command_id' => $this->id('over'), 'work_order_id' => $workOrder->id, 'expected_version' => 1, 'warehouse_id' => $balance->warehouse_id,
            'lines' => [$this->pickLine($workOrder, $requirement, $balance, 11)],
        ], $user, self::PERMISSIONS, true));

        $task = $service->createPickingTask([
            'client_command_id' => $this->id('task'), 'work_order_id' => $workOrder->id, 'expected_version' => 1, 'warehouse_id' => $balance->warehouse_id,
            'lines' => [$this->pickLine($workOrder, $requirement, $balance, 2)],
        ], $user, self::PERMISSIONS, true);
        $this->expectDomain('version_conflict', fn () => $service->startPickingTask($task->id, ['client_command_id' => $this->id('stale'), 'expected_version' => 99], $user, self::PERMISSIONS, true), 409);
        $assigned = $service->assignPickingTask($task->id, ['client_command_id' => $this->id('assign'), 'expected_version' => 1, 'assigned_picker_legacy_id' => $user->legacy_id], $user, self::PERMISSIONS, true);
        $picking = $service->startPickingTask($task->id, ['client_command_id' => $this->id('start'), 'expected_version' => $assigned->business_version], $user, self::PERMISSIONS, true);
        $picked = $service->confirmPickingTask($task->id, ['client_command_id' => $this->id('confirm'), 'expected_version' => $picking->business_version, 'lines' => [['picking_task_line_id' => $task->lines->first()->id, 'actual_pick_qty' => 2]]], $user, self::PERMISSIONS, true);
        $this->expectDomain('reverse_required', fn () => $service->cancelPickingTask($task->id, ['client_command_id' => $this->id('cancel'), 'expected_version' => $picked->business_version, 'reason' => '不可直接取消'], $user, self::PERMISSIONS, true), 409);
    }

    public function test_unposted_picking_can_be_cancelled_from_waiting_or_active_without_inventory_side_effects(): void
    {
        [$user, $workOrder, $requirement, $balance] = $this->fixture();
        $service = app(ProductionMaterialExecutionService::class);
        $initialAvailable = (float) $balance->quantity_available;

        $waiting = $service->createPickingTask([
            'client_command_id' => $this->id('cancel-waiting-create'), 'work_order_id' => $workOrder->id,
            'expected_version' => 1, 'warehouse_id' => $balance->warehouse_id,
            'lines' => [$this->pickLine($workOrder, $requirement, $balance, 2)],
        ], $user, self::PERMISSIONS, true);
        $cancelPayload = [
            'client_command_id' => $this->id('cancel-waiting'), 'expected_version' => 1,
            'reason' => '仓库批次调整',
        ];
        $cancelled = $service->cancelPickingTask($waiting->id, $cancelPayload, $user, self::PERMISSIONS, true);
        $replay = $service->cancelPickingTask($waiting->id, $cancelPayload, $user, self::PERMISSIONS, true);
        $this->assertSame($cancelled->id, $replay->id);
        $this->assertSame('CANCELLED', $cancelled->status);
        $this->assertTrue($cancelled->lines->every(fn ($line) => $line->status === 'CANCELLED'));

        $active = $service->createPickingTask([
            'client_command_id' => $this->id('cancel-active-create'), 'work_order_id' => $workOrder->id,
            'expected_version' => 1, 'warehouse_id' => $balance->warehouse_id,
            'lines' => [$this->pickLine($workOrder, $requirement, $balance, 2)],
        ], $user, self::PERMISSIONS, true);
        $assigned = $service->assignPickingTask($active->id, [
            'client_command_id' => $this->id('cancel-active-assign'), 'expected_version' => 1,
            'assigned_picker_legacy_id' => $user->legacy_id,
        ], $user, self::PERMISSIONS, true);
        $picking = $service->startPickingTask($active->id, [
            'client_command_id' => $this->id('cancel-active-start'), 'expected_version' => $assigned->business_version,
        ], $user, self::PERMISSIONS, true);
        $cancelledActive = $service->cancelPickingTask($active->id, [
            'client_command_id' => $this->id('cancel-active'), 'expected_version' => $picking->business_version,
            'reason' => '生产计划暂停',
        ], $user, self::PERMISSIONS, true);

        $this->assertSame('CANCELLED', $cancelledActive->status);
        $this->assertSame($initialAvailable, (float) $balance->fresh()->quantity_available);
        $this->assertSame(0.0, (float) $requirement->fresh()->picked_qty);
        $this->assertSame(0, DB::table('erp_inventory_transactions')->where('source_type', 'material_picking_task')
            ->whereIn('source_id', [$waiting->id, $active->id])->count());
    }

    public function test_rejected_delivery_remains_open_until_exact_redelivery_is_received(): void
    {
        [$user, $workOrder, $requirement, $balance] = $this->fixture();
        $service = app(ProductionMaterialExecutionService::class);

        $task = $service->createPickingTask([
            'client_command_id' => $this->id('reject-pick'), 'work_order_id' => $workOrder->id,
            'expected_version' => 1, 'warehouse_id' => $balance->warehouse_id,
            'lines' => [$this->pickLine($workOrder, $requirement, $balance, 4)],
        ], $user, self::PERMISSIONS, true);
        $assigned = $service->assignPickingTask($task->id, [
            'client_command_id' => $this->id('reject-assign'), 'expected_version' => 1,
            'assigned_picker_legacy_id' => $user->legacy_id,
        ], $user, self::PERMISSIONS, true);
        $picking = $service->startPickingTask($task->id, [
            'client_command_id' => $this->id('reject-start'), 'expected_version' => $assigned->business_version,
        ], $user, self::PERMISSIONS, true);
        $picked = $service->confirmPickingTask($task->id, [
            'client_command_id' => $this->id('reject-confirm'), 'expected_version' => $picking->business_version,
            'lines' => [['picking_task_line_id' => $task->lines->first()->id, 'actual_pick_qty' => 4]],
        ], $user, self::PERMISSIONS, true);

        $delivery = $service->createDelivery([
            'client_command_id' => $this->id('reject-delivery'), 'picking_task_id' => $task->id,
            'expected_version' => $picked->business_version,
            'lines' => [['picking_task_line_id' => $task->lines->first()->id, 'delivery_qty' => 4]],
        ], $user, self::PERMISSIONS, true);
        $inTransit = $service->dispatchDelivery($delivery->id, [
            'client_command_id' => $this->id('reject-dispatch'), 'expected_version' => $delivery->business_version,
            'delivery_user_legacy_id' => $user->legacy_id,
        ], $user, self::PERMISSIONS, true);
        $delivered = $service->deliverDelivery($delivery->id, [
            'client_command_id' => $this->id('reject-deliver'), 'expected_version' => $inTransit->business_version,
        ], $user, self::PERMISSIONS, true);
        $receipt = $service->receiveDelivery($delivery->id, [
            'client_command_id' => $this->id('reject-receive'), 'expected_version' => $delivered->business_version,
            'lines' => [[
                'delivery_line_id' => $delivered->lines->first()->id,
                'accepted_qty' => 3, 'rejected_qty' => 1, 'reject_reason' => '包装破损',
            ]],
        ], $user, self::PERMISSIONS, true);

        $this->assertSame('RECEIVED', $receipt->delivery->status);
        $this->assertSame('PARTIALLY_RECEIVED', $task->fresh()->status);
        $this->assertSame(3.0, (float) $requirement->fresh()->received_qty);

        $this->expectDomain('redelivery_quantity_exceeded', fn () => $service->createDelivery([
            'client_command_id' => $this->id('reject-over-redelivery'), 'picking_task_id' => $task->id,
            'expected_version' => $task->fresh()->business_version, 'delivery_type' => 'redelivery',
            'source_delivery_id' => $delivery->id,
            'lines' => [['picking_task_line_id' => $task->lines->first()->id, 'delivery_qty' => 2]],
        ], $user, self::PERMISSIONS, true));

        $redelivery = $service->createDelivery([
            'client_command_id' => $this->id('reject-redelivery'), 'picking_task_id' => $task->id,
            'expected_version' => $task->fresh()->business_version, 'delivery_type' => 'redelivery',
            'source_delivery_id' => $delivery->id,
            'lines' => [['picking_task_line_id' => $task->lines->first()->id, 'delivery_qty' => 1]],
        ], $user, self::PERMISSIONS, true);
        $redeliveryInTransit = $service->dispatchDelivery($redelivery->id, [
            'client_command_id' => $this->id('reject-redelivery-dispatch'), 'expected_version' => $redelivery->business_version,
            'delivery_user_legacy_id' => $user->legacy_id,
        ], $user, self::PERMISSIONS, true);
        $redeliveryDelivered = $service->deliverDelivery($redelivery->id, [
            'client_command_id' => $this->id('reject-redelivery-deliver'), 'expected_version' => $redeliveryInTransit->business_version,
        ], $user, self::PERMISSIONS, true);
        $receivePayload = [
            'client_command_id' => $this->id('reject-redelivery-receive'),
            'expected_version' => $redeliveryDelivered->business_version,
            'lines' => [[
                'delivery_line_id' => $redeliveryDelivered->lines->first()->id,
                'accepted_qty' => 1, 'rejected_qty' => 0,
            ]],
        ];
        $completed = $service->receiveDelivery($redelivery->id, $receivePayload, $user, self::PERMISSIONS, true);
        $replay = $service->receiveDelivery($redelivery->id, $receivePayload, $user, self::PERMISSIONS, true);

        $this->assertSame($completed->id, $replay->id);
        $this->assertSame('RECEIVED', $task->fresh()->status);
        $this->assertSame(4.0, (float) $requirement->fresh()->received_qty);
        $this->assertSame(2, DB::table('erp_material_receipts')->whereIn('delivery_id', [$delivery->id, $redelivery->id])->count());
    }

    public function test_ready_delivery_can_be_cancelled_and_its_quantity_reallocated(): void
    {
        [$user, $workOrder, $requirement, $balance] = $this->fixture();
        $service = app(ProductionMaterialExecutionService::class);
        $task = $service->createPickingTask([
            'client_command_id' => $this->id('delivery-cancel-pick'), 'work_order_id' => $workOrder->id,
            'expected_version' => 1, 'warehouse_id' => $balance->warehouse_id,
            'lines' => [$this->pickLine($workOrder, $requirement, $balance, 4)],
        ], $user, self::PERMISSIONS, true);
        $assigned = $service->assignPickingTask($task->id, [
            'client_command_id' => $this->id('delivery-cancel-assign'), 'expected_version' => 1,
            'assigned_picker_legacy_id' => $user->legacy_id,
        ], $user, self::PERMISSIONS, true);
        $picking = $service->startPickingTask($task->id, [
            'client_command_id' => $this->id('delivery-cancel-start'), 'expected_version' => $assigned->business_version,
        ], $user, self::PERMISSIONS, true);
        $picked = $service->confirmPickingTask($task->id, [
            'client_command_id' => $this->id('delivery-cancel-confirm'), 'expected_version' => $picking->business_version,
            'lines' => [['picking_task_line_id' => $task->lines->first()->id, 'actual_pick_qty' => 4]],
        ], $user, self::PERMISSIONS, true);
        $delivery = $service->createDelivery([
            'client_command_id' => $this->id('delivery-cancel-create'), 'picking_task_id' => $task->id,
            'expected_version' => $picked->business_version,
            'lines' => [['picking_task_line_id' => $task->lines->first()->id, 'delivery_qty' => 4]],
        ], $user, self::PERMISSIONS, true);
        $cancelPayload = [
            'client_command_id' => $this->id('delivery-cancel'), 'expected_version' => $delivery->business_version,
            'reason' => '目标工位填写错误',
        ];
        $cancelled = $service->cancelDelivery($delivery->id, $cancelPayload, $user, self::PERMISSIONS, true);
        $replay = $service->cancelDelivery($delivery->id, $cancelPayload, $user, self::PERMISSIONS, true);

        $this->assertSame($cancelled->id, $replay->id);
        $this->assertSame('CANCELLED', $cancelled->status);
        $this->assertSame('PICKED', $task->fresh()->status);
        $replacement = $service->createDelivery([
            'client_command_id' => $this->id('delivery-cancel-replacement'), 'picking_task_id' => $task->id,
            'expected_version' => $task->fresh()->business_version,
            'lines' => [['picking_task_line_id' => $task->lines->first()->id, 'delivery_qty' => 4]],
        ], $user, self::PERMISSIONS, true);
        $this->assertSame('READY', $replacement->status);
        $this->assertSame(4.0, (float) $replacement->lines->first()->delivery_qty);
        $this->expectDomain('permission_denied', fn () => $service->cancelDelivery($replacement->id, [
            'client_command_id' => $this->id('delivery-cancel-forbidden'), 'expected_version' => $replacement->business_version,
            'reason' => '越权取消',
        ], $user, [], true), 403);
    }

    public function test_additional_supplement_keeps_standard_requirement_and_creates_separate_demand(): void
    {
        [$user, $workOrder, $requirement] = $this->fixture();
        $task = DB::table('erp_production_tasks')->where('work_order_id', $workOrder->id)->first();
        $service = app(ProductionMaterialSupplementService::class);

        $submitted = $service->request([
            'client_command_id' => $this->id('supplement-request'), 'expected_version' => 1,
            'task_id' => $task->id, 'target_type' => 'quantity_operation', 'target_id' => $workOrder->test_target_id,
            'blocking' => true, 'reason' => '生产损耗超标',
            'lines' => [['component_item_id' => $requirement->component_item_id, 'additional_base_qty' => 2]],
        ], $user, self::PERMISSIONS);
        $this->assertSame('SUBMITTED', $submitted['status']);
        $this->assertSame(10.0, (float) $requirement->fresh()->base_required_qty);

        $approved = $service->approve($submitted['id'], [
            'client_command_id' => $this->id('supplement-approve'), 'expected_version' => 1,
            'approved' => true, 'reason' => '批准返工补料',
        ], $user, self::PERMISSIONS);
        $this->assertSame('APPROVED', $approved['status']);
        $this->assertSame(10.0, (float) $requirement->fresh()->base_required_qty);
        $this->assertSame(2.0, (float) DB::table('erp_production_target_material_requirements')
            ->where('target_type', 'quantity_operation')->where('target_id', $workOrder->test_target_id)
            ->where('requirement_kind', 'supplement_'.$submitted['id'])->value('required_base_qty'));
    }

    public function test_rejected_supplement_does_not_mutate_frozen_or_target_requirements(): void
    {
        [$user, $workOrder, $requirement] = $this->fixture();
        $task = DB::table('erp_production_tasks')->where('work_order_id', $workOrder->id)->first();
        $service = app(ProductionMaterialSupplementService::class);
        $beforeCount = DB::table('erp_work_order_material_requirements')->where('work_order_id', $workOrder->id)->count();

        $submitted = $service->request([
            'client_command_id' => $this->id('supplement-reject-request'), 'expected_version' => 1,
            'task_id' => $task->id, 'target_type' => 'quantity_operation', 'target_id' => $workOrder->test_target_id,
            'blocking' => false, 'reason' => '申请额外备品',
            'lines' => [['component_item_id' => $requirement->component_item_id, 'additional_base_qty' => 3]],
        ], $user, self::PERMISSIONS);
        $decisionPayload = [
            'client_command_id' => $this->id('supplement-reject'), 'expected_version' => 1,
            'approved' => false, 'reason' => '无额外用料依据',
        ];
        $rejected = $service->approve($submitted['id'], $decisionPayload, $user, self::PERMISSIONS);
        $replay = $service->approve($submitted['id'], $decisionPayload, $user, self::PERMISSIONS);

        $this->assertEquals($rejected, $replay);
        $this->assertSame('REJECTED', $rejected['status']);
        $this->assertSame(10.0, (float) $requirement->fresh()->base_required_qty);
        $this->assertSame($beforeCount, DB::table('erp_work_order_material_requirements')->where('work_order_id', $workOrder->id)->count());
        $this->assertSame(0, DB::table('erp_production_target_material_requirements')
            ->where('target_type', 'quantity_operation')->where('target_id', $workOrder->test_target_id)
            ->where('requirement_kind', 'supplement_'.$submitted['id'])->count());
    }

    public function test_normal_and_quality_returns_have_distinct_available_and_quarantine_inventory_facts(): void
    {
        [$user, $workOrder, $requirement, $balance] = $this->fixture();
        $task = DB::table('erp_production_tasks')->where('work_order_id', $workOrder->id)->first();
        $materialExecution = app(ProductionMaterialExecutionService::class);
        $pick = $materialExecution->createPickingTask([
            'client_command_id' => $this->id('return-pick'), 'work_order_id' => $workOrder->id,
            'expected_version' => 1, 'warehouse_id' => $balance->warehouse_id,
            'lines' => [$this->pickLine($workOrder, $requirement, $balance, 5)],
        ], $user, self::PERMISSIONS, true);
        $assigned = $materialExecution->assignPickingTask($pick->id, ['client_command_id' => $this->id('return-assign'), 'expected_version' => 1, 'assigned_picker_legacy_id' => $user->legacy_id], $user, self::PERMISSIONS, true);
        $picking = $materialExecution->startPickingTask($pick->id, ['client_command_id' => $this->id('return-start'), 'expected_version' => $assigned->business_version], $user, self::PERMISSIONS, true);
        $picked = $materialExecution->confirmPickingTask($pick->id, ['client_command_id' => $this->id('return-confirm'), 'expected_version' => $picking->business_version, 'lines' => [['picking_task_line_id' => $pick->lines->first()->id, 'actual_pick_qty' => 5]]], $user, self::PERMISSIONS, true);
        $delivery = $materialExecution->createDelivery(['client_command_id' => $this->id('return-delivery'), 'picking_task_id' => $pick->id, 'expected_version' => $picked->business_version, 'lines' => [['picking_task_line_id' => $pick->lines->first()->id, 'delivery_qty' => 5]]], $user, self::PERMISSIONS, true);
        $inTransit = $materialExecution->dispatchDelivery($delivery->id, ['client_command_id' => $this->id('return-dispatch'), 'expected_version' => 1, 'delivery_user_legacy_id' => $user->legacy_id], $user, self::PERMISSIONS, true);
        $delivered = $materialExecution->deliverDelivery($delivery->id, ['client_command_id' => $this->id('return-deliver'), 'expected_version' => $inTransit->business_version], $user, self::PERMISSIONS, true);
        $materialExecution->receiveDelivery($delivery->id, ['client_command_id' => $this->id('return-receive'), 'expected_version' => $delivered->business_version, 'lines' => [['delivery_line_id' => $delivered->lines->first()->id, 'accepted_qty' => 5, 'rejected_qty' => 0]]], $user, self::PERMISSIONS, true);

        $mobileRequirements = app(ProductionKittingService::class)->requirements($task->id, 'quantity_operation', $workOrder->test_target_id, $user, self::PERMISSIONS);
        $this->assertSame($requirement->id, $mobileRequirements[0]['material_requirement_id']);
        $this->assertSame($balance->warehouse_id, $mobileRequirements[0]['return_sources'][0]['warehouse_id']);
        $this->assertSame(5.0, $mobileRequirements[0]['return_sources'][0]['returnable_base_qty']);

        $service = app(ProductionMaterialReturnService::class);
        $line = ['material_requirement_id' => $requirement->id, 'warehouse_id' => $balance->warehouse_id,
            'location_id' => $balance->location_id, 'batch_no' => $balance->batch_no, 'return_base_qty' => 2];
        $this->expectDomain('return_quantity_exceeds_received', fn () => $service->create([
            'client_command_id' => $this->id('wrong-return-source'), 'expected_version' => 1,
            'task_id' => $task->id, 'target_type' => 'quantity_operation', 'target_id' => $workOrder->test_target_id,
            'return_type' => 'normal_return', 'reason' => '伪造批次', 'lines' => [array_merge($line, ['batch_no' => 'NOT-RECEIVED'])],
        ], $user, self::PERMISSIONS));

        $normal = $service->create(['client_command_id' => $this->id('normal-return'), 'expected_version' => 1,
            'task_id' => $task->id, 'target_type' => 'quantity_operation', 'target_id' => $workOrder->test_target_id,
            'return_type' => 'normal_return', 'reason' => '正常未用退回', 'lines' => [$line]], $user, self::PERMISSIONS);
        $normalReceived = $service->receive($normal['id'], ['client_command_id' => $this->id('normal-receive'), 'expected_version' => 1], $user, self::PERMISSIONS);
        $this->assertSame('COMPLETED', $normalReceived['status']);
        $this->assertSame(17.0, (float) $balance->fresh()->quantity_available);
        $afterNormal = app(ProductionKittingService::class)->requirements($task->id, 'quantity_operation', $workOrder->test_target_id, $user, self::PERMISSIONS);
        $this->assertSame(5.0, $afterNormal[0]['gross_received_base_qty']);
        $this->assertSame(2.0, $afterNormal[0]['returned_base_qty']);
        $this->assertSame(3.0, $afterNormal[0]['satisfied_base_qty']);

        $quality = $service->create(['client_command_id' => $this->id('quality-return'), 'expected_version' => 1,
            'task_id' => $task->id, 'target_type' => 'quantity_operation', 'target_id' => $workOrder->test_target_id,
            'return_type' => 'quality_return', 'reason' => '物料外观异常', 'lines' => [array_merge($line, ['return_base_qty' => 1])]], $user, self::PERMISSIONS);
        $qualityReceived = $service->receive($quality['id'], ['client_command_id' => $this->id('quality-receive'), 'expected_version' => 1], $user, self::PERMISSIONS);
        $this->assertSame('WAIT_QUALITY', $qualityReceived['status']);
        $afterQualityWarehouseReceipt = app(ProductionKittingService::class)->requirements($task->id, 'quantity_operation', $workOrder->test_target_id, $user, self::PERMISSIONS);
        $this->assertSame(3.0, $afterQualityWarehouseReceipt[0]['returned_base_qty']);
        $this->assertSame(2.0, $afterQualityWarehouseReceipt[0]['satisfied_base_qty']);
        $quarantined = $balance->fresh();
        $this->assertSame(1.0, (float) $quarantined->quantity_pending);
        $this->assertSame(17.0, (float) $quarantined->quantity_available);
        $released = $service->quality($quality['id'], ['client_command_id' => $this->id('quality-pass'),
            'expected_version' => 2, 'passed' => true, 'reason' => '检验合格'], $user, self::PERMISSIONS);
        $this->assertSame('COMPLETED', $released['status']);
        $this->assertSame(0.0, (float) $balance->fresh()->quantity_pending);
        $this->assertSame(18.0, (float) $balance->fresh()->quantity_available);

        $failedQuality = $service->create(['client_command_id' => $this->id('quality-return-failed'), 'expected_version' => 1,
            'task_id' => $task->id, 'target_type' => 'quantity_operation', 'target_id' => $workOrder->test_target_id,
            'return_type' => 'quality_return', 'reason' => '物料变形', 'lines' => [array_merge($line, ['return_base_qty' => 1])]], $user, self::PERMISSIONS);
        $failedQualityReceived = $service->receive($failedQuality['id'], [
            'client_command_id' => $this->id('quality-return-failed-receive'), 'expected_version' => 1,
        ], $user, self::PERMISSIONS);
        $failedPayload = ['client_command_id' => $this->id('quality-return-fail'),
            'expected_version' => $failedQualityReceived['business_version'], 'passed' => false, 'reason' => '确认不可用'];
        $quarantine = $service->quality($failedQuality['id'], $failedPayload, $user, self::PERMISSIONS);
        $quarantineReplay = $service->quality($failedQuality['id'], $failedPayload, $user, self::PERMISSIONS);
        $this->assertEquals($quarantine, $quarantineReplay);
        $this->assertSame('QUARANTINED', $quarantine['status']);
        $this->assertNull($quarantine['inventory_transaction_id']);
        $this->assertSame(1.0, (float) $balance->fresh()->quantity_pending);
        $this->assertSame(18.0, (float) $balance->fresh()->quantity_available);
        $this->assertSame(1, DB::table('erp_production_material_return_inspections')->where('return_id', $failedQuality['id'])->count());
    }

    public function test_return_reduces_kitting_net_and_replenishment_restores_readiness_without_replay_double_count(): void
    {
        [$user, $workOrder, $requirement, $balance] = $this->fixture();
        $task = DB::table('erp_production_tasks')->where('work_order_id', $workOrder->id)->first();
        $targetRequirement = DB::table('erp_production_target_material_requirements')
            ->where('target_type', 'quantity_operation')->where('target_id', $workOrder->test_target_id)->first();
        DB::table('erp_production_target_material_requirements')->where('id', $targetRequirement->id)->update(['required_base_qty' => 5]);

        $this->deliverQuantity($user, $workOrder, $requirement, $balance, 5, 'net-initial');
        $kitting = app(ProductionKittingService::class);
        $received = $kitting->requirements($task->id, 'quantity_operation', $workOrder->test_target_id, $user, self::PERMISSIONS);
        $this->assertSame(5.0, $received[0]['satisfied_base_qty']);
        $this->assertSame(5.0, $received[0]['return_sources'][0]['returnable_base_qty']);

        $returns = app(ProductionMaterialReturnService::class);
        $created = $returns->create([
            'client_command_id' => $this->id('net-return-create'), 'expected_version' => 1,
            'task_id' => $task->id, 'target_type' => 'quantity_operation', 'target_id' => $workOrder->test_target_id,
            'return_type' => 'normal_return', 'reason' => '退回未使用物料',
            'lines' => [[
                'material_requirement_id' => $requirement->id, 'warehouse_id' => $balance->warehouse_id,
                'location_id' => $balance->location_id, 'batch_no' => $balance->batch_no, 'return_base_qty' => 2,
            ]],
        ], $user, self::PERMISSIONS);
        $receivePayload = ['client_command_id' => $this->id('net-return-receive'), 'expected_version' => 1];
        $firstReceipt = $returns->receive($created['id'], $receivePayload, $user, self::PERMISSIONS);
        $replayReceipt = $returns->receive($created['id'], $receivePayload, $user, self::PERMISSIONS);
        $this->assertEquals($firstReceipt, $replayReceipt);

        $short = $kitting->requirements($task->id, 'quantity_operation', $workOrder->test_target_id, $user, self::PERMISSIONS);
        $this->assertSame(5.0, $short[0]['gross_received_base_qty']);
        $this->assertSame(2.0, $short[0]['returned_base_qty']);
        $this->assertSame(3.0, $short[0]['satisfied_base_qty']);
        $this->assertSame(2.0, $short[0]['shortage_base_qty']);
        $this->assertSame(2.0, (float) DB::table('erp_production_target_material_requirements')->where('id', $targetRequirement->id)->value('returned_base_qty'));
        $this->expectDomain('materials_not_ready', fn () => $kitting->confirm(
            $task->id, 'quantity_operation', $workOrder->test_target_id,
            ['client_command_id' => $this->id('net-confirm-short'), 'expected_version' => 1],
            $user, self::PERMISSIONS
        ));

        $this->deliverQuantity($user, $workOrder, $requirement, $balance, 2, 'net-replenishment');
        $ready = $kitting->requirements($task->id, 'quantity_operation', $workOrder->test_target_id, $user, self::PERMISSIONS);
        $this->assertSame(7.0, $ready[0]['gross_received_base_qty']);
        $this->assertSame(5.0, $ready[0]['satisfied_base_qty']);
        $this->assertEquals(0.0, $ready[0]['shortage_base_qty']);
        $confirmation = $kitting->confirm(
            $task->id, 'quantity_operation', $workOrder->test_target_id,
            ['client_command_id' => $this->id('net-confirm-ready'), 'expected_version' => 1],
            $user, self::PERMISSIONS
        );
        $this->assertSame('CONFIRMED', $confirmation['status']);
    }

    private function fixture(): array
    {
        $suffix = strtoupper(substr(uniqid(), -8));
        $user = (object) ['legacy_id' => 980000 + random_int(1, 9999), 'username' => 'phase6b-'.$suffix, 'nickname' => 'Phase6B 验收用户'];
        DB::table('erp_legacy_admin_users')->insert(['legacy_id' => $user->legacy_id, 'username' => $user->username, 'nickname' => $user->nickname, 'status' => 'normal', 'auth_group_names' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        $unit = Unit::create(['unit_code' => 'P6B-U-'.$suffix, 'unit_name' => '件', 'unit_type' => 'quantity', 'decimal_places' => 4, 'is_base' => true, 'status' => 'enabled']);
        $output = Item::create(['item_code' => 'P6B-FG-'.$suffix, 'item_name' => 'Phase6B 成品', 'item_type' => 'finished_good', 'unit_id' => $unit->id, 'is_stock_item' => true, 'status' => 'enabled']);
        $component = Item::create(['item_code' => 'P6B-RM-'.$suffix, 'item_name' => 'Phase6B 原料', 'item_type' => 'raw_material', 'unit_id' => $unit->id, 'is_stock_item' => true, 'status' => 'enabled']);
        $bom = Bom::create(['bom_no' => 'P6B-BOM-'.$suffix, 'bom_name' => 'Phase6B 冻结 BOM', 'output_item_id' => $output->id, 'bom_type' => 'standard', 'version' => 'V1', 'status' => 'active', 'audit_status' => 'approved']);
        $bomLine = BomItem::create(['bom_id' => $bom->id, 'line_no' => 1, 'component_item_id' => $component->id, 'component_item_code' => $component->item_code, 'component_item_name' => $component->item_name, 'qty' => 1, 'unit_id' => $unit->id, 'loss_rate' => 0, 'fixed_qty' => 0, 'replaceable' => false]);
        $workOrder = WorkOrder::create(['work_order_no' => 'P6B-WO-'.$suffix, 'source_type' => 'stock_prebuild', 'output_item_id' => $output->id, 'target_qty' => 10, 'target_base_qty' => 10, 'target_unit_id' => $unit->id, 'base_unit_id' => $unit->id, 'status' => 'RELEASED', 'business_version' => 1, 'bom_id' => $bom->id, 'bom_version_id' => $bom->id, 'bom_version' => 'V1', 'production_location_name' => 'Phase6B 车间', 'created_by_legacy_id' => $user->legacy_id, 'updated_by_legacy_id' => $user->legacy_id]);
        $requirement = WorkOrderMaterialRequirement::create(['work_order_id' => $workOrder->id, 'line_no' => 1, 'bom_id' => $bom->id, 'bom_item_id' => $bomLine->id, 'component_item_id' => $component->id, 'component_item_code_snapshot' => $component->item_code, 'component_item_name_snapshot' => $component->item_name, 'unit_id' => $unit->id, 'unit_name_snapshot' => '件', 'per_output_qty' => 1, 'loss_rate' => 0, 'fixed_qty' => 0, 'required_qty' => 10, 'base_unit_id' => $unit->id, 'base_unit_name_snapshot' => '件', 'base_required_qty' => 10, 'issued_qty' => 0, 'returned_qty' => 0, 'remaining_qty' => 10, 'status' => 'OPEN', 'business_version' => 1]);
        $warehouse = Warehouse::create(['warehouse_code' => 'P6B-WH-'.$suffix, 'warehouse_name' => 'Phase6B 仓库', 'status' => 'enabled']);
        $location = Location::create(['location_code' => 'P6B-LC-'.$suffix, 'location_name' => 'Phase6B 库位', 'warehouse_id' => $warehouse->id, 'status' => 'enabled']);
        $balance = InventoryBalance::create(['item_id' => $component->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'batch_no' => 'P6B-BATCH-'.$suffix, 'unit_id' => $unit->id, 'quantity_on_hand' => 20, 'quantity_available' => 20, 'quantity_locked' => 0, 'quantity_defective' => 0, 'quantity_pending' => 0, 'average_unit_cost' => 3]);
        $operationId = DB::table('erp_production_operations')->insertGetId(['operation_no' => 'P6B-OP-'.$suffix,
            'operation_name' => 'Phase6B 配料目标工序', 'status' => 'enabled', 'sort' => 10, 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $routingId = DB::table('erp_production_routings')->insertGetId(['routing_no' => 'P6B-RT-'.$suffix, 'routing_name' => 'Phase6B 路线',
            'output_item_id' => $output->id, 'version' => 1, 'status' => 'active', 'is_default' => true, 'default_scope_key' => $output->id,
            'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $routingOperationId = DB::table('erp_production_routing_operations')->insertGetId(['routing_id' => $routingId, 'operation_id' => $operationId,
            'sequence' => 10, 'is_key_operation' => true, 'created_at' => now(), 'updated_at' => now()]);
        $supplyId = DB::table('erp_work_order_material_supply_rules')->insertGetId(['work_order_id' => $workOrder->id,
            'material_requirement_id' => $requirement->id, 'component_item_id' => $component->id, 'target_routing_operation_id_snapshot' => $routingOperationId,
            'target_operation_code_snapshot' => 'P6B-OP-'.$suffix, 'target_operation_name_snapshot' => 'Phase6B 配料目标工序',
            'required_base_qty_snapshot' => 10, 'supply_mode_snapshot' => 'dedicated_delivery', 'requires_delivery_snapshot' => true,
            'participates_in_kitting_snapshot' => true, 'allow_partial_delivery_snapshot' => true, 'delivery_location_type_snapshot' => 'operation_station',
            'rule_snapshot' => json_encode(['required_qty_ratio' => 1]), 'created_at' => now(), 'updated_at' => now()]);
        $targetId = DB::table('erp_production_quantity_operations')->insertGetId(['work_order_id' => $workOrder->id,
            'routing_operation_id_snapshot' => $routingOperationId, 'operation_id_snapshot' => $operationId,
            'operation_code_snapshot' => 'P6B-OP-'.$suffix, 'operation_name_snapshot' => 'Phase6B 配料目标工序', 'sequence_no_snapshot' => 10,
            'status' => 'WAIT_MATERIAL', 'planned_base_qty' => 10, 'completed_base_qty' => 0, 'scrapped_base_qty' => 0, 'remaining_base_qty' => 10,
            'kitting_required' => true, 'output_mode_snapshot' => 'flow_only', 'quality_mode_snapshot' => 'none',
            'allow_continue_without_warehouse_snapshot' => true, 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $productionTaskId = DB::table('erp_production_tasks')->insertGetId(['task_no' => 'P6B-PT-'.$suffix, 'work_order_id' => $workOrder->id,
            'execution_mode' => 'quantity', 'routing_operation_id_snapshot' => $routingOperationId, 'operation_code_snapshot' => 'P6B-OP-'.$suffix,
            'operation_name_snapshot' => 'Phase6B 配料目标工序', 'sequence_no_snapshot' => 10, 'status' => 'CLAIMED',
            'assignee_user_legacy_id' => $user->legacy_id, 'assignment_mode' => 'manual_claim', 'claimed_at' => now(),
            'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('erp_production_task_targets')->insert(['task_id' => $productionTaskId, 'target_type' => 'quantity_operation',
            'target_id' => $targetId, 'status_snapshot' => 'WAIT_MATERIAL', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('erp_production_target_material_requirements')->insert(['work_order_id' => $workOrder->id, 'target_type' => 'quantity_operation',
            'target_id' => $targetId, 'material_requirement_id' => $requirement->id, 'material_supply_rule_snapshot_id' => $supplyId,
            'component_item_id' => $component->id, 'requirement_kind' => 'standard', 'required_base_qty' => 10, 'satisfied_base_qty' => 0,
            'consumed_base_qty' => 0, 'returned_base_qty' => 0, 'status' => 'OPEN', 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $workOrder->setAttribute('test_supply_id', $supplyId);
        $workOrder->setAttribute('test_target_id', $targetId);
        return [$user, $workOrder, $requirement, $balance];
    }

    private function reservedSource(object $user, WorkOrder $targetWorkOrder, WorkOrderMaterialRequirement $requirement, string $outputMode, float $quantity = 2): array
    {
        $suffix = strtoupper(substr(uniqid(), -8));
        $target = DB::table('erp_production_quantity_operations')->where('id', $targetWorkOrder->test_target_id)->first();
        $targetRequirementId = (int) DB::table('erp_production_target_material_requirements')
            ->where('target_type', 'quantity_operation')->where('target_id', $target->id)
            ->where('component_item_id', $requirement->component_item_id)->value('id');
        $workOrder = WorkOrder::create([
            'work_order_no' => 'SPB-WO-'.$suffix, 'source_type' => 'stock_prebuild',
            'stocking_purpose' => 'reserved_for_work_order', 'reserved_for_work_order_id' => $targetWorkOrder->id,
            'reserved_for_target_operation_id' => $target->routing_operation_id_snapshot,
            'configured_output_mode_snapshot' => $outputMode, 'effective_output_mode_snapshot' => $outputMode,
            'effective_output_item_id_snapshot' => $requirement->component_item_id,
            'output_item_id' => $requirement->component_item_id, 'target_qty' => $quantity, 'target_base_qty' => $quantity,
            'target_unit_id' => $requirement->unit_id, 'base_unit_id' => $requirement->base_unit_id,
            'production_execution_mode_snapshot' => 'quantity', 'status' => 'IN_PROGRESS',
            'responsible_user_legacy_id' => $user->legacy_id, 'business_version' => 1,
        ]);
        $sourceTargetId = DB::table('erp_production_quantity_operations')->insertGetId([
            'work_order_id' => $workOrder->id, 'operation_code_snapshot' => 'SPB-FINAL',
            'operation_name_snapshot' => '备货目标工序', 'sequence_no_snapshot' => 1, 'status' => 'COMPLETED',
            'planned_base_qty' => $quantity, 'completed_base_qty' => $quantity, 'scrapped_base_qty' => 0, 'remaining_base_qty' => 0,
            'output_item_id_snapshot' => $requirement->component_item_id, 'output_mode_snapshot' => $outputMode,
            'quality_mode_snapshot' => 'none', 'business_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $output = \App\Models\Erp\ProductionOutputRecord::create([
            'output_no' => 'SPB-OUT-'.$suffix, 'work_order_id' => $workOrder->id,
            'source_target_type' => 'quantity_operation', 'source_target_id' => $sourceTargetId,
            'output_item_id' => $requirement->component_item_id, 'output_base_qty' => $quantity,
            'output_mode_snapshot' => $outputMode, 'quality_mode_snapshot' => 'none', 'status' => 'WAIT_COMPLETION',
            'created_by_legacy_id' => $user->legacy_id, 'produced_at' => now(), 'business_version' => 1,
        ]);
        return compact('workOrder', 'output', 'targetRequirementId');
    }

    private function pickLine(WorkOrder $workOrder, WorkOrderMaterialRequirement $requirement, InventoryBalance $balance, float $qty): array
    {
        return ['material_requirement_id' => $requirement->id, 'material_supply_rule_snapshot_id' => $workOrder->test_supply_id,
            'production_target_type' => 'quantity_operation', 'production_target_id' => $workOrder->test_target_id,
            'inventory_balance_id' => $balance->id, 'planned_pick_qty' => $qty];
    }

    private function deliverQuantity(object $user, WorkOrder $workOrder, WorkOrderMaterialRequirement $requirement, InventoryBalance $balance, float $qty, string $prefix): void
    {
        $service = app(ProductionMaterialExecutionService::class);
        $pick = $service->createPickingTask([
            'client_command_id' => $this->id($prefix.'-pick'), 'work_order_id' => $workOrder->id,
            'expected_version' => 1, 'warehouse_id' => $balance->warehouse_id,
            'lines' => [$this->pickLine($workOrder, $requirement, $balance, $qty)],
        ], $user, self::PERMISSIONS, true);
        $assigned = $service->assignPickingTask($pick->id, [
            'client_command_id' => $this->id($prefix.'-assign'), 'expected_version' => 1,
            'assigned_picker_legacy_id' => $user->legacy_id,
        ], $user, self::PERMISSIONS, true);
        $picking = $service->startPickingTask($pick->id, [
            'client_command_id' => $this->id($prefix.'-start'), 'expected_version' => $assigned->business_version,
        ], $user, self::PERMISSIONS, true);
        $picked = $service->confirmPickingTask($pick->id, [
            'client_command_id' => $this->id($prefix.'-confirm'), 'expected_version' => $picking->business_version,
            'lines' => [['picking_task_line_id' => $pick->lines->first()->id, 'actual_pick_qty' => $qty]],
        ], $user, self::PERMISSIONS, true);
        $delivery = $service->createDelivery([
            'client_command_id' => $this->id($prefix.'-delivery'), 'picking_task_id' => $pick->id,
            'expected_version' => $picked->business_version,
            'lines' => [['picking_task_line_id' => $pick->lines->first()->id, 'delivery_qty' => $qty]],
        ], $user, self::PERMISSIONS, true);
        $inTransit = $service->dispatchDelivery($delivery->id, [
            'client_command_id' => $this->id($prefix.'-dispatch'), 'expected_version' => 1,
            'delivery_user_legacy_id' => $user->legacy_id,
        ], $user, self::PERMISSIONS, true);
        $delivered = $service->deliverDelivery($delivery->id, [
            'client_command_id' => $this->id($prefix.'-deliver'), 'expected_version' => $inTransit->business_version,
        ], $user, self::PERMISSIONS, true);
        $service->receiveDelivery($delivery->id, [
            'client_command_id' => $this->id($prefix.'-receive'), 'expected_version' => $delivered->business_version,
            'lines' => [['delivery_line_id' => $delivered->lines->first()->id, 'accepted_qty' => $qty, 'rejected_qty' => 0]],
        ], $user, self::PERMISSIONS, true);
    }

    private function id(string $prefix): string { return $prefix.'-'.uniqid(); }

    private function expectDomain(string $code, callable $callback, int $status = 422): void
    {
        try { $callback(); $this->fail('Expected domain exception '.$code); }
        catch (WorkOrderDomainException $exception) { $this->assertSame($code, $exception->errorCode); $this->assertSame($status, $exception->status); }
    }
}
