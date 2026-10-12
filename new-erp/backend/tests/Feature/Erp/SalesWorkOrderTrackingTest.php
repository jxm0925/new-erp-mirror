<?php

namespace Tests\Feature\Erp;

use App\Http\Controllers\Api\V1\Erp\SalesOrderController;
use App\Models\Erp\{AssemblyComponentDemand, AssemblyProductionPlan, Item, ProductionQuantityOperation, SalesOrder, Unit, WorkOrder, WorkOrderReleaseGateCheck};
use App\Services\Erp\{AuthContextService, RbacBootstrapService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesWorkOrderTrackingTest extends TestCase
{
    use DatabaseTransactions;

    private Unit $unit;
    private Item $item;
    private SalesOrder $order;
    private int $actor;
    private array $permissions = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringEndsWith('_test', strtolower((string) DB::connection()->getDatabaseName()));
        $this->actor = random_int(6800000, 6899999);
        $this->unit = Unit::create(['unit_code' => $this->code('EA'), 'unit_name' => '件', 'decimal_places' => 8, 'status' => 'enabled']);
        $this->item = Item::create(['item_code' => $this->code('I'), 'item_name' => '销售跟踪测试整机', 'item_type' => 'finished_good',
            'unit_id' => $this->unit->id, 'is_stock_item' => true, 'is_production_item' => true, 'status' => 'enabled']);
        $this->order = $this->salesOrder();
        $this->mock(AuthContextService::class, function ($mock): void {
            $mock->shouldReceive('currentUser')->andReturn((object) ['legacy_id' => $this->actor]);
            $mock->shouldReceive('permissionCodes')->andReturnUsing(fn () => $this->permissions);
            $mock->shouldReceive('isSuperAdmin')->andReturn(false);
        });
    }

    public function test_pending_root_does_not_claim_the_first_planned_node_is_running_or_expose_production_details(): void
    {
        $root = $this->workOrder($this->order, ['routing_snapshot' => ['operations' => [['operation_name' => '仅计划下料']]]]);
        $this->plan($root, 'BLOCKED', [['code' => 'routing_missing', 'message' => '请完善工艺路线']]);
        DB::enableQueryLog(); DB::flushQueryLog();
        $row = $this->tracking()[0];
        $queries = DB::getQueryLog(); DB::disableQueryLog();
        $this->assertSame($root->id, $row['root_work_order_id']);
        $this->assertSame('blocked', $row['preparation_status']);
        $this->assertNull($row['current_process_name']);
        $this->assertSame(0, $row['total_operation_count']);
        $this->assertFalse($row['can_view']);
        $this->assertContains('请完善工艺路线', array_column($row['preparation_issues'], 'message'));
        foreach (['cost', 'components', 'rows', 'routing_snapshot', 'material_preparation'] as $key) $this->assertArrayNotHasKey($key, $row);
        foreach ($queries as $query) $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|alter|create)\b/i', $query['query']);
    }

    public function test_actual_operation_counts_and_current_names_come_from_execution_not_planned_routes(): void
    {
        $root = $this->workOrder($this->order, ['status' => 'IN_PROGRESS', 'released_at' => now()]);
        $this->operation($root, 10, '下料', 'COMPLETED');
        $this->operation($root, 20, '焊接', 'IN_PROGRESS');
        $this->operation($root, 30, '作废工序', 'CANCELLED');
        $row = $this->tracking()[0];
        $this->assertSame('焊接', $row['current_process_name']);
        $this->assertSame(1, $row['completed_operation_count']);
        $this->assertSame(2, $row['total_operation_count']);
        $this->assertSame('生产中 · 工序完成 1/2', $row['progress_text']);
    }

    public function test_component_aggregate_is_limited_to_the_sales_root_tree_and_separate_from_root_progress(): void
    {
        $root = $this->workOrder($this->order);
        $child = $this->child($root, 'IN_PROGRESS');
        $this->operation($child, 10, '部件焊接', 'IN_PROGRESS');
        $this->child($root, 'WAIT_RELEASE');
        $otherRoot = $this->workOrder($this->salesOrder());
        $otherChild = $this->child($otherRoot, 'IN_PROGRESS');
        $this->operation($otherChild, 10, '其他订单保密工序', 'IN_PROGRESS');
        $orphan = $this->workOrder($this->order, ['assembly_root_work_order_id' => $root->id, 'status' => 'IN_PROGRESS']);
        $this->operation($orphan, 10, '无需求关系工序', 'IN_PROGRESS');
        $rows = $this->tracking(); $this->assertCount(1, $rows); $row = $rows[0];
        $this->assertSame('WAIT_RELEASE', $row['status']); $this->assertNull($row['current_process_name']);
        $this->assertSame(2, $row['component_progress']['work_order_count']);
        $this->assertSame('部件焊接', $row['component_progress']['current_process_name']);
        $this->assertSame(1, $row['component_progress']['total_operation_count']);
        $this->assertStringNotContainsString('其他订单保密工序', json_encode($rows, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('无需求关系工序', json_encode($rows, JSON_UNESCAPED_UNICODE));
    }

    public function test_only_current_version_persisted_gate_blockers_are_visible_and_read_does_not_regenerate_checks(): void
    {
        $root = $this->workOrder($this->order, ['business_version' => 2]);
        foreach ([[1, 'bom_match', '旧版本BOM阻塞'], [2, 'production_funding', '生产资金条件未满足']] as [$version, $key, $message]) {
            WorkOrderReleaseGateCheck::create(['work_order_id' => $root->id, 'work_order_version' => $version,
                'check_key' => $key, 'status' => 'blocked', 'message' => $message, 'evaluated_at' => now()]);
        }
        $before = WorkOrderReleaseGateCheck::where('work_order_id', $root->id)->count();
        $messages = array_column($this->tracking()[0]['preparation_issues'], 'message');
        $this->assertContains('生产资金条件未满足', $messages); $this->assertNotContains('旧版本BOM阻塞', $messages);
        $this->assertSame($before, WorkOrderReleaseGateCheck::where('work_order_id', $root->id)->count());
    }

    public function test_sales_root_pages_and_production_detail_links_each_respect_their_own_scope(): void
    {
        $own = $this->workOrder($this->order, ['responsible_user_legacy_id' => $this->actor]);
        $other = $this->workOrder($this->order, ['responsible_user_legacy_id' => $this->actor + 1]);
        app(RbacBootstrapService::class)->bootstrap();
        DB::table('erp_legacy_admin_users')->insert(['legacy_id' => $this->actor, 'username' => $this->code('U'), 'nickname' => '只读跟踪测试', 'status' => 'normal', 'auth_group_names' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        $role = DB::table('erp_rbac_roles')->insertGetId(['code' => $this->code('R'), 'name' => '跟踪本人生产权限', 'data_scope' => 'self', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $permission = DB::table('erp_rbac_permissions')->where('code', 'production.work_order.view')->value('id');
        DB::table('erp_rbac_role_permissions')->insert(['role_id' => $role, 'permission_id' => $permission]);
        DB::table('erp_rbac_user_roles')->insert(['role_id' => $role, 'user_legacy_id' => $this->actor]);
        $this->permissions = ['production.work_order.view'];
        $first = $this->tracking(['tracking_per_page' => 1]);
        $this->assertSame($own->id, $first[0]['work_order_id']); $this->assertTrue($first[0]['can_view']);
        $this->assertSame(2, $this->order->work_order_tracking_pagination['total']);
        $second = $this->tracking(['tracking_per_page' => 1, 'tracking_page' => 2]);
        $this->assertSame($other->id, $second[0]['work_order_id']); $this->assertFalse($second[0]['can_view']);
    }

    public function test_child_preparation_reasons_survive_root_version_changes_without_exposing_unrelated_children(): void
    {
        $root = $this->workOrder($this->order, ['business_version' => 2]);
        $child = $this->child($root, 'WAIT_RELEASE');
        $child->update(['technical_version' => 1]);
        $orphan = $this->workOrder($this->order, ['assembly_root_work_order_id' => $root->id]);
        $plan = $this->plan($root);
        $plan->update(['status' => 'PREPARED', 'plan_snapshot' => ['issues' => [], 'components' => [],
            'child_material_preparations' => [
                (string) $child->id => ['requires_technical_confirmation' => true],
                (string) $orphan->id => ['requires_technical_confirmation' => true],
            ]]]);
        WorkOrderReleaseGateCheck::create(['work_order_id' => $root->id, 'work_order_version' => 1,
            'check_key' => 'assembly_preparation', 'status' => 'blocked', 'message' => '旧版准备原因', 'evaluated_at' => now()]);
        $row = $this->tracking()[0];
        $this->assertFalse($row['preparation_ready']);
        $this->assertContains('部件 '.$child->work_order_no.'：子工单技术资料已确认，请由根工单重新核料。', array_column($row['preparation_issues'], 'message'));
        $encoded = json_encode($row, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($orphan->work_order_no, $encoded);
        $this->assertStringNotContainsString('旧版准备原因', $encoded);
        foreach ($row['preparation_issues'] as $issue) $this->assertSame(['code', 'message'], array_keys($issue));
        foreach (['child_material_preparations', 'rows', 'cost', 'components'] as $key) $this->assertArrayNotHasKey($key, $row);
    }

    public function test_released_legacy_order_without_new_header_is_unknown_not_blocked_or_ready(): void
    {
        $this->assertLegacyPreparationProjection();
    }

    public function test_released_legacy_order_with_missing_preparation_schema_keeps_historical_facts(): void
    {
        \Illuminate\Support\Facades\Schema::partialMock()->shouldReceive('hasColumns')
            ->with('erp_work_order_material_preparations', \Mockery::type('array'))->andReturn(false);
        $this->assertLegacyPreparationProjection();
    }

    public function test_published_new_preparation_is_not_relabelled_as_legacy(): void
    {
        $root = $this->workOrder($this->order, ['status' => 'IN_PROGRESS', 'released_at' => now()]);
        $this->plan($root, 'PREPARED');
        DB::table('erp_work_order_material_preparations')->insert(['work_order_id' => $root->id,
            'version' => 1, 'work_order_version' => 1, 'status' => 'PUBLISHED', 'input_hash' => str_repeat('a', 64),
            'input_snapshot' => '{}', 'prepared_by_legacy_id' => $this->actor, 'published_at' => now(),
            'created_at' => now(), 'updated_at' => now()]);
        $row = $this->tracking()[0];
        $this->assertSame('prepared', $row['preparation_status']);
        $this->assertSame('published', $row['material_preparation_status']);
        $this->assertTrue($row['preparation_ready']);
    }

    private function assertLegacyPreparationProjection(): void
    {
        $root = $this->workOrder($this->order, ['status' => 'IN_PROGRESS', 'released_at' => now()]);
        $plan = $this->plan($root, 'PREPARED', [
            ['code' => 'not_prepared', 'message' => '尚未完成物料准备。'],
            ['code' => 'historical_quality_hold', 'message' => '保留的历史质量原因'],
        ]);
        $before = $plan->plan_snapshot;
        DB::enableQueryLog(); DB::flushQueryLog();
        $row = $this->tracking()[0];
        $queries = DB::getQueryLog(); DB::disableQueryLog();
        $this->assertSame('IN_PROGRESS', $row['status']);
        $this->assertSame('legacy_snapshot', $row['preparation_status']);
        $this->assertNull($row['preparation_ready']);
        $this->assertNull($row['material_preparation_status']);
        $this->assertSame([['code' => 'historical_quality_hold', 'message' => '保留的历史质量原因']], $row['preparation_issues']);
        $this->assertSame($before, $plan->fresh()->plan_snapshot);
        foreach ($queries as $query) $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|alter|create)\b/i', $query['query']);
    }
    private function tracking(array $query = []): array
    {
        return (new \ReflectionMethod(SalesOrderController::class, 'workOrderTrackingProjection'))
            ->invoke(app(SalesOrderController::class), $this->order, Request::create('/', 'GET', $query));
    }

    private function salesOrder(): SalesOrder
    {
        return SalesOrder::create(['sales_order_no' => $this->code('SO'), 'customer_name' => '跟踪测试客户',
            'order_status' => 'confirmed', 'confirm_status' => 'confirmed', 'production_confirm_status' => 'confirmed', 'business_version' => 1]);
    }

    private function workOrder(SalesOrder $order, array $extra = []): WorkOrder
    {
        return WorkOrder::create(array_replace(['work_order_no' => $this->code('WO'), 'source_type' => 'sales_order',
            'source_id' => $order->id, 'source_no_snapshot' => $order->sales_order_no, 'output_item_id' => $this->item->id,
            'target_qty' => 2, 'target_base_qty' => 2, 'target_unit_id' => $this->unit->id, 'base_unit_id' => $this->unit->id,
            'status' => 'WAIT_RELEASE', 'business_version' => 1], $extra));
    }

    private function plan(WorkOrder $root, string $status = 'PENDING', array $issues = []): AssemblyProductionPlan
    {
        return AssemblyProductionPlan::firstOrCreate(['root_work_order_id' => $root->id], ['status' => $status,
            'work_order_version' => $root->business_version, 'plan_version' => 1, 'plan_snapshot' => ['issues' => $issues, 'components' => []]]);
    }

    private function child(WorkOrder $root, string $status): WorkOrder
    {
        $child = $this->workOrder($this->order, ['status' => $status, 'assembly_root_work_order_id' => $root->id, 'assembly_parent_work_order_id' => $root->id]);
        $demand = AssemblyComponentDemand::create(['assembly_plan_id' => $this->plan($root)->id, 'root_work_order_id' => $root->id,
            'parent_work_order_id' => $root->id, 'child_work_order_id' => $child->id, 'bom_item_id' => 1,
            'item_id' => $this->item->id, 'base_unit_id' => $this->unit->id, 'path_key' => $this->code('PATH'),
            'required_base_qty' => 2, 'production_base_qty' => 2, 'status' => 'PREPARED', 'demand_snapshot' => []]);
        $child->update(['assembly_component_demand_id' => $demand->id]);
        return $child;
    }

    private function operation(WorkOrder $workOrder, int $sequence, string $name, string $status): void
    {
        ProductionQuantityOperation::create(['work_order_id' => $workOrder->id, 'operation_code_snapshot' => $this->code('OP'),
            'operation_name_snapshot' => $name, 'sequence_no_snapshot' => $sequence, 'status' => $status,
            'planned_base_qty' => 2, 'remaining_base_qty' => $status === 'COMPLETED' ? 0 : 2,
            'completed_base_qty' => $status === 'COMPLETED' ? 2 : 0,
            'started_at' => in_array($status, ['IN_PROGRESS', 'COMPLETED'], true) ? now() : null,
            'completed_at' => $status === 'COMPLETED' ? now() : null, 'business_version' => 1]);
    }

    private function code(string $prefix): string { return $prefix.'-'.Str::upper(Str::random(12)); }
}
