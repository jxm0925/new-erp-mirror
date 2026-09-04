<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\Item;
use App\Models\Erp\ProductionRouting;
use App\Models\Erp\Unit;
use App\Services\Erp\DocumentNumberService;
use App\Services\Erp\ProductionMasterDataService;
use App\Services\Erp\WorkOrderApplicationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class Phase6AProductionMasterTest extends TestCase
{
    use DatabaseTransactions;

    private const MASTER_PERMISSIONS = [
        'production.operation.view', 'production.operation.create', 'production.operation.edit', 'production.operation.toggle',
        'production.routing.view', 'production.routing.create', 'production.routing.edit', 'production.routing.activate', 'production.routing.default',
    ];

    private const WORK_ORDER_PERMISSIONS = [
        'production.work_order.view', 'production.work_order.create', 'production.work_order.edit',
        'production.work_order.submit', 'production.work_order.cancel',
    ];

    public function test_cases_01_to_08_operation_and_routing_lifecycle(): void
    {
        [$user, $item] = $this->fixture();
        $service = app(ProductionMasterDataService::class);

        $cut = $this->createOperation($service, $user, '切割', 10);
        $weld = $this->createOperation($service, $user, '焊接', 20);
        $polish = $this->createOperation($service, $user, '打磨', 30);
        $this->assertDatabaseHas('erp_production_operations', ['id' => $cut->id, 'operation_name' => '切割']); // CASE 1

        $cut = $service->updateOperation($cut->id, ['client_command_id' => $this->id('op-edit'), 'expected_version' => 1, 'operation_name' => '精密切割', 'sort' => 5], $user, self::MASTER_PERMISSIONS, true);
        $this->assertSame('精密切割', $cut->operation_name); // CASE 2

        $standalone = $this->createOperation($service, $user, '包装', 90);
        $standalone = $service->setOperationEnabled($standalone->id, false, ['client_command_id' => $this->id('op-disable'), 'expected_version' => 1], $user, self::MASTER_PERMISSIONS, true);
        $this->assertSame('disabled', $standalone->status); // CASE 3

        $route = $this->createRouting($service, $user, $item, [$cut->id, $weld->id, $polish->id]);
        $this->assertSame(3, $route->operations->count()); // CASE 4, 5
        $this->assertSame([10, 20, 30], $route->operations->pluck('sequence')->all());

        $route = $service->updateRouting($route->id, [
            'client_command_id' => $this->id('route-reorder'), 'expected_version' => 1,
            'operations' => [
                ['operation_id' => $weld->id, 'sequence' => 10],
                ['operation_id' => $cut->id, 'sequence' => 20],
                ['operation_id' => $polish->id, 'sequence' => 30],
            ],
        ], $user, self::MASTER_PERMISSIONS, true);
        $this->assertSame([$weld->id, $cut->id, $polish->id], $route->operations->pluck('operation_id')->all()); // CASE 6

        $route = $service->activateRouting($route->id, ['client_command_id' => $this->id('route-active'), 'expected_version' => 2], $user, self::MASTER_PERMISSIONS, true);
        $route = $service->setDefaultRouting($route->id, ['client_command_id' => $this->id('route-default'), 'expected_version' => 3], $user, self::MASTER_PERMISSIONS, true);
        $this->assertTrue($route->is_default); // CASE 7

        $second = $this->createRouting($service, $user, $item, [$cut->id, $polish->id], 2);
        $second = $service->activateRouting($second->id, ['client_command_id' => $this->id('route2-active'), 'expected_version' => 1], $user, self::MASTER_PERMISSIONS, true);
        $second = $service->setDefaultRouting($second->id, ['client_command_id' => $this->id('route2-default'), 'expected_version' => 2], $user, self::MASTER_PERMISSIONS, true);
        $this->assertTrue($second->is_default);
        $this->assertFalse((bool) $route->fresh()->is_default); // CASE 8
    }

    public function test_cases_11_to_16_stock_prebuild_validation_snapshot_state_and_idempotency(): void
    {
        [$user, $item] = $this->fixture();
        $master = app(ProductionMasterDataService::class);
        $cut = $this->createOperation($master, $user, '下料', 10);
        $assembly = $this->createOperation($master, $user, '组装', 20);
        $outside = $this->createOperation($master, $user, '路线外工序', 30);
        $route = $this->createRouting($master, $user, $item, [$cut->id, $assembly->id]);
        $route = $master->activateRouting($route->id, ['client_command_id' => $this->id('active'), 'expected_version' => 1], $user, self::MASTER_PERMISSIONS, true);

        $numbers = app(DocumentNumberService::class);
        $session = $this->uuid();
        $reservation = $numbers->reserve('work_order', $session, $user->legacy_id, '/production/work-orders/create');
        $payload = [
            'client_command_id' => $this->id('wo-stock'), 'creation_session_id' => $session, 'reservation_token' => $reservation->reservation_token,
            'source_type' => 'stock_prebuild',
            'output_item_id' => $item->id, 'production_routing_id' => $route->id, 'target_routing_operation_id' => $route->operations->last()->id,
            'target_qty' => 12, 'planned_date' => '2026-09-10', 'production_batch' => 'SP-01',
        ];
        $service = app(WorkOrderApplicationService::class);
        $workOrder = $service->createDraft($payload, $user, self::WORK_ORDER_PERMISSIONS, true);
        $this->assertSame('stock_prebuild', $workOrder->source_type); // CASE 11
        $this->assertSame($assembly->id, (int) $workOrder->target_operation_id); // CASE 12
        $this->assertSame($reservation->document_no, $workOrder->work_order_no);

        $badSession = $this->uuid();
        $badReservation = $numbers->reserve('work_order', $badSession, $user->legacy_id, '/production/work-orders/create');
        try {
            $outsideRoute = $this->createRouting($master, $user, $item, [$outside->id]);
            $service->createDraft([...$payload, 'client_command_id' => $this->id('wo-invalid-target'), 'creation_session_id' => $badSession, 'reservation_token' => $badReservation->reservation_token, 'target_routing_operation_id' => $outsideRoute->operations->first()->id], $user, self::WORK_ORDER_PERMISSIONS, true);
            $this->fail('路线外工序必须被拒绝。');
        } catch (\App\Exceptions\Erp\WorkOrderDomainException $exception) {
            $this->assertSame('target_routing_operation_invalid', $exception->errorCode); // CASE 13
        }

        $snapshot = $workOrder->routing_snapshot;
        DB::table('erp_production_routings')->where('id', $route->id)->update(['routing_name' => '主数据已改名']);
        DB::table('erp_production_operations')->where('id', $assembly->id)->update(['operation_name' => '主数据组装已改名']);
        $this->assertSame($snapshot, $workOrder->fresh()->routing_snapshot); // CASE 14

        try {
            $master->updateRouting($route->id, ['client_command_id' => $this->id('invalid-state'), 'expected_version' => 2, 'routing_name' => '禁止修改'], $user, self::MASTER_PERMISSIONS, true);
            $this->fail('生效路线不允许直接编辑。');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors()); // CASE 15
        }

        $same = $service->createDraft($payload, $user, self::WORK_ORDER_PERMISSIONS, true);
        $this->assertSame($workOrder->id, $same->id);
        $this->assertSame(1, DB::table('erp_work_orders')->where('origin_command_id', $payload['client_command_id'])->count()); // CASE 16
    }

    public function test_cases_09_and_10_legacy_sales_work_order_contract_is_preserved(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('erp_work_orders', 'production_demand_id'));
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('erp_work_orders', 'source_type'));
        $column = collect(DB::getSchemaBuilder()->getColumns('erp_work_orders'))->firstWhere('name', 'production_demand_id');
        $this->assertTrue((bool) ($column['nullable'] ?? false));
        $this->assertSame(0, DB::table('erp_work_orders')->whereNotNull('production_demand_id')->where('source_type', '<>', 'sales_order')->count());
    }

    private function fixture(): array
    {
        $legacyId = random_int(800000, 899999);
        DB::table('erp_legacy_admin_users')->insert(['legacy_id' => $legacyId, 'username' => 'phase6a-'.$legacyId, 'nickname' => 'Phase6A验收员', 'status' => 'normal', 'auth_group_names' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([['operation', '生产工序', 'OP'], ['routing', '工艺路线', 'RT'], ['work_order', '生产工单', 'WO'], ['stock_prebuild', '备货来源号', 'SPB']] as [$type, $name, $prefix]) {
            DB::table('erp_document_number_rules')->updateOrInsert(['document_type' => $type], ['name' => $name, 'prefix' => $prefix, 'date_format' => 'Ymd', 'sequence_length' => 5, 'reset_cycle' => 'daily', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $unit = Unit::create(['unit_code' => $this->id('U'), 'unit_name' => '件', 'unit_type' => 'count', 'decimal_places' => 0, 'is_base' => true, 'status' => 'enabled']);
        $item = Item::create(['item_code' => $this->id('ITEM'), 'item_name' => 'Phase6A产出物料', 'unit_id' => $unit->id, 'status' => 'enabled', 'is_production_item' => true, 'is_stock_item' => true]);
        return [DB::table('erp_legacy_admin_users')->where('legacy_id', $legacyId)->first(), $item];
    }

    private function createOperation(ProductionMasterDataService $service, object $user, string $name, int $sort)
    {
        $session = $this->uuid();
        $reservation = app(DocumentNumberService::class)->reserve('operation', $session, $user->legacy_id, '/production/operations/new');
        return $service->createOperation(['client_command_id' => $this->id('operation'), 'creation_session_id' => $session, 'reservation_token' => $reservation->reservation_token, 'operation_name' => $name, 'sort' => $sort, 'status' => 'enabled'], $user, self::MASTER_PERMISSIONS, true);
    }

    private function createRouting(ProductionMasterDataService $service, object $user, Item $item, array $operationIds, int $version = 1): ProductionRouting
    {
        $session = $this->uuid();
        $reservation = app(DocumentNumberService::class)->reserve('routing', $session, $user->legacy_id, '/production/routings/new');
        return $service->createRouting([
            'client_command_id' => $this->id('routing'), 'creation_session_id' => $session, 'reservation_token' => $reservation->reservation_token,
            'routing_name' => "标准制造路线 V{$version}", 'output_item_id' => $item->id, 'version' => $version,
            'operations' => collect($operationIds)->values()->map(fn ($id, $index) => ['operation_id' => $id, 'sequence' => ($index + 1) * 10, 'is_key_operation' => $index === count($operationIds) - 1])->all(),
        ], $user, self::MASTER_PERMISSIONS, true);
    }

    private function id(string $prefix): string { return $prefix.'-'.str_replace('.', '', uniqid('', true)); }
    private function uuid(): string { return (string) \Illuminate\Support\Str::uuid(); }
}
