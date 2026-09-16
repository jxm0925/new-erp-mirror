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
use App\Services\Erp\ProductionMaterialExecutionService;
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
        'production.material_requirement.view',
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
        $this->assertCount(15, $gate['checks']);
        $this->assertSame(15, DB::table('erp_work_order_release_gate_checks')
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

        $preparationDemands = DB::table('erp_production_target_material_requirements as demand')
            ->join('erp_work_order_material_supply_rules as supply', 'supply.id', '=', 'demand.material_supply_rule_snapshot_id')
            ->where('demand.work_order_id', $released->id)
            ->get(['demand.*', 'supply.target_routing_operation_id_snapshot']);
        $this->assertNotEmpty($preparationDemands);
        foreach ($preparationDemands as $preparationDemand) {
            $this->assertSame('WAIT_PREPARE', $preparationDemand->status);
            $this->assertSame($released->id, (int) $preparationDemand->work_order_id);
            $this->assertGreaterThan(0, (int) $preparationDemand->target_routing_operation_id_snapshot);
            $this->assertContains($preparationDemand->target_type, ['unit_operation', 'quantity_operation']);
            $this->assertGreaterThan(0, (int) $preparationDemand->target_id);
            $this->assertGreaterThan(0, (int) $preparationDemand->material_requirement_id);
            $this->assertGreaterThan(0, (int) $preparationDemand->component_item_id);
            $this->assertGreaterThan(0, (float) $preparationDemand->required_base_qty);
        }

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

    public function test_length_cut_configuration_survives_bom_work_order_and_production_targets(): void
    {
        [$user, $demand, $bom] = $this->fixture(7521);
        $component = $bom->items()->first()->componentItem;
        $component->update([
            'item_name' => '304 方管',
            'material_grade' => '304',
            'standard_stock_length_mm' => 6000,
            'is_length_cut_material' => true,
        ]);
        $bom->items()->delete();
        foreach ([[350, 2], [680, 4], [1250, 1]] as $index => [$length, $pieces]) {
            BomItem::create([
                'bom_id' => $bom->id,
                'line_no' => ($index + 1) * 10,
                'component_item_id' => $component->id,
                'component_item_code' => $component->item_code,
                'component_item_name' => $component->item_name,
                'cut_length_mm' => $length,
                'piece_qty' => $pieces,
                'qty' => 1,
                'unit_id' => $component->unit_id,
                'loss_rate' => 0,
                'fixed_qty' => 0,
                'replaceable' => false,
            ]);
        }
        $configuration = ['cut_requirements' => [
            ['component_item_id' => $component->id, 'cut_length_mm' => 350, 'piece_qty' => 2, 'remark' => '短撑'],
            ['component_item_id' => $component->id, 'cut_length_mm' => 680, 'piece_qty' => 4, 'remark' => '横梁'],
            ['component_item_id' => $component->id, 'cut_length_mm' => 1250, 'piece_qty' => 1, 'remark' => '立柱'],
        ]];
        $demand->update(['configuration_snapshot' => $configuration]);
        $demand->line()->update(['configuration_snapshot' => $configuration]);

        $service = app(WorkOrderApplicationService::class);
        $draft = $service->createDraft([
            'client_command_id' => 'length-cut-create',
            'production_demand_id' => $demand->id,
            'expected_demand_version' => 1,
            'target_qty' => 2,
            'planned_date' => '2026-09-18',
            'production_batch' => 'LENGTH-CUT-REAL-01',
            'responsible_user_legacy_id' => $user->legacy_id,
            'production_location_name' => '方管下料车间',
        ], $user, self::PERMISSIONS);
        $waiting = $service->submit($draft->id, [
            'client_command_id' => 'length-cut-submit',
            'expected_version' => 1,
            'reason' => '真实配置单下料需求确认',
        ], $user, self::PERMISSIONS);
        $released = $service->publish($waiting->id, [
            'client_command_id' => 'length-cut-publish',
            'expected_version' => 2,
            'reason' => '冻结下料尺寸与段数',
        ], $user, self::PERMISSIONS);

        $workRows = DB::table('erp_work_order_material_requirements')
            ->where('work_order_id', $released->id)->orderBy('cut_length_mm_snapshot')->get();
        $this->assertSame([350.0, 680.0, 1250.0], $workRows->pluck('cut_length_mm_snapshot')->map(fn ($value) => (float) $value)->all());
        $this->assertSame([4.0, 8.0, 2.0], $workRows->pluck('required_piece_qty')->map(fn ($value) => (float) $value)->all());
        $this->assertSame('approved_bom_with_configuration_cut_requirements', data_get($released->bom_snapshot, 'resolution_source'));

        $targetRows = DB::table('erp_production_target_material_requirements')
            ->where('work_order_id', $released->id)->orderBy('cut_length_mm_snapshot')->get();
        $this->assertCount(6, $targetRows);
        $this->assertSame([350.0, 680.0, 1250.0], $targetRows->pluck('cut_length_mm_snapshot')->map(fn ($value) => (float) $value)->unique()->sort()->values()->all());
        $this->assertSame([1.0, 2.0, 4.0], $targetRows->pluck('required_piece_qty_snapshot')->map(fn ($value) => (float) $value)->unique()->sort()->values()->all());
        $this->assertSame(1, Item::query()->whereKey($component->id)->count());
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
        $this->assertSame(20, DB::table('erp_production_tasks')->where('work_order_id', $released->id)->count());
        $this->assertSame(20, DB::table('erp_production_task_targets')->whereIn('task_id', DB::table('erp_production_tasks')->where('work_order_id', $released->id)->pluck('id'))->count());
        $this->assertSame(20, DB::table('erp_production_tasks')->where('work_order_id', $released->id)
            ->whereNotNull('production_unit_id')->whereNotNull('production_unit_operation_id')->count());
    }

    public function test_unit_mode_ten_by_three_creates_thirty_independent_tasks_at_publish(): void
    {
        [$user, $demand] = $this->fixture(7540);
        $routingId = DB::table('erp_production_routings')->where('output_item_id', $demand->item_id)->value('id');
        DB::table('erp_production_routing_operations')->where('routing_id', $routingId)->where('sequence', 10)
            ->update(['work_mode' => 'automatic', 'updated_at' => now()]);
        foreach ([20 => '加工', 30 => '包装'] as $sequence => $name) {
            $operationId = DB::table('erp_production_operations')->insertGetId([
                'operation_no' => 'WO04-OP-'.$sequence.'-'.strtoupper(substr(uniqid(), -6)),
                'operation_name' => 'WO04 '.$name,
                'status' => 'enabled',
                'sort' => $sequence,
                'business_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('erp_production_routing_operations')->insert([
                'routing_id' => $routingId,
                'operation_id' => $operationId,
                'sequence' => $sequence,
                'is_key_operation' => $sequence === 30,
                'output_item_id' => $demand->item_id,
                'output_mode' => $sequence === 30 ? 'warehouse_required' : 'flow_only',
                'quality_mode' => 'none',
                'allow_continue_without_warehouse' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $service = app(WorkOrderApplicationService::class);
        $draft = $service->createDraft([
            'client_command_id' => 'phase6b-unit-10x3-create',
            'production_demand_id' => $demand->id,
            'expected_demand_version' => 1,
            'target_qty' => 10,
            'planned_date' => '2026-09-18',
            'production_location_name' => '逐件三工序车间',
        ], $user, self::PERMISSIONS);
        $waiting = $service->submit($draft->id, [
            'client_command_id' => 'phase6b-unit-10x3-submit',
            'expected_version' => 1,
            'reason' => '逐件三工序验证',
        ], $user, self::PERMISSIONS);
        $released = $service->publish($waiting->id, [
            'client_command_id' => 'phase6b-unit-10x3-publish',
            'expected_version' => 2,
            'reason' => '逐件三工序验证',
        ], $user, self::PERMISSIONS);

        $tasks = DB::table('erp_production_tasks')->where('work_order_id', $released->id)->get();
        $this->assertCount(30, $tasks);
        $this->assertSame(10, $tasks->where('status', 'WAIT_CLAIM')->count());
        $this->assertSame(20, $tasks->where('status', 'WAIT_PREDECESSOR')->count());
        $this->assertSame(30, $tasks->pluck('production_unit_operation_id')->filter()->unique()->count());
        $this->assertSame(30, DB::table('erp_production_task_targets')->whereIn('task_id', $tasks->pluck('id'))->count());
        $this->assertSame(10, DB::table('erp_production_unit_operations')->where('work_order_id', $released->id)
            ->where('sequence_no_snapshot', 10)->where('work_mode_snapshot', 'automatic')->count());
        $this->assertSame(20, DB::table('erp_production_unit_operations')->where('work_order_id', $released->id)
            ->whereIn('sequence_no_snapshot', [20, 30])->where('work_mode_snapshot', 'manual')->count());
        DB::table('erp_production_routing_operations')->where('routing_id', $routingId)->where('sequence', 10)
            ->update(['work_mode' => 'manual', 'updated_at' => now()]);
        $this->assertSame(10, DB::table('erp_production_unit_operations')->where('work_order_id', $released->id)
            ->where('sequence_no_snapshot', 10)->where('work_mode_snapshot', 'automatic')->count(),
            '发布后修改路线运行方式不得改变已冻结的工序快照');
    }

    public function test_sales_work_orders_share_one_master_order_and_hierarchy_endpoints_are_paginated(): void
    {
        [$user, $demand] = $this->fixture(7541);
        $service = app(WorkOrderApplicationService::class);
        $first = $service->createDraft([
            'client_command_id' => 'phase6b-mwo-first',
            'production_demand_id' => $demand->id,
            'expected_demand_version' => 1,
            'target_qty' => 4,
            'planned_date' => '2026-09-18',
            'production_location_name' => '主生产工单车间',
        ], $user, self::PERMISSIONS);
        $second = $service->createDraft([
            'client_command_id' => 'phase6b-mwo-second',
            'production_demand_id' => $demand->id,
            'expected_demand_version' => 2,
            'target_qty' => 6,
            'planned_date' => '2026-09-19',
            'production_location_name' => '主生产工单车间',
        ], $user, self::PERMISSIONS);

        $this->assertNotNull($first->production_master_order_id);
        $this->assertSame((int) $first->production_master_order_id, (int) $second->production_master_order_id);
        $this->assertSame(1, DB::table('erp_production_master_orders')
            ->where('sales_order_id', $demand->sales_order_id)->count());

        $first = $service->submit($first->id, [
            'client_command_id' => 'phase6b-pb-first-submit', 'expected_version' => 1, 'reason' => '生成订单备料单',
        ], $user, self::PERMISSIONS);
        $first = $service->publish($first->id, [
            'client_command_id' => 'phase6b-pb-first-publish', 'expected_version' => 2, 'reason' => '生成订单备料单',
        ], $user, self::PERMISSIONS);
        $second = $service->submit($second->id, [
            'client_command_id' => 'phase6b-pb-second-submit', 'expected_version' => 1, 'reason' => '合并订单备料单',
        ], $user, self::PERMISSIONS);
        $second = $service->publish($second->id, [
            'client_command_id' => 'phase6b-pb-second-publish', 'expected_version' => 2, 'reason' => '合并订单备料单',
        ], $user, self::PERMISSIONS);

        $preparation = DB::table('erp_production_preparation_orders')
            ->where('production_master_order_id', $first->production_master_order_id)->first();
        $this->assertNotNull($preparation);
        $this->assertSame('WAIT_PREPARE', $preparation->status);
        $this->assertSame(1, DB::table('erp_production_preparation_orders')
            ->where('production_master_order_id', $first->production_master_order_id)->count());
        $formalRequirementIds = DB::table('erp_work_order_material_requirements')
            ->whereIn('work_order_id', [$first->id, $second->id])->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $preparationRequirementIds = DB::table('erp_production_preparation_order_lines')
            ->where('preparation_order_id', $preparation->id)->pluck('material_requirement_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $this->assertSame($formalRequirementIds, $preparationRequirementIds);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], DB::table('erp_production_preparation_order_lines')
            ->where('preparation_order_id', $preparation->id)->pluck('work_order_id')->map(fn ($id) => (int) $id)->unique()->all());

        $token = $this->token($user->legacy_id);
        $masterId = (int) $first->production_master_order_id;
        $masterNo = DB::table('erp_production_master_orders')->where('id', $masterId)->value('master_order_no');
        $masterList = $this->withToken($token)->getJson('/api/v1/erp/production/master-orders?per_page=1&keyword='.urlencode($masterNo));
        $masterList
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $masterId)
            ->assertJsonPath('data.0.display_status', 'WAIT_CONDITION')
            ->assertJsonPath('data.0.work_order_count', 2)
            ->assertJsonPath('data.0.product_summary.0.work_order_count', 2)
            ->assertJsonPath('data.0.product_summary.0.planned_qty', 10)
            ->assertJsonPath('data.0.quantity_summary.comparable', true)
            ->assertJsonPath('data.0.quantity_summary.planned_qty', 10)
            ->assertJsonPath('data.0.total_unit_qty', 10)
            ->assertJsonPath('data.0.production_task_progress.total', 10)
            ->assertJsonPath('data.0.production_task_progress.completed', 0)
            ->assertJsonPath('data.0.delivery.status', 'WAIT_PREPARE')
            ->assertJsonPath('data.0.delivery.total_line_count', 2)
            ->assertJsonPath('data.0.delivery.received_line_count', 0)
            ->assertJsonPath('data.0.kitting.required_target_count', 10)
            ->assertJsonPath('data.0.kitting.confirmed_target_count', 0)
            ->assertJsonPath('data.0.funding_status', 'passed')
            ->assertJsonPath('data.0.shipment_status', 'passed')
            ->assertJsonPath('data.0.blocker_count', 0);
        $summary = $masterList->json('summary');
        $this->assertGreaterThanOrEqual(1, $summary['total']);
        $this->assertSame($summary['total'], $summary['in_progress'] + $summary['wait_condition'] + $summary['exception'] + $summary['completed']);
        $this->withToken($token)->getJson('/api/v1/erp/production/master-orders?status=WAIT_CONDITION&per_page=1&keyword='.urlencode($masterNo))
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $masterId);

        $restricted = $this->createUser(7591, 'mwo-self-scope');
        $outsider = $this->createUser(7592, 'mwo-hidden-owner');
        $this->grantRole($restricted->legacy_id, ['production.work_order.view'], 'self');
        DB::table('erp_work_orders')->where('id', $first->id)->update(['responsible_user_legacy_id' => $restricted->legacy_id]);
        DB::table('erp_work_orders')->where('id', $second->id)->update(['responsible_user_legacy_id' => $outsider->legacy_id]);
        $this->withToken($this->token($restricted->legacy_id))
            ->getJson('/api/v1/erp/production/master-orders?per_page=1&keyword='.urlencode($masterNo))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.work_order_count', 1)
            ->assertJsonPath('data.0.total_unit_qty', 4)
            ->assertJsonPath('data.0.product_summary.0.work_order_count', 1);
        $this->withToken($token)->getJson('/api/v1/erp/production/master-orders/'.$masterId)
            ->assertOk()->assertJsonPath('data.id', $masterId)
            ->assertJsonPath('data.work_order_count', 2);
        $this->withToken($token)->getJson('/api/v1/erp/production/master-orders/'.$masterId.'/work-orders?per_page=1')
            ->assertOk()->assertJsonPath('total', 2)
            ->assertJsonCount(1, 'data');
        $this->withToken($token)->getJson('/api/v1/erp/production/master-orders/'.$masterId.'/units?per_page=1')
            ->assertOk()->assertJsonPath('total', 10)
            ->assertJsonPath('data.0.serial.status', 'NOT_APPLICABLE');

        // A legacy unit snapshot used the unfortunate device_no name even though
        // it stores the ProductionSerial SN.  The MWO contract must expose that
        // fact as serial and must not manufacture an equipment identity.
        $firstUnit = DB::table('erp_production_units')->where('work_order_id', $first->id)->orderBy('sequence_no')->first();
        DB::table('erp_work_orders')->where('id', $first->id)->update([
            'serial_policy_snapshot' => json_encode([
                'serial_tracking_mode' => 'required',
                'serial_generation_stage' => 'before_finished_goods_posting',
            ], JSON_UNESCAPED_UNICODE),
        ]);
        $serialNo = 'SN-MWO-'.$masterId.'-'.strtoupper(substr(uniqid(), -6));
        $serialId = DB::table('erp_production_serials')->insertGetId([
            'serial_no' => $serialNo, 'item_id' => $first->output_item_id,
            'serial_type' => 'finished_device', 'generation_stage' => 'before_finished_goods_posting',
            'status' => 'generated', 'source_type' => 'production_unit', 'source_id' => $firstUnit->id,
            'generated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('erp_production_units')->where('id', $firstUnit->id)->update([
            'device_serial_id' => $serialId,
            'device_no_snapshot' => $serialNo,
        ]);
        $unitPayload = $this->withToken($token)->getJson('/api/v1/erp/production/master-orders/'.$masterId.'/units?per_page=1')
            ->assertOk()->assertJsonPath('data.0.unit_no', $firstUnit->unit_no)
            ->assertJsonPath('data.0.serial.serial_no', $serialNo)
            ->assertJsonPath('data.0.serial.status', 'GENERATED')
            ->assertJsonPath('data.0.equipment_identity.status', 'NOT_APPLICABLE')
            ->json('data.0');
        $this->assertArrayNotHasKey('device_no', $unitPayload);
        $this->assertArrayNotHasKey('device_no_snapshot', $unitPayload);
        $this->withToken($token)->getJson('/api/v1/erp/production/master-orders/'.$masterId.'/units?per_page=1&page=2')
            ->assertOk()->assertJsonPath('data.0.serial.status', 'PENDING_GENERATION')
            ->assertJsonPath('data.0.serial.label', '待生成');
        $this->withToken($token)->getJson('/api/v1/erp/production/preparation-orders?per_page=1&production_master_order_id='.$masterId)
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $preparation->id)
            ->assertJsonCount(2, 'data.0.lines');
        $this->withToken($token)->getJson('/api/v1/erp/production/preparation-orders/'.$preparation->id)
            ->assertOk()->assertJsonPath('data.id', $preparation->id)
            ->assertJsonCount(2, 'data.lines');

        $mixedUnit = Unit::create([
            'unit_code' => 'WO04-MIX-'.strtoupper(substr(uniqid(), -6)),
            'unit_name' => '米',
            'unit_type' => 'length',
            'decimal_places' => 4,
            'is_base' => true,
            'status' => 'enabled',
        ]);
        DB::table('erp_work_orders')->where('id', $second->id)->update([
            'base_unit_id' => $mixedUnit->id,
            'base_unit_name_snapshot' => $mixedUnit->unit_name,
        ]);
        $this->withToken($token)->getJson('/api/v1/erp/production/master-orders?per_page=1&keyword='.urlencode($masterNo))
            ->assertOk()
            ->assertJsonPath('data.0.quantity_summary.comparable', false)
            ->assertJsonPath('data.0.quantity_summary.display_mode', 'multiple_units')
            ->assertJsonPath('data.0.total_unit_qty', null)
            ->assertJsonCount(2, 'data.0.quantity_summary.groups');

        DB::table('erp_sales_orders')->where('id', $demand->sales_order_id)->update([
            'total_amount' => 100,
            'final_receivable_amount' => 100,
        ]);
        $this->withToken($token)->getJson('/api/v1/erp/production/master-orders?per_page=1&keyword='.urlencode($masterNo))
            ->assertOk()
            ->assertJsonPath('data.0.funding_status', 'blocked')
            ->assertJsonPath('data.0.shipment_status', 'blocked')
            ->assertJsonPath('data.0.blocker_count', 1)
            ->assertJsonPath('data.0.blockers.0.type', 'production_funding');
    }

    public function test_terminal_unit_output_generates_the_configured_production_serial_before_inventory_posting(): void
    {
        [$user, $demand] = $this->fixture(7542);
        $item = Item::findOrFail($demand->item_id);
        $workOrder = \App\Models\Erp\WorkOrder::create([
            'work_order_no' => 'WO04-SN-'.strtoupper(substr(uniqid(), -8)), 'source_type' => 'stock_prebuild',
            'output_item_id' => $item->id, 'target_qty' => 1, 'target_base_qty' => 1,
            'target_unit_id' => $item->unit_id, 'base_unit_id' => $item->unit_id,
            'production_execution_mode_snapshot' => 'unit',
            'serial_policy_snapshot' => [
                'serial_tracking_mode' => 'required', 'serial_number_prefix' => 'FGSN',
                'serial_generation_stage' => 'before_finished_goods_posting',
            ],
            'status' => 'IN_PROGRESS', 'responsible_user_legacy_id' => $user->legacy_id, 'business_version' => 1,
        ]);
        $unitId = DB::table('erp_production_units')->insertGetId([
            'unit_no' => 'PU-SN-'.strtoupper(substr(uniqid(), -8)), 'work_order_id' => $workOrder->id,
            'sequence_no' => 1, 'output_item_id' => $item->id, 'status' => 'PROCESSING',
            'routing_snapshot' => json_encode(['operations' => []]), 'business_version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $operationId = DB::table('erp_production_unit_operations')->insertGetId([
            'production_unit_id' => $unitId, 'work_order_id' => $workOrder->id,
            'operation_code_snapshot' => 'FG-END', 'operation_name_snapshot' => '成品完工',
            'sequence_no_snapshot' => 1, 'status' => 'PAUSED', 'responsible_user_legacy_id' => $user->legacy_id,
            'output_item_id_snapshot' => $item->id, 'output_mode_snapshot' => 'warehouse_required',
            'quality_mode_snapshot' => 'none', 'business_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $taskId = DB::table('erp_production_tasks')->insertGetId([
            'task_no' => 'PT-SN-'.strtoupper(substr(uniqid(), -8)), 'work_order_id' => $workOrder->id,
            'production_unit_id' => $unitId, 'production_unit_operation_id' => $operationId,
            'execution_mode' => 'unit', 'operation_code_snapshot' => 'FG-END', 'operation_name_snapshot' => '成品完工',
            'sequence_no_snapshot' => 1, 'status' => 'PAUSED', 'assignee_user_legacy_id' => $user->legacy_id,
            'business_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('erp_production_task_targets')->insert([
            'task_id' => $taskId, 'target_type' => 'unit_operation', 'target_id' => $operationId,
            'status_snapshot' => 'PAUSED', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('erp_production_labor_sessions')->insert([
            'task_id' => $taskId, 'target_type' => 'unit_operation', 'target_id' => $operationId,
            'employee_legacy_id' => $user->legacy_id, 'role' => 'owner', 'status' => 'ENDED',
            'started_at' => now()->subMinutes(1), 'ended_at' => now(), 'actual_labor_minutes' => 1,
            'responsibility_weight_snapshot' => 1, 'credited_labor_minutes' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $completed = app(ProductionExecutionActionService::class)->complete($taskId, 'unit_operation', $operationId, [
            'client_command_id' => 'wo04-sn-complete-'.uniqid(), 'expected_version' => 1, 'disposition' => 'warehouse',
        ], $user, ['production.task.complete']);
        $output = DB::table('erp_production_output_records')->find($completed['output_record_id']);
        $serial = DB::table('erp_production_serials')->find($output->serial_id);
        $unit = DB::table('erp_production_units')->find($unitId);
        $this->assertNotNull($serial);
        $this->assertSame($serial->serial_no, $output->serial_no_snapshot);
        $this->assertSame($serial->serial_no, $unit->device_no_snapshot);
        $this->assertSame((int) $serial->id, (int) $unit->device_serial_id);
        $this->assertSame('before_finished_goods_posting', $serial->generation_stage);
    }

    public function test_mwo_wo_and_pu_detail_contracts_use_one_execution_fact_chain(): void
    {
        [$user, $demand] = $this->fixture(7544);
        $this->grantRole($user->legacy_id, ['sales_order.view_attachment', 'production.unit.view', 'production.trace.view']);
        DB::table('erp_sales_orders')->where('id', $demand->sales_order_id)->update([
            'remark' => '接口验收订单备注',
            'updated_at' => now(),
        ]);
        $service = app(WorkOrderApplicationService::class);
        $draft = $service->createDraft([
            'client_command_id' => 'mwo-detail-contract-create',
            'production_demand_id' => $demand->id,
            'expected_demand_version' => 1,
            'target_qty' => 6,
            'planned_date' => '2026-09-20',
            'production_location_name' => '接口验收车间',
        ], $user, self::PERMISSIONS);
        $waiting = $service->submit($draft->id, [
            'client_command_id' => 'mwo-detail-contract-submit', 'expected_version' => 1, 'reason' => '接口验收',
        ], $user, self::PERMISSIONS);
        $released = $service->publish($waiting->id, [
            'client_command_id' => 'mwo-detail-contract-publish', 'expected_version' => 2, 'reason' => '接口验收',
        ], $user, self::PERMISSIONS);

        $units = DB::table('erp_production_units')->where('work_order_id', $released->id)->orderBy('sequence_no')->get();
        $operations = DB::table('erp_production_unit_operations')->where('work_order_id', $released->id)
            ->orderBy('production_unit_id')->get()->keyBy('production_unit_id');
        $tasks = DB::table('erp_production_tasks')->where('work_order_id', $released->id)
            ->orderBy('production_unit_id')->get()->keyBy('production_unit_id');
        foreach ($units->take(4) as $unit) {
            DB::table('erp_production_units')->where('id', $unit->id)->update(['status' => 'COMPLETED']);
            DB::table('erp_production_unit_operations')->where('id', $operations[$unit->id]->id)->update([
                'status' => 'COMPLETED', 'started_at' => now()->subMinutes(45), 'completed_at' => now()->subMinutes(10),
                'actual_labor_minutes' => 35,
            ]);
            DB::table('erp_production_tasks')->where('id', $tasks[$unit->id]->id)->update(['status' => 'COMPLETED']);
        }
        $activeUnit = $units[4];
        $activeOperation = $operations[$activeUnit->id];
        $activeTask = $tasks[$activeUnit->id];
        DB::table('erp_production_units')->where('id', $activeUnit->id)->update(['status' => 'PROCESSING']);
        DB::table('erp_production_unit_operations')->where('id', $activeOperation->id)->update([
            'status' => 'IN_PROGRESS', 'started_at' => now()->subMinutes(23), 'responsible_user_legacy_id' => $user->legacy_id,
        ]);
        DB::table('erp_production_tasks')->where('id', $activeTask->id)->update([
            'status' => 'IN_PROGRESS', 'assignee_user_legacy_id' => $user->legacy_id,
        ]);
        DB::table('erp_production_labor_sessions')->insert([
            'task_id' => $activeTask->id, 'target_type' => 'unit_operation', 'target_id' => $activeOperation->id,
            'employee_legacy_id' => $user->legacy_id, 'role' => 'owner', 'status' => 'ACTIVE',
            'started_at' => now()->subMinutes(23), 'actual_labor_minutes' => 0,
            'responsibility_weight_snapshot' => 1, 'credited_labor_minutes' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('erp_sales_order_lines')->where('id', $demand->sales_order_line_id)->update(['shipped_qty' => 4]);
        DB::table('erp_sales_order_attachments')->insert([
            'sales_order_id' => $demand->sales_order_id, 'attachment_scope' => 'order', 'attachment_type' => 'design_drawing',
            'original_name' => '接口验收图纸.pdf', 'stored_name' => 'mwo-contract.pdf', 'storage_disk' => 'local',
            'storage_path' => 'acceptance/mwo-contract.pdf', 'mime_type' => 'application/pdf', 'file_size' => 2048,
            'uploaded_by_legacy_id' => $user->legacy_id, 'uploaded_by' => $user->nickname, 'uploaded_at' => now(),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('erp_sales_order_logs')->insert([
            'sales_order_id' => $demand->sales_order_id, 'order_no_snapshot' => $demand->order->sales_order_no,
            'action' => 'order_remark_update', 'operator' => $user->nickname, 'content' => '生产排程已同步给车间',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $masterId = (int) $released->production_master_order_id;
        $token = $this->token($user->legacy_id);
        $this->withToken($token)->getJson('/api/v1/erp/production/master-orders/'.$masterId)
            ->assertOk()
            ->assertJsonPath('data.quantity_summary.planned_qty', 6)
            ->assertJsonPath('data.quantity_summary.completed_qty', 4)
            ->assertJsonPath('data.quantity_summary.in_progress_qty', 1)
            ->assertJsonPath('data.quantity_summary.waiting_qty', 1)
            ->assertJsonPath('data.production_task_progress.completed', 4)
            ->assertJsonPath('data.production_task_progress.total', 6)
            ->assertJsonPath('data.shipment.total_qty', 10)
            ->assertJsonPath('data.shipment.shipped_qty', 4)
            ->assertJsonPath('data.attachments.0.name', '接口验收图纸.pdf')
            ->assertJsonPath('data.attachments.0.can_preview', true)
            ->assertJsonPath('data.remarks.0.content', '接口验收订单备注')
            ->assertJsonPath('data.remarks.1.content', '生产排程已同步给车间')
            ->assertJsonPath('data.delivery_overview.total_required_line_count', 1)
            ->assertJsonPath('data.delivery_overview.pending_alerts.0.type', 'WAIT_CONFIGURATION');
        $this->withToken($token)->getJson('/api/v1/erp/production/master-orders/'.$masterId.'/work-orders?per_page=20')
            ->assertOk()
            ->assertJsonPath('data.0.quantity_summary.completed_qty', 4)
            ->assertJsonPath('data.0.quantity_summary.in_progress_qty', 1)
            ->assertJsonPath('data.0.quantity_summary.waiting_qty', 1)
            ->assertJsonPath('data.0.production_task_progress.completed', 4);
        $this->withToken($token)->getJson('/api/v1/erp/production/work-orders/'.$released->id)
            ->assertOk()
            ->assertJsonPath('data.execution_summary.quantity.completed_qty', 4)
            ->assertJsonPath('data.execution_summary.tasks.total', 6);
        $this->withToken($token)->getJson('/api/v1/erp/production/units/'.$activeUnit->id)
            ->assertOk()
            ->assertJsonPath('data.execution.current_task.owner.display_name', $user->nickname)
            ->assertJsonPath('data.execution.current_task.owner_active_labor', true)
            ->assertJsonPath('data.operations.0.status', 'IN_PROGRESS')
            ->assertJsonPath('data.operations.0.task.task_no', $activeTask->task_no);

        $restricted = $this->createUser(7545, 'mwo-no-attachment');
        $this->grantRole($restricted->legacy_id, ['production.work_order.view']);
        $this->withToken($this->token($restricted->legacy_id))->getJson('/api/v1/erp/production/master-orders/'.$masterId)
            ->assertOk()->assertJsonPath('data.attachment_access.can_view', false)->assertJsonCount(0, 'data.attachments');
    }

    public function test_unit_creation_freezes_independent_equipment_policy_without_conflating_equipment_and_sn(): void
    {
        [$user, $demand] = $this->fixture(7543);
        Item::query()->whereKey($demand->item_id)->update([
            'serial_tracking_mode' => 'required', 'is_serial_managed' => true,
            'serial_number_prefix' => 'SNID', 'serial_generation_stage' => 'production_unit_created',
            'equipment_identity_requirement' => 'required',
        ]);
        $service = app(WorkOrderApplicationService::class);
        $draft = $service->createDraft([
            'client_command_id' => 'wo04-identity-create', 'production_demand_id' => $demand->id,
            'expected_demand_version' => 1, 'target_qty' => 2, 'planned_date' => '2026-09-20',
            'production_location_name' => '身份编号车间',
        ], $user, self::PERMISSIONS);
        $waiting = $service->submit($draft->id, [
            'client_command_id' => 'wo04-identity-submit', 'expected_version' => 1, 'reason' => '验证独立身份',
        ], $user, self::PERMISSIONS);
        $released = $service->publish($waiting->id, [
            'client_command_id' => 'wo04-identity-publish', 'expected_version' => 2, 'reason' => '验证独立身份',
        ], $user, self::PERMISSIONS);
        $units = DB::table('erp_production_units')->where('work_order_id', $released->id)->orderBy('sequence_no')->get();
        $this->assertCount(2, $units);
        $this->assertCount(2, $units->pluck('unit_no')->unique());
        $serials = DB::table('erp_production_serials')->whereIn('id', $units->pluck('device_serial_id'))->get()->keyBy('id');
        $this->assertCount(2, $serials);
        $identities = DB::table('erp_production_unit_equipment_identities')->whereIn('production_unit_id', $units->pluck('id'))->get()->keyBy('production_unit_id');
        $this->assertSame('PENDING_GENERATION', $identities[$units[0]->id]->status);
        $equipmentNo = 'EQ-'.strtoupper(substr(uniqid(), -10));
        DB::table('erp_production_unit_equipment_identities')->where('production_unit_id', $units[0]->id)->update([
            'status' => 'BOUND', 'equipment_no' => $equipmentNo, 'source_type' => 'equipment_register',
            'source_id' => 1, 'bound_by_legacy_id' => $user->legacy_id, 'bound_at' => now(),
        ]);
        $masterId = (int) $released->production_master_order_id;
        $payload = $this->withToken($this->token($user->legacy_id))
            ->getJson('/api/v1/erp/production/master-orders/'.$masterId.'/units?per_page=1')
            ->assertOk()
            ->assertJsonPath('data.0.serial.status', 'GENERATED')
            ->assertJsonPath('data.0.equipment_identity.status', 'BOUND')
            ->assertJsonPath('data.0.equipment_identity.equipment_no', $equipmentNo)
            ->json('data.0');
        $this->assertNotSame($payload['serial']['serial_no'], $payload['equipment_identity']['equipment_no']);

        $operation = DB::table('erp_production_unit_operations')->where('production_unit_id', $units[0]->id)->orderBy('sequence_no_snapshot')->first();
        $task = DB::table('erp_production_tasks')->where('production_unit_operation_id', $operation->id)->first();
        DB::table('erp_production_unit_operations')->where('id', $operation->id)->update(['status' => 'IN_PROGRESS']);
        DB::table('erp_production_tasks')->where('id', $task->id)->update(['status' => 'IN_PROGRESS', 'assignee_user_legacy_id' => null]);
        $this->withToken($this->token($user->legacy_id))
            ->getJson('/api/v1/erp/production/master-orders/'.$masterId.'/units?per_page=1')
            ->assertOk()->assertJsonPath('data.0.execution.current_task.execution_integrity.valid', false)
            ->assertJsonPath('data.0.execution.current_task.execution_integrity.reason_code', 'in_progress_labor_missing');
        DB::table('erp_production_tasks')->where('id', $task->id)->update(['assignee_user_legacy_id' => $user->legacy_id]);
        DB::table('erp_production_labor_sessions')->insert([
            'task_id' => $task->id, 'target_type' => 'unit_operation', 'target_id' => $operation->id,
            'employee_legacy_id' => $user->legacy_id, 'role' => 'owner', 'status' => 'ACTIVE',
            'started_at' => now(), 'actual_labor_minutes' => 0, 'responsibility_weight_snapshot' => 1,
            'credited_labor_minutes' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->withToken($this->token($user->legacy_id))
            ->getJson('/api/v1/erp/production/master-orders/'.$masterId.'/units?per_page=1')
            ->assertOk()->assertJsonPath('data.0.execution.current_task.owner.display_name', $user->nickname)
            ->assertJsonPath('data.0.execution.current_task.owner_active_labor', true)
            ->assertJsonPath('data.0.execution.current_task.execution_integrity.valid', true);
    }

    public function test_workstation_stock_does_not_enter_per_order_preparation_queue(): void
    {
        [$user, $demand, $bom] = $this->fixture(7539);
        $componentId = DB::table('erp_bom_items')->where('bom_id', $bom->id)->value('component_item_id');
        DB::table('erp_routing_operation_material_supply_rules')->where('component_item_id', $componentId)->update([
            'supply_mode' => 'workstation_stock',
            'requires_delivery' => false,
            'updated_at' => now(),
        ]);
        $service = app(WorkOrderApplicationService::class);
        $draft = $service->createDraft([
            'client_command_id' => 'phase6b-workstation-create', 'production_demand_id' => $demand->id,
            'expected_demand_version' => 1, 'target_qty' => 2, 'planned_date' => '2026-09-18',
            'production_location_name' => '工位常备料车间',
        ], $user, self::PERMISSIONS);
        $waiting = $service->submit($draft->id, [
            'client_command_id' => 'phase6b-workstation-submit', 'expected_version' => 1, 'reason' => '工位常备料验证',
        ], $user, self::PERMISSIONS);
        $released = $service->publish($waiting->id, [
            'client_command_id' => 'phase6b-workstation-publish', 'expected_version' => 2, 'reason' => '工位常备料验证',
        ], $user, self::PERMISSIONS);

        $targetDemand = DB::table('erp_production_target_material_requirements')->where('work_order_id', $released->id)->first();
        $this->assertNotNull($targetDemand, '工位常备料仍须保留目标需求用于齐套现场数量审计');
        $this->assertSame('OPEN', $targetDemand->status);
        $page = app(ProductionMaterialExecutionService::class)->paginatePreparationDemands(
            ['work_order_id' => $released->id], $user, self::PERMISSIONS, true
        );
        $this->assertSame(0, $page->total());
        $this->assertSame(0, DB::table('erp_material_picking_tasks')->where('work_order_id', $released->id)->count());
        $this->assertSame(0, DB::table('erp_material_deliveries')->where('work_order_id', $released->id)->count());
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

    public function test_required_kitting_immediately_starts_owner_labor_and_is_idempotent(): void
    {
        [$user, $demand] = $this->fixture(7533);
        $service = app(WorkOrderApplicationService::class);
        $draft = $service->createDraft(['client_command_id' => 'phase6b-facts-create', 'production_demand_id' => $demand->id,
            'expected_demand_version' => 1, 'target_qty' => 2, 'planned_date' => '2026-09-18',
            'production_location_name' => '执行事实车间'], $user, self::PERMISSIONS);
        $waiting = $service->submit($draft->id, ['client_command_id' => 'phase6b-facts-submit', 'expected_version' => 1,
            'reason' => '执行事实验证'], $user, self::PERMISSIONS);
        $released = $service->publish($waiting->id, ['client_command_id' => 'phase6b-facts-publish', 'expected_version' => 2,
            'reason' => '执行事实验证'], $user, self::PERMISSIONS);
        $tasks = DB::table('erp_production_tasks')->where('work_order_id', $released->id)->orderBy('id')->get();
        $task = $tasks->first();
        $secondTask = $tasks->skip(1)->first();
        $link = DB::table('erp_production_task_targets')->where('task_id', $task->id)->first();
        $secondLink = DB::table('erp_production_task_targets')->where('task_id', $secondTask->id)->first();

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

        $beforeCheck = app(\App\Services\Erp\ProductionTaskQueryService::class)->show(
            $task->id, $user, ['production.task.view', 'production.kitting.confirm'], true
        )->target_details[0];
        $this->assertSame('onsite_confirmation_required', $beforeCheck['readiness']['reason_code']);
        $this->assertTrue($beforeCheck['allowed_actions']['confirm_kitting']);
        $this->assertSame('confirm_kitting_and_start', $beforeCheck['primary_action']['code']);
        try {
            app(ProductionExecutionActionService::class)->start($task->id, 'unit_operation', $target->id,
                ['client_command_id' => 'phase6b-facts-wait-material-ordinary-start', 'expected_version' => 2],
                $user, ['production.task.start']);
            $this->fail('WAIT_MATERIAL 不得通过普通 start 开工。');
        } catch (WorkOrderDomainException $exception) {
            $this->assertSame('target_not_ready', $exception->errorCode);
        }

        try {
            app(ProductionKittingService::class)->confirm($task->id, 'unit_operation', $target->id,
                ['client_command_id' => 'phase6b-facts-kitting-insufficient', 'expected_version' => 2,
                    'workstation_stock_confirmations' => [[
                        'requirement_id' => $materialRequirement->id,
                        'onsite_available_base_qty' => max(0, (float) $materialRequirement->required_base_qty - 1),
                        'workstation' => '总装一号工位',
                    ]]], $user, ['production.kitting.confirm']);
            $this->fail('工位常备料不足必须阻断齐套确认。');
        } catch (WorkOrderDomainException $exception) {
            $this->assertSame('workstation_stock_insufficient', $exception->errorCode);
        }
        $this->assertDatabaseHas('erp_production_workstation_stock_confirmations', [
            'target_material_requirement_id' => $materialRequirement->id,
            'attempt_no' => 1,
            'result' => 'INSUFFICIENT',
            'client_command_id' => 'phase6b-facts-kitting-insufficient',
        ]);
        $this->assertSame('WAIT_MATERIAL', DB::table('erp_production_unit_operations')->where('id', $target->id)->value('status'));
        $afterShortage = app(\App\Services\Erp\ProductionTaskQueryService::class)->show(
            $task->id, $user, ['production.task.view', 'production.kitting.confirm'], true
        )->target_details[0];
        $this->assertSame('workstation_stock_insufficient', $afterShortage['readiness']['reason_code']);
        $this->assertTrue($afterShortage['allowed_actions']['confirm_kitting']);
        $this->assertSame(1.0, (float) $afterShortage['readiness']['shortages'][0]['shortage_base_qty']);

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
        $this->assertEquals($started->kitting_confirmed_at, $started->started_at);
        $this->assertSame('IN_PROGRESS', DB::table('erp_production_task_targets')->where('task_id', $task->id)->where('target_id', $target->id)->value('status_snapshot'));
        $this->assertSame(1, DB::table('erp_production_labor_sessions')->where('target_id', $target->id)->where('status', 'ACTIVE')->count());
        $fact = DB::table('erp_production_workstation_stock_confirmations')->where('target_material_requirement_id', $materialRequirement->id)->orderByDesc('id')->first();
        $this->assertSame(2, (int) $fact->attempt_no);
        $this->assertSame('SUFFICIENT', $fact->result);
        $this->assertSame('总装一号工位', $fact->workstation_snapshot);
        $this->assertSame((float) $materialRequirement->required_base_qty + 2, (float) $fact->onsite_available_base_qty_snapshot);

        $replayed = app(ProductionKittingService::class)->confirm($task->id, 'unit_operation', $target->id,
            ['client_command_id' => 'phase6b-facts-kitting', 'expected_version' => 2,
                'workstation_stock_confirmations' => [[
                    'requirement_id' => $materialRequirement->id,
                    'onsite_available_base_qty' => (float) $materialRequirement->required_base_qty + 2,
                    'workstation' => '总装一号工位',
                ]]], $user, ['production.kitting.confirm']);
        $this->assertSame($kitting['id'], $replayed['id']);
        $this->assertSame(1, DB::table('erp_production_labor_sessions')->where('target_id', $target->id)->where('status', 'ACTIVE')->count());
        $this->assertSame(2, DB::table('erp_production_workstation_stock_confirmations')->where('target_material_requirement_id', $materialRequirement->id)->count());

        $secondRequirement = DB::table('erp_production_target_material_requirements')
            ->where('target_type', 'unit_operation')->where('target_id', $secondLink->target_id)->first();
        DB::table('erp_work_order_material_supply_rules')->where('id', $secondRequirement->material_supply_rule_snapshot_id)
            ->update(['supply_mode_snapshot' => 'workstation_stock', 'requires_delivery_snapshot' => false, 'updated_at' => now()]);
        DB::table('erp_production_unit_operations')->where('id', $secondLink->target_id)
            ->update(['work_mode_snapshot' => 'automatic', 'updated_at' => now()]);
        app(ProductionTaskAssignmentService::class)->claim($secondTask->id,
            ['client_command_id' => 'phase6b-facts-second-claim', 'expected_version' => 1], $user, ['production.task.claim']);
        $oldSessionId = (int) DB::table('erp_production_labor_sessions')->where('employee_legacy_id', $user->legacy_id)
            ->where('status', 'ACTIVE')->value('id');
        try {
            app(ProductionKittingService::class)->confirm($secondTask->id, 'unit_operation', $secondLink->target_id,
                ['client_command_id' => 'phase6b-facts-second-kitting', 'expected_version' => 2,
                    'workstation_stock_confirmations' => [[
                        'requirement_id' => $secondRequirement->id,
                        'onsite_available_base_qty' => (float) $secondRequirement->required_base_qty,
                        'workstation' => '总装二号工位',
                    ]]], $user, ['production.kitting.confirm']);
            $this->fail('跨任务启动必须先返回切换确认上下文。');
        } catch (WorkOrderDomainException $exception) {
            $this->assertSame('labor_switch_confirmation_required', $exception->errorCode);
            $this->assertSame($oldSessionId, $exception->details['active_labor_session_id']);
            $this->assertSame((int) $task->id, $exception->details['current_task']['id']);
            $this->assertSame(
                DB::table('erp_production_units')->where('id', $target->production_unit_id)->value('unit_no'),
                $exception->details['current_task']['production_unit_no']
            );
        }
        app(ProductionKittingService::class)->confirm($secondTask->id, 'unit_operation', $secondLink->target_id,
            ['client_command_id' => 'phase6b-facts-second-kitting', 'expected_version' => 2,
                'switch_active_labor' => true, 'expected_active_labor_session_id' => $oldSessionId,
                'workstation_stock_confirmations' => [[
                    'requirement_id' => $secondRequirement->id,
                    'onsite_available_base_qty' => (float) $secondRequirement->required_base_qty,
                    'workstation' => '总装二号工位',
                ]]], $user, ['production.kitting.confirm']);
        $this->assertSame('PAUSED', DB::table('erp_production_tasks')->where('id', $task->id)->value('status'),
            '负责人切换到另一任务时，原人工工序没有其他活动工时就必须暂停');
        $this->assertSame('task_switched', DB::table('erp_production_labor_sessions')->where('target_id', $target->id)->where('status', 'ENDED')->value('end_reason'));
        $this->assertSame('IN_PROGRESS', DB::table('erp_production_task_targets')->where('id', $secondLink->id)->value('status_snapshot'));
        app(ProductionExecutionActionService::class)->pause($secondTask->id, 'unit_operation', $secondLink->target_id,
            ['client_command_id' => 'phase6b-facts-automatic-owner-pause', 'expected_version' => 3],
            $user, ['production.task.pause']);
        $this->assertSame('IN_PROGRESS', DB::table('erp_production_unit_operations')->where('id', $secondLink->target_id)->value('status'),
            '自动工序停止个人计时后仍保持运行中');
        $this->assertSame(0, DB::table('erp_production_labor_sessions')->where('target_id', $secondLink->target_id)->where('status', 'ACTIVE')->count());

        $collaboratorId = $user->legacy_id + 100000;
        DB::table('erp_legacy_admin_users')->insert(['legacy_id' => $collaboratorId, 'username' => 'phase6b-collaborator-'.$collaboratorId,
            'nickname' => '协作者', 'status' => 'normal', 'auth_group_names' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('erp_work_orders')->where('id', $released->id)->update(['collaboration_enabled' => true, 'updated_at' => now()]);
        $collaborator = DB::table('erp_legacy_admin_users')->where('legacy_id', $collaboratorId)->first();
        $this->grantRole($user->legacy_id, ['production.task.view', 'production.task.resume', 'production.task.pause']);
        $this->grantRole($collaboratorId, ['production.task.view', 'production.task.collaborate', 'production.task.resume']);
        $ownerToken = $this->token($user->legacy_id);
        $collaboratorToken = $this->token($collaboratorId);
        app(ProductionTaskCollaborationService::class)->join($task->id,
            ['client_command_id' => 'phase6b-facts-collaborator-join', 'expected_version' => 4],
            $collaborator, ['production.task.collaborate']);
        $this->assertSame(0, DB::table('erp_production_labor_sessions')->where('target_id', $target->id)->where('status', 'ACTIVE')->count(),
            '加入协同不得自动给协作者启动计时');
        $collaboratorStarted = app(ProductionTaskCollaborationService::class)->startLabor($task->id, 'unit_operation', $target->id,
            ['client_command_id' => 'phase6b-facts-collaborator-start', 'expected_version' => 4],
            $collaborator, ['production.task.collaborate']);
        $this->assertSame('IN_PROGRESS', $collaboratorStarted['target_status']);
        $this->assertSame(1, DB::table('erp_production_labor_sessions')->where('target_id', $target->id)->where('status', 'ACTIVE')->count());
        try {
            DB::table('erp_production_labor_sessions')->insert([
                'task_id' => $secondTask->id, 'target_type' => 'unit_operation', 'target_id' => $secondLink->target_id,
                'employee_legacy_id' => $collaboratorId, 'role' => 'owner', 'status' => 'ACTIVE',
                'started_at' => now(), 'actual_labor_minutes' => 0, 'responsibility_weight_snapshot' => 1,
                'credited_labor_minutes' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->fail('同一员工不得因角色不同而形成第二条 ACTIVE 工时。');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertSame(1062, (int) ($exception->errorInfo[1] ?? 0));
        }

        $targetPath = '/api/v1/erp/production/tasks/'.$task->id.'/targets/unit_operation/'.$target->id;
        $this->withToken($collaboratorToken)->postJson($targetPath.'/resume', [
            'client_command_id' => 'phase6b-facts-collaborator-owner-route-denied', 'expected_version' => 5,
        ])->assertStatus(403)->assertJsonPath('error_code', 'task_owner_required');
        $this->withToken($ownerToken)->postJson($targetPath.'/resume', [
            'client_command_id' => 'phase6b-facts-owner-resume-with-helper', 'expected_version' => 5,
        ])->assertOk()->assertJsonPath('data.target_status', 'IN_PROGRESS');
        $this->assertSame(2, DB::table('erp_production_labor_sessions')->where('target_id', $target->id)->where('status', 'ACTIVE')->count(),
            '普通人工工序允许负责人和协作者同时实际作业');
        $ownerProjection = app(\App\Services\Erp\ProductionTaskQueryService::class)->show(
            $task->id, $user, ['production.task.view', 'production.task.pause', 'production.task.resume'], true
        )->target_details[0];
        $this->assertSame('owner', $ownerProjection['my_role']);
        $this->assertSame('manual', $ownerProjection['operation_runtime']['work_mode']);
        $this->assertSame(2, $ownerProjection['operation_runtime']['active_labor_count']);
        $this->assertSame('ACTIVE', $ownerProjection['my_labor']['status']);
        $this->assertNotNull($ownerProjection['my_labor']['session_id']);
        $this->withToken($collaboratorToken)->postJson($targetPath.'/collaborator-labor/pause', [
            'client_command_id' => 'phase6b-facts-collaborator-pause', 'expected_version' => 6,
        ])->assertOk()->assertJsonPath('data.target_status', 'IN_PROGRESS');
        $this->assertSame(1, DB::table('erp_production_labor_sessions')->where('target_id', $target->id)->where('status', 'ACTIVE')->count());
        $this->assertSame('IN_PROGRESS', DB::table('erp_production_unit_operations')->where('id', $target->id)->value('status'),
            '协作者暂停时负责人仍在作业，人工工序必须保持加工中');
        $this->withToken($ownerToken)->postJson($targetPath.'/pause', [
            'client_command_id' => 'phase6b-facts-owner-pause-after-helper', 'expected_version' => 7,
        ])->assertOk()->assertJsonPath('data.target_status', 'PAUSED');
        $this->assertSame(0, DB::table('erp_production_labor_sessions')->where('target_id', $target->id)->where('status', 'ACTIVE')->count());
        $this->assertSame('PAUSED', DB::table('erp_production_unit_operations')->where('id', $target->id)->value('status'));
        try {
            app(ProductionExecutionActionService::class)->start($task->id, 'unit_operation', $target->id,
                ['client_command_id' => 'phase6b-facts-duplicate-start', 'expected_version' => 8],
                $user, ['production.task.start']);
            $this->fail('需要齐套的工序不得重复点击开始加工。');
        } catch (WorkOrderDomainException $exception) {
            $this->assertSame('target_not_ready', $exception->errorCode);
            $this->assertSame(409, $exception->status);
        }
        $completed = app(ProductionExecutionActionService::class)->complete($task->id, 'unit_operation', $target->id,
            ['client_command_id' => 'phase6b-facts-complete', 'expected_version' => 8], $user, ['production.task.complete']);
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
            'funding_policy_snapshot' => [
                'policy_type' => 'full_prepay',
                'shipment_requires_full_payment' => true,
            ],
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

    private function grantRole(int $userId, array $permissions, string $dataScope = 'all'): void
    {
        $suffix = strtoupper(substr(uniqid(), -8));
        $roleId = DB::table('erp_rbac_roles')->insertGetId([
            'code' => 'wo04_role_'.$suffix,
            'name' => 'WO04 测试角色',
            'data_scope' => $dataScope,
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
