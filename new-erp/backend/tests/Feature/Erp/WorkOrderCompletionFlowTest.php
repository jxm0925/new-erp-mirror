<?php

namespace Tests\Feature\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{
    Item,
    Location,
    ProductionLaborSession,
    ProductionOutputRecord,
    ProductionQuantityOperation,
    ProductionTask,
    ProductionUnit,
    ProductionUnitOperation,
    Unit,
    Warehouse,
    WorkOrder
};
use App\Services\Erp\{ProductionOutputService, RbacBootstrapService, WorkOrderCompletionService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkOrderCompletionFlowTest extends TestCase
{
    use DatabaseTransactions;

    public function test_rejected_completion_can_be_resubmitted_and_approved_before_finished_goods_receipt(): void
    {
        $f = $this->quantityFixture('warehouse_required');
        $service = app(WorkOrderCompletionService::class);
        $user = (object) ['legacy_id' => 971001, 'nickname' => '完工测试员'];
        $commandId = (string) Str::uuid();
        $payload = ['client_command_id' => $commandId, 'expected_version' => 1,
            'output_record_ids' => [$f['output']->id], 'remark' => '首轮完工申报'];

        $submitted = $service->submit($f['workOrder']->id, $payload, $user,
            ['production.completion.create'], true);
        $this->assertSame('PENDING_REVIEW', $submitted['status']);
        $this->assertEquals($submitted, $service->submit($f['workOrder']->id, $payload, $user,
            ['production.completion.create'], true));
        $this->expectDomain('command_conflict', fn () => $service->submit($f['workOrder']->id,
            array_merge($payload, ['remark' => '不同请求']), $user, ['production.completion.create'], true), 409);

        $rejected = $service->review($submitted['completion_id'], [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => 1,
            'decision' => 'reject', 'reason' => '完工资料需补充',
        ], $user, ['production.completion.review'], true);
        $this->assertSame('REJECTED', $rejected['status']);
        $this->assertSame('WAIT_COMPLETION', $f['output']->fresh()->status);
        $this->assertSame('IN_PROGRESS', $f['workOrder']->fresh()->status);

        // Defense in depth: even a stale/manual WAIT_WAREHOUSE state cannot bypass approval.
        $f['output']->fresh()->update(['status' => 'WAIT_WAREHOUSE']);
        $this->expectDomain('completion_approval_required', fn () => app(ProductionOutputService::class)->warehouse(
            $f['output']->id,
            ['client_command_id' => (string) Str::uuid(), 'expected_version' => 3,
                'warehouse_id' => $f['warehouse']->id, 'location_id' => $f['location']->id, 'batch_no' => $this->code('BLOCK')],
            $user, ['production.output.warehouse']), 409);
        $f['output']->fresh()->update(['status' => 'WAIT_COMPLETION']);

        $resubmitted = $service->submit($f['workOrder']->id, [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => 2,
            'output_record_ids' => [$f['output']->id], 'remark' => '补充后重新申报',
        ], $user, ['production.completion.create'], true);
        $approved = $service->review($resubmitted['completion_id'], [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => 1, 'decision' => 'approve',
        ], $user, ['production.completion.review'], true);
        $this->assertSame('APPROVED', $approved['status']);
        $this->assertSame('IN_PROGRESS', $f['workOrder']->fresh()->status);
        $this->assertSame('WAIT_WAREHOUSE', $f['output']->fresh()->status);
        $this->assertDatabaseMissing('erp_work_order_status_logs', [
            'work_order_id' => $f['workOrder']->id, 'after_status' => 'COMPLETED',
        ]);

        $output = $f['output']->fresh();
        $firstPostingPayload = [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => $output->business_version,
            'warehouse_id' => $f['warehouse']->id, 'location_id' => $f['location']->id,
            'batch_no' => $this->code('FG1'), 'posted_base_qty' => 2,
        ];
        $posted = app(ProductionOutputService::class)->warehouse($output->id, $firstPostingPayload,
            $user, ['production.output.warehouse']);
        $this->assertSame('WAIT_WAREHOUSE', $posted['output_status']);
        $this->assertSame(3.0, $posted['remaining_receivable_base_qty']);
        $this->assertSame('IN_PROGRESS', $f['workOrder']->fresh()->status);
        $this->assertEquals($posted, app(ProductionOutputService::class)->warehouse($output->id,
            $firstPostingPayload, $user, ['production.output.warehouse']));
        $this->expectDomain('finished_goods_receipt_quantity_invalid', fn () => app(ProductionOutputService::class)->warehouse(
            $output->id,
            ['client_command_id' => (string) Str::uuid(), 'expected_version' => $posted['output_business_version'],
                'warehouse_id' => $f['warehouse']->id, 'location_id' => $f['location']->id,
                'batch_no' => $this->code('OVER'), 'posted_base_qty' => 4],
            $user, ['production.output.warehouse']), 409);

        $finalPosting = app(ProductionOutputService::class)->warehouse($output->id, [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => $posted['output_business_version'],
            'warehouse_id' => $f['warehouse']->id, 'location_id' => $f['location']->id,
            'batch_no' => $this->code('FG2'), 'posted_base_qty' => 3,
        ], $user, ['production.output.warehouse']);
        $this->assertSame('WAREHOUSED', $finalPosting['output_status']);
        $this->assertSame(0.0, $finalPosting['remaining_receivable_base_qty']);
        $this->assertSame('COMPLETED', $f['workOrder']->fresh()->status);
        $this->assertDatabaseHas('erp_work_order_status_logs', [
            'work_order_id' => $f['workOrder']->id, 'before_status' => 'IN_PROGRESS', 'after_status' => 'COMPLETED',
        ]);
        $this->assertDatabaseHas('erp_work_order_finished_goods_receipts', [
            'id' => $posted['finished_goods_receipt_id'], 'completion_id' => $resubmitted['completion_id'],
            'output_record_id' => $output->id, 'inventory_transaction_id' => $posted['inventory_transaction_id'],
            'posted_base_qty' => 2,
        ]);
        $this->assertSame(2, DB::table('erp_work_order_finished_goods_receipts')->where('output_record_id', $output->id)->count());
        $this->assertSame(5.0, (float) DB::table('erp_work_order_finished_goods_receipts')->where('output_record_id', $output->id)->sum('posted_base_qty'));
        $this->assertSame(2, DB::table('erp_inventory_transactions')->where('source_type', 'work_order_finished_goods_receipt')
            ->whereIn('source_id', DB::table('erp_work_order_finished_goods_receipts')->where('output_record_id', $output->id)->select('id'))->count());
        $page = $service->paginate($f['workOrder']->id, 1, 20, $user,
            ['production.completion.view'], true);
        $this->assertCount(2, $page['data']);
        $this->assertSame(2, $page['total']);
    }

    public function test_unit_mode_requires_all_terminal_units_to_be_approved_and_warehoused_before_work_order_completion(): void
    {
        $f = $this->unitFixture();
        $service = app(WorkOrderCompletionService::class);
        $user = (object) ['legacy_id' => 971002];

        $first = $service->submit($f['workOrder']->id, [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => 1,
            'output_record_ids' => [$f['outputs'][0]->id],
        ], $user, ['production.completion.create'], true);
        $service->review($first['completion_id'], [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => 1, 'decision' => 'approve',
        ], $user, ['production.completion.review'], true);
        $this->assertSame('IN_PROGRESS', $f['workOrder']->fresh()->status);
        $this->assertSame('WAIT_WAREHOUSE', $f['outputs'][0]->fresh()->status);
        app(ProductionOutputService::class)->warehouse($f['outputs'][0]->id, [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => 3,
            'warehouse_id' => $f['warehouse']->id, 'location_id' => $f['location']->id,
            'batch_no' => $this->code('UNIT1'), 'posted_base_qty' => 1,
        ], $user, ['production.output.warehouse']);
        $this->assertSame('IN_PROGRESS', $f['workOrder']->fresh()->status);

        $second = $service->submit($f['workOrder']->id, [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => 2,
            'output_record_ids' => [$f['outputs'][1]->id],
        ], $user, ['production.completion.create'], true);
        $service->review($second['completion_id'], [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => 1, 'decision' => 'approve',
        ], $user, ['production.completion.review'], true);
        $this->assertSame('IN_PROGRESS', $f['workOrder']->fresh()->status);
        app(ProductionOutputService::class)->warehouse($f['outputs'][1]->id, [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => 3,
            'warehouse_id' => $f['warehouse']->id, 'location_id' => $f['location']->id,
            'batch_no' => $this->code('UNIT2'), 'posted_base_qty' => 1,
        ], $user, ['production.output.warehouse']);
        $this->assertSame('COMPLETED', $f['workOrder']->fresh()->status);
        $this->assertSame(2.0, (float) DB::table('erp_work_order_completions')
            ->where('work_order_id', $f['workOrder']->id)->where('status', 'APPROVED')->sum('submitted_base_qty'));
    }

    public function test_terminal_quality_pass_waits_for_completion_instead_of_creating_handover(): void
    {
        $f = $this->quantityFixture('flow_only', 'required');
        $result = app(ProductionOutputService::class)->inspect($f['output']->id, [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => 1,
            'result' => 'passed', 'qualified_base_qty' => 5, 'unqualified_base_qty' => 0,
            'next_step' => 'direct_handover',
        ], (object) ['legacy_id' => 971003], ['production.output.quality']);

        $this->assertSame('WAIT_COMPLETION', $result['output_status']);
        $this->assertSame('COMPLETED', $f['target']->fresh()->status);
        $this->assertSame('IN_PROGRESS', $f['workOrder']->fresh()->status);
        $this->assertDatabaseMissing('erp_production_operation_handovers', ['output_record_id' => $f['output']->id]);
    }

    public function test_preflight_reports_each_blocker_and_permissions_and_scope_are_enforced(): void
    {
        $f = $this->quantityFixture('warehouse_required');
        $service = app(WorkOrderCompletionService::class);
        $owner = (object) ['legacy_id' => 971004];
        $outsider = (object) ['legacy_id' => 971005];
        $task = ProductionTask::create([
            'task_no' => $this->code('TASK'), 'work_order_id' => $f['workOrder']->id,
            'execution_mode' => 'quantity', 'operation_code_snapshot' => 'FINAL', 'operation_name_snapshot' => '终工序',
            'sequence_no_snapshot' => 1, 'status' => 'COMPLETED', 'assignee_user_legacy_id' => $owner->legacy_id,
            'business_version' => 1,
        ]);
        $labor = ProductionLaborSession::create([
            'task_id' => $task->id, 'target_type' => 'quantity_operation', 'target_id' => $f['target']->id,
            'employee_legacy_id' => $owner->legacy_id, 'role' => 'owner', 'status' => 'ACTIVE',
            'started_at' => now(), 'actual_labor_minutes' => 0, 'responsibility_weight_snapshot' => 1,
            'credited_labor_minutes' => 0,
        ]);
        $f['target']->update(['remaining_base_qty' => 1]);
        $returnId = DB::table('erp_production_material_returns')->insertGetId([
            'return_no' => $this->code('RET'), 'work_order_id' => $f['workOrder']->id, 'task_id' => $task->id,
            'target_type' => 'quantity_operation', 'target_id' => $f['target']->id,
            'return_type' => 'normal_return', 'status' => 'SUBMITTED', 'reason' => '余料退回',
            'requested_by_legacy_id' => $owner->legacy_id, 'requested_at' => now(), 'business_version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $blocked = $service->preflight($f['workOrder']->id, $owner, ['production.completion.create'], true);
        $checks = collect($blocked['checks'])->keyBy('key');
        $this->assertFalse($blocked['passed']);
        $this->assertSame(1, $checks['labor_ended']['value']);
        $this->assertSame(1.0, $checks['reports_settled']['value']);
        $this->assertSame(1, $checks['material_returns_settled']['value']);
        $this->assertSame(5.0, $blocked['quantity']['reported_base_qty']);

        $labor->update(['status' => 'ENDED', 'ended_at' => now()]);
        $f['target']->update(['remaining_base_qty' => 0]);
        DB::table('erp_production_material_returns')->where('id', $returnId)->update(['status' => 'COMPLETED']);
        $this->assertTrue($service->preflight($f['workOrder']->id, $owner,
            ['production.completion.create'], true)['passed']);

        $this->expectDomain('permission_denied', fn () => $service->preflight(
            $f['workOrder']->id, $owner, [], true), 403);
        $this->expectDomain('data_scope_denied', fn () => $service->preflight(
            $f['workOrder']->id, $outsider, ['production.completion.create', 'production.work_order.view'], false), 403);
    }

    public function test_real_http_routes_apply_operator_scope_and_manager_review_permission(): void
    {
        $f = $this->quantityFixture('flow_only');
        app(RbacBootstrapService::class)->bootstrap(true);
        $users = [971004 => 'completion-owner', 971005 => 'completion-outsider', 971006 => 'completion-manager'];
        foreach ($users as $legacyId => $name) {
            DB::table('erp_legacy_admin_users')->insert([
                'legacy_id' => $legacyId, 'username' => $name.'-'.Str::lower(Str::random(6)),
                'nickname' => $name, 'status' => 'normal', 'auth_group_names' => '[]',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $roleCode = $legacyId === 971006 ? 'production_manager' : 'production_operator';
            DB::table('erp_rbac_user_roles')->insert([
                'user_legacy_id' => $legacyId,
                'role_id' => DB::table('erp_rbac_roles')->where('code', $roleCode)->value('id'),
            ]);
        }

        $this->withToken($this->token(971004))
            ->getJson('/api/v1/erp/production/work-orders/'.$f['workOrder']->id.'/completion-preflight')
            ->assertOk()->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.quantity.reported_base_qty', 5)
            ->assertJsonPath('data.terminal_outputs.0.output_record_id', $f['output']->id);

        $payload = ['client_command_id' => (string) Str::uuid(), 'expected_version' => 1,
            'output_record_ids' => [$f['output']->id]];
        $this->withToken($this->token(971005))
            ->postJson('/api/v1/erp/production/work-orders/'.$f['workOrder']->id.'/completions', $payload)
            ->assertForbidden()->assertJsonPath('error_code', 'data_scope_denied');

        $payload['client_command_id'] = (string) Str::uuid();
        $submitted = $this->withToken($this->token(971004))
            ->postJson('/api/v1/erp/production/work-orders/'.$f['workOrder']->id.'/completions', $payload)
            ->assertCreated()->assertJsonPath('data.status', 'PENDING_REVIEW');
        $completionId = (int) $submitted->json('data.completion_id');

        $review = ['client_command_id' => (string) Str::uuid(), 'expected_version' => 1, 'decision' => 'approve'];
        $this->withToken($this->token(971004))
            ->postJson('/api/v1/erp/production/completions/'.$completionId.'/review', $review)
            ->assertForbidden()->assertJsonPath('error_code', 'permission_denied');
        $review['client_command_id'] = (string) Str::uuid();
        $this->withToken($this->token(971006))
            ->postJson('/api/v1/erp/production/completions/'.$completionId.'/review', $review)
            ->assertOk()->assertJsonPath('data.status', 'APPROVED');

        $this->withToken($this->token(971004))
            ->getJson('/api/v1/erp/production/work-orders/'.$f['workOrder']->id.'/completions')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    private function quantityFixture(string $outputMode, string $qualityMode = 'none'): array
    {
        $suffix = Str::upper(Str::random(8));
        $unit = Unit::create(['unit_code' => 'WC-U-'.$suffix, 'unit_name' => '件', 'unit_type' => 'quantity',
            'decimal_places' => 0, 'is_base' => true, 'status' => 'enabled']);
        $item = Item::create(['item_code' => 'WC-I-'.$suffix, 'item_name' => '完工测试成品', 'item_type' => 'finished_good',
            'unit_id' => $unit->id, 'is_stock_item' => true, 'is_production_item' => true, 'status' => 'enabled']);
        $warehouse = Warehouse::create(['warehouse_code' => 'WC-WH-'.$suffix, 'warehouse_name' => '完工测试仓', 'status' => 'enabled']);
        $location = Location::create(['warehouse_id' => $warehouse->id, 'location_code' => 'WC-L-'.$suffix,
            'location_name' => '完工测试库位', 'status' => 'enabled']);
        $workOrder = WorkOrder::create(['work_order_no' => 'WC-WO-'.$suffix, 'source_type' => 'stock_prebuild',
            'output_item_id' => $item->id, 'target_qty' => 5, 'target_base_qty' => 5,
            'target_unit_id' => $unit->id, 'base_unit_id' => $unit->id, 'status' => 'IN_PROGRESS',
            'responsible_user_legacy_id' => 971004, 'business_version' => 1]);
        $target = ProductionQuantityOperation::create(['work_order_id' => $workOrder->id,
            'operation_code_snapshot' => 'FINAL', 'operation_name_snapshot' => '终工序', 'sequence_no_snapshot' => 1,
            'status' => $qualityMode === 'none' ? 'COMPLETED' : 'WAIT_QUALITY', 'planned_base_qty' => 5,
            'completed_base_qty' => 5, 'unqualified_base_qty' => 0, 'scrapped_base_qty' => 0, 'remaining_base_qty' => 0,
            'completed_at' => now(), 'output_item_id_snapshot' => $item->id,
            'output_mode_snapshot' => $outputMode, 'quality_mode_snapshot' => $qualityMode, 'business_version' => 1]);
        $output = ProductionOutputRecord::create(['output_no' => 'WC-OUT-'.$suffix, 'work_order_id' => $workOrder->id,
            'source_target_type' => 'quantity_operation', 'source_target_id' => $target->id,
            'output_item_id' => $item->id, 'output_base_qty' => 5, 'output_mode_snapshot' => $outputMode,
            'quality_mode_snapshot' => $qualityMode, 'status' => $qualityMode === 'none' ? 'WAIT_COMPLETION' : 'WAIT_QUALITY',
            'created_by_legacy_id' => 971004, 'produced_at' => now(), 'business_version' => 1]);

        return compact('unit', 'item', 'warehouse', 'location', 'workOrder', 'target', 'output');
    }

    private function unitFixture(): array
    {
        $f = $this->quantityFixture('warehouse_required');
        $f['output']->delete();
        $f['target']->delete();
        $f['workOrder']->update(['production_execution_mode_snapshot' => 'unit', 'target_qty' => 2, 'target_base_qty' => 2]);
        $outputs = [];
        foreach ([1, 2] as $sequence) {
            $unit = ProductionUnit::create(['unit_no' => $this->code('PU'), 'work_order_id' => $f['workOrder']->id,
                'sequence_no' => $sequence, 'output_item_id' => $f['item']->id, 'status' => 'COMPLETED',
                'routing_snapshot' => [], 'business_version' => 1]);
            $operation = ProductionUnitOperation::create(['production_unit_id' => $unit->id, 'work_order_id' => $f['workOrder']->id,
                'operation_code_snapshot' => 'FINAL', 'operation_name_snapshot' => '终工序', 'sequence_no_snapshot' => 1,
                'status' => 'COMPLETED', 'completed_at' => now(), 'output_item_id_snapshot' => $f['item']->id,
                'output_mode_snapshot' => 'warehouse_required', 'quality_mode_snapshot' => 'none', 'business_version' => 1]);
            $outputs[] = ProductionOutputRecord::create(['output_no' => $this->code('UOUT'), 'work_order_id' => $f['workOrder']->id,
                'source_target_type' => 'unit_operation', 'source_target_id' => $operation->id,
                'production_unit_id' => $unit->id, 'output_item_id' => $f['item']->id, 'output_base_qty' => 1,
                'output_mode_snapshot' => 'warehouse_required', 'quality_mode_snapshot' => 'none',
                'status' => 'WAIT_COMPLETION', 'created_by_legacy_id' => 971002, 'produced_at' => now(), 'business_version' => 1]);
        }
        $f['outputs'] = $outputs;
        return $f;
    }

    private function expectDomain(string $code, callable $callback, int $status): void
    {
        try {
            $callback();
            $this->fail('Expected domain exception '.$code);
        } catch (WorkOrderDomainException $exception) {
            $this->assertSame($code, $exception->errorCode);
            $this->assertSame($status, $exception->status);
        }
    }

    private function code(string $prefix): string
    {
        return $prefix.'-'.Str::upper(Str::random(10));
    }

    private function token(int $userId): string
    {
        $token = 'completion-token-'.Str::random(24);
        DB::table('erp_auth_tokens')->insert([
            'user_legacy_id' => $userId, 'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $token;
    }
}
