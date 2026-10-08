<?php

namespace Tests\Feature\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{Item, ProductionLaborSession, ProductionQuantityOperation, ProductionTask, ProductionTaskTarget, Unit, WorkOrder};
use App\Services\Erp\{ProductionExecutionActionService, ProductionKittingService, ProductionReportService, ProductionTargetReadinessService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductionExecutionReadinessGateTest extends TestCase
{
    use DatabaseTransactions;

    public function test_inline_scrap_cannot_fill_the_qualified_target_or_advance_the_next_operation(): void
    {
        $fixture = $this->fixture();
        $command = (string) Str::uuid();
        $this->expectDomain('qualified_quantity_not_complete', fn () => app(ProductionExecutionActionService::class)->complete(
            $fixture['task']->id, 'quantity_operation', $fixture['target']->id,
            ['client_command_id' => $command, 'expected_version' => 1, 'completed_base_qty' => 2,
                'scrapped_base_qty' => 1, 'defect_reason' => '加工报废一件', 'disposition' => 'direct_handover'],
            $fixture['user'], ['production.task.complete']
        ), 409);

        $target = $fixture['target']->fresh();
        $this->assertSame('IN_PROGRESS', $target->status);
        $this->assertSame(0.0, (float) $target->completed_base_qty);
        $this->assertSame(0.0, (float) $target->scrapped_base_qty);
        $this->assertSame(3.0, (float) $target->remaining_base_qty);
        $this->assertSame(1, (int) $target->business_version);
        $this->assertSame('ACTIVE', $fixture['session']->fresh()->status);
        $this->assertSame('WAIT_PREDECESSOR', $fixture['next']->fresh()->status);
        $this->assertSame('WAIT_PREDECESSOR', $fixture['nextTask']->fresh()->status);
        $this->assertSame(0, DB::table('erp_production_reports')->where('target_id', $target->id)->count());
        $this->assertSame(0, DB::table('erp_production_output_records')->where('source_target_id', $target->id)->count());
        $this->assertDatabaseMissing('erp_production_execution_commands', ['client_command_id' => $command]);
        $this->assertSame(0, DB::table('erp_production_operation_handovers')->where('source_target_id', $target->id)->count());
    }

    public function test_reported_scrap_keeps_the_qualified_deficit_open_then_full_good_output_advances(): void
    {
        $fixture = $this->fixture();
        $reports = app(ProductionReportService::class);
        $first = $reports->report($fixture['task']->id, 'quantity_operation', $fixture['target']->id,
            ['client_command_id' => (string) Str::uuid(), 'expected_version' => 1, 'qualified_base_qty' => 2,
                'unqualified_base_qty' => 0, 'scrapped_base_qty' => 1, 'defect_reason' => '加工报废一件'],
            $fixture['user'], ['production.report.create']);
        $this->assertSame(1.0, $first['remaining_required_qualified_base_qty']);
        $this->assertSame(1.0, $first['remaining_base_qty']);
        $this->assertFalse($first['ready_for_completion']);

        $this->expectDomain('reports_not_complete', fn () => app(ProductionExecutionActionService::class)->complete(
            $fixture['task']->id, 'quantity_operation', $fixture['target']->id,
            ['client_command_id' => (string) Str::uuid(), 'expected_version' => 2, 'disposition' => 'direct_handover'],
            $fixture['user'], ['production.task.complete']
        ), 409);
        $this->assertSame('WAIT_PREDECESSOR', $fixture['next']->fresh()->status);
        $this->assertSame('ACTIVE', $fixture['session']->fresh()->status);

        $last = $reports->report($fixture['task']->id, 'quantity_operation', $fixture['target']->id,
            ['client_command_id' => (string) Str::uuid(), 'expected_version' => 2, 'qualified_base_qty' => 1,
                'unqualified_base_qty' => 0, 'scrapped_base_qty' => 0],
            $fixture['user'], ['production.report.create']);
        $this->assertTrue($last['ready_for_completion']);
        $complete = app(ProductionExecutionActionService::class)->complete(
            $fixture['task']->id, 'quantity_operation', $fixture['target']->id,
            ['client_command_id' => (string) Str::uuid(), 'expected_version' => 3, 'disposition' => 'direct_handover'],
            $fixture['user'], ['production.task.complete']);
        $this->assertSame('COMPLETED', $complete['target_status']);
        $this->assertSame(3.0, (float) DB::table('erp_production_output_records')->where('id', $complete['output_record_id'])->value('output_base_qty'));
        $this->assertSame(1.0, (float) $fixture['target']->fresh()->scrapped_base_qty);
        $this->assertSame('WAIT_CLAIM', $fixture['next']->fresh()->status);
        $this->assertDatabaseHas('erp_production_operation_handovers', [
            'source_target_type' => 'quantity_operation', 'source_target_id' => $fixture['target']->id,
            'target_target_id' => $fixture['next']->id, 'output_record_id' => $complete['output_record_id'], 'status' => 'WAIT_RECEIVE',
        ]);
    }

    public function test_non_kitting_target_waits_for_every_internal_issue_to_be_received(): void
    {
        $fixture = $this->fixture('CLAIMED');
        $this->issue($fixture, 'RECEIVED');
        $issued = $this->issue($fixture, 'ISSUED');
        $waiting = $this->issue($fixture, 'WAIT_ISSUE');
        $service = app(ProductionTargetReadinessService::class);
        $blocked = $service->refresh('quantity_operation', $fixture['target'], $fixture['task']);
        $this->assertSame('materials_not_ready', $blocked['readiness']['reason_code']);
        $this->assertFalse($blocked['readiness']['ready']);
        $this->assertFalse($blocked['readiness']['confirm_kitting_allowed']);
        $this->assertSame('WAIT_MATERIAL', $blocked['target_status']);
        $this->assertSame('WAIT_MATERIAL', $blocked['task_status']);

        DB::table('erp_production_internal_issue_tasks')->where('id', $issued)->update(['status' => 'RECEIVED']);
        $stillBlocked = $service->refresh('quantity_operation', $fixture['target']->fresh(), $fixture['task']->fresh());
        $this->assertSame('WAIT_MATERIAL', $stillBlocked['target_status']);
        $this->assertSame('materials_not_ready', $stillBlocked['readiness']['reason_code']);
        DB::table('erp_production_internal_issue_tasks')->where('id', $waiting)->update(['status' => 'RECEIVED']);
        $ready = $service->refresh('quantity_operation', $fixture['target']->fresh(), $fixture['task']->fresh());
        $this->assertTrue($ready['readiness']['ready']);
        $this->assertSame('READY', $ready['target_status']);
        $this->assertSame('READY', $ready['task_status']);
        $this->assertDatabaseHas('erp_production_task_targets', ['task_id' => $fixture['task']->id,
            'target_id' => $fixture['target']->id, 'status_snapshot' => 'READY']);
    }

    public function test_start_rechecks_pending_receipt_when_the_stored_ready_status_is_stale(): void
    {
        $fixture = $this->fixture('READY');
        $issue = $this->issue($fixture, 'ISSUED');
        $command = (string) Str::uuid();
        $service = app(ProductionExecutionActionService::class);
        $this->expectDomain('materials_not_ready', fn () => $service->start(
            $fixture['task']->id, 'quantity_operation', $fixture['target']->id,
            ['client_command_id' => $command, 'expected_version' => 1], $fixture['user'], ['production.task.start']
        ), 409);
        $this->assertSame('READY', $fixture['target']->fresh()->status);
        $this->assertNull($fixture['target']->fresh()->started_at);
        $this->assertSame(0, ProductionLaborSession::where('target_id', $fixture['target']->id)->count());
        $this->assertDatabaseMissing('erp_production_execution_commands', ['client_command_id' => $command]);

        DB::table('erp_production_internal_issue_tasks')->where('id', $issue)->update(['status' => 'RECEIVED']);
        $started = $service->start($fixture['task']->id, 'quantity_operation', $fixture['target']->id,
            ['client_command_id' => (string) Str::uuid(), 'expected_version' => 1], $fixture['user'], ['production.task.start']);
        $this->assertSame('IN_PROGRESS', $started['target_status']);
        $this->assertSame(1, ProductionLaborSession::where('target_id', $fixture['target']->id)->where('status', 'ACTIVE')->count());
    }

    public function test_kitting_cannot_bypass_pending_internal_issues_outside_the_kitting_bom(): void
    {
        $fixture = $this->fixture('WAIT_MATERIAL', true);
        $issue = $this->issue($fixture, 'WAIT_ISSUE');
        $command = (string) Str::uuid();
        $service = app(ProductionKittingService::class);
        $this->expectDomain('materials_not_ready', fn () => $service->confirm(
            $fixture['task']->id, 'quantity_operation', $fixture['target']->id,
            ['client_command_id' => $command, 'expected_version' => 1], $fixture['user'], ['production.kitting.confirm']
        ), 422);
        $this->assertSame('WAIT_MATERIAL', $fixture['target']->fresh()->status);
        $this->assertSame(0, DB::table('erp_production_kitting_confirmations')->where('target_id', $fixture['target']->id)->count());
        $this->assertSame(0, ProductionLaborSession::where('target_id', $fixture['target']->id)->count());
        $this->assertDatabaseMissing('erp_production_execution_commands', ['client_command_id' => $command]);

        DB::table('erp_production_internal_issue_tasks')->where('id', $issue)->update(['status' => 'RECEIVED']);
        $confirmed = $service->confirm($fixture['task']->id, 'quantity_operation', $fixture['target']->id,
            ['client_command_id' => (string) Str::uuid(), 'expected_version' => 1], $fixture['user'], ['production.kitting.confirm']);
        $this->assertSame('IN_PROGRESS', $confirmed['target_status']);
        $this->assertSame(1, ProductionLaborSession::where('target_id', $fixture['target']->id)->where('status', 'ACTIVE')->count());
    }

    private function fixture(string $status = 'IN_PROGRESS', bool $kitting = false): array
    {
        $suffix = Str::upper(Str::random(8));
        $user = (object) ['legacy_id' => random_int(960001, 969999), 'nickname' => '执行门禁测试员'];
        $unit = Unit::create(['unit_code' => 'EG-U-'.$suffix, 'unit_name' => '件', 'unit_type' => 'quantity',
            'decimal_places' => 0, 'is_base' => true, 'status' => 'enabled']);
        $item = Item::create(['item_code' => 'EG-I-'.$suffix, 'item_name' => '执行门禁测试产出', 'item_type' => 'finished_good',
            'unit_id' => $unit->id, 'is_stock_item' => true, 'is_production_item' => true, 'status' => 'enabled']);
        $workOrder = WorkOrder::create(['work_order_no' => 'EG-WO-'.$suffix, 'source_type' => 'stock_prebuild',
            'output_item_id' => $item->id, 'target_qty' => 10, 'target_base_qty' => 10,
            'target_unit_id' => $unit->id, 'base_unit_id' => $unit->id, 'status' => 'IN_PROGRESS',
            'responsible_user_legacy_id' => $user->legacy_id, 'business_version' => 1]);
        $target = ProductionQuantityOperation::create(['work_order_id' => $workOrder->id,
            'operation_code_snapshot' => 'EG-FIRST', 'operation_name_snapshot' => '新制三件', 'sequence_no_snapshot' => 10,
            'status' => $status, 'planned_base_qty' => 3, 'completed_base_qty' => 0,
            'unqualified_base_qty' => 0, 'scrapped_base_qty' => 0, 'remaining_base_qty' => 3,
            'responsible_user_legacy_id' => $user->legacy_id, 'kitting_required' => $kitting,
            'started_at' => $status === 'IN_PROGRESS' ? now()->subMinutes(10) : null,
            'output_item_id_snapshot' => $item->id, 'output_mode_snapshot' => 'flow_only',
            'quality_mode_snapshot' => 'none', 'business_version' => 1]);
        $task = ProductionTask::create(['task_no' => 'EG-T-'.$suffix, 'work_order_id' => $workOrder->id,
            'production_quantity_operation_id' => $target->id, 'execution_mode' => 'quantity',
            'operation_code_snapshot' => 'EG-FIRST', 'operation_name_snapshot' => '新制三件', 'sequence_no_snapshot' => 10,
            'status' => $status, 'assignee_user_legacy_id' => $user->legacy_id, 'organization_code' => 'EG-DEPT', 'business_version' => 1]);
        ProductionTaskTarget::create(['task_id' => $task->id, 'target_type' => 'quantity_operation',
            'target_id' => $target->id, 'status_snapshot' => $status]);
        $next = ProductionQuantityOperation::create(['work_order_id' => $workOrder->id,
            'operation_code_snapshot' => 'EG-NEXT', 'operation_name_snapshot' => '后续十件', 'sequence_no_snapshot' => 20,
            'status' => 'WAIT_PREDECESSOR', 'planned_base_qty' => 10, 'completed_base_qty' => 0, 'remaining_base_qty' => 10,
            'output_item_id_snapshot' => $item->id, 'output_mode_snapshot' => 'flow_only',
            'quality_mode_snapshot' => 'none', 'business_version' => 1]);
        $nextTask = ProductionTask::create(['task_no' => 'EG-NT-'.$suffix, 'work_order_id' => $workOrder->id,
            'production_quantity_operation_id' => $next->id, 'execution_mode' => 'quantity', 'operation_code_snapshot' => 'EG-NEXT',
            'operation_name_snapshot' => '后续十件', 'sequence_no_snapshot' => 20, 'status' => 'WAIT_PREDECESSOR', 'business_version' => 1]);
        ProductionTaskTarget::create(['task_id' => $nextTask->id, 'target_type' => 'quantity_operation',
            'target_id' => $next->id, 'status_snapshot' => 'WAIT_PREDECESSOR']);
        $session = $status === 'IN_PROGRESS' ? ProductionLaborSession::create([
            'task_id' => $task->id, 'target_type' => 'quantity_operation', 'target_id' => $target->id,
            'employee_legacy_id' => $user->legacy_id, 'role' => 'owner', 'status' => 'ACTIVE', 'started_at' => now()->subMinutes(10),
            'actual_labor_minutes' => 0, 'responsibility_weight_snapshot' => 1, 'credited_labor_minutes' => 0,
        ]) : null;
        return compact('user', 'unit', 'item', 'workOrder', 'target', 'task', 'next', 'nextTask', 'session');
    }

    private function issue(array $fixture, string $status): int
    {
        return DB::table('erp_production_internal_issue_tasks')->insertGetId([
            'issue_no' => 'EG-ISS-'.Str::upper(Str::random(10)), 'work_order_id' => $fixture['workOrder']->id,
            'target_task_id' => $fixture['task']->id, 'target_type' => 'quantity_operation', 'target_id' => $fixture['target']->id,
            'source_type' => 'inventory_continuation', 'status' => $status, 'business_version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function expectDomain(string $code, callable $callback, int $status): void
    {
        try { $callback(); $this->fail('Expected domain exception '.$code); }
        catch (WorkOrderDomainException $exception) {
            $this->assertSame($code, $exception->errorCode);
            $this->assertSame($status, $exception->status);
        }
    }
}
