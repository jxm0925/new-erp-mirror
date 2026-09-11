<?php

namespace Tests\Feature\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{Item, Location, ProductionOutputRecord, ProductionQuantityOperation, ProductionTask, ProductionTaskTarget, Unit, Warehouse, WorkOrder};
use App\Services\Erp\{ProductionExecutionActionService, ProductionHandoverService, ProductionInternalIssueService, ProductionOutputService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductionOutputFlowMatrixTest extends TestCase
{
    use DatabaseTransactions;

    public function test_optional_quality_pass_can_directly_handover_and_quality_failure_returns_to_rework(): void
    {
        $fixture = $this->fixture('warehouse_optional', 'required', true);
        $service = app(ProductionOutputService::class);
        $user = (object) ['legacy_id' => 8801];

        $passed = $service->inspect($fixture['output']->id, [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => 1,
            'result' => 'passed', 'qualified_base_qty' => 2, 'unqualified_base_qty' => 0,
            'next_step' => 'direct_handover',
        ], $user, ['production.output.quality']);
        $this->assertSame('HANDED_OVER', $passed['output_status']);
        $this->assertSame('COMPLETED', $fixture['source']->fresh()->status);
        $this->assertDatabaseHas('erp_production_operation_handovers', [
            'output_record_id' => $fixture['output']->id, 'target_target_id' => $fixture['next']->id, 'status' => 'WAIT_RECEIVE',
        ]);
        $this->assertDatabaseHas('erp_production_tasks', ['work_order_id' => $fixture['workOrder']->id, 'status' => 'WAIT_CLAIM']);

        $failedFixture = $this->fixture('warehouse_optional', 'required', true);
        $reworkTask = ProductionTask::create([
            'task_no' => $this->code('REWORK'), 'work_order_id' => $failedFixture['workOrder']->id,
            'execution_mode' => 'quantity', 'operation_code_snapshot' => 'OP1', 'operation_name_snapshot' => '前工序',
            'sequence_no_snapshot' => 1, 'status' => 'WAIT_QUALITY', 'assignee_user_legacy_id' => 8801,
            'business_version' => 1,
        ]);
        ProductionTaskTarget::create(['task_id' => $reworkTask->id, 'target_type' => 'quantity_operation',
            'target_id' => $failedFixture['source']->id, 'status_snapshot' => 'WAIT_QUALITY']);
        $failed = $service->inspect($failedFixture['output']->id, [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => 1,
            'result' => 'failed', 'qualified_base_qty' => 0, 'unqualified_base_qty' => 2,
            'reason' => '尺寸超差',
        ], $user, ['production.output.quality']);
        $this->assertSame('QUALITY_FAILED', $failed['output_status']);
        $this->assertSame('REWORK', $failedFixture['source']->fresh()->status);
        $this->assertSame(0.0, (float) $failedFixture['source']->fresh()->completed_base_qty);
        $this->assertSame(2.0, (float) $failedFixture['source']->fresh()->remaining_base_qty);
        $this->assertSame('REWORK', $reworkTask->fresh()->status);
        $this->assertDatabaseHas('erp_production_execution_events', [
            'aggregate_type' => 'quantity_operation', 'aggregate_id' => $failedFixture['source']->id,
            'action' => 'quality_rework', 'after_status' => 'REWORK',
        ]);
        $this->assertDatabaseMissing('erp_production_operation_handovers', ['output_record_id' => $failedFixture['output']->id]);

        $execution = app(ProductionExecutionActionService::class);
        $started = $execution->restartRework($reworkTask->id, 'quantity_operation', $failedFixture['source']->id, [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => 2,
        ], $user, ['production.task.start']);
        $recompleted = $execution->complete($reworkTask->id, 'quantity_operation', $failedFixture['source']->id, [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => $started['target_business_version'],
            'completed_base_qty' => 2, 'scrapped_base_qty' => 0, 'disposition' => 'direct_handover',
        ], $user, ['production.task.complete']);
        $this->assertSame($failedFixture['output']->id, $recompleted['output_record_id']);
        $this->assertSame('WAIT_QUALITY', $failedFixture['output']->fresh()->status);
        $this->assertSame(3, (int) $failedFixture['output']->fresh()->business_version);
        $repassed = $service->inspect($failedFixture['output']->id, [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => 3,
            'result' => 'passed', 'qualified_base_qty' => 2, 'unqualified_base_qty' => 0,
            'next_step' => 'direct_handover',
        ], $user, ['production.output.quality']);
        $this->assertSame('HANDED_OVER', $repassed['output_status']);
        $this->assertSame('COMPLETED', $failedFixture['source']->fresh()->status);
        $this->assertSame(2, DB::table('erp_production_quality_inspections')->where('output_record_id', $failedFixture['output']->id)->count());

        $partialFixture = $this->fixture('warehouse_optional', 'required', false);
        try {
            $service->inspect($partialFixture['output']->id, [
                'client_command_id' => (string) Str::uuid(), 'expected_version' => 1,
                'result' => 'passed', 'qualified_base_qty' => 1, 'unqualified_base_qty' => 1,
                'next_step' => 'warehouse',
            ], $user, ['production.output.quality']);
            $this->fail('混合合格产出不得进入库或交接。');
        } catch (WorkOrderDomainException $exception) {
            $this->assertSame('partial_quality_not_supported', $exception->errorCode);
        }
        $this->assertDatabaseMissing('erp_production_quality_inspections', ['output_record_id' => $partialFixture['output']->id]);
    }

    public function test_required_warehouse_creates_real_internal_issue_then_dispatch_and_receive(): void
    {
        $fixture = $this->fixture('warehouse_required', 'none', true, true);
        $user = (object) ['legacy_id' => 8801];
        $warehouse = Warehouse::create(['warehouse_code' => $this->code('WH'), 'warehouse_name' => '工序中转仓', 'status' => 'enabled']);
        $location = Location::create(['warehouse_id' => $warehouse->id, 'location_code' => $this->code('LOC'), 'location_name' => '中转库位', 'status' => 'enabled']);
        $result = app(ProductionOutputService::class)->warehouse($fixture['output']->id, [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => 1,
            'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'batch_no' => $this->code('BATCH'),
        ], $user, ['production.output.warehouse']);

        $this->assertSame('WAREHOUSED', $result['output_status']);
        $this->assertNotNull($result['internal_issue_task_id']);
        $issueId = (int) $result['internal_issue_task_id'];
        $this->assertDatabaseHas('erp_production_internal_issue_tasks', ['id' => $issueId, 'status' => 'WAIT_ISSUE']);
        $this->assertSame(2.0, (float) DB::table('erp_inventory_balances')->where('item_id', $fixture['item']->id)->value('quantity_available'));

        $issues = app(ProductionInternalIssueService::class);
        $dispatch = $issues->dispatch($issueId, ['client_command_id' => (string) Str::uuid(), 'expected_version' => 1], $user, ['production.output.issue']);
        $this->assertSame('ISSUED', $dispatch['status']);
        $receive = $issues->receive($issueId, ['client_command_id' => (string) Str::uuid(), 'expected_version' => 2], $user, ['production.output.receive']);
        $this->assertSame('RECEIVED', $receive['status']);
        $this->assertSame('READY', $receive['target_status']);
        $this->assertSame(0.0, (float) DB::table('erp_inventory_balances')->where('item_id', $fixture['item']->id)->value('quantity_available'));
        $this->assertDatabaseHas('erp_inventory_transactions', ['source_type' => 'production_internal_issue', 'source_id' => $issueId]);
    }

    public function test_rejected_handover_reopens_upstream_task_and_can_be_produced_again(): void
    {
        $fixture = $this->fixture('warehouse_optional', 'required', true, true);
        $user = (object) ['legacy_id' => 8801, 'nickname' => '返工操作员'];
        $sourceTask = ProductionTask::create([
            'task_no' => $this->code('SOURCE'), 'work_order_id' => $fixture['workOrder']->id,
            'execution_mode' => 'quantity', 'operation_code_snapshot' => 'OP1', 'operation_name_snapshot' => '前工序',
            'sequence_no_snapshot' => 1, 'status' => 'WAIT_QUALITY', 'assignee_user_legacy_id' => 8801,
            'business_version' => 1,
        ]);
        ProductionTaskTarget::create([
            'task_id' => $sourceTask->id, 'target_type' => 'quantity_operation',
            'target_id' => $fixture['source']->id, 'status_snapshot' => 'WAIT_QUALITY',
        ]);

        $quality = app(ProductionOutputService::class)->inspect($fixture['output']->id, [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => 1,
            'result' => 'passed', 'qualified_base_qty' => 2, 'unqualified_base_qty' => 0,
            'next_step' => 'direct_handover',
        ], $user, ['production.output.quality']);
        $this->assertSame('HANDED_OVER', $quality['output_status']);
        $handover = DB::table('erp_production_operation_handovers')->where('output_record_id', $fixture['output']->id)->first();

        $rejected = app(ProductionHandoverService::class)->reject($handover->id, [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => 1,
            'reason' => '下游发现装配面划伤',
        ], $user, ['production.handover.reject']);
        $this->assertSame('REJECTED', $rejected['status']);
        $this->assertSame('WAIT_HANDOVER', $fixture['next']->fresh()->status);
        $this->assertSame('REWORK', $fixture['source']->fresh()->status);
        $this->assertSame(0.0, (float) $fixture['source']->fresh()->completed_base_qty);
        $this->assertSame(2.0, (float) $fixture['source']->fresh()->remaining_base_qty);
        $this->assertSame('REWORK', $sourceTask->fresh()->status);
        $this->assertSame('HANDOVER_REJECTED', $fixture['output']->fresh()->status);
        $this->assertDatabaseHas('erp_production_execution_events', [
            'aggregate_type' => 'quantity_operation', 'aggregate_id' => $fixture['source']->id,
            'action' => 'handover_rework', 'after_status' => 'REWORK',
        ]);

        $execution = app(ProductionExecutionActionService::class);
        $started = $execution->restartRework($sourceTask->id, 'quantity_operation', $fixture['source']->id, [
            'client_command_id' => (string) Str::uuid(),
            'expected_version' => $fixture['source']->fresh()->business_version,
        ], $user, ['production.task.start']);
        $completed = $execution->complete($sourceTask->id, 'quantity_operation', $fixture['source']->id, [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => $started['target_business_version'],
            'completed_base_qty' => 2, 'scrapped_base_qty' => 0, 'disposition' => 'direct_handover',
        ], $user, ['production.task.complete']);

        $this->assertSame($fixture['output']->id, $completed['output_record_id']);
        $this->assertSame('WAIT_QUALITY', $fixture['output']->fresh()->status);
        $repassed = app(ProductionOutputService::class)->inspect($fixture['output']->id, [
            'client_command_id' => (string) Str::uuid(),
            'expected_version' => $fixture['output']->fresh()->business_version,
            'result' => 'passed', 'qualified_base_qty' => 2, 'unqualified_base_qty' => 0,
            'next_step' => 'direct_handover',
        ], $user, ['production.output.quality']);
        $this->assertSame('HANDED_OVER', $repassed['output_status']);
        $reopenedHandover = DB::table('erp_production_operation_handovers')->where('id', $handover->id)->first();
        $this->assertSame('WAIT_RECEIVE', $reopenedHandover->status);
        $this->assertSame(3, (int) $reopenedHandover->business_version);
        $this->assertSame(1, DB::table('erp_production_operation_handovers')->where('output_record_id', $fixture['output']->id)->count());
    }

    public function test_expected_next_owner_can_accept_handover_and_other_user_cannot(): void
    {
        $fixture = $this->fixture('warehouse_optional', 'required', true, true);
        $owner = (object) ['legacy_id' => 8801];
        app(ProductionOutputService::class)->inspect($fixture['output']->id, [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => 1,
            'result' => 'passed', 'qualified_base_qty' => 2, 'unqualified_base_qty' => 0,
            'next_step' => 'direct_handover',
        ], $owner, ['production.output.quality']);
        $handover = DB::table('erp_production_operation_handovers')->where('output_record_id', $fixture['output']->id)->first();
        $service = app(ProductionHandoverService::class);
        try {
            $service->accept($handover->id, [
                'client_command_id' => (string) Str::uuid(), 'expected_version' => 1,
            ], (object) ['legacy_id' => 8899], ['production.handover.receive']);
            $this->fail('非下工序负责人不得接收交接。');
        } catch (WorkOrderDomainException $exception) {
            $this->assertSame('expected_receiver_required', $exception->errorCode);
        }
        $accepted = $service->accept($handover->id, [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => 1,
            'completeness' => ['complete' => true],
        ], $owner, ['production.handover.receive']);
        $this->assertSame('RECEIVED', $accepted['status']);
        $this->assertSame('READY', $fixture['next']->fresh()->status);
        $this->assertSame(8801, (int) DB::table('erp_production_operation_handovers')->where('id', $handover->id)->value('received_by_legacy_id'));
    }

    public function test_optional_quality_pass_can_choose_warehouse_path(): void
    {
        $fixture = $this->fixture('warehouse_optional', 'required', true);
        $user = (object) ['legacy_id' => 8801];
        $quality = app(ProductionOutputService::class)->inspect($fixture['output']->id, [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => 1,
            'result' => 'passed', 'qualified_base_qty' => 2, 'unqualified_base_qty' => 0, 'next_step' => 'warehouse',
        ], $user, ['production.output.quality']);
        $this->assertSame('WAIT_WAREHOUSE', $quality['output_status']);
        $this->assertSame('WAIT_WAREHOUSE', $fixture['source']->fresh()->status);

        $warehouse = Warehouse::create(['warehouse_code' => $this->code('OWH'), 'warehouse_name' => '可选入库仓', 'status' => 'enabled']);
        $location = Location::create(['warehouse_id' => $warehouse->id, 'location_code' => $this->code('OLOC'), 'location_name' => '可选入库位', 'status' => 'enabled']);
        $posted = app(ProductionOutputService::class)->warehouse($fixture['output']->id, [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => 2,
            'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'batch_no' => $this->code('OB'),
        ], $user, ['production.output.warehouse']);
        $this->assertSame('WAREHOUSED', $posted['output_status']);
        $this->assertSame('COMPLETED', $fixture['source']->fresh()->status);
    }

    private function fixture(string $outputMode, string $qualityMode, bool $withNext, bool $claimedNext = false): array
    {
        $unit = Unit::create(['unit_code' => $this->code('U'), 'unit_name' => '件', 'unit_type' => 'quantity', 'decimal_places' => 0, 'is_base' => true, 'status' => 'enabled']);
        $item = Item::create(['item_code' => $this->code('I'), 'item_name' => '工序半成品', 'item_type' => 'semi_finished', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_production_item' => true, 'status' => 'enabled']);
        $workOrder = WorkOrder::create(['work_order_no' => $this->code('WO'), 'source_type' => 'stock_prebuild', 'output_item_id' => $item->id,
            'target_qty' => 2, 'target_base_qty' => 2, 'target_unit_id' => $unit->id, 'base_unit_id' => $unit->id,
            'status' => 'IN_PROGRESS', 'responsible_user_legacy_id' => 8801, 'business_version' => 1]);
        $source = ProductionQuantityOperation::create(['work_order_id' => $workOrder->id, 'operation_code_snapshot' => 'OP1', 'operation_name_snapshot' => '前工序',
            'sequence_no_snapshot' => 1, 'status' => $qualityMode === 'none' ? 'WAIT_WAREHOUSE' : 'WAIT_QUALITY', 'planned_base_qty' => 2,
            'completed_base_qty' => 2, 'remaining_base_qty' => 0, 'output_item_id_snapshot' => $item->id,
            'output_mode_snapshot' => $outputMode, 'quality_mode_snapshot' => $qualityMode, 'business_version' => 1]);
        $output = ProductionOutputRecord::create(['output_no' => $this->code('OUT'), 'work_order_id' => $workOrder->id,
            'source_target_type' => 'quantity_operation', 'source_target_id' => $source->id, 'output_item_id' => $item->id,
            'output_base_qty' => 2, 'output_mode_snapshot' => $outputMode, 'quality_mode_snapshot' => $qualityMode,
            'status' => $qualityMode === 'none' ? 'WAIT_WAREHOUSE' : 'WAIT_QUALITY', 'created_by_legacy_id' => 8801,
            'produced_at' => now(), 'business_version' => 1]);
        $next = null;
        if ($withNext) {
            $next = ProductionQuantityOperation::create(['work_order_id' => $workOrder->id, 'operation_code_snapshot' => 'OP2', 'operation_name_snapshot' => '后工序',
                'sequence_no_snapshot' => 2, 'status' => 'WAIT_PREDECESSOR', 'planned_base_qty' => 2, 'completed_base_qty' => 0,
                'remaining_base_qty' => 2, 'output_item_id_snapshot' => $item->id, 'output_mode_snapshot' => 'flow_only',
                'quality_mode_snapshot' => 'none', 'kitting_required' => false, 'business_version' => 1]);
            // v3 creates every PT during publish. Even focused output-flow fixtures
            // must model that invariant; output completion may activate this row but
            // must never manufacture a successor task at runtime.
            $task = ProductionTask::create([
                'task_no' => $this->code('TASK'),
                'work_order_id' => $workOrder->id,
                'production_quantity_operation_id' => $next->id,
                'execution_mode' => 'quantity',
                'operation_code_snapshot' => 'OP2',
                'operation_name_snapshot' => '后工序',
                'sequence_no_snapshot' => 2,
                'status' => $claimedNext ? 'CLAIMED' : 'WAIT_PREDECESSOR',
                'assignee_user_legacy_id' => $claimedNext ? 8801 : null,
                'business_version' => 1,
            ]);
            ProductionTaskTarget::create([
                'task_id' => $task->id,
                'target_type' => 'quantity_operation',
                'target_id' => $next->id,
                'status_snapshot' => 'WAIT_PREDECESSOR',
            ]);
        }
        return compact('unit', 'item', 'workOrder', 'source', 'output', 'next');
    }

    private function code(string $prefix): string { return $prefix.'-'.Str::upper(Str::random(10)); }
}
