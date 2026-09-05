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
use App\Services\Erp\ProductionMaterialExecutionService;
use App\Services\Erp\ProductionMaterialReturnService;
use App\Services\Erp\ProductionMaterialSupplementService;
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
        'production.material_receipt.view', 'production.material_receipt.confirm',
        'production.material_supplement.request', 'production.material_supplement.approve',
        'production.material_return.create', 'production.material_return.receive', 'production.material_return.quality',
    ];

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

    public function test_normal_and_quality_returns_have_distinct_available_and_quarantine_inventory_facts(): void
    {
        [$user, $workOrder, $requirement, $balance] = $this->fixture();
        $requirement->update(['received_qty' => 5]);
        $task = DB::table('erp_production_tasks')->where('work_order_id', $workOrder->id)->first();
        $service = app(ProductionMaterialReturnService::class);
        $line = ['material_requirement_id' => $requirement->id, 'warehouse_id' => $balance->warehouse_id,
            'location_id' => $balance->location_id, 'batch_no' => $balance->batch_no, 'return_base_qty' => 2];

        $normal = $service->create(['client_command_id' => $this->id('normal-return'), 'expected_version' => 1,
            'task_id' => $task->id, 'target_type' => 'quantity_operation', 'target_id' => $workOrder->test_target_id,
            'return_type' => 'normal_return', 'reason' => '正常未用退回', 'lines' => [$line]], $user, self::PERMISSIONS);
        $normalReceived = $service->receive($normal['id'], ['client_command_id' => $this->id('normal-receive'), 'expected_version' => 1], $user, self::PERMISSIONS);
        $this->assertSame('COMPLETED', $normalReceived['status']);
        $this->assertSame(22.0, (float) $balance->fresh()->quantity_available);

        $quality = $service->create(['client_command_id' => $this->id('quality-return'), 'expected_version' => 1,
            'task_id' => $task->id, 'target_type' => 'quantity_operation', 'target_id' => $workOrder->test_target_id,
            'return_type' => 'quality_return', 'reason' => '物料外观异常', 'lines' => [array_merge($line, ['return_base_qty' => 1])]], $user, self::PERMISSIONS);
        $qualityReceived = $service->receive($quality['id'], ['client_command_id' => $this->id('quality-receive'), 'expected_version' => 1], $user, self::PERMISSIONS);
        $this->assertSame('WAIT_QUALITY', $qualityReceived['status']);
        $quarantined = $balance->fresh();
        $this->assertSame(1.0, (float) $quarantined->quantity_pending);
        $this->assertSame(22.0, (float) $quarantined->quantity_available);
        $released = $service->quality($quality['id'], ['client_command_id' => $this->id('quality-pass'),
            'expected_version' => 2, 'passed' => true, 'reason' => '检验合格'], $user, self::PERMISSIONS);
        $this->assertSame('COMPLETED', $released['status']);
        $this->assertSame(0.0, (float) $balance->fresh()->quantity_pending);
        $this->assertSame(23.0, (float) $balance->fresh()->quantity_available);
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

    private function pickLine(WorkOrder $workOrder, WorkOrderMaterialRequirement $requirement, InventoryBalance $balance, float $qty): array
    {
        return ['material_requirement_id' => $requirement->id, 'material_supply_rule_snapshot_id' => $workOrder->test_supply_id,
            'production_target_type' => 'quantity_operation', 'production_target_id' => $workOrder->test_target_id,
            'inventory_balance_id' => $balance->id, 'planned_pick_qty' => $qty];
    }

    private function id(string $prefix): string { return $prefix.'-'.uniqid(); }

    private function expectDomain(string $code, callable $callback, int $status = 422): void
    {
        try { $callback(); $this->fail('Expected domain exception '.$code); }
        catch (WorkOrderDomainException $exception) { $this->assertSame($code, $exception->errorCode); $this->assertSame($status, $exception->status); }
    }
}
