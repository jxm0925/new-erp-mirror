<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\{Item, ProductionOperation, ProductionQuantityOperation, ProductionRouting, ProductionTask, ProductionUnitOperation, Unit, WorkOrder};
use App\Services\Erp\{DocumentNumberService, ProductionExecutionFoundationService, ProductionMasterDataService, ProductionTaskQueryService, RbacBootstrapService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductionPublicOperationTest extends TestCase
{
    use DatabaseTransactions;

    private const PERMISSIONS = [
        'production.operation.view', 'production.operation.create', 'production.operation.edit',
        'production.routing.view', 'production.routing.create', 'production.routing.activate', 'production.routing.copy',
        'production.task.view', 'production.task.claim', 'production.unit.view', 'production.work_order.view',
    ];
    private object $manager;
    private string $managerToken;
    private Item $firstItem;
    private Item $secondItem;

    protected function setUp(): void
    {
        parent::setUp();
        app(RbacBootstrapService::class)->bootstrap(true);
        [$this->manager, $this->managerToken] = $this->user(self::PERMISSIONS, 'all');
        $this->withToken($this->managerToken);
        foreach ([['operation', '生产工序', 'OP'], ['routing', '工艺路线', 'RT'], ['production_unit', '生产单元', 'PU'], ['production_task', '工序任务', 'PT']] as [$type, $name, $prefix]) {
            DB::table('erp_document_number_rules')->updateOrInsert(['document_type' => $type], [
                'name' => $name, 'prefix' => $prefix, 'date_format' => 'Ymd', 'sequence_length' => 5,
                'reset_cycle' => 'daily', 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $unit = Unit::create(['unit_code' => $this->code('U'), 'unit_name' => '件', 'unit_type' => 'count',
            'decimal_places' => 0, 'is_base' => true, 'status' => 'enabled']);
        $this->firstItem = $this->item($unit, '第一种成品');
        $this->secondItem = $this->item($unit, '第二种成品');
    }

    public function test_http_save_preserves_false_true_and_omitted_values_with_versions_and_command_replay(): void
    {
        $payload = $this->operationPayload();
        $created = $this->postJson('/api/v1/erp/production/operations', $payload)->assertCreated()
            ->assertJsonPath('data.is_public', false)->assertJsonPath('data.business_version', 1)->json('data');
        $id = $created['id'];
        $change = ['client_command_id' => $this->code('SET-PUBLIC'), 'expected_version' => 1, 'is_public' => true];
        $this->putJson('/api/v1/erp/production/operations/'.$id, $change)->assertOk()
            ->assertJsonPath('data.is_public', true)->assertJsonPath('data.business_version', 2);
        $this->putJson('/api/v1/erp/production/operations/'.$id, $change)->assertOk()
            ->assertJsonPath('data.is_public', true)->assertJsonPath('data.business_version', 2);
        $this->putJson('/api/v1/erp/production/operations/'.$id, [
            'client_command_id' => $this->code('STALE'), 'expected_version' => 1, 'is_public' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->putJson('/api/v1/erp/production/operations/'.$id, [
            'client_command_id' => $this->code('INVALID'), 'expected_version' => 2, 'is_public' => 'shared',
        ])->assertUnprocessable()->assertJsonValidationErrors('is_public');
        $this->putJson('/api/v1/erp/production/operations/'.$id, [
            'client_command_id' => $this->code('RENAME'), 'expected_version' => 2, 'operation_name' => '公共焊接',
        ])->assertOk()->assertJsonPath('data.is_public', true)->assertJsonPath('data.business_version', 3);
        $this->putJson('/api/v1/erp/production/operations/'.$id, [
            'client_command_id' => $this->code('CLEAR'), 'expected_version' => 3, 'is_public' => false,
        ])->assertOk()->assertJsonPath('data.is_public', false)->assertJsonPath('data.business_version', 4);
        $this->getJson('/api/v1/erp/production/operations/'.$id)->assertOk()->assertJsonPath('data.is_public', false);
        $this->assertFalse(ProductionOperation::findOrFail($id)->is_public);
        $explicit = $this->postJson('/api/v1/erp/production/operations', $this->operationPayload() + ['is_public' => true])
            ->assertCreated()->assertJsonPath('data.is_public', true)->json('data.id');
        $this->assertTrue(ProductionOperation::findOrFail($explicit)->is_public);
    }

    public function test_master_and_selector_http_filters_include_explicit_zero_and_empty_means_all(): void
    {
        $keyword = $this->code('FILTER');
        $public = $this->createOperation(true, $keyword.'公共');
        $ordinary = $this->createOperation(false, $keyword.'普通');
        foreach ([['1', [$public->id]], ['0', [$ordinary->id]], ['', [$public->id, $ordinary->id]]] as [$flag, $expected]) {
            foreach (['operations', 'select-options/operations'] as $endpoint) {
                $rows = $this->getJson('/api/v1/erp/production/'.$endpoint.'?'.http_build_query(['keyword' => $keyword, 'is_public' => $flag]))
                    ->assertOk()->json('data');
                $this->assertEqualsCanonicalizing($expected, array_column($rows, 'id'));
                if ($flag !== '') $this->assertSame($flag === '1', $rows[0]['is_public']);
                else foreach ($rows as $row) $this->assertIsBool($row['is_public']);
            }
        }
        $this->getJson('/api/v1/erp/production/operations?is_public=shared')->assertUnprocessable()->assertJsonValidationErrors('is_public');
    }

    public function test_two_material_routes_share_one_operation_while_each_route_keeps_its_own_rules(): void
    {
        $shared = $this->createOperation(false);
        $first = $this->route($this->firstItem, [$shared], 5);
        $second = $this->route($this->secondItem, [$shared], 12);
        foreach ([$first, $second] as $route) app(ProductionMasterDataService::class)->activateRouting($route->id,
            ['client_command_id' => $this->code('ACTIVATE'), 'expected_version' => 1], $this->manager, self::PERMISSIONS, false);
        $detail = $this->getJson('/api/v1/erp/production/operations/'.$shared->id)->assertOk()
            ->assertJsonPath('data.is_public', false)->assertJsonPath('data.active_routing_count', 2)->json('data');
        $this->assertCount(2, $detail['active_routings']);
        $this->assertSame(1, ProductionOperation::where('id', $shared->id)->count());
        $this->assertSame($shared->id, (int) $first->operations->sole()->operation_id);
        $this->assertSame($shared->id, (int) $second->operations->sole()->operation_id);
        $this->assertSame('5.00', $first->operations->sole()->unit_standard_minutes);
        $this->assertSame('12.00', $second->operations->sole()->unit_standard_minutes);
        $this->putJson('/api/v1/erp/production/operations/'.$shared->id, [
            'client_command_id' => $this->code('EXPLICIT-PUBLIC'), 'expected_version' => 1, 'is_public' => true,
        ])->assertOk()->assertJsonPath('data.is_public', true);
        $this->getJson('/api/v1/erp/production/routings/'.$first->id)->assertOk()->assertJsonPath('data.operations.0.operation.is_public', true);
        $this->assertTrue(app(ProductionMasterDataService::class)->snapshot($first->fresh())['operations'][0]['is_public']);
    }

    public function test_quantity_tasks_filter_by_frozen_classification_after_master_updates_and_keep_separate_work_orders(): void
    {
        $public = $this->createOperation(true);
        $ordinary = $this->createOperation(false);
        $firstRoute = $this->route($this->firstItem, [$public, $ordinary], 5);
        $secondRoute = $this->route($this->secondItem, [$public], 12);
        $first = $this->workOrder($firstRoute, 10);
        $second = $this->workOrder($secondRoute, 3);
        $frozen = $first->fresh()->routing_snapshot;
        $this->setPublic($public, false);
        $this->setPublic($ordinary, true);
        // 在工单已经冻结、执行尚未展开时改档案，也必须继续使用工单冻结值。
        $this->initialize($first, 'quantity');
        $this->initialize($second, 'quantity');
        $this->assertSame($frozen, $first->fresh()->routing_snapshot);
        $this->assertSame(2, ProductionTask::where('work_order_id', $first->id)->count());
        $this->assertSame(1, ProductionTask::where('work_order_id', $second->id)->count());
        $this->assertSame(3, ProductionQuantityOperation::whereIn('work_order_id', [$first->id, $second->id])->count());
        foreach ([['1', true], ['0', false]] as [$filter, $value]) {
            $data = $this->getJson('/api/v1/erp/production/tasks?'.http_build_query([
                'work_order_id' => $first->id, 'is_public' => $filter, 'include_stats' => 1,
            ]))->assertOk()->assertJsonPath('total', 1)->assertJsonPath('stats.total', 1)
                ->assertJsonPath('data.0.is_public_snapshot', $value)->assertJsonPath('data.0.target_details.0.is_public_snapshot', $value)->json('data.0');
            $this->getJson('/api/v1/erp/production/tasks/'.$data['id'])->assertOk()->assertJsonPath('data.is_public_snapshot', $value);
            $this->assertSame($value, ProductionQuantityOperation::findOrFail($data['production_quantity_operation_id'])->is_public_snapshot);
        }
        $this->getJson('/api/v1/erp/production/work-orders/'.$first->id.'/operations')->assertOk()
            ->assertJsonPath('data.0.is_public_snapshot', true)->assertJsonPath('data.1.is_public_snapshot', false);
        $this->getJson('/api/v1/erp/production/tasks?work_order_id='.$first->id.'&is_public=')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/v1/erp/production/tasks?is_public=shared')->assertUnprocessable()->assertJsonValidationErrors('is_public');
    }

    public function test_unit_tasks_and_unit_timeline_use_the_same_frozen_public_marker(): void
    {
        $public = $this->createOperation(true);
        $ordinary = $this->createOperation(false);
        $route = $this->route($this->firstItem, [$public, $ordinary]);
        $order = $this->workOrder($route, 2);
        $this->initialize($order, 'unit');
        $this->setPublic($public, false);
        $this->setPublic($ordinary, true);
        $this->assertSame(4, $order->productionTasks()->count());
        $this->assertSame(2, $order->productionTasks()->where('is_public_snapshot', true)->count());
        $this->assertSame(2, $order->productionTasks()->where('is_public_snapshot', false)->count());
        foreach ($order->productionUnits()->get() as $unit) {
            $this->getJson('/api/v1/erp/production/units/'.$unit->id)->assertOk()
                ->assertJsonPath('data.operations.0.is_public_snapshot', true)->assertJsonPath('data.operations.1.is_public_snapshot', false);
            $this->assertSame([true, false], $unit->operations()->get()->pluck('is_public_snapshot')->all());
        }
        $this->getJson('/api/v1/erp/production/tasks?work_order_id='.$order->id.'&is_public=0&include_stats=1')
            ->assertOk()->assertJsonPath('total', 2)->assertJsonPath('stats.total', 2);
    }

    public function test_public_classification_does_not_grant_write_claim_or_other_owners_task_scope(): void
    {
        $public = $this->createOperation(true);
        $order = $this->workOrder($this->route($this->firstItem, [$public]), 1);
        $this->initialize($order, 'quantity');
        $task = $order->productionTasks()->sole();
        $this->postJson('/api/v1/erp/production/tasks/'.$task->id.'/claim', [
            'client_command_id' => $this->code('CLAIM'), 'expected_version' => 1,
        ])->assertOk();
        [$viewer, $token] = $this->user(['production.task.view', 'production.operation.view'], 'self');
        $this->withToken($token)->getJson('/api/v1/erp/production/tasks?work_order_id='.$order->id.'&is_public=1&include_stats=1')
            ->assertOk()->assertJsonPath('total', 0)->assertJsonPath('stats.total', 0);
        $this->getJson('/api/v1/erp/production/tasks/'.$task->id)->assertNotFound();
        $this->postJson('/api/v1/erp/production/tasks/'.$task->id.'/claim', [
            'client_command_id' => $this->code('FORBIDDEN-CLAIM'), 'expected_version' => 2,
        ])->assertForbidden();
        $this->putJson('/api/v1/erp/production/operations/'.$public->id, [
            'client_command_id' => $this->code('FORBIDDEN-EDIT'), 'expected_version' => 1, 'is_public' => false,
        ])->assertForbidden();
        $this->assertTrue($public->fresh()->is_public);
        $this->assertSame((int) $this->manager->legacy_id, (int) $task->fresh()->assignee_user_legacy_id);
        $this->assertSame(1, $order->productionTasks()->count());
        $this->withHeader('Authorization', '')->getJson('/api/v1/erp/production/tasks?is_public=1')->assertUnauthorized();
        $this->assertNotSame((int) $viewer->legacy_id, (int) $task->fresh()->assignee_user_legacy_id);
    }

    public function test_missing_legacy_classification_defaults_to_false_without_inferring_from_master_or_references(): void
    {
        $shared = $this->createOperation(true);
        $route = $this->route($this->firstItem, [$shared]);
        $quantityOrder = $this->workOrder($route, 1);
        $unitOrder = $this->workOrder($route, 1);
        $legacy = $quantityOrder->routing_snapshot;
        unset($legacy['operations'][0]['is_public']);
        $quantityOrder->update(['routing_snapshot' => $legacy]);
        $unitOrder->update(['routing_snapshot' => $legacy]);
        $legacy = $quantityOrder->fresh()->routing_snapshot;
        $this->initialize($quantityOrder, 'quantity');
        $this->initialize($unitOrder, 'unit');
        // 旧快照没有分类时使用默认值，即使当前档案为公共且已被多个工单引用。
        $this->assertTrue($shared->fresh()->is_public);
        $default = ProductionOperation::create(['operation_no' => $this->code('DEFAULT'), 'operation_name' => '未分类工序', 'status' => 'enabled']);
        $this->assertFalse($default->fresh()->is_public);
        $this->assertTrue(Schema::hasColumn('erp_production_operations', 'is_public'));
        foreach (['erp_production_unit_operations', 'erp_production_quantity_operations', 'erp_production_tasks', 'erp_shipment_packing_operations'] as $name) $this->assertTrue(Schema::hasColumn($name, 'is_public_snapshot'));
        $this->assertTrue(Schema::hasIndex('erp_production_operations', 'prod_operation_public_status_idx'));
        $this->assertTrue(Schema::hasIndex('erp_production_tasks', 'prod_task_public_status_idx'));
        $this->assertFalse($quantityOrder->productionTasks()->sole()->is_public_snapshot);
        $this->assertFalse(ProductionQuantityOperation::where('work_order_id', $quantityOrder->id)->sole()->is_public_snapshot);
        $this->assertFalse($unitOrder->productionTasks()->sole()->is_public_snapshot);
        $this->assertFalse(ProductionUnitOperation::where('work_order_id', $unitOrder->id)->sole()->is_public_snapshot);
        $this->assertSame($legacy, $quantityOrder->fresh()->routing_snapshot);
        $this->assertSame($legacy, $unitOrder->fresh()->routing_snapshot);
        $this->assertSame($legacy, $unitOrder->productionUnits()->sole()->routing_snapshot);
        $this->assertSame(1, $quantityOrder->productionTasks()->sole()->business_version);
    }

    private function user(array $permissions, string $scope): array
    {
        $id = random_int(3100000, 3199999);
        DB::table('erp_legacy_admin_users')->insert(['legacy_id' => $id, 'username' => $this->code('USER'),
            'nickname' => '公共工序验证员', 'status' => 'normal', 'auth_group_names' => '[]', 'department_names' => '[]',
            'created_at' => now(), 'updated_at' => now()]);
        $role = DB::table('erp_rbac_roles')->insertGetId(['code' => $this->code('ROLE'), 'name' => '公共工序专项角色',
            'enabled' => true, 'data_scope' => $scope, 'created_at' => now(), 'updated_at' => now()]);
        foreach (DB::table('erp_rbac_permissions')->whereIn('code', $permissions)->pluck('id') as $permission) {
            DB::table('erp_rbac_role_permissions')->insert(['role_id' => $role, 'permission_id' => $permission]);
        }
        DB::table('erp_rbac_user_roles')->insert(['user_legacy_id' => $id, 'role_id' => $role]);
        $token = Str::random(48);
        DB::table('erp_auth_tokens')->insert(['user_legacy_id' => $id, 'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        return [DB::table('erp_legacy_admin_users')->where('legacy_id', $id)->first(), $token];
    }

    private function item(Unit $unit, string $name): Item
    {
        return Item::create(['item_code' => $this->code('ITEM'), 'item_name' => $name, 'item_type' => 'finished_good',
            'unit_id' => $unit->id, 'is_production_item' => true, 'is_stock_item' => true, 'status' => 'enabled']);
    }

    private function operationPayload(): array
    {
        $session = (string) Str::uuid();
        $reservation = app(DocumentNumberService::class)->reserve('operation', $session, $this->manager->legacy_id, '/production/operations');
        return ['client_command_id' => $this->code('OP-CREATE'), 'creation_session_id' => $session,
            'reservation_token' => $reservation->reservation_token, 'operation_name' => $this->code('工序'), 'status' => 'enabled'];
    }

    private function createOperation(bool $public, ?string $name = null): ProductionOperation
    {
        return app(ProductionMasterDataService::class)->createOperation(array_replace($this->operationPayload(),
            ['is_public' => $public], $name === null ? [] : ['operation_name' => $name]), $this->manager, self::PERMISSIONS, false);
    }

    private function route(Item $item, array $operations, int $minutes = 5): ProductionRouting
    {
        $session = (string) Str::uuid();
        $reservation = app(DocumentNumberService::class)->reserve('routing', $session, $this->manager->legacy_id, '/production/routings');
        return app(ProductionMasterDataService::class)->createRouting([
            'client_command_id' => $this->code('ROUTE'), 'creation_session_id' => $session, 'reservation_token' => $reservation->reservation_token,
            'routing_name' => $this->code('共享工序路线'), 'output_item_id' => $item->id,
            'operations' => collect($operations)->values()->map(fn ($operation, $index) => [
                'operation_id' => $operation->id, 'sequence' => ($index + 1) * 10,
                'output_item_id' => $item->id, 'output_mode' => 'flow_only', 'quality_mode' => 'none',
                'setup_standard_minutes' => 2, 'unit_standard_minutes' => $minutes,
            ])->all(),
        ], $this->manager, self::PERMISSIONS, false);
    }

    private function workOrder(ProductionRouting $route, int $quantity): WorkOrder
    {
        $item = $route->outputItem;
        return WorkOrder::create(['work_order_no' => $this->code('WO'), 'source_type' => 'production_plan',
            'output_item_id' => $item->id, 'target_qty' => $quantity, 'target_base_qty' => $quantity,
            'target_unit_id' => $item->unit_id, 'base_unit_id' => $item->unit_id,
            'production_routing_id' => $route->id, 'routing_version_snapshot' => $route->version,
            'routing_snapshot' => app(ProductionMasterDataService::class)->snapshot($route),
            'status' => 'RELEASED', 'business_version' => 1, 'responsible_user_legacy_id' => $this->manager->legacy_id]);
    }

    private function initialize(WorkOrder $order, string $mode): void
    {
        app(ProductionExecutionFoundationService::class)->initializePublished($order, [
            'production_execution_mode' => $mode, 'serial_tracking_mode' => 'none',
            'serial_generation_stage' => 'before_finished_goods_posting', 'equipment_identity_requirement' => 'not_applicable',
        ]);
    }

    private function setPublic(ProductionOperation $operation, bool $public): void
    {
        app(ProductionMasterDataService::class)->updateOperation($operation->id, [
            'client_command_id' => $this->code('SET'), 'expected_version' => $operation->fresh()->business_version, 'is_public' => $public,
        ], $this->manager, self::PERMISSIONS, false);
    }

    private function code(string $prefix): string { return 'PUBLIC-'.$prefix.'-'.Str::upper(Str::random(10)); }
}
