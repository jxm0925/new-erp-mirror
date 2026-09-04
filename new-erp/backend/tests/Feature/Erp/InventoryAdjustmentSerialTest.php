<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventorySerial;
use App\Models\Erp\Item;
use App\Models\Erp\Location;
use App\Models\Erp\Unit;
use App\Models\Erp\Warehouse;
use App\Services\Erp\InventoryAdjustmentApplicationService;
use App\Services\Erp\InventoryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryAdjustmentSerialTest extends TestCase
{
    use DatabaseTransactions;

    private string $serialPrefix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->serialPrefix = 'T'.strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
    }

    public function test_required_serial_increase_and_decrease_change_quantity_and_serial_atomically(): void
    {
        [$required, , , $balance] = $this->fixture();
        $adjustments = app(InventoryAdjustmentApplicationService::class);
        $inventory = app(InventoryService::class);

        $increase = $adjustments->save($this->payload($required, $balance, 2, [
            ['serial_no' => $this->sn('ADJ-0001'), 'source' => 'system'],
            ['serial_no' => $this->sn('ADJ-0002'), 'source' => 'manual'],
        ]));
        $adjustments->submit($increase->id);
        $inventory->postAdjustment($increase->id);

        $this->assertSame(7.0, (float) $balance->fresh()->quantity_on_hand);
        $this->assertDatabaseHas('erp_inventory_serials', ['serial_no' => $this->sn('ADJ-0001'), 'serial_status' => 'available', 'inventory_balance_id' => $balance->id]);
        $this->assertDatabaseHas('erp_inventory_serial_events', ['event_type' => 'manual_adjustment_in', 'to_status' => 'available']);

        $decrease = $adjustments->save($this->payload($required, $balance->fresh(), -1, [
            ['serial_no' => $this->sn('ADJ-0001'), 'source' => 'manual'],
        ]));
        $adjustments->submit($decrease->id);
        $inventory->postAdjustment($decrease->id);

        $this->assertSame(6.0, (float) $balance->fresh()->quantity_on_hand);
        $this->assertDatabaseHas('erp_inventory_serials', ['serial_no' => $this->sn('ADJ-0001'), 'serial_status' => 'adjusted_out']);
        $this->assertDatabaseHas('erp_inventory_serial_events', ['event_type' => 'manual_adjustment_out', 'to_status' => 'adjusted_out']);
    }

    public function test_required_serial_rejects_missing_duplicate_existing_and_wrong_locator_numbers(): void
    {
        [$required, , , $balance, $otherBalance] = $this->fixture();
        InventorySerial::create($this->serialAttributes($required, $otherBalance, $this->sn('OTHER-LOCATION')));
        $service = app(InventoryAdjustmentApplicationService::class);

        $this->assertRejected(fn () => $service->save($this->payload($required, $balance, 1, [])), 'serial_entries');
        $this->assertRejected(fn () => $service->save($this->payload($required, $balance, 2, [
            ['serial_no' => $this->sn('DUPLICATE'), 'source' => 'manual'],
            ['serial_no' => $this->sn('DUPLICATE'), 'source' => 'manual'],
        ])), 'serial_entries');
        $this->assertRejected(fn () => $service->save($this->payload($required, $balance, 1, [
            ['serial_no' => $this->sn('OTHER-LOCATION'), 'source' => 'manual'],
        ])), 'serial_entries');
        $this->assertRejected(fn () => $service->save($this->payload($required, $balance, -1, [
            ['serial_no' => $this->sn('OTHER-LOCATION'), 'source' => 'manual'],
        ])), 'serial_entries');
    }

    public function test_optional_serial_decrease_only_uses_the_unnumbered_remainder_when_no_number_is_selected(): void
    {
        [, $optional, , , , $optionalBalance] = $this->fixture();
        InventorySerial::create($this->serialAttributes($optional, $optionalBalance, $this->sn('OPTIONAL-0001')));
        InventorySerial::create($this->serialAttributes($optional, $optionalBalance, $this->sn('OPTIONAL-0002')));
        $service = app(InventoryAdjustmentApplicationService::class);

        $draft = $service->save($this->payload($optional, $optionalBalance, -3, []));
        $this->assertSame('draft', $draft->adjustment_status);

        // 5 件库存中 2 件有编号，未编号余量只有 3；减少 4 件时必须明确选择至少一个编号。
        $this->assertRejected(fn () => $service->save($this->payload($optional, $optionalBalance, -4, [])), 'serial_entries');

        $mixed = $service->save($this->payload($optional, $optionalBalance, -4, [
            ['serial_no' => $this->sn('OPTIONAL-0001'), 'source' => 'manual'],
        ]));
        $this->assertCount(1, $mixed->items->first()->serials);
    }

    public function test_same_available_serial_cannot_be_reserved_by_two_open_adjustments(): void
    {
        [$required, , , $balance] = $this->fixture();
        InventorySerial::create($this->serialAttributes($required, $balance, $this->sn('OPEN-RESERVATION')));
        $service = app(InventoryAdjustmentApplicationService::class);

        $first = $service->save($this->payload($required, $balance, -1, [
            ['serial_no' => $this->sn('OPEN-RESERVATION'), 'source' => 'manual'],
        ]));
        $this->assertSame('draft', $first->adjustment_status);

        $this->assertRejected(fn () => $service->save($this->payload($required, $balance, -1, [
            ['serial_no' => $this->sn('OPEN-RESERVATION'), 'source' => 'manual'],
        ])), 'serial_entries');
    }

    public function test_non_serial_item_still_posts_normally_and_rejects_accidental_numbers(): void
    {
        [, , $none, , , , $noneBalance] = $this->fixture();
        $adjustments = app(InventoryAdjustmentApplicationService::class);

        $this->assertRejected(fn () => $adjustments->save($this->payload($none, $noneBalance, 1, [
            ['serial_no' => 'SHOULD-NOT-EXIST', 'source' => 'manual'],
        ])), 'serial_entries');

        $adjustment = $adjustments->save($this->payload($none, $noneBalance, -1, []));
        $adjustments->submit($adjustment->id);
        app(InventoryService::class)->postAdjustment($adjustment->id);

        $this->assertSame(4.0, (float) $noneBalance->fresh()->quantity_on_hand);
        $this->assertDatabaseHas('erp_inventory_transactions', [
            'source_type' => 'inventory_adjustment',
            'source_id' => $adjustment->id,
            'transaction_type' => 'manual_adjustment',
        ]);
    }

    private function fixture(): array
    {
        $unit = Unit::create(['unit_code' => 'PCS-ADJ', 'unit_name' => '件', 'unit_type' => 'count', 'decimal_places' => 0, 'is_base' => true, 'status' => 'enabled']);
        $warehouse = Warehouse::create(['warehouse_code' => 'WH-ADJ', 'warehouse_name' => '调整测试仓', 'status' => 'enabled']);
        $location = Location::create(['location_code' => 'LOC-ADJ-A', 'location_name' => 'A库位', 'warehouse_id' => $warehouse->id, 'status' => 'enabled']);
        $otherLocation = Location::create(['location_code' => 'LOC-ADJ-B', 'location_name' => 'B库位', 'warehouse_id' => $warehouse->id, 'status' => 'enabled']);

        $required = Item::create(['item_code' => 'ITEM-ADJ-REQ', 'item_name' => '必管序列号设备', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_serial_managed' => true, 'serial_tracking_mode' => 'required', 'status' => 'enabled']);
        $optional = Item::create(['item_code' => 'ITEM-ADJ-OPT', 'item_name' => '可选序列号部件', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_serial_managed' => false, 'serial_tracking_mode' => 'optional', 'status' => 'enabled']);
        $none = Item::create(['item_code' => 'ITEM-ADJ-NONE', 'item_name' => '非序列号耗材', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_serial_managed' => false, 'serial_tracking_mode' => 'none', 'status' => 'enabled']);

        $balance = $this->balance($required, $warehouse, $location, $unit, 'BAT-REQ');
        $otherBalance = $this->balance($required, $warehouse, $otherLocation, $unit, 'BAT-REQ');
        $optionalBalance = $this->balance($optional, $warehouse, $location, $unit, 'BAT-OPT');
        $noneBalance = $this->balance($none, $warehouse, $location, $unit, 'BAT-NONE');

        return [$required, $optional, $none, $balance, $otherBalance, $optionalBalance, $noneBalance];
    }

    private function balance(Item $item, Warehouse $warehouse, Location $location, Unit $unit, string $batch): InventoryBalance
    {
        return InventoryBalance::create([
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'location_id' => $location->id,
            'batch_no' => $batch,
            'unit_id' => $unit->id,
            'quantity_on_hand' => 5,
            'quantity_available' => 5,
            'average_unit_cost' => 100,
            'inventory_value' => 500,
        ]);
    }

    private function payload(Item $item, InventoryBalance $balance, float $quantity, array $serials): array
    {
        return [
            'reason' => 'stocktaking_difference',
            'remark' => '序列号库存调整自动化验收',
            'items' => [[
                'item_id' => $item->id,
                'warehouse_id' => $balance->warehouse_id,
                'location_id' => $balance->location_id,
                'batch_no' => $balance->batch_no,
                'unit_id' => $balance->unit_id,
                'change_qty' => $quantity,
                'serial_entries' => $serials,
            ]],
        ];
    }

    private function serialAttributes(Item $item, InventoryBalance $balance, string $serialNo): array
    {
        return [
            'serial_no' => $serialNo,
            'inventory_balance_id' => $balance->id,
            'item_id' => $item->id,
            'warehouse_id' => $balance->warehouse_id,
            'location_id' => $balance->location_id,
            'batch_no' => $balance->batch_no,
            'origin_type' => 'purchase',
            'number_source' => 'supplier',
            'serial_status' => 'available',
        ];
    }

    private function sn(string $suffix): string
    {
        return "SN-{$this->serialPrefix}-{$suffix}";
    }

    private function assertRejected(callable $callback, string $field): void
    {
        try {
            $callback();
            $this->fail("Expected validation error for {$field}.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }
}
