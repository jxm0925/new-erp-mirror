<?php

namespace Tests\Feature\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{Item, ProductionLaborSession, ProductionQuantityOperation, ProductionTask, ProductionTaskTarget, Unit, WorkOrder};
use App\Services\Erp\{ProductionExecutionActionService, ProductionReportService, RbacBootstrapService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductionReportFlowTest extends TestCase
{
    use DatabaseTransactions;

    public function test_quantity_reports_are_partial_idempotent_and_required_before_completion(): void
    {
        $fixture = $this->fixture();
        $service = app(ProductionReportService::class);
        $firstPayload = [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => 1,
            'qualified_base_qty' => 3, 'unqualified_base_qty' => 1, 'scrapped_base_qty' => 0,
            'defect_reason' => '外观划伤待复核', 'end_labor' => false,
        ];
        $first = $service->report($fixture['task']->id, 'quantity_operation', $fixture['target']->id,
            $firstPayload, $fixture['user'], ['production.report.create']);
        $replay = $service->report($fixture['task']->id, 'quantity_operation', $fixture['target']->id,
            $firstPayload, $fixture['user'], ['production.report.create']);

        $this->assertEquals($first, $replay);
        $this->assertSame(6.0, $first['remaining_base_qty']);
        $this->assertFalse($first['ready_for_completion']);
        $this->assertSame(1, DB::table('erp_production_reports')->where('target_id', $fixture['target']->id)->count());
        $target = $fixture['target']->fresh();
        $this->assertSame(3.0, (float) $target->completed_base_qty);
        $this->assertSame(1.0, (float) $target->unqualified_base_qty);
        $this->assertSame(6.0, (float) $target->remaining_base_qty);

        $this->expectDomain('task_participant_required', fn () => $service->report(
            $fixture['task']->id, 'quantity_operation', $fixture['target']->id,
            ['client_command_id' => (string) Str::uuid(), 'expected_version' => 2,
                'qualified_base_qty' => 1, 'unqualified_base_qty' => 0],
            (object) ['legacy_id' => 9922], ['production.report.create']
        ), 403);
        $this->expectDomain('defect_reason_required', fn () => $service->report(
            $fixture['task']->id, 'quantity_operation', $fixture['target']->id,
            ['client_command_id' => (string) Str::uuid(), 'expected_version' => 2,
                'qualified_base_qty' => 0, 'unqualified_base_qty' => 1],
            $fixture['user'], ['production.report.create']
        ));
        $this->expectDomain('report_quantity_exceeds_remaining', fn () => $service->report(
            $fixture['task']->id, 'quantity_operation', $fixture['target']->id,
            ['client_command_id' => (string) Str::uuid(), 'expected_version' => 2,
                'qualified_base_qty' => 7, 'unqualified_base_qty' => 0],
            $fixture['user'], ['production.report.create']
        ), 409);

        $second = $service->report($fixture['task']->id, 'quantity_operation', $fixture['target']->id, [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => 2,
            'qualified_base_qty' => 6, 'unqualified_base_qty' => 0, 'end_labor' => true,
        ], $fixture['user'], ['production.report.create']);
        $this->assertFalse($second['ready_for_completion']);
        $this->assertSame(1.0, $second['remaining_base_qty']);
        $this->assertSame(9.0, $second['accepted_completed_base_qty']);
        $this->assertSame(1.0, $second['remaining_required_qualified_base_qty']);
        $this->assertTrue($second['ended_reporter_labor']);
        $this->assertSame('PAUSED', $second['target_status']);
        $this->assertSame('ENDED', $fixture['session']->fresh()->status);

        $third = $service->report($fixture['task']->id, 'quantity_operation', $fixture['target']->id, [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => 3,
            'qualified_base_qty' => 1, 'unqualified_base_qty' => 0, 'scrapped_base_qty' => 0,
        ], $fixture['user'], ['production.report.create']);
        $this->assertTrue($third['ready_for_completion']);
        $this->assertSame(0.0, $third['remaining_required_qualified_base_qty']);

        $completed = app(ProductionExecutionActionService::class)->complete(
            $fixture['task']->id, 'quantity_operation', $fixture['target']->id,
            ['client_command_id' => (string) Str::uuid(), 'expected_version' => 4, 'disposition' => 'direct_handover'],
            $fixture['user'], ['production.task.complete']
        );
        $this->assertSame('COMPLETED', $completed['target_status']);
        $this->assertSame(10.0, (float) DB::table('erp_production_output_records')->where('id', $completed['output_record_id'])->value('output_base_qty'));
        $this->assertSame(3, DB::table('erp_production_reports')->where('target_id', $fixture['target']->id)->count());
    }

    public function test_processed_quantity_does_not_replace_missing_qualified_output(): void
    {
        $fixture = $this->fixture();
        $fixture['workOrder']->update(['target_qty' => 20, 'target_base_qty' => 20]);
        $fixture['target']->update(['planned_base_qty' => 20, 'remaining_base_qty' => 20]);
        $service = app(ProductionReportService::class);

        $first = $service->report($fixture['task']->id, 'quantity_operation', $fixture['target']->id, [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => 1,
            'qualified_base_qty' => 18, 'unqualified_base_qty' => 1, 'scrapped_base_qty' => 1,
            'defect_reason' => '一件不良待返工，一件确认报废',
        ], $fixture['user'], ['production.report.create']);

        $this->assertSame(20.0, $first['processed_base_qty']);
        $this->assertSame(18.0, $first['accepted_completed_base_qty']);
        $this->assertSame(2.0, $first['remaining_required_qualified_base_qty']);
        $this->assertSame(2.0, $first['remaining_base_qty']);
        $this->assertFalse($first['ready_for_completion']);
        $this->assertDatabaseHas('erp_production_reports', [
            'id' => $first['report_id'], 'qualified_base_qty' => 18,
            'unqualified_base_qty' => 1, 'scrapped_base_qty' => 1,
        ]);

        $second = $service->report($fixture['task']->id, 'quantity_operation', $fixture['target']->id, [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => 2,
            'qualified_base_qty' => 2, 'unqualified_base_qty' => 0, 'scrapped_base_qty' => 0,
        ], $fixture['user'], ['production.report.create']);
        $this->assertSame(22.0, $second['processed_base_qty']);
        $this->assertSame(20.0, $second['accepted_completed_base_qty']);
        $this->assertSame(0.0, $second['remaining_required_qualified_base_qty']);
        $this->assertTrue($second['ready_for_completion']);
        $this->assertSame(1.0, (float) $fixture['target']->fresh()->unqualified_base_qty);
        $this->assertSame(1.0, (float) $fixture['target']->fresh()->scrapped_base_qty);
    }

    public function test_legacy_full_quantity_completion_creates_report_fact_but_partial_completion_rolls_back(): void
    {
        $partial = $this->fixture();
        $actions = app(ProductionExecutionActionService::class);
        $this->expectDomain('reports_not_complete', fn () => $actions->complete(
            $partial['task']->id, 'quantity_operation', $partial['target']->id,
            ['client_command_id' => (string) Str::uuid(), 'expected_version' => 1,
                'completed_base_qty' => 4, 'scrapped_base_qty' => 0, 'disposition' => 'direct_handover'],
            $partial['user'], ['production.task.complete']
        ), 409);
        $this->assertSame(10.0, (float) $partial['target']->fresh()->remaining_base_qty);
        $this->assertSame('ACTIVE', $partial['session']->fresh()->status);
        $this->assertSame(0, DB::table('erp_production_reports')->where('target_id', $partial['target']->id)->count());

        $full = $this->fixture();
        $commandId = (string) Str::uuid();
        $completed = $actions->complete(
            $full['task']->id, 'quantity_operation', $full['target']->id,
            ['client_command_id' => $commandId, 'expected_version' => 1,
                'completed_base_qty' => 10, 'scrapped_base_qty' => 0, 'disposition' => 'direct_handover'],
            $full['user'], ['production.task.complete']
        );
        $this->assertSame('COMPLETED', $completed['target_status']);
        $this->assertDatabaseHas('erp_production_reports', [
            'client_command_id' => $commandId, 'task_id' => $full['task']->id,
            'target_id' => $full['target']->id, 'qualified_base_qty' => 10,
        ]);
    }

    public function test_real_http_report_uses_authenticated_permissions_and_task_participation(): void
    {
        $fixture = $this->fixture();
        app(RbacBootstrapService::class)->bootstrap(true);
        $ownerId = $fixture['user']->legacy_id;
        $outsiderId = $ownerId + 100000;
        foreach ([[$ownerId, 'report-owner'], [$outsiderId, 'report-outsider']] as [$id, $username]) {
            DB::table('erp_legacy_admin_users')->insert([
                'legacy_id' => $id, 'username' => $username.'-'.Str::lower(Str::random(6)),
                'nickname' => $username, 'status' => 'normal', 'auth_group_names' => '[]',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $roleId = DB::table('erp_rbac_roles')->where('code', 'production_operator')->value('id');
            DB::table('erp_rbac_user_roles')->insert(['user_legacy_id' => $id, 'role_id' => $roleId]);
        }

        $payload = ['client_command_id' => (string) Str::uuid(), 'expected_version' => 1,
            'qualified_base_qty' => 1, 'unqualified_base_qty' => 0, 'scrapped_base_qty' => 0];
        $this->withToken($this->token($outsiderId))
            ->postJson('/api/v1/erp/production/tasks/'.$fixture['task']->id.'/targets/quantity_operation/'.$fixture['target']->id.'/report', $payload)
            ->assertForbidden()->assertJsonPath('error_code', 'task_participant_required');

        $payload['client_command_id'] = (string) Str::uuid();
        $this->withToken($this->token($ownerId))
            ->postJson('/api/v1/erp/production/tasks/'.$fixture['task']->id.'/targets/quantity_operation/'.$fixture['target']->id.'/report', $payload)
            ->assertCreated()->assertJsonPath('data.remaining_base_qty', 9)
            ->assertJsonPath('data.qualified_base_qty', 1);
    }

    private function fixture(): array
    {
        $suffix = Str::upper(Str::random(8));
        $user = (object) ['legacy_id' => random_int(980001, 989999), 'nickname' => '报工测试员'];
        $unit = Unit::create(['unit_code' => 'RP-U-'.$suffix, 'unit_name' => '件', 'unit_type' => 'quantity',
            'decimal_places' => 0, 'is_base' => true, 'status' => 'enabled']);
        $item = Item::create(['item_code' => 'RP-I-'.$suffix, 'item_name' => '报工测试成品', 'item_type' => 'finished_good',
            'unit_id' => $unit->id, 'is_stock_item' => true, 'is_production_item' => true, 'status' => 'enabled']);
        $workOrder = WorkOrder::create(['work_order_no' => 'RP-WO-'.$suffix, 'source_type' => 'stock_prebuild',
            'output_item_id' => $item->id, 'target_qty' => 10, 'target_base_qty' => 10,
            'target_unit_id' => $unit->id, 'base_unit_id' => $unit->id, 'status' => 'IN_PROGRESS',
            'responsible_user_legacy_id' => $user->legacy_id, 'business_version' => 1]);
        $target = ProductionQuantityOperation::create(['work_order_id' => $workOrder->id,
            'operation_code_snapshot' => 'RP-OP', 'operation_name_snapshot' => '数量报工工序', 'sequence_no_snapshot' => 1,
            'status' => 'IN_PROGRESS', 'planned_base_qty' => 10, 'completed_base_qty' => 0,
            'unqualified_base_qty' => 0, 'scrapped_base_qty' => 0, 'remaining_base_qty' => 10,
            'started_at' => now()->subMinutes(10), 'output_item_id_snapshot' => $item->id,
            'output_mode_snapshot' => 'flow_only', 'quality_mode_snapshot' => 'none', 'business_version' => 1]);
        $task = ProductionTask::create(['task_no' => 'RP-T-'.$suffix, 'work_order_id' => $workOrder->id,
            'execution_mode' => 'quantity', 'operation_code_snapshot' => 'RP-OP', 'operation_name_snapshot' => '数量报工工序',
            'sequence_no_snapshot' => 1, 'status' => 'IN_PROGRESS', 'assignee_user_legacy_id' => $user->legacy_id,
            'organization_code' => 'RP-DEPT', 'business_version' => 1]);
        ProductionTaskTarget::create(['task_id' => $task->id, 'target_type' => 'quantity_operation',
            'target_id' => $target->id, 'status_snapshot' => 'IN_PROGRESS']);
        $session = ProductionLaborSession::create(['task_id' => $task->id, 'target_type' => 'quantity_operation',
            'target_id' => $target->id, 'employee_legacy_id' => $user->legacy_id, 'role' => 'owner',
            'status' => 'ACTIVE', 'started_at' => now()->subMinutes(10), 'actual_labor_minutes' => 0,
            'responsibility_weight_snapshot' => 1, 'credited_labor_minutes' => 0]);
        return compact('user', 'unit', 'item', 'workOrder', 'target', 'task', 'session');
    }

    private function expectDomain(string $code, callable $callback, int $status = 422): void
    {
        try { $callback(); $this->fail('Expected domain exception '.$code); }
        catch (WorkOrderDomainException $exception) { $this->assertSame($code, $exception->errorCode); $this->assertSame($status, $exception->status); }
    }

    private function token(int $userId): string
    {
        $token = 'report-token-'.Str::random(24);
        DB::table('erp_auth_tokens')->insert([
            'user_legacy_id' => $userId, 'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $token;
    }
}
