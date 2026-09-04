<?php

namespace Tests\Feature\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\Item;
use App\Models\Erp\ProductionDemand;
use App\Models\Erp\ProductionRouting;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesOrderLine;
use App\Models\Erp\Unit;
use App\Models\Erp\WorkOrder;
use App\Services\Erp\ProductionMasterDataService;
use App\Services\Erp\WorkOrderApplicationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class Phase6A2RoutingRecoveryTest extends TestCase
{
    use DatabaseTransactions;

    private const WO_PERMS = ['production.work_order.view', 'production.work_order.create', 'production.work_order.edit'];
    private const MASTER_PERMS = ['production.routing.view', 'production.routing.create', 'production.routing.edit', 'production.routing.activate', 'production.routing.default'];

    public function test_cases_a1_to_a3_and_a9_sales_work_order_can_freeze_a_later_default_route_idempotently(): void
    {
        [$user, $item] = $this->base();
        $workOrder = $this->salesWorkOrderWithoutRouting($user, $item);
        $route = $this->route($item, true);
        $command = [
            'client_command_id' => $this->id('REMATCH'),
            'expected_version' => 1,
            'reason' => '主数据补齐后重新匹配',
        ];

        $service = app(WorkOrderApplicationService::class);
        $matched = $service->rematchRouting($workOrder->id, $command, $user, self::WO_PERMS, true);
        $retry = $service->rematchRouting($workOrder->id, $command, $user, self::WO_PERMS, true);

        $this->assertSame($route->id, (int) $matched->production_routing_id); // A1
        $this->assertSame($route->routing_no, $matched->routing_snapshot['routing_no']); // A2
        $this->assertSame(2, (int) $matched->business_version);
        $this->assertSame($matched->id, $retry->id); // A9
        $this->assertSame(1, DB::table('erp_work_order_command_ledgers')->where('client_command_id', $command['client_command_id'])->count());
        $snapshot = $matched->routing_snapshot;
        $route->update(['routing_name' => '匹配后的主数据新名称']);
        $this->assertSame($snapshot, $matched->fresh()->routing_snapshot); // A3
        $this->assertDatabaseHas('erp_work_order_status_logs', [
            'work_order_id' => $workOrder->id,
            'reason' => '主数据补齐后重新匹配',
            'before_version' => 1,
            'after_version' => 2,
        ]);
    }

    public function test_cases_a4_to_a8_and_a10_reject_ineligible_stale_or_conflicting_requests(): void
    {
        [$user, $item] = $this->base();
        $service = app(WorkOrderApplicationService::class);

        $matched = $this->salesWorkOrderWithoutRouting($user, $item);
        $this->route($item, true);
        $service->rematchRouting($matched->id, $this->rematchPayload(1), $user, self::WO_PERMS, true);
        $this->expectDomain('routing_already_frozen', fn () => $service->rematchRouting($matched->id, $this->rematchPayload(2), $user, self::WO_PERMS, true)); // A4

        $released = $this->salesWorkOrderWithoutRouting($user, $this->item($item->unit_id, 'RELEASED'));
        $released->update(['status' => WorkOrderApplicationService::RELEASED]);
        $this->expectDomain('routing_rematch_not_allowed', fn () => $service->rematchRouting($released->id, $this->rematchPayload(1), $user, self::WO_PERMS, true)); // A5

        $stock = WorkOrder::create([
            'work_order_no' => $this->id('WO'), 'source_type' => 'stock_prebuild',
            'output_item_id' => $item->id, 'target_qty' => 1, 'target_base_qty' => 1,
            'status' => WorkOrderApplicationService::DRAFT, 'business_version' => 1,
            'created_by_legacy_id' => $user->legacy_id, 'updated_by_legacy_id' => $user->legacy_id,
        ]);
        $this->expectDomain('routing_rematch_not_allowed', fn () => $service->rematchRouting($stock->id, $this->rematchPayload(1), $user, self::WO_PERMS, true)); // A6

        $missing = $this->salesWorkOrderWithoutRouting($user, $this->item($item->unit_id, 'MISSING'));
        $this->expectDomain('routing_not_found', fn () => $service->rematchRouting($missing->id, $this->rematchPayload(1), $user, self::WO_PERMS, true)); // A7

        $staleItem = $this->item($item->unit_id, 'STALE');
        $stale = $this->salesWorkOrderWithoutRouting($user, $staleItem);
        $this->route($staleItem, true);
        $this->expectDomain('version_conflict', fn () => $service->rematchRouting($stale->id, $this->rematchPayload(99), $user, self::WO_PERMS, true)); // A8

        $conflictItem = $this->item($item->unit_id, 'CONFLICT');
        $conflict = $this->salesWorkOrderWithoutRouting($user, $conflictItem);
        $this->route($conflictItem, true);
        $commandId = $this->id('SAME');
        $service->rematchRouting($conflict->id, $this->rematchPayload(1, $commandId, '第一次'), $user, self::WO_PERMS, true);
        $this->expectDomain('idempotency_hash_conflict', fn () => $service->rematchRouting($conflict->id, $this->rematchPayload(1, $commandId, '第二次'), $user, self::WO_PERMS, true)); // A10
    }

    public function test_cases_b1_to_b4_switching_default_versions_both_routes_and_preserves_uniqueness(): void
    {
        [$user, $item] = $this->base();
        $old = $this->route($item, true);
        $new = $this->route($item, false);
        $oldVersion = $old->business_version;
        $newVersion = $new->business_version;
        $master = app(ProductionMasterDataService::class);

        $selected = $master->setDefaultRouting($new->id, [
            'client_command_id' => $this->id('DEFAULT'),
            'expected_version' => $newVersion,
        ], $user, self::MASTER_PERMS, true);

        $this->assertSame($oldVersion + 1, (int) $old->fresh()->business_version); // B1
        $this->assertSame($newVersion + 1, (int) $selected->business_version); // B2
        try {
            $master->updateRouting($old->id, [
                'client_command_id' => $this->id('STALE-EDIT'),
                'expected_version' => $oldVersion,
                'routing_name' => '旧页面覆盖名称',
            ], $user, self::MASTER_PERMS, true);
            $this->fail('旧页面版本必须被拒绝。');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('expected_version', $exception->errors()); // B3
        }
        $this->assertSame(1, ProductionRouting::where('output_item_id', $item->id)->where('is_default', true)->count()); // B4
    }

    public function test_cases_c2_and_c3_route_family_versions_are_continuous_unique_and_command_idempotent(): void
    {
        [$user, $item] = $this->base();
        $route = $this->route($item, false);
        $master = app(ProductionMasterDataService::class);
        $firstCommand = ['client_command_id' => $this->id('COPY')];
        $v2 = $master->copyRouting($route->id, $firstCommand, $user, self::MASTER_PERMS, true);
        $retry = $master->copyRouting($route->id, $firstCommand, $user, self::MASTER_PERMS, true);
        $v3 = $master->copyRouting($v2->id, ['client_command_id' => $this->id('COPY')], $user, self::MASTER_PERMS, true);

        $this->assertSame($v2->id, $retry->id); // C3
        $this->assertSame([1, 2, 3], ProductionRouting::where('routing_no', $route->routing_no)->orderBy('version')->pluck('version')->map(fn ($value) => (int) $value)->all()); // C2
        $this->assertSame(3, ProductionRouting::where('routing_no', $route->routing_no)->distinct()->count('version'));
        $this->assertSame(3, $v3->version);
    }

    private function base(): array
    {
        $legacyId = random_int(700000, 899999);
        DB::table('erp_legacy_admin_users')->insert([
            'legacy_id' => $legacyId, 'username' => 'p6a2-'.$legacyId, 'nickname' => '6A.2验收员',
            'status' => 'normal', 'auth_group_names' => '[]', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('erp_document_number_rules')->updateOrInsert(['document_type' => 'work_order'], [
            'name' => '生产工单', 'prefix' => 'WO', 'date_format' => 'Ymd', 'sequence_length' => 5,
            'reset_cycle' => 'daily', 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $unit = Unit::create([
            'unit_code' => $this->id('UNIT'), 'unit_name' => '件', 'unit_type' => 'count',
            'decimal_places' => 0, 'is_base' => true, 'status' => 'enabled',
        ]);
        return [DB::table('erp_legacy_admin_users')->where('legacy_id', $legacyId)->first(), $this->item($unit->id, 'FG')];
    }

    private function item(int $unitId, string $name): Item
    {
        return Item::create([
            'item_code' => $this->id('ITEM'), 'item_name' => '6A.2-'.$name, 'unit_id' => $unitId,
            'status' => 'enabled', 'is_production_item' => true, 'is_stock_item' => true,
        ]);
    }

    private function route(Item $item, bool $default): ProductionRouting
    {
        $operationId = DB::table('erp_production_operations')->insertGetId([
            'operation_no' => $this->id('OP'), 'operation_name' => '装配', 'status' => 'enabled',
            'sort' => 10, 'business_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $id = DB::table('erp_production_routings')->insertGetId([
            'routing_no' => $this->id('RT'), 'routing_name' => '6A.2路线', 'output_item_id' => $item->id,
            'version' => 1, 'status' => 'active', 'is_default' => $default,
            'default_scope_key' => $default ? $item->id : null, 'business_version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('erp_production_routing_operations')->insert([
            'routing_id' => $id, 'operation_id' => $operationId, 'sequence' => 10,
            'is_key_operation' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return ProductionRouting::with(['outputItem', 'operations.operation'])->findOrFail($id);
    }

    private function salesWorkOrderWithoutRouting(object $user, Item $item): WorkOrder
    {
        $order = SalesOrder::create([
            'sales_order_no' => $this->id('SO'), 'customer_name' => '6A.2客户', 'order_status' => 'confirmed',
            'confirm_status' => 'confirmed', 'production_confirm_status' => 'confirmed',
            'sales_user_legacy_id' => $user->legacy_id, 'created_by_legacy_id' => $user->legacy_id,
            'total_amount' => 0, 'final_receivable_amount' => 0,
        ]);
        $line = SalesOrderLine::create([
            'sales_order_id' => $order->id, 'line_no' => 1, 'line_uuid' => $this->id('LINE'),
            'line_type' => 'physical', 'item_id' => $item->id, 'item_name' => $item->item_name,
            'order_qty' => 10, 'unit_id' => $item->unit_id, 'unit_name_snapshot' => '件',
            'item_base_unit_id' => $item->unit_id, 'item_base_required_qty' => 10,
            'unit_price' => 0, 'amount' => 0,
        ]);
        $demand = ProductionDemand::create([
            'requirement_no' => $this->id('PD'), 'sales_order_id' => $order->id, 'sales_order_line_id' => $line->id,
            'item_id' => $item->id, 'production_qty' => 10, 'base_unit_id' => $item->unit_id,
            'base_unit_name_snapshot' => '件', 'allocated_qty' => 0, 'consumed_qty' => 0,
            'remaining_qty' => 10, 'closed_qty' => 0, 'requirement_status' => 'ready',
            'bom_match_status' => 'matched', 'is_active' => true, 'requirement_version' => 1,
            'business_version' => 1, 'is_ready_for_work_order' => false,
        ]);
        return app(WorkOrderApplicationService::class)->createDraft([
            'client_command_id' => $this->id('CREATE'), 'source_type' => 'sales_order',
            'production_demand_id' => $demand->id, 'expected_demand_version' => 1, 'target_qty' => 1,
        ], $user, self::WO_PERMS, true);
    }

    private function rematchPayload(int $version, ?string $commandId = null, string $reason = '重新匹配路线'): array
    {
        return ['client_command_id' => $commandId ?: $this->id('REMATCH'), 'expected_version' => $version, 'reason' => $reason];
    }

    private function expectDomain(string $code, callable $callback): void
    {
        try {
            $callback();
            $this->fail("应拒绝：{$code}");
        } catch (WorkOrderDomainException $exception) {
            $this->assertSame($code, $exception->errorCode);
        }
    }

    private function id(string $prefix): string
    {
        return $prefix.'-'.str_replace('.', '', uniqid('', true));
    }
}
