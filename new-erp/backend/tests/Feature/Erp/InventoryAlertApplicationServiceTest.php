<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\InventoryAlert;
use App\Models\Erp\InventoryAlertHistory;
use App\Models\Erp\InventoryBalance;
use App\Models\Erp\Item;
use App\Models\Erp\Location;
use App\Models\Erp\Unit;
use App\Models\Erp\Warehouse;
use App\Services\Erp\InventoryAlertApplicationService;
use App\Services\Erp\RbacBootstrapService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryAlertApplicationServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_available_stock_state_machine_deduplicates_same_severity_and_uses_locked_quantity(): void
    {
        $suffix = strtoupper(substr((string) Str::ulid(), -8));
        $unit = Unit::create(['unit_code' => "U-ALERT-{$suffix}", 'unit_name' => '件', 'unit_type' => 'count', 'decimal_places' => 0, 'is_base' => true, 'status' => 'enabled']);
        $warehouse = Warehouse::create(['warehouse_code' => "WH-ALERT-{$suffix}", 'warehouse_name' => '库存预警测试仓', 'status' => 'enabled']);
        $location = Location::create(['location_code' => "LOC-ALERT-{$suffix}", 'location_name' => '库存预警测试库位', 'warehouse_id' => $warehouse->id, 'status' => 'enabled']);
        $item = Item::create(['item_code' => "ITEM-ALERT-{$suffix}", 'item_name' => 'TEST-X-ALERT', 'item_type' => 'raw_material', 'unit_id' => $unit->id, 'is_stock_item' => true, 'status' => 'enabled']);
        $balance = InventoryBalance::create([
            'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id,
            'batch_no' => "BAT-ALERT-{$suffix}", 'unit_id' => $unit->id,
            'quantity_on_hand' => 25, 'quantity_available' => 25, 'quantity_locked' => 0,
            'quantity_defective' => 0, 'quantity_pending' => 0, 'average_unit_cost' => 1, 'inventory_value' => 25,
        ]);

        $service = app(InventoryAlertApplicationService::class);
        $service->savePolicy($item->id, ['min_stock' => 10, 'safety_stock' => 20, 'max_stock' => 100, 'suggested_replenishment_qty' => 50], 1, true);

        $this->assertSame('normal', InventoryAlert::where('item_id', $item->id)->value('alert_status'));
        $this->assertFalse((bool) InventoryAlert::where('item_id', $item->id)->value('is_active'));

        $balance->update(['quantity_on_hand' => 18, 'quantity_available' => 18]);
        $service->recalculateForItemWarehouse($item->id, $warehouse->id, 'test_warning');
        $alert = InventoryAlert::where('item_id', $item->id)->firstOrFail();
        $this->assertSame('low_stock', $alert->alert_status);
        $this->assertSame('warning', $alert->severity);
        $historyAfterWarning = InventoryAlertHistory::where('alert_id', $alert->id)->count();

        $balance->update(['quantity_on_hand' => 17, 'quantity_available' => 17]);
        $service->recalculateForItemWarehouse($item->id, $warehouse->id, 'test_same_warning');
        $this->assertSame($historyAfterWarning, InventoryAlertHistory::where('alert_id', $alert->id)->count());

        $balance->update(['quantity_on_hand' => 8, 'quantity_available' => 8]);
        $service->recalculateForItemWarehouse($item->id, $warehouse->id, 'test_critical');
        $this->assertSame('critical', $alert->fresh()->severity);
        $this->assertSame($historyAfterWarning + 1, InventoryAlertHistory::where('alert_id', $alert->id)->count());

        $balance->update(['quantity_on_hand' => 0, 'quantity_available' => 0]);
        $service->recalculateForItemWarehouse($item->id, $warehouse->id, 'test_out');
        $this->assertSame('out_of_stock', $alert->fresh()->alert_status);

        $balance->update(['quantity_on_hand' => 50, 'quantity_available' => 50, 'quantity_locked' => 45]);
        $service->recalculateForItemWarehouse($item->id, $warehouse->id, 'test_locked');
        $this->assertSame(5.0, (float) $alert->fresh()->available_qty);
        $this->assertSame('low_stock', $alert->fresh()->alert_status);
        $this->assertSame('critical', $alert->fresh()->severity);

        $balance->update(['quantity_locked' => 0, 'quantity_available' => 50]);
        $service->recalculateForItemWarehouse($item->id, $warehouse->id, 'test_recovery');
        $this->assertSame('normal', $alert->fresh()->alert_status);
        $this->assertFalse((bool) $alert->fresh()->is_active);
        $this->assertNotNull($alert->fresh()->resolved_at);

        // A warehouse can have no balance row after stock reaches zero. Re-enabling
        // the rule must still re-evaluate its persisted alert scope rather than
        // leaving an old normal record behind.
        $balance->delete();
        $service->disablePolicy($item->id, null, 1);
        $service->savePolicy($item->id, ['min_stock' => 10, 'safety_stock' => 20, 'max_stock' => 100, 'suggested_replenishment_qty' => 50], 1, true);
        $this->assertSame('out_of_stock', $alert->fresh()->alert_status);
        $this->assertTrue((bool) $alert->fresh()->is_active);

        // The safety-stock boundary is inclusive: available = safety remains
        // low-stock/warning.  It must not regress to a normal or critical state.
        $balance = InventoryBalance::create([
            'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id,
            'batch_no' => "BAT-SAFETY-{$suffix}", 'unit_id' => $unit->id,
            'quantity_on_hand' => 300, 'quantity_available' => 300, 'quantity_locked' => 0,
            'quantity_defective' => 0, 'quantity_pending' => 0, 'average_unit_cost' => 1, 'inventory_value' => 300,
        ]);
        $service->savePolicy($item->id, ['min_stock' => 200, 'safety_stock' => 300, 'max_stock' => 1000, 'suggested_replenishment_qty' => 500], 1, true);
        $service->recalculateForItemWarehouse($item->id, $warehouse->id, 'test_safety_boundary');
        $this->assertSame(300.0, (float) $alert->fresh()->available_qty);
        $this->assertSame('low_stock', $alert->fresh()->alert_status);
        $this->assertSame('warning', $alert->fresh()->severity);
    }

    public function test_private_inventory_alert_channel_requires_the_alert_view_permission(): void
    {
        $suffix = strtoupper(substr((string) Str::ulid(), -8));
        $permittedLegacyId = random_int(8_000_000, 8_999_999);
        $deniedLegacyId = random_int(9_000_000, 9_999_999);
        $now = now();

        app(RbacBootstrapService::class)->bootstrap();

        foreach ([[$permittedLegacyId, 'alert-viewer'], [$deniedLegacyId, 'alert-denied']] as [$legacyId, $username]) {
            DB::table('erp_legacy_admin_users')->insert([
                'legacy_id' => $legacyId,
                'username' => $username.'-'.$suffix,
                'status' => 'normal',
                'auth_group_names' => json_encode([]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $roleId = DB::table('erp_rbac_roles')->insertGetId([
            'code' => 'alert-viewer-'.$suffix,
            'name' => 'Inventory alert viewer '.$suffix,
            'data_scope' => 'self',
            'enabled' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $permissionId = DB::table('erp_rbac_permissions')->where('code', 'inventory.alert.view')->value('id');
        $this->assertNotNull($permissionId, 'Inventory-alert viewing permission must be available to RBAC before channel authorization.');
        DB::table('erp_rbac_user_roles')->insert(['user_legacy_id' => $permittedLegacyId, 'role_id' => $roleId]);
        DB::table('erp_rbac_role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);

        $channel = Broadcast::connection()->getChannels()->get('inventory-alerts');
        $this->assertNotNull($channel, 'The private inventory-alerts channel must be registered.');
        $this->assertTrue($channel(DB::table('erp_legacy_admin_users')->where('legacy_id', $permittedLegacyId)->first()));
        $this->assertFalse($channel(DB::table('erp_legacy_admin_users')->where('legacy_id', $deniedLegacyId)->first()));
    }
}
