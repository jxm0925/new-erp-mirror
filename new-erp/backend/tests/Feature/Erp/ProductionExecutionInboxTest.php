<?php

namespace Tests\Feature\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{Item, ProductionTask, Unit, WorkOrder};
use App\Services\Erp\ProductionExecutionInboxService;
use App\Services\Erp\RbacBootstrapService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductionExecutionInboxTest extends TestCase
{
    use DatabaseTransactions;

    public function test_all_execution_records_are_discoverable_with_detail_and_backend_actions(): void
    {
        [$workOrder, $task] = $this->fixture();
        $now = now();
        $outputId = DB::table('erp_production_output_records')->insertGetId([
            'output_no' => 'OUT-'.Str::upper(Str::random(8)), 'work_order_id' => $workOrder->id,
            'source_target_type' => 'quantity_operation', 'source_target_id' => 900001,
            'output_item_id' => $workOrder->output_item_id, 'output_base_qty' => 2,
            'output_mode_snapshot' => 'warehouse_required', 'quality_mode_snapshot' => 'required',
            'status' => 'WAIT_QUALITY', 'created_by_legacy_id' => 7001, 'produced_at' => $now,
            'business_version' => 2, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $issueId = DB::table('erp_production_internal_issue_tasks')->insertGetId([
            'issue_no' => 'ISS-'.Str::upper(Str::random(8)), 'work_order_id' => $workOrder->id,
            'target_task_id' => $task->id, 'target_type' => 'quantity_operation', 'target_id' => 900002,
            'source_type' => 'common_inventory', 'status' => 'ISSUED', 'expected_receiver_legacy_id' => 7001,
            'business_version' => 3, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $supplementId = DB::table('erp_production_material_supplement_requests')->insertGetId([
            'request_no' => 'SUP-'.Str::upper(Str::random(8)), 'work_order_id' => $workOrder->id, 'task_id' => $task->id,
            'target_type' => 'quantity_operation', 'target_id' => 900002, 'status' => 'SUBMITTED', 'blocking' => true,
            'reason' => '测试补料', 'requested_by_legacy_id' => 7001, 'requested_at' => $now,
            'business_version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('erp_production_material_supplement_lines')->insert([
            'supplement_request_id' => $supplementId, 'component_item_id' => $workOrder->output_item_id,
            'standard_base_qty_snapshot' => 2, 'additional_base_qty' => 1.5, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $returnId = DB::table('erp_production_material_returns')->insertGetId([
            'return_no' => 'RET-'.Str::upper(Str::random(8)), 'work_order_id' => $workOrder->id, 'task_id' => $task->id,
            'target_type' => 'quantity_operation', 'target_id' => 900002, 'return_type' => 'quality_return',
            'status' => 'SUBMITTED', 'reason' => '测试退料', 'requested_by_legacy_id' => 7001, 'requested_at' => $now,
            'business_version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $permissions = ['production.task.view', 'production.output.quality', 'production.output.warehouse',
            'production.output.issue', 'production.output.receive', 'production.material_supplement.approve',
            'production.material_return.receive', 'production.material_return.quality'];
        $user = (object) ['legacy_id' => 7001];
        $service = app(ProductionExecutionInboxService::class);

        $output = $service->show('outputs', $outputId, $user, $permissions, true);
        $this->assertTrue($output['allowed_actions']['quality_inspect']);
        $this->assertFalse($output['allowed_actions']['warehouse']);
        $this->assertArrayHasKey('quality_inspections', $output);
        $this->assertSame($issueId, $service->paginate('internal_issues', ['status' => 'ISSUED'], $user, $permissions, true)->items()[0]['id']);
        $this->assertTrue($service->show('internal_issues', $issueId, $user, $permissions, true)['allowed_actions']['receive']);
        $this->assertTrue($service->show('material_supplements', $supplementId, $user, $permissions, true)['allowed_actions']['decide']);
        $supplementList = $service->paginate('material_supplements', [], $user, $permissions, true)->items()[0];
        $this->assertSame(1, $supplementList['line_count']);
        $this->assertSame(1.5, $supplementList['line_summary'][0]['quantity']);
        $this->assertTrue($service->show('material_returns', $returnId, $user, $permissions, true)['allowed_actions']['receive']);
    }

    public function test_inbox_requires_task_view_permission(): void
    {
        $this->expectException(WorkOrderDomainException::class);
        app(ProductionExecutionInboxService::class)->paginate('outputs', [], (object) ['legacy_id' => 7002], [], true);
    }

    public function test_real_http_inbox_obeys_self_work_order_scope(): void
    {
        app(RbacBootstrapService::class)->bootstrap(true);
        foreach ([7001, 7002] as $id) DB::table('erp_legacy_admin_users')->insert([
            'legacy_id' => $id, 'username' => 'inbox-'.$id, 'nickname' => '待办用户 '.$id,
            'status' => 'normal', 'auth_group_names' => '[]', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $roleId = DB::table('erp_rbac_roles')->insertGetId(['code' => 'inbox_self_'.Str::lower(Str::random(8)),
            'name' => '待办自有范围', 'data_scope' => 'self', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach (DB::table('erp_rbac_permissions')->whereIn('code', ['production.task.view', 'production.output.quality'])->pluck('id') as $permissionId) {
            DB::table('erp_rbac_role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
        DB::table('erp_rbac_user_roles')->insert(['user_legacy_id' => 7001, 'role_id' => $roleId]);
        [$own] = $this->fixture(7001);
        [$other] = $this->fixture(7002);
        $ownOutput = $this->createOutputRecord($own);
        $otherOutput = $this->createOutputRecord($other);
        $token = 'inbox-token-'.Str::random(30);
        DB::table('erp_auth_tokens')->insert(['user_legacy_id' => 7001, 'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);

        $this->withToken($token)->getJson('/api/v1/erp/production/outputs?status=WAIT_QUALITY')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $ownOutput);
        $this->withToken($token)->getJson('/api/v1/erp/production/outputs/'.$otherOutput)
            ->assertNotFound()->assertJsonPath('error_code', 'execution_record_not_found');

        $qualityPayload = [
            'client_command_id' => 'http-scope-'.Str::uuid(),
            'expected_version' => 1,
            'result' => 'passed',
            'qualified_base_qty' => 1,
            'unqualified_base_qty' => 0,
            'next_step' => 'direct_handover',
        ];
        $this->withToken($token)->postJson('/api/v1/erp/production/outputs/'.$otherOutput.'/quality-inspect', $qualityPayload)
            ->assertNotFound()->assertJsonPath('error_code', 'execution_record_not_found');
        $this->withToken($token)->postJson('/api/v1/erp/production/outputs/'.$ownOutput.'/quality-inspect', [
            ...$qualityPayload,
            'client_command_id' => 'http-own-'.Str::uuid(),
        ])->assertOk()->assertJsonPath('data.output_status', 'HANDED_OVER');
    }

    private function fixture(int $ownerId = 7001): array
    {
        $suffix = Str::upper(Str::random(8));
        $unit = Unit::create(['unit_code' => 'IN-'.$suffix, 'unit_name' => '件', 'unit_type' => 'quantity', 'decimal_places' => 0, 'is_base' => true, 'status' => 'enabled']);
        $item = Item::create(['item_code' => 'IN-'.$suffix, 'item_name' => '待办测试成品', 'item_type' => 'finished_good', 'unit_id' => $unit->id, 'status' => 'enabled']);
        $workOrder = WorkOrder::create(['work_order_no' => 'IN-'.$suffix, 'source_type' => 'stock_prebuild', 'output_item_id' => $item->id,
            'target_qty' => 2, 'target_base_qty' => 2, 'target_unit_id' => $unit->id, 'base_unit_id' => $unit->id,
            'status' => 'IN_PROGRESS', 'responsible_user_legacy_id' => $ownerId, 'business_version' => 1]);
        $task = ProductionTask::create(['task_no' => 'IN-T-'.$suffix, 'work_order_id' => $workOrder->id, 'execution_mode' => 'quantity',
            'operation_code_snapshot' => 'OP', 'operation_name_snapshot' => '装配', 'sequence_no_snapshot' => 1,
            'status' => 'CLAIMED', 'assignee_user_legacy_id' => $ownerId, 'business_version' => 1]);
        return [$workOrder, $task];
    }

    private function createOutputRecord(WorkOrder $workOrder): int
    {
        return DB::table('erp_production_output_records')->insertGetId([
            'output_no' => 'HTTP-OUT-'.Str::upper(Str::random(8)), 'work_order_id' => $workOrder->id,
            'source_target_type' => 'quantity_operation', 'source_target_id' => random_int(910000, 990000),
            'output_item_id' => $workOrder->output_item_id, 'output_base_qty' => 1,
            'output_mode_snapshot' => 'warehouse_optional', 'quality_mode_snapshot' => 'required',
            'status' => 'WAIT_QUALITY', 'created_by_legacy_id' => 7001, 'produced_at' => now(),
            'business_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
