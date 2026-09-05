<?php

namespace Tests\Feature\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\Bom;
use App\Models\Erp\BomItem;
use App\Models\Erp\Item;
use App\Models\Erp\Product;
use App\Models\Erp\ProductionDemand;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesOrderLine;
use App\Models\Erp\Sku;
use App\Models\Erp\Unit;
use App\Services\Erp\RbacBootstrapService;
use App\Services\Erp\ReleaseGateApplicationService;
use App\Services\Erp\ProductionExecutionActionService;
use App\Services\Erp\ProductionKittingService;
use App\Services\Erp\ProductionTaskAssignmentService;
use App\Services\Erp\ProductionTaskCollaborationService;
use App\Services\Erp\WorkOrderApplicationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkOrderWo04ReleaseTest extends TestCase
{
    use DatabaseTransactions;

    private const PERMISSIONS = [
        'production.demand.view', 'production.work_order.view', 'production.work_order.create',
        'production.work_order.edit', 'production.work_order.submit', 'production.work_order.cancel',
        'production.work_order.gate.view', 'production.work_order.publish', 'production.material.view',
    ];

    public function test_release_gate_publish_and_material_snapshot_are_real_and_idempotent(): void
    {
        [$user, $demand, $bom] = $this->fixture();
        $workOrders = app(WorkOrderApplicationService::class);
        $releaseGate = app(ReleaseGateApplicationService::class);

        $draft = $workOrders->createDraft([
            'client_command_id' => 'wo04-create-1',
            'production_demand_id' => $demand->id,
            'expected_demand_version' => 1,
            'target_qty' => 5,
            'planned_date' => '2026-09-08',
            'production_batch' => 'WO04-BATCH-01',
            'responsible_user_legacy_id' => $user->legacy_id,
            'production_location_name' => '一号装配车间',
        ], $user, self::PERMISSIONS);
        $waiting = $workOrders->submit($draft->id, [
            'client_command_id' => 'wo04-submit-1',
            'expected_version' => 1,
            'reason' => '计划已确认',
        ], $user, self::PERMISSIONS);

        $gate = $releaseGate->evaluate($waiting->id, $user, self::PERMISSIONS);
        $this->assertTrue($gate['allowed']);
        $this->assertSame('passed', $gate['status']);
        $this->assertFalse($gate['immutable']);
        $this->assertSame($bom->id, $gate['bom']['bom_id']);
        $this->assertCount(13, $gate['checks']);
        $this->assertSame(13, DB::table('erp_work_order_release_gate_checks')
            ->where('work_order_id', $waiting->id)
            ->count());

        $payload = [
            'client_command_id' => 'wo04-publish-1',
            'expected_version' => 2,
            'reason' => '物料与计划均已确认',
        ];
        $released = $workOrders->publish($waiting->id, $payload, $user, self::PERMISSIONS);
        $this->assertSame(WorkOrderApplicationService::RELEASED, $released->status);
        $this->assertSame(3, (int) $released->business_version);
        $this->assertSame($bom->id, (int) $released->bom_id);
        $this->assertSame($bom->id, (int) $released->bom_version_id);
        $this->assertSame('V1.0', $released->bom_version);
        $this->assertSame('passed', $released->release_gate_status);
        $this->assertSame('物料与计划均已确认', $released->release_reason);

        $material = DB::table('erp_work_order_material_requirements')->where('work_order_id', $released->id)->first();
        $this->assertNotNull($material);
        $this->assertSame(2.0, (float) $material->per_output_qty);
        $this->assertSame(10.0, (float) $material->loss_rate);
        $this->assertSame(1.0, (float) $material->fixed_qty);
        $this->assertSame(12.0, (float) $material->required_qty);
        $this->assertSame(12.0, (float) $material->base_required_qty);
        $this->assertSame(12.0, (float) $material->remaining_qty);
        $this->assertSame(0.0, (float) $material->issued_qty);
        $this->assertSame('OPEN', $material->status);

        $replay = $workOrders->publish($waiting->id, $payload, $user, self::PERMISSIONS);
        $this->assertSame($released->id, $replay->id);
        $this->assertSame(1, DB::table('erp_work_order_material_requirements')->where('work_order_id', $released->id)->count());

        try {
            $workOrders->publish($waiting->id, [...$payload, 'reason' => '篡改后的发布原因'], $user, self::PERMISSIONS);
            $this->fail('同一 command id 不得接受不同请求哈希。');
        } catch (WorkOrderDomainException $exception) {
            $this->assertSame('idempotency_hash_conflict', $exception->errorCode);
            $this->assertSame(409, $exception->status);
        }

        $bom->update(['status' => 'disabled', 'version' => 'V2.0']);
        $frozenGate = $releaseGate->evaluate($released->id, $user, self::PERMISSIONS);
        $this->assertTrue($frozenGate['allowed']);
        $this->assertTrue($frozenGate['immutable']);
        $this->assertSame($bom->id, $frozenGate['bom']['bom_id']);
        $this->assertSame('V1.0', $frozenGate['bom']['version']);

        $page = $releaseGate->materialRequirements($released->id, ['page' => 1, 'per_page' => 10], $user, self::PERMISSIONS);
        $this->assertSame(1, $page->total());
        $this->assertSame(1, $page->currentPage());
    }

    public function test_unit_mode_twenty_creates_exactly_twenty_units_without_rounding(): void
    {
        [$user, $demand] = $this->fixture(7530);
        $demand->update(['production_qty' => 20, 'remaining_qty' => 20]);
        DB::table('erp_sales_order_lines')->where('id', $demand->sales_order_line_id)
            ->update(['order_qty' => 20, 'item_base_required_qty' => 20]);
        $service = app(WorkOrderApplicationService::class);
        $draft = $service->createDraft([
            'client_command_id' => 'phase6b-unit-20-create', 'production_demand_id' => $demand->id,
            'expected_demand_version' => 1, 'target_qty' => 20, 'planned_date' => '2026-09-18',
            'production_location_name' => '逐件执行车间',
        ], $user, self::PERMISSIONS);
        $waiting = $service->submit($draft->id, ['client_command_id' => 'phase6b-unit-20-submit',
            'expected_version' => 1, 'reason' => '逐件数量验证'], $user, self::PERMISSIONS);
        $released = $service->publish($waiting->id, ['client_command_id' => 'phase6b-unit-20-publish',
            'expected_version' => 2, 'reason' => '逐件数量验证'], $user, self::PERMISSIONS);

        $this->assertSame('unit', $released->production_execution_mode_snapshot);
        $this->assertSame(20, DB::table('erp_production_units')->where('work_order_id', $released->id)->count());
        $this->assertSame(range(1, 20), DB::table('erp_production_units')->where('work_order_id', $released->id)->orderBy('sequence_no')->pluck('sequence_no')->map(fn ($value) => (int) $value)->all());
        $this->assertSame(20, DB::table('erp_production_unit_operations')->where('work_order_id', $released->id)->count());
        $this->assertSame(1, DB::table('erp_production_tasks')->where('work_order_id', $released->id)->count());
        $this->assertSame(20, DB::table('erp_production_task_targets')->whereIn('task_id', DB::table('erp_production_tasks')->where('work_order_id', $released->id)->pluck('id'))->count());
    }

    public function test_fractional_unit_mode_is_blocked_and_creates_no_execution_facts(): void
    {
        [$user, $demand] = $this->fixture(7531);
        $service = app(WorkOrderApplicationService::class);
        $draft = $service->createDraft(['client_command_id' => 'phase6b-unit-fraction-create',
            'production_demand_id' => $demand->id, 'expected_demand_version' => 1, 'target_qty' => 2.5,
            'planned_date' => '2026-09-18', 'production_location_name' => '逐件执行车间'], $user, self::PERMISSIONS);
        $waiting = $service->submit($draft->id, ['client_command_id' => 'phase6b-unit-fraction-submit',
            'expected_version' => 1, 'reason' => '小数阻断验证'], $user, self::PERMISSIONS);
        $gate = app(ReleaseGateApplicationService::class)->evaluate($waiting->id, $user, self::PERMISSIONS);
        $this->assertFalse($gate['allowed']);
        $this->assertContains('production_unit_quantity_not_integer', array_column($gate['blockers'], 'reason_code'));
        try {
            $service->publish($waiting->id, ['client_command_id' => 'phase6b-unit-fraction-publish',
                'expected_version' => 2, 'reason' => '不得取整'], $user, self::PERMISSIONS);
            $this->fail('逐件模式小数数量不得发布。');
        } catch (WorkOrderDomainException $exception) {
            $this->assertSame('release_gate_blocked', $exception->errorCode);
        }
        $this->assertSame(0, DB::table('erp_production_units')->where('work_order_id', $waiting->id)->count());
        $this->assertSame(0, DB::table('erp_production_quantity_operations')->where('work_order_id', $waiting->id)->count());
    }

    public function test_fractional_quantity_mode_publishes_without_fake_units_and_freezes_mode(): void
    {
        [$user, $demand] = $this->fixture(7532);
        Item::query()->whereKey($demand->item_id)->update(['production_execution_mode' => 'quantity']);
        $service = app(WorkOrderApplicationService::class);
        $draft = $service->createDraft(['client_command_id' => 'phase6b-qty-fraction-create',
            'production_demand_id' => $demand->id, 'expected_demand_version' => 1, 'target_qty' => 2.5,
            'planned_date' => '2026-09-18', 'production_location_name' => '数量执行车间'], $user, self::PERMISSIONS);
        $waiting = $service->submit($draft->id, ['client_command_id' => 'phase6b-qty-fraction-submit',
            'expected_version' => 1, 'reason' => '数量模式验证'], $user, self::PERMISSIONS);
        $released = $service->publish($waiting->id, ['client_command_id' => 'phase6b-qty-fraction-publish',
            'expected_version' => 2, 'reason' => '数量模式验证'], $user, self::PERMISSIONS);
        $this->assertSame('quantity', $released->production_execution_mode_snapshot);
        $this->assertSame(0, DB::table('erp_production_units')->where('work_order_id', $released->id)->count());
        $operation = DB::table('erp_production_quantity_operations')->where('work_order_id', $released->id)->first();
        $this->assertNotNull($operation);
        $this->assertSame(2.5, (float) $operation->planned_base_qty);
        Item::query()->whereKey($demand->item_id)->update(['production_execution_mode' => 'unit']);
        $this->assertSame('quantity', DB::table('erp_work_orders')->where('id', $released->id)->value('production_execution_mode_snapshot'));
    }

    public function test_required_kitting_freezes_workstation_fact_and_starts_owner_labor_immediately(): void
    {
        [$user, $demand] = $this->fixture(7533);
        $service = app(WorkOrderApplicationService::class);
        $draft = $service->createDraft(['client_command_id' => 'phase6b-facts-create', 'production_demand_id' => $demand->id,
            'expected_demand_version' => 1, 'target_qty' => 1, 'planned_date' => '2026-09-18',
            'production_location_name' => '执行事实车间'], $user, self::PERMISSIONS);
        $waiting = $service->submit($draft->id, ['client_command_id' => 'phase6b-facts-submit', 'expected_version' => 1,
            'reason' => '执行事实验证'], $user, self::PERMISSIONS);
        $released = $service->publish($waiting->id, ['client_command_id' => 'phase6b-facts-publish', 'expected_version' => 2,
            'reason' => '执行事实验证'], $user, self::PERMISSIONS);
        $task = DB::table('erp_production_tasks')->where('work_order_id', $released->id)->first();
        $link = DB::table('erp_production_task_targets')->where('task_id', $task->id)->first();

        $claimed = app(ProductionTaskAssignmentService::class)->claim($task->id,
            ['client_command_id' => 'phase6b-facts-claim', 'expected_version' => 1], $user, ['production.task.claim']);
        $target = DB::table('erp_production_unit_operations')->where('id', $link->target_id)->first();
        $this->assertNotNull($claimed['claimed_at']);
        $this->assertSame('WAIT_MATERIAL', $target->status);
        $this->assertNull($target->kitting_confirmed_at);
        $this->assertNull($target->started_at);
        $this->assertSame(0, DB::table('erp_production_labor_sessions')->where('target_id', $target->id)->count());

        $materialRequirement = DB::table('erp_production_target_material_requirements')
            ->where('target_type', 'unit_operation')->where('target_id', $target->id)->first();
        DB::table('erp_work_order_material_supply_rules')->where('id', $materialRequirement->material_supply_rule_snapshot_id)
            ->update(['supply_mode_snapshot' => 'workstation_stock', 'requires_delivery_snapshot' => false, 'updated_at' => now()]);

        $kitting = app(ProductionKittingService::class)->confirm($task->id, 'unit_operation', $target->id,
            ['client_command_id' => 'phase6b-facts-kitting', 'expected_version' => 2,
                'workstation_stock_confirmations' => [[
                    'requirement_id' => $materialRequirement->id,
                    'onsite_available_base_qty' => (float) $materialRequirement->required_base_qty + 2,
                    'workstation' => '总装一号工位',
                ]]], $user, ['production.kitting.confirm']);
        $this->assertSame('IN_PROGRESS', $kitting['target_status']);
        $started = DB::table('erp_production_unit_operations')->where('id', $target->id)->first();
        $this->assertNotNull($started->kitting_confirmed_at);
        $this->assertNotNull($started->started_at);
        $this->assertSame(1, DB::table('erp_production_labor_sessions')->where('target_id', $target->id)->where('status', 'ACTIVE')->count());
        $fact = DB::table('erp_production_workstation_stock_confirmations')->where('target_material_requirement_id', $materialRequirement->id)->first();
        $this->assertSame('总装一号工位', $fact->workstation_snapshot);
        $this->assertSame((float) $materialRequirement->required_base_qty + 2, (float) $fact->onsite_available_base_qty_snapshot);

        $collaboratorId = $user->legacy_id + 100000;
        DB::table('erp_legacy_admin_users')->insert(['legacy_id' => $collaboratorId, 'username' => 'phase6b-collaborator-'.$collaboratorId,
            'nickname' => '协作者', 'status' => 'normal', 'auth_group_names' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('erp_work_orders')->where('id', $released->id)->update(['collaboration_enabled' => true, 'updated_at' => now()]);
        $collaborator = DB::table('erp_legacy_admin_users')->where('legacy_id', $collaboratorId)->first();
        app(ProductionTaskCollaborationService::class)->join($task->id,
            ['client_command_id' => 'phase6b-facts-collaborator-join', 'expected_version' => 3],
            $collaborator, ['production.task.collaborate']);
        $this->assertSame(1, DB::table('erp_production_labor_sessions')->where('target_id', $target->id)->where('status', 'ACTIVE')->count(),
            '加入协同不得自动给协作者启动计时');
        $collaboratorStarted = app(ProductionExecutionActionService::class)->start($task->id, 'unit_operation', $target->id,
            ['client_command_id' => 'phase6b-facts-collaborator-start', 'expected_version' => 3],
            $collaborator, ['production.task.start']);
        $this->assertSame('IN_PROGRESS', $collaboratorStarted['target_status']);
        $this->assertSame(2, DB::table('erp_production_labor_sessions')->where('target_id', $target->id)->where('status', 'ACTIVE')->count());
        app(ProductionExecutionActionService::class)->pause($task->id, 'unit_operation', $target->id,
            ['client_command_id' => 'phase6b-facts-collaborator-pause', 'expected_version' => 4],
            $collaborator, ['production.task.pause']);
        $this->assertSame(1, DB::table('erp_production_labor_sessions')->where('target_id', $target->id)->where('status', 'ACTIVE')->count());
        try {
            app(ProductionExecutionActionService::class)->start($task->id, 'unit_operation', $target->id,
                ['client_command_id' => 'phase6b-facts-duplicate-start', 'expected_version' => 5],
                $user, ['production.task.start']);
            $this->fail('需要齐套的工序不得重复点击开始加工。');
        } catch (WorkOrderDomainException $exception) {
            $this->assertSame('kitting_starts_processing', $exception->errorCode);
            $this->assertSame(409, $exception->status);
        }
        $completed = app(ProductionExecutionActionService::class)->complete($task->id, 'unit_operation', $target->id,
            ['client_command_id' => 'phase6b-facts-complete', 'expected_version' => 5], $user, ['production.task.complete']);
        $this->assertSame('COMPLETED', $completed['target_status']);
        $this->assertNotNull($completed['output_record_id']);
        $this->assertSame(0, DB::table('erp_production_labor_sessions')->where('target_id', $target->id)->where('status', 'ACTIVE')->count());
        $this->assertSame(1, DB::table('erp_production_output_records')->where('source_target_type', 'unit_operation')->where('source_target_id', $target->id)->count());
    }

    public function test_blocked_gate_cannot_publish_and_does_not_create_material_facts(): void
    {
        [$user, $demand] = $this->fixture(7502);
        $service = app(WorkOrderApplicationService::class);
        $gateService = app(ReleaseGateApplicationService::class);
        $draft = $service->createDraft([
            'client_command_id' => 'wo04-create-blocked',
            'production_demand_id' => $demand->id,
            'expected_demand_version' => 1,
            'target_qty' => 2,
            'planned_date' => '2026-09-09',
            'responsible_user_legacy_id' => $user->legacy_id,
            'production_location_name' => null,
        ], $user, self::PERMISSIONS);
        $waiting = $service->submit($draft->id, [
            'client_command_id' => 'wo04-submit-blocked',
            'expected_version' => 1,
            'reason' => '进入发布检查',
        ], $user, self::PERMISSIONS);

        $gate = $gateService->evaluate($waiting->id, $user, self::PERMISSIONS);
        $this->assertFalse($gate['allowed']);
        $this->assertSame('production_location_missing', collect($gate['blockers'])->firstWhere('key', 'production_location')['reason_code']);

        try {
            $service->publish($waiting->id, [
                'client_command_id' => 'wo04-publish-blocked',
                'expected_version' => 2,
                'reason' => '不应成功',
            ], $user, self::PERMISSIONS);
            $this->fail('未通过发布 Gate 的工单不得发布。');
        } catch (WorkOrderDomainException $exception) {
            $this->assertSame('release_gate_blocked', $exception->errorCode);
            $this->assertSame(422, $exception->status);
        }

        $this->assertDatabaseHas('erp_work_orders', ['id' => $waiting->id, 'status' => WorkOrderApplicationService::WAIT_RELEASE]);
        $this->assertSame(0, DB::table('erp_work_order_material_requirements')->where('work_order_id', $waiting->id)->count());
    }

    public function test_publish_recovers_after_business_commit_before_ledger_finalization(): void
    {
        [$user, $demand] = $this->fixture(7504);
        $service = app(WorkOrderApplicationService::class);
        $draft = $service->createDraft([
            'client_command_id' => 'wo04-create-crash',
            'production_demand_id' => $demand->id,
            'expected_demand_version' => 1,
            'target_qty' => 3,
            'planned_date' => '2026-09-11',
            'responsible_user_legacy_id' => $user->legacy_id,
            'production_location_name' => '恢复验证车间',
        ], $user, self::PERMISSIONS);
        $waiting = $service->submit($draft->id, [
            'client_command_id' => 'wo04-submit-crash',
            'expected_version' => 1,
            'reason' => '进入发布恢复验证',
        ], $user, self::PERMISSIONS);
        $payload = [
            'client_command_id' => 'wo04-publish-crash',
            'expected_version' => 2,
            'reason' => '验证发布提交后恢复',
        ];

        try {
            putenv('WO02_TEST_CRASH_AFTER_BUSINESS_COMMIT=1');
            $service->publish($waiting->id, $payload, $user, self::PERMISSIONS);
            $this->fail('Fault injection must interrupt ledger finalization.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('WO02_TEST_CRASH_AFTER_BUSINESS_COMMIT', $exception->getMessage());
        } finally {
            putenv('WO02_TEST_CRASH_AFTER_BUSINESS_COMMIT');
        }

        $this->assertDatabaseHas('erp_work_orders', [
            'id' => $waiting->id,
            'status' => WorkOrderApplicationService::RELEASED,
            'last_command_id' => $payload['client_command_id'],
        ]);
        $this->assertDatabaseHas('erp_work_order_command_ledgers', [
            'client_command_id' => $payload['client_command_id'],
            'status' => 'processing',
        ]);
        $this->assertSame(1, DB::table('erp_work_order_material_requirements')->where('work_order_id', $waiting->id)->count());

        try {
            putenv('WO02_TEST_PROCESSING_TIMEOUT_SECONDS=0');
            $recovered = $service->publish($waiting->id, $payload, $user, self::PERMISSIONS);
        } finally {
            putenv('WO02_TEST_PROCESSING_TIMEOUT_SECONDS');
        }

        $this->assertSame(WorkOrderApplicationService::RELEASED, $recovered->status);
        $this->assertSame(3, (int) $recovered->business_version);
        $this->assertSame(1, DB::table('erp_work_order_material_requirements')->where('work_order_id', $waiting->id)->count());
        $this->assertDatabaseHas('erp_work_order_command_ledgers', [
            'client_command_id' => $payload['client_command_id'],
            'status' => 'succeeded',
            'result_id' => $waiting->id,
        ]);
    }

    public function test_two_independent_mysql_processes_cannot_publish_twice(): void
    {
        $setup = $this->runPublishProbe(['setup']);
        $this->assertTrue($setup['ok'] ?? false, json_encode($setup));
        $ownerId = (int) $setup['owner_id'];
        $workOrderId = (int) $setup['work_order_id'];

        try {
            $first = $this->startPublishProbe(['publish', $workOrderId, $ownerId, 'wo04-race-a-'.$ownerId]);
            $second = $this->startPublishProbe(['publish', $workOrderId, $ownerId, 'wo04-race-b-'.$ownerId]);
            $results = [$this->finishPublishProbe($first), $this->finishPublishProbe($second)];

            $this->assertSame(1, count(array_filter($results, fn (array $result): bool => ($result['ok'] ?? false) === true && ($result['status'] ?? null) === WorkOrderApplicationService::RELEASED)), json_encode($results));
            $this->assertSame(1, count(array_filter($results, fn (array $result): bool => ($result['error_code'] ?? null) === 'version_conflict' && ($result['status'] ?? null) === 409)), json_encode($results));
            $this->assertSame(1, DB::table('erp_work_order_material_requirements')->where('work_order_id', $workOrderId)->count());
            $this->assertSame(1, DB::table('erp_work_order_status_logs')->where('work_order_id', $workOrderId)->where('after_status', WorkOrderApplicationService::RELEASED)->count());
        } finally {
            $cleanup = $this->runPublishProbe(['cleanup', $ownerId]);
            $this->assertTrue($cleanup['ok'] ?? false, json_encode($cleanup));
        }
    }

    public function test_real_http_operator_is_read_only_and_manager_publish_obeys_expected_version(): void
    {
        [$creator, $demand] = $this->fixture(7510);
        $operator = $this->createUser(7511, 'wo04-operator');
        $manager = $this->createUser(7512, 'wo04-manager');
        $this->assignBuiltInRole($operator->legacy_id, 'production_operator');
        $this->assignBuiltInRole($manager->legacy_id, 'production_manager');

        $service = app(WorkOrderApplicationService::class);
        $draft = $service->createDraft([
            'client_command_id' => 'wo04-http-create',
            'production_demand_id' => $demand->id,
            'expected_demand_version' => 1,
            'target_qty' => 2,
            'planned_date' => '2026-09-12',
            'responsible_user_legacy_id' => $operator->legacy_id,
            'production_location_name' => 'HTTP 权限验证车间',
        ], $creator, self::PERMISSIONS);
        $waiting = $service->submit($draft->id, [
            'client_command_id' => 'wo04-http-submit',
            'expected_version' => 1,
            'reason' => 'HTTP 权限验证',
        ], $creator, self::PERMISSIONS);

        $operatorToken = $this->token($operator->legacy_id);
        $managerToken = $this->token($manager->legacy_id);
        $this->withToken($operatorToken)
            ->getJson('/api/v1/erp/production/work-orders/'.$waiting->id.'/release-gate')
            ->assertOk()
            ->assertJsonPath('data.allowed', true);
        $this->withToken($operatorToken)
            ->postJson('/api/v1/erp/production/work-orders/'.$waiting->id.'/publish', [
                'client_command_id' => 'wo04-http-operator-publish',
                'expected_version' => 2,
                'reason' => '操作员不应发布',
            ])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'permission_denied');

        $this->withToken($managerToken)
            ->postJson('/api/v1/erp/production/work-orders/'.$waiting->id.'/publish', [
                'client_command_id' => 'wo04-http-manager-stale',
                'expected_version' => 1,
                'reason' => '过期版本不应发布',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'version_conflict');

        $this->withToken($managerToken)
            ->postJson('/api/v1/erp/production/work-orders/'.$waiting->id.'/publish', [
                'client_command_id' => 'wo04-http-manager-publish',
                'expected_version' => 2,
                'reason' => '生产经理正式发布',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', WorkOrderApplicationService::RELEASED)
            ->assertJsonPath('data.actions.publish', false);
        $this->withToken($operatorToken)
            ->getJson('/api/v1/erp/production/work-orders/'.$waiting->id.'/material-requirements?per_page=10')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.status', 'OPEN');
    }

    public function test_real_http_department_principal_can_publish_peer_but_outsider_is_denied(): void
    {
        [$creator, $demand] = $this->fixture(7520);
        $principal = $this->createUser(7521, 'wo04-principal');
        $peer = $this->createUser(7522, 'wo04-peer');
        $outsider = $this->createUser(7523, 'wo04-outsider');
        $this->assignBuiltInRole($principal->legacy_id, 'department_principal');
        $this->assignBuiltInRole($peer->legacy_id, 'production_operator');
        $this->assignBuiltInRole($outsider->legacy_id, 'production_operator');
        $this->attachDepartment(8041, [$principal->legacy_id, $peer->legacy_id], $principal->legacy_id);
        $this->attachDepartment(8042, [$outsider->legacy_id]);

        $service = app(WorkOrderApplicationService::class);
        $draft = $service->createDraft([
            'client_command_id' => 'wo04-department-create',
            'production_demand_id' => $demand->id,
            'expected_demand_version' => 1,
            'target_qty' => 2,
            'planned_date' => '2026-09-13',
            'responsible_user_legacy_id' => $peer->legacy_id,
            'production_location_name' => '部门权限验证车间',
        ], $creator, self::PERMISSIONS);
        $waiting = $service->submit($draft->id, [
            'client_command_id' => 'wo04-department-submit',
            'expected_version' => 1,
            'reason' => '部门权限验证',
        ], $creator, self::PERMISSIONS);

        $principalToken = $this->token($principal->legacy_id);
        $outsiderToken = $this->token($outsider->legacy_id);
        $this->withToken($outsiderToken)
            ->getJson('/api/v1/erp/production/work-orders/'.$waiting->id.'/release-gate')
            ->assertForbidden()
            ->assertJsonPath('error_code', 'data_scope_denied');
        $this->withToken($principalToken)
            ->getJson('/api/v1/erp/production/work-orders/'.$waiting->id.'/release-gate')
            ->assertOk()
            ->assertJsonPath('data.allowed', true);
        $this->withToken($principalToken)
            ->postJson('/api/v1/erp/production/work-orders/'.$waiting->id.'/publish', [
                'client_command_id' => 'wo04-department-publish',
                'expected_version' => 2,
                'reason' => '部门负责人发布同部门工单',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', WorkOrderApplicationService::RELEASED);
        $this->withToken($principalToken)
            ->getJson('/api/v1/erp/production/work-orders/'.$waiting->id.'/material-requirements')
            ->assertOk()
            ->assertJsonPath('total', 1);
    }

    public function test_admin_flag_never_replaces_release_permissions_and_role_matrix_is_explicit(): void
    {
        [$user, $demand] = $this->fixture(7503);
        $service = app(WorkOrderApplicationService::class);
        $draft = $service->createDraft([
            'client_command_id' => 'wo04-create-permission',
            'production_demand_id' => $demand->id,
            'expected_demand_version' => 1,
            'target_qty' => 1,
            'planned_date' => '2026-09-10',
            'responsible_user_legacy_id' => $user->legacy_id,
            'production_location_name' => '二号车间',
        ], $user, self::PERMISSIONS);
        $waiting = $service->submit($draft->id, [
            'client_command_id' => 'wo04-submit-permission',
            'expected_version' => 1,
            'reason' => '进入发布检查',
        ], $user, self::PERMISSIONS);

        foreach ([
            fn () => app(ReleaseGateApplicationService::class)->evaluate($waiting->id, $user, [], true),
            fn () => $service->publish($waiting->id, ['client_command_id' => 'wo04-publish-permission', 'expected_version' => 2, 'reason' => '权限验证'], $user, [], true),
        ] as $operation) {
            try {
                $operation();
                $this->fail('管理员标记不得替代明确的生产权限码。');
            } catch (WorkOrderDomainException $exception) {
                $this->assertSame('permission_denied', $exception->errorCode);
                $this->assertSame(403, $exception->status);
            }
        }

        app(RbacBootstrapService::class)->bootstrap();
        $matrix = DB::table('erp_rbac_roles as r')
            ->join('erp_rbac_role_permissions as rp', 'rp.role_id', '=', 'r.id')
            ->join('erp_rbac_permissions as p', 'p.id', '=', 'rp.permission_id')
            ->whereIn('r.code', ['production_manager', 'production_operator', 'department_principal'])
            ->whereIn('p.code', ['production.work_order.gate.view', 'production.work_order.publish', 'production.material.view'])
            ->get(['r.code as role_code', 'p.code as permission_code'])
            ->groupBy('role_code');

        $this->assertEqualsCanonicalizing(
            ['production.work_order.gate.view', 'production.work_order.publish', 'production.material.view'],
            $matrix['production_manager']->pluck('permission_code')->all(),
        );
        $this->assertEqualsCanonicalizing(
            ['production.work_order.gate.view', 'production.material.view'],
            $matrix['production_operator']->pluck('permission_code')->all(),
        );
        $this->assertEqualsCanonicalizing(
            ['production.work_order.gate.view', 'production.work_order.publish', 'production.material.view'],
            $matrix['department_principal']->pluck('permission_code')->all(),
        );
    }

    private function fixture(int $userId = 7501): array
    {
        app(RbacBootstrapService::class)->bootstrap();
        $suffix = strtoupper(substr(uniqid(), -8));
        DB::table('erp_legacy_admin_users')->insert([
            'legacy_id' => $userId,
            'username' => 'wo04-'.$suffix,
            'nickname' => 'WO04 验收用户',
            'status' => 'normal',
            'auth_group_names' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->grantRole($userId, self::PERMISSIONS);
        $user = DB::table('erp_legacy_admin_users')->where('legacy_id', $userId)->first();

        $unit = Unit::create([
            'unit_code' => 'WO04-U-'.$suffix,
            'unit_name' => '件',
            'unit_type' => 'quantity',
            'decimal_places' => 4,
            'is_base' => true,
            'status' => 'enabled',
        ]);
        $product = Product::create([
            'product_code' => 'WO04-P-'.$suffix,
            'product_name' => 'WO04 发布产品',
            'product_type' => 'standard',
            'status' => 'enabled',
        ]);
        $sku = Sku::create([
            'product_id' => $product->id,
            'sales_unit_id' => $unit->id,
            'sku_code' => 'WO04-S-'.$suffix,
            'sku_name' => 'WO04 发布规格',
            'order_line_type' => 'physical',
            'fulfillment_type' => 'physical',
            'status' => 'enabled',
        ]);
        $output = Item::create([
            'item_code' => 'WO04-FG-'.$suffix,
            'item_name' => 'WO04 成品',
            'item_type' => 'finished_good',
            'unit_id' => $unit->id,
            'is_stock_item' => true,
            'is_production_item' => true,
            'production_execution_mode' => 'unit',
            'status' => 'enabled',
        ]);
        $component = Item::create([
            'item_code' => 'WO04-RM-'.$suffix,
            'item_name' => 'WO04 原料',
            'item_type' => 'raw_material',
            'unit_id' => $unit->id,
            'is_stock_item' => true,
            'status' => 'enabled',
        ]);
        $operationId = DB::table('erp_production_operations')->insertGetId([
            'operation_no' => 'WO04-OP-'.$suffix, 'operation_name' => 'WO04 组装', 'status' => 'enabled',
            'sort' => 10, 'business_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $routingId = DB::table('erp_production_routings')->insertGetId([
            'routing_no' => 'WO04-RT-'.$suffix, 'routing_name' => 'WO04 默认路线', 'output_item_id' => $output->id,
            'version' => 1, 'status' => 'active', 'is_default' => true, 'default_scope_key' => $output->id,
            'business_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $routingOperationId = DB::table('erp_production_routing_operations')->insertGetId([
            'routing_id' => $routingId, 'operation_id' => $operationId, 'sequence' => 10,
            'is_key_operation' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $order = SalesOrder::create([
            'sales_order_no' => 'WO04-SO-'.$suffix,
            'customer_name' => 'WO04 客户',
            'order_status' => 'confirmed',
            'confirm_status' => 'confirmed',
            'production_confirm_status' => 'confirmed',
            'sales_user_legacy_id' => $userId,
            'created_by_legacy_id' => $userId,
            'total_amount' => 0,
            'final_receivable_amount' => 0,
            'required_delivery_date' => '2026-09-20',
        ]);
        $line = SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'line_no' => 1,
            'line_uuid' => 'WO04-L-'.$suffix,
            'line_type' => 'physical',
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'sku_id' => $sku->id,
            'sku_name' => $sku->sku_name,
            'item_id' => $output->id,
            'item_name' => $output->item_name,
            'order_qty' => 10,
            'unit_id' => $unit->id,
            'unit_name_snapshot' => $unit->unit_name,
            'unit_price' => 0,
            'amount' => 0,
            'item_base_unit_id' => $unit->id,
            'item_base_required_qty' => 10,
            'is_special_customized' => false,
        ]);
        $demand = ProductionDemand::create([
            'requirement_no' => 'WO04-D-'.$suffix,
            'sales_order_id' => $order->id,
            'sales_order_line_id' => $line->id,
            'product_id' => $product->id,
            'sku_id' => $sku->id,
            'item_id' => $output->id,
            'production_qty' => 10,
            'base_unit_id' => $unit->id,
            'base_unit_name_snapshot' => $unit->unit_name,
            'allocated_qty' => 0,
            'consumed_qty' => 0,
            'remaining_qty' => 10,
            'closed_qty' => 0,
            'requirement_status' => 'ready',
            'bom_match_status' => 'matched',
            'is_active' => true,
            'requirement_version' => 1,
            'business_version' => 1,
            'is_ready_for_work_order' => true,
            'required_delivery_date' => '2026-09-20',
        ]);
        $bom = Bom::create([
            'bom_no' => 'WO04-BOM-'.$suffix,
            'bom_name' => 'WO04 发布 BOM',
            'product_id' => $product->id,
            'sku_id' => $sku->id,
            'output_item_id' => $output->id,
            'bom_type' => 'standard',
            'version' => 'V1.0',
            'is_default' => true,
            'status' => 'active',
            'audit_status' => 'approved',
            'effective_date' => '2026-09-01',
        ]);
        BomItem::create([
            'bom_id' => $bom->id,
            'line_no' => 10,
            'component_item_id' => $component->id,
            'component_item_code' => $component->item_code,
            'component_item_name' => $component->item_name,
            'qty' => 2,
            'unit_id' => $unit->id,
            'loss_rate' => 10,
            'fixed_qty' => 1,
            'replaceable' => false,
        ]);
        DB::table('erp_routing_operation_material_supply_rules')->insert([
            'routing_operation_id' => $routingOperationId,
            'component_item_id' => $component->id,
            'target_routing_operation_id' => $routingOperationId,
            'required_qty_ratio' => 1,
            'supply_mode' => 'dedicated_delivery',
            'requires_delivery' => true,
            'participates_in_kitting' => true,
            'allow_partial_delivery' => false,
            'delivery_location_type' => 'operation_station',
            'business_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$user, $demand, $bom];
    }

    private function grantRole(int $userId, array $permissions): void
    {
        $suffix = strtoupper(substr(uniqid(), -8));
        $roleId = DB::table('erp_rbac_roles')->insertGetId([
            'code' => 'wo04_role_'.$suffix,
            'name' => 'WO04 测试角色',
            'data_scope' => 'all',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $permissionIds = DB::table('erp_rbac_permissions')->whereIn('code', $permissions)->pluck('id');
        foreach ($permissionIds as $permissionId) {
            DB::table('erp_rbac_role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
        DB::table('erp_rbac_user_roles')->insert(['user_legacy_id' => $userId, 'role_id' => $roleId]);
    }

    private function createUser(int $userId, string $username): object
    {
        DB::table('erp_legacy_admin_users')->insert([
            'legacy_id' => $userId,
            'username' => $username,
            'nickname' => 'WO04 '.$username,
            'status' => 'normal',
            'auth_group_names' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return DB::table('erp_legacy_admin_users')->where('legacy_id', $userId)->first();
    }

    private function assignBuiltInRole(int $userId, string $roleCode): void
    {
        app(RbacBootstrapService::class)->bootstrap(true);
        $roleId = DB::table('erp_rbac_roles')->where('code', $roleCode)->value('id');
        DB::table('erp_rbac_user_roles')->insertOrIgnore([
            'user_legacy_id' => $userId,
            'role_id' => $roleId,
        ]);
    }

    private function token(int $userId): string
    {
        $token = 'wo04-token-'.uniqid();
        DB::table('erp_auth_tokens')->insert([
            'user_legacy_id' => $userId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $token;
    }

    private function attachDepartment(int $departmentId, array $userIds, ?int $principalId = null): void
    {
        DB::table('erp_departments')->insert([
            'legacy_id' => $departmentId,
            'parent_legacy_id' => 0,
            'name' => 'WO04 Department '.$departmentId,
            'status' => 'normal',
            'sort' => 0,
            'legacy_payload' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ($userIds as $userId) {
            DB::table('erp_department_users')->insert([
                'department_legacy_id' => $departmentId,
                'user_legacy_id' => $userId,
                'is_principal' => $principalId === $userId,
                'is_owner' => false,
                'legacy_payload' => '[]',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function runPublishProbe(array $arguments): array
    {
        return $this->finishPublishProbe($this->startPublishProbe($arguments));
    }

    private function startPublishProbe(array $arguments): array
    {
        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('tests/Support/work_order_publish_probe.php'));
        foreach ($arguments as $argument) $command .= ' '.escapeshellarg((string) $argument);
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
        if (! is_resource($process)) $this->fail('Could not start the independent WO04 publish probe.');
        return [$process, $pipes];
    }

    private function finishPublishProbe(array $handle): array
    {
        [$process, $pipes] = $handle;
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $result = json_decode(trim($stdout), true);
        if (! is_array($result)) $this->fail('WO04 publish probe did not return JSON: '.trim($stderr).' '.trim($stdout));
        return $result;
    }
}
