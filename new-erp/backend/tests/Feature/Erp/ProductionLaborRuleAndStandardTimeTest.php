<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\Item;
use App\Models\Erp\ProductionQuantityOperation;
use App\Models\Erp\ProductionTask;
use App\Models\Erp\ProductionUnitOperation;
use App\Models\Erp\Unit;
use App\Models\Erp\WorkOrder;
use App\Services\Erp\ProductionExecutionFoundationService;
use App\Services\Erp\ProductionLaborAllocationRuleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductionLaborRuleAndStandardTimeTest extends TestCase
{
    use DatabaseTransactions;

    private const RULE_PERMISSIONS = ['production.labor_rule.view', 'production.labor_rule.manage'];

    public function test_rule_versions_are_frozen_on_tasks_and_standard_time_uses_the_correct_formula(): void
    {
        $user = (object) ['legacy_id' => 12001, 'username' => 'labor-rule-tester', 'nickname' => '工时规则测试员'];
        $rules = app(ProductionLaborAllocationRuleService::class);
        $v1 = $rules->createVersion([
            'client_command_id' => (string) Str::uuid(), 'rule_name' => '生产协同工时分配规则',
            'owner_ratio' => 0.6, 'collaborator_total_ratio' => 0.4,
        ], $user, self::RULE_PERMISSIONS);
        $v1 = $rules->activate($v1['id'], [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => $v1['business_version'],
        ], $user, self::RULE_PERMISSIONS);

        [$unitWorkOrder] = $this->workOrderFixture('unit', 2);
        app(ProductionExecutionFoundationService::class)->initializePublished($unitWorkOrder, [
            'production_execution_mode' => 'unit', 'serial_tracking_mode' => 'none',
            'serial_generation_stage' => 'before_finished_goods_posting',
        ]);
        $unitTask = ProductionTask::query()->where('work_order_id', $unitWorkOrder->id)->firstOrFail();
        $unitTargets = ProductionUnitOperation::query()->where('work_order_id', $unitWorkOrder->id)->get();
        $this->assertCount(2, $unitTargets);
        $this->assertSame(40.0, (float) $unitTargets->first()->standard_minutes_snapshot);
        $this->assertSame(20.0, (float) $unitTargets->first()->setup_standard_minutes_snapshot);
        $this->assertSame(40.0, (float) $unitTargets->first()->unit_standard_minutes_snapshot);
        $this->assertSame('unit_standard', $unitTargets->first()->standard_time_formula_snapshot);
        $this->assertSame(1, (int) $unitTask->labor_allocation_rule_version);
        $this->assertSame(0.6, (float) data_get($unitTask->labor_allocation_rule_snapshot, 'owner_ratio'));

        $v2 = $rules->createVersion([
            'client_command_id' => (string) Str::uuid(), 'rule_name' => '生产协同工时分配规则',
            'owner_ratio' => 0.7, 'collaborator_total_ratio' => 0.3,
        ], $user, self::RULE_PERMISSIONS);
        $v2 = $rules->activate($v2['id'], [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => $v2['business_version'],
        ], $user, self::RULE_PERMISSIONS);

        $this->assertSame('retired', DB::table('erp_production_labor_allocation_rules')->where('id', $v1['id'])->value('status'));
        $this->assertSame('active', $v2['status']);
        $this->assertSame(1, (int) $unitTask->fresh()->labor_allocation_rule_version);
        $this->assertSame(0.6, (float) data_get($unitTask->fresh()->labor_allocation_rule_snapshot, 'owner_ratio'));

        [$quantityWorkOrder] = $this->workOrderFixture('quantity', 10);
        app(ProductionExecutionFoundationService::class)->initializePublished($quantityWorkOrder, [
            'production_execution_mode' => 'quantity', 'serial_tracking_mode' => 'none',
            'serial_generation_stage' => 'before_finished_goods_posting',
        ]);
        $quantityTarget = ProductionQuantityOperation::query()->where('work_order_id', $quantityWorkOrder->id)->firstOrFail();
        $quantityTask = ProductionTask::query()->where('work_order_id', $quantityWorkOrder->id)->firstOrFail();
        $this->assertSame(420.0, (float) $quantityTarget->standard_minutes_snapshot);
        $this->assertSame(10.0, (float) $quantityTarget->standard_quantity_snapshot);
        $this->assertSame('setup_plus_unit_times_qty', $quantityTarget->standard_time_formula_snapshot);
        $this->assertSame(2, (int) $quantityTask->labor_allocation_rule_version);
        $this->assertSame(0.7, (float) data_get($quantityTask->labor_allocation_rule_snapshot, 'owner_ratio'));
    }

    public function test_invalid_ratio_is_rejected_and_same_command_is_idempotent(): void
    {
        $user = (object) ['legacy_id' => 12002, 'username' => 'labor-rule-idempotency'];
        $service = app(ProductionLaborAllocationRuleService::class);
        $command = (string) Str::uuid();
        $payload = ['client_command_id' => $command, 'owner_ratio' => 0.6, 'collaborator_total_ratio' => 0.4];
        $first = $service->createVersion($payload, $user, self::RULE_PERMISSIONS);
        $this->assertEquals($first, $service->createVersion($payload, $user, self::RULE_PERMISSIONS));

        $this->expectException(\App\Exceptions\Erp\WorkOrderDomainException::class);
        $service->createVersion([
            'client_command_id' => (string) Str::uuid(), 'owner_ratio' => 0.8, 'collaborator_total_ratio' => 0.4,
        ], $user, self::RULE_PERMISSIONS);
    }

    private function workOrderFixture(string $mode, float $quantity): array
    {
        $suffix = Str::upper(Str::random(8));
        $unit = Unit::create(['unit_code' => 'U-'.$suffix, 'unit_name' => $mode === 'unit' ? '件' : '千克',
            'unit_type' => $mode === 'unit' ? 'quantity' : 'weight', 'decimal_places' => $mode === 'unit' ? 0 : 3,
            'is_base' => true, 'status' => 'enabled']);
        $item = Item::create(['item_code' => 'ITEM-'.$suffix, 'item_name' => '工时冻结物料-'.$suffix,
            'item_type' => 'finished_good', 'unit_id' => $unit->id, 'is_stock_item' => true,
            'is_production_item' => true, 'production_execution_mode' => $mode, 'status' => 'enabled']);
        $operationId = DB::table('erp_production_operations')->insertGetId([
            'operation_no' => 'OP-'.$suffix, 'operation_name' => '标准工时工序', 'status' => 'enabled',
            'sort' => 10, 'business_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $routingId = DB::table('erp_production_routings')->insertGetId([
            'routing_no' => 'RT-'.$suffix, 'routing_name' => '标准工时路线', 'output_item_id' => $item->id,
            'version' => 1, 'status' => 'active', 'is_default' => true, 'default_scope_key' => $item->id,
            'business_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $routingOperationId = DB::table('erp_production_routing_operations')->insertGetId([
            'routing_id' => $routingId, 'operation_id' => $operationId, 'sequence' => 10,
            'is_key_operation' => true, 'standard_minutes' => 40,
            'setup_standard_minutes' => 20, 'unit_standard_minutes' => 40,
            'output_item_id' => $item->id, 'output_mode' => 'warehouse_required', 'quality_mode' => 'required',
            'allow_continue_without_warehouse' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $snapshot = ['routing_id' => $routingId, 'routing_no' => 'RT-'.$suffix, 'version' => 1,
            'operations' => [[
                'routing_operation_id' => $routingOperationId, 'operation_id' => $operationId,
                'operation_no' => 'OP-'.$suffix, 'operation_name' => '标准工时工序', 'sequence' => 10,
                'standard_minutes' => 40, 'setup_standard_minutes' => 20, 'unit_standard_minutes' => 40,
                'output_item_id' => $item->id, 'output_mode' => 'warehouse_required', 'quality_mode' => 'required',
                'allow_continue_without_warehouse' => false, 'material_supply_rules' => [],
            ]]];
        $workOrder = WorkOrder::create([
            'work_order_no' => 'WO-'.$suffix, 'source_type' => 'stock_prebuild', 'output_item_id' => $item->id,
            'production_routing_id' => $routingId, 'routing_version_snapshot' => 1, 'routing_snapshot' => $snapshot,
            'target_operation_id' => $operationId, 'target_routing_operation_id' => $routingOperationId,
            'target_qty' => $quantity, 'target_base_qty' => $quantity, 'target_unit_id' => $unit->id,
            'base_unit_id' => $unit->id, 'status' => 'RELEASED', 'business_version' => 1,
        ]);
        return [$workOrder, $item];
    }
}
