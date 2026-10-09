<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\{InventoryBalance, InventoryLocationBalance, Item, ItemCategory, Location, PurchaseRequest, PurchaseRequestItem, Unit, Warehouse};
use App\Services\Erp\AuthContextService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WarehouseManagementScopeMasterTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;
    private Unit $unit;
    private array $permissions = ['master.warehouse.create', 'master.warehouse.edit', 'master.warehouse.view',
        'master.location.view', 'master.item.create', 'master.item.edit'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'PWSC-'.Str::upper(Str::random(8));
        $this->unit = Unit::create(['unit_code' => $this->code(), 'unit_name' => $this->prefix.'件', 'unit_type' => 'quantity',
            'status' => 'enabled', 'allow_decimal' => false, 'decimal_places' => 0, 'is_legacy' => false]);
        $actor = (object) ['legacy_id' => 99, 'username' => 'warehouse-scope-test'];
        $auth = \Mockery::mock(AuthContextService::class)->makePartial();
        $auth->shouldReceive('currentUser')->andReturn($actor);
        $auth->shouldReceive('isSuperAdmin')->andReturnFalse();
        $auth->shouldReceive('permissionCodes')->andReturnUsing(fn () => $this->permissions);
        $this->app->instance(AuthContextService::class, $auth);
    }

    public function test_warehouse_scope_is_explicit_audited_and_cannot_be_switched(): void
    {
        $data = $this->postJson('/api/v1/erp/master/warehouses', $this->warehousePayload('office'))
            ->assertCreated()->assertJsonPath('data.management_scope', 'office')->json('data');
        $before = Warehouse::findOrFail($data['id'])->getRawOriginal();
        $this->putJson('/api/v1/erp/master/warehouses/'.$data['id'], [...$data, 'management_scope' => 'factory'])
            ->assertUnprocessable()->assertJsonValidationErrors('management_scope');
        $this->assertSame($before, Warehouse::findOrFail($data['id'])->getRawOriginal());
        $this->assertDatabaseHas('erp_operation_logs', ['module' => 'warehouse', 'action' => 'set_management_scope', 'target_id' => $data['id'], 'operator_id' => 99]);
        $this->postJson('/api/v1/erp/master/warehouses', $this->warehousePayload(''))
            ->assertUnprocessable()->assertJsonValidationErrors('management_scope');
    }

    public function test_warehouse_and_location_scope_filtering_happens_before_pagination(): void
    {
        $factory = $this->warehouse('factory'); $office = $this->warehouse('office');
        $officeLocation = $this->location($office); $this->location($factory);
        $this->getJson('/api/v1/erp/master/warehouses?keyword='.$this->prefix.'&management_scope=office&per_page=1')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $office->id);
        $this->getJson('/api/v1/erp/master/locations?keyword='.$this->prefix.'&management_scope=office&per_page=1')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $officeLocation->id);
        $this->getJson('/api/v1/erp/master/warehouses/'.$factory->id.'?management_scope=office')->assertUnprocessable();
        $this->getJson('/api/v1/erp/master/locations/'.$officeLocation->id.'?management_scope=factory')->assertUnprocessable();
        $this->getJson('/api/v1/erp/master/warehouses?management_scope=bad')->assertUnprocessable();
    }

    public function test_unknown_warehouse_can_only_be_classified_to_its_real_live_stock_scope(): void
    {
        $warehouse = $this->warehouse(null); $item = $this->item('factory');
        $balance = $this->balance($item, $warehouse); $before = $balance->refresh()->getRawOriginal();
        $payload = [...$this->warehousePayload('office'), 'warehouse_code' => $warehouse->warehouse_code];
        $this->putJson('/api/v1/erp/master/warehouses/'.$warehouse->id, $payload)->assertUnprocessable();
        $this->putJson('/api/v1/erp/master/warehouses/'.$warehouse->id, [...$payload, 'management_scope' => 'factory'])
            ->assertOk()->assertJsonPath('data.management_scope', 'factory');
        $this->assertSame($before, $balance->fresh()->getRawOriginal());
    }

    public function test_mixed_historical_warehouse_cannot_be_relabelled_to_hide_the_other_stock(): void
    {
        $warehouse = $this->warehouse(null);
        $this->balance($this->item('factory'), $warehouse); $this->balance($this->item('office'), $warehouse);
        foreach (['factory', 'office'] as $scope) {
            $this->putJson('/api/v1/erp/master/warehouses/'.$warehouse->id,
                [...$this->warehousePayload($scope), 'warehouse_code' => $warehouse->warehouse_code])->assertUnprocessable();
        }
        $this->assertNull($warehouse->fresh()->management_scope);
    }

    public function test_item_default_warehouse_must_match_the_item_scope(): void
    {
        $office = $this->item('office'); $factoryWarehouse = $this->warehouse('factory'); $officeWarehouse = $this->warehouse('office');
        $payload = [...$this->itemPayload($office), 'default_warehouse_id' => $factoryWarehouse->id];
        $this->putJson('/api/v1/erp/master/items/'.$office->id, $payload)->assertUnprocessable()->assertJsonValidationErrors('default_warehouse_id');
        $this->putJson('/api/v1/erp/master/items/'.$office->id, [...$payload, 'default_warehouse_id' => $officeWarehouse->id])
            ->assertOk()->assertJsonPath('data.default_warehouse_id', $officeWarehouse->id);
    }

    public function test_live_stock_blocks_item_reclassification_without_changing_stock_facts(): void
    {
        $item = $this->item('factory'); $balance = $this->balance($item, $this->warehouse('factory'));
        $before = $balance->refresh()->getRawOriginal(); $itemBefore = $item->refresh()->getRawOriginal();
        $office = $this->item('office');
        $this->putJson('/api/v1/erp/master/items/'.$item->id, [...$this->itemPayload($item),
            'management_scope' => 'office', 'item_type' => 'office_consumable', 'category_id' => $office->category_id])
            ->assertUnprocessable()->assertJsonValidationErrors('management_scope');
        $this->assertSame($before, $balance->fresh()->getRawOriginal());
        $this->assertSame($itemBefore, $item->fresh()->getRawOriginal());
    }

    public function test_purchase_reference_blocks_item_reclassification(): void
    {
        $item = $this->item('factory'); $office = $this->item('office');
        $request = PurchaseRequest::create(['request_no' => $this->code(), 'item_id' => $item->id,
            'request_qty' => 1, 'management_scope' => 'factory']);
        PurchaseRequestItem::create(['request_id' => $request->id, 'item_id' => $item->id,
            'unit_id' => $this->unit->id, 'request_qty' => 1]);
        $this->putJson('/api/v1/erp/master/items/'.$item->id, [...$this->itemPayload($item),
            'management_scope' => 'office', 'item_type' => 'office_consumable', 'category_id' => $office->category_id])
            ->assertUnprocessable()->assertJsonValidationErrors('management_scope');
        $this->assertSame('factory', $item->fresh()->management_scope);
    }

    public function test_location_only_stock_cannot_be_hidden_by_item_or_warehouse_reclassification(): void
    {
        $warehouse = $this->warehouse(null); $item = $this->item('factory'); $office = $this->item('office');
        $balance = InventoryLocationBalance::create(['item_id' => $item->id, 'warehouse_id' => $warehouse->id,
            'location_id' => $this->location($warehouse)->id, 'unit_id' => $this->unit->id,
            'quantity_on_hand' => 3, 'quantity_available' => 3]);
        $before = $balance->fresh()->getRawOriginal();
        $this->putJson('/api/v1/erp/master/warehouses/'.$warehouse->id,
            [...$this->warehousePayload('office'), 'warehouse_code' => $warehouse->warehouse_code])->assertUnprocessable();
        $this->putJson('/api/v1/erp/master/items/'.$item->id, [...$this->itemPayload($item),
            'management_scope' => 'office', 'item_type' => 'office_consumable', 'category_id' => $office->category_id])
            ->assertUnprocessable()->assertJsonValidationErrors('management_scope');
        $this->assertNull($warehouse->fresh()->management_scope);
        $this->assertSame('factory', $item->fresh()->management_scope);
        $this->assertSame($before, $balance->fresh()->getRawOriginal());
    }

    public function test_scope_field_does_not_grant_warehouse_write_permission(): void
    {
        $this->permissions = ['master.warehouse.view'];
        $this->postJson('/api/v1/erp/master/warehouses', $this->warehousePayload('office'))->assertForbidden();
        $this->assertDatabaseMissing('erp_warehouses', ['warehouse_name' => $this->prefix.'仓库']);
    }

    private function warehousePayload(string $scope): array
    {
        return ['warehouse_code' => $this->code(), 'warehouse_name' => $this->prefix.'仓库',
            'warehouse_type' => 'general', 'management_scope' => $scope, 'status' => 'enabled'];
    }
    private function warehouse(?string $scope): Warehouse { return Warehouse::create([...$this->warehousePayload($scope ?? 'factory'), 'management_scope' => $scope])->fresh(); }
    private function location(Warehouse $warehouse): Location { return Location::create(['location_code' => $this->code(), 'location_name' => $this->prefix.'库位', 'warehouse_id' => $warehouse->id, 'status' => 'enabled']); }
    private function item(string $scope): Item
    {
        $category = ItemCategory::create(['category_code' => $this->code(), 'category_name' => $this->prefix.'分类',
            'category_type' => 'item', 'management_scope' => $scope, 'status' => 'enabled']);
        return Item::create(['item_code' => $this->code(), 'item_name' => $this->prefix.'物料', 'item_type' => $scope === 'office' ? 'office_consumable' : 'raw_material',
            'management_scope' => $scope, 'category_id' => $category->id, 'unit_id' => $this->unit->id,
            'is_purchase_item' => true, 'is_stock_item' => true, 'is_production_item' => false, 'status' => 'enabled', 'cost_method' => 'weighted_average']);
    }
    private function balance(Item $item, Warehouse $warehouse): InventoryBalance
    {
        return InventoryBalance::create(['item_id' => $item->id, 'warehouse_id' => $warehouse->id,
            'location_id' => $this->location($warehouse)->id, 'unit_id' => $this->unit->id, 'batch_no' => $this->code(),
            'quantity_on_hand' => 7, 'quantity_available' => 7, 'inventory_value' => 21]);
    }
    private function itemPayload(Item $item): array
    {
        return ['item_code' => $item->item_code, 'item_name' => $item->item_name, 'management_scope' => $item->management_scope,
            'item_type' => $item->item_type, 'category_id' => $item->category_id, 'unit_id' => $item->unit_id,
            'is_purchase_item' => true, 'is_stock_item' => true, 'is_production_item' => false, 'status' => 'enabled', 'cost_method' => 'weighted_average'];
    }
    private function code(): string { return $this->prefix.'-'.Str::upper(Str::random(8)); }
}
