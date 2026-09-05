<?php

namespace Tests\Feature\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventoryBatch;
use App\Models\Erp\InventoryReservation;
use App\Models\Erp\Item;
use App\Models\Erp\Location;
use App\Models\Erp\ProductionDemand;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesOrderLine;
use App\Models\Erp\Unit;
use App\Models\Erp\Warehouse;
use App\Services\Erp\SalesOrderInventoryLockService;
use App\Services\Erp\WorkOrderApplicationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalesOrderInventoryLockTest extends TestCase
{
    use DatabaseTransactions;

    private const PERMISSIONS = ['sales_order.inventory_lock'];

    public function test_confirmed_order_locks_available_inventory_and_reports_only_shortage_as_pending_production(): void
    {
        [$order, $line, $balance, $user] = $this->fixture(10, 4);
        $result = app(SalesOrderInventoryLockService::class)->lock($order->id, [
            'client_command_id' => (string) Str::uuid(),
            'expected_version' => 1,
        ], $user, self::PERMISSIONS);

        $this->assertSame(4.0, (float) $result['totals']['locked_inventory_qty']);
        $this->assertSame(6.0, (float) $result['totals']['pending_production_qty']);
        $this->assertSame(10.0, (float) $result['totals']['pending_fulfillment_qty']);
        $this->assertSame('partial', $result['inventory_lock_status']);
        $this->assertSame(4.0, (float) $balance->fresh()->quantity_locked);
        $this->assertSame(0.0, (float) $balance->fresh()->quantity_available);
        $this->assertSame(1, InventoryReservation::query()->where('source_order_id', $order->id)->where('reservation_status', 'active')->count());
        $this->assertSame(4.0, (float) $line->fresh()->fulfillments()->where('fulfillment_type', 'inventory')->sum('sales_qty'));
    }

    public function test_same_command_replays_and_new_command_does_not_double_lock(): void
    {
        [$order, , $balance, $user] = $this->fixture(10, 4);
        $service = app(SalesOrderInventoryLockService::class);
        $payload = ['client_command_id' => (string) Str::uuid(), 'expected_version' => 1];
        $first = $service->lock($order->id, $payload, $user, self::PERMISSIONS);
        $replayed = $service->lock($order->id, $payload, $user, self::PERMISSIONS);
        $second = $service->lock($order->id, [
            'client_command_id' => (string) Str::uuid(),
            'expected_version' => $order->fresh()->business_version,
        ], $user, self::PERMISSIONS);

        $this->assertEquals($first, $replayed);
        $this->assertSame(0, $second['created_fulfillment_count']);
        $this->assertSame(4.0, (float) $balance->fresh()->quantity_locked);
        $this->assertSame(1, InventoryReservation::query()->where('source_order_id', $order->id)->count());
    }

    public function test_stale_version_and_reused_command_with_different_request_are_rejected(): void
    {
        [$order, , , $user] = $this->fixture(10, 4);
        $service = app(SalesOrderInventoryLockService::class);
        $command = (string) Str::uuid();
        $service->lock($order->id, ['client_command_id' => $command, 'expected_version' => 1], $user, self::PERMISSIONS);

        try {
            $service->lock($order->id, ['client_command_id' => (string) Str::uuid(), 'expected_version' => 1], $user, self::PERMISSIONS);
            $this->fail('Expected version_conflict.');
        } catch (WorkOrderDomainException $e) {
            $this->assertSame('version_conflict', $e->errorCode);
        }

        try {
            $service->lock($order->id, ['client_command_id' => $command, 'expected_version' => 2], $user, self::PERMISSIONS);
            $this->fail('Expected command_conflict.');
        } catch (WorkOrderDomainException $e) {
            $this->assertSame('command_conflict', $e->errorCode);
        }
    }

    public function test_creating_sales_work_order_for_shortage_does_not_release_existing_order_lock(): void
    {
        [$order, $line, $balance, $user] = $this->fixture(10, 4);
        app(SalesOrderInventoryLockService::class)->lock($order->id, [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => 1,
        ], $user, self::PERMISSIONS);
        $line->update(['production_required_qty' => 6]);
        $operationId = DB::table('erp_production_operations')->insertGetId([
            'operation_no' => 'OP-'.Str::upper(Str::random(8)), 'operation_name' => '总装',
            'status' => 'enabled', 'sort' => 10, 'business_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $routingId = DB::table('erp_production_routings')->insertGetId([
            'routing_no' => 'RT-'.Str::upper(Str::random(8)), 'routing_name' => '默认生产路线',
            'output_item_id' => $line->item_id, 'version' => 1, 'status' => 'active',
            'is_default' => true, 'default_scope_key' => $line->item_id, 'business_version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('erp_production_routing_operations')->insert([
            'routing_id' => $routingId, 'operation_id' => $operationId, 'sequence' => 10,
            'is_key_operation' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $demand = ProductionDemand::create([
            'requirement_no' => 'SOPR-'.Str::upper(Str::random(8)), 'sales_order_id' => $order->id,
            'sales_order_line_id' => $line->id, 'item_id' => $line->item_id, 'production_qty' => 6,
            'item_base_required_qty' => 6, 'base_unit_id' => $line->item_base_unit_id,
            'base_unit_name_snapshot' => '件', 'allocated_qty' => 0, 'consumed_qty' => 0,
            'remaining_qty' => 6, 'closed_qty' => 0, 'requirement_status' => 'ready',
            'bom_match_status' => 'matched', 'is_active' => true, 'requirement_version' => 1,
            'business_version' => 1, 'is_ready_for_work_order' => false,
        ]);

        app(WorkOrderApplicationService::class)->createDraft([
            'client_command_id' => (string) Str::uuid(), 'source_type' => 'sales_order',
            'production_demand_id' => $demand->id, 'expected_demand_version' => 1, 'target_qty' => 6,
        ], $user, ['production.work_order.create'], true);

        $this->assertSame(4.0, (float) $balance->fresh()->quantity_locked);
        $this->assertSame(4.0, (float) InventoryReservation::query()->where('source_order_id', $order->id)
            ->where('reservation_status', 'active')->sum('reserved_qty'));
    }

    private function fixture(float $orderQty, float $stockQty): array
    {
        $suffix = Str::upper(Str::random(8));
        $unit = Unit::create(['unit_code' => 'EA-'.$suffix, 'unit_name' => '件', 'unit_type' => 'quantity', 'decimal_places' => 0, 'is_base' => true, 'status' => 'enabled']);
        $warehouse = Warehouse::create(['warehouse_code' => 'WH-'.$suffix, 'warehouse_name' => '销售锁库存仓', 'status' => 'enabled']);
        $location = Location::create(['warehouse_id' => $warehouse->id, 'location_code' => 'LOC-'.$suffix, 'location_name' => '销售锁库存位', 'status' => 'enabled']);
        $item = Item::create(['item_code' => 'ITEM-'.$suffix, 'item_name' => '销售锁库存成品', 'item_type' => 'finished_good', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_production_item' => true, 'status' => 'enabled']);
        InventoryBatch::create(['item_id' => $item->id, 'batch_no' => 'BATCH-'.$suffix, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'status' => 'enabled']);
        $balance = InventoryBalance::create([
            'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id,
            'batch_no' => 'BATCH-'.$suffix, 'unit_id' => $unit->id,
            'quantity_on_hand' => $stockQty, 'quantity_locked' => 0, 'quantity_available' => $stockQty,
            'quantity_defective' => 0, 'quantity_pending' => 0,
        ]);
        $order = SalesOrder::create([
            'sales_order_no' => 'SO-LOCK-'.$suffix, 'customer_name' => '锁库存测试客户',
            'order_status' => 'confirmed', 'confirm_status' => 'confirmed', 'production_confirm_status' => 'pending',
            'shipment_status' => 'not_shipped', 'business_version' => 1,
        ]);
        $line = SalesOrderLine::create([
            'sales_order_id' => $order->id, 'line_no' => 1, 'item_id' => $item->id, 'item_name' => $item->item_name,
            'line_type' => 'physical', 'order_qty' => $orderQty, 'cancelled_qty' => 0, 'shipped_qty' => 0,
            'unit_id' => $unit->id, 'unit_code_snapshot' => $unit->unit_code, 'unit_name_snapshot' => $unit->unit_name,
            'fulfillment_factor_snapshot' => 1, 'item_base_unit_id' => $unit->id,
            'item_base_unit_name_snapshot' => $unit->unit_name, 'item_base_required_qty' => $orderQty,
        ]);
        $user = (object) ['legacy_id' => 10001, 'username' => 'sales-lock-tester', 'nickname' => '锁库存测试员'];
        return [$order, $line, $balance, $user];
    }
}
