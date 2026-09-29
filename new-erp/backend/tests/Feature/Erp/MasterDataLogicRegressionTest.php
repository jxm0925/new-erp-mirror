<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\{ImportBatch, ImportRow, InventoryBalance, Item, ItemCategory, Location, PaymentMethod, Product, Sku, Unit, Warehouse};
use App\Services\Erp\AuthContextService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MasterDataLogicRegressionTest extends TestCase
{
    use DatabaseTransactions;

    private array $permissions = [];
    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = '逻辑回归'.Str::random(10);
        $actor = (object) ['legacy_id' => 1, 'username' => 'master-regression', 'nickname' => '主数据回归'];
        $auth = \Mockery::mock(AuthContextService::class)->makePartial();
        $auth->shouldReceive('currentUser')->andReturn($actor);
        $auth->shouldReceive('isSuperAdmin')->andReturnFalse();
        $auth->shouldReceive('permissionCodes')->andReturnUsing(fn () => $this->permissions);
        $this->app->instance(AuthContextService::class, $auth);
    }

    public function test_write_permissions_cover_all_generic_entities_and_actual_item_save_paths(): void
    {
        foreach (['products', 'skus', 'items', 'units', 'categories', 'suppliers', 'warehouses', 'locations'] as $entity) {
            $base = '/api/v1/erp/master/'.$entity;
            $this->postJson($base, [])->assertForbidden();
            $this->putJson($base.'/999999999', [])->assertForbidden();
            $this->postJson($base.'/999999999/enable')->assertForbidden();
            $this->postJson($base.'/999999999/disable')->assertForbidden();
            $this->deleteJson($base.'/999999999')->assertForbidden();
        }
        foreach (['items/integrated-form', 'items/999999999/material-policy/activate', 'items/999999999/purchase-conversions',
            'items/999999999/purchase-conversions/999999999/disable', 'products/image-upload', 'skus/image-upload', 'trade-platforms'] as $path) {
            $this->postJson('/api/v1/erp/master/'.$path, [])->assertForbidden();
        }
        foreach (['items/999999999/integrated-form', 'items/999999999/material-policy/draft', 'items/999999999/purchase-conversions/999999999'] as $path) {
            $this->putJson('/api/v1/erp/master/'.$path, [])->assertForbidden();
        }
        // 菜单权限本身不能写；新增权限也不能用来修改已存在档案。
        $this->permissions = ['master.product', 'master.base_archive'];
        $this->postJson('/api/v1/erp/master/products', [])->assertForbidden();
        $this->postJson('/api/v1/erp/master/trade-platforms', [])->assertForbidden();
        $this->permissions = ['master.product.create'];
        $this->putJson('/api/v1/erp/master/products/999999999', [])->assertForbidden();
    }

    public function test_product_draft_is_saved_inactive_and_cannot_deactivate_enabled_child_skus(): void
    {
        $this->permissions = ['master.product.create', 'master.product.edit'];
        $unit = $this->unit();
        $payload = ['product_code' => $this->code(), 'product_name' => $this->prefix, 'product_type' => 'standard', 'unit_id' => $unit->id, 'status' => 'draft'];
        $id = $this->postJson('/api/v1/erp/master/products', $payload)->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
        $this->getJson('/api/v1/erp/master/products?order_available=1&keyword='.$this->prefix)->assertOk()->assertJsonPath('total', 0);
        $this->postJson('/api/v1/erp/master/products/'.$id.'/enable')->assertOk()->assertJsonPath('data.status', 'enabled');
        Sku::create(['product_id' => $id, 'sku_code' => $this->code(), 'sku_name' => $this->prefix, 'order_line_type' => 'service', 'status' => 'enabled']);
        $this->putJson('/api/v1/erp/master/products/'.$id, $payload)->assertUnprocessable();
        $this->assertDatabaseHas('erp_products', ['id' => $id, 'status' => 'enabled']);
    }

    public function test_sku_type_spec_search_and_missing_relation_filter_use_real_fields(): void
    {
        $product = $this->product();
        foreach (['physical', 'service', 'no_delivery'] as $type) {
            Sku::create(['product_id' => $product->id, 'sku_code' => $this->code(), 'sku_name' => $type,
                'spec_text' => $this->prefix.'规格', 'order_line_type' => $type, 'status' => 'draft']);
        }
        $url = '/api/v1/erp/master/skus?keyword='.$this->prefix.'&include_stats=1';
        $this->getJson($url.'&order_line_type=service')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.order_line_type', 'service')->assertJsonPath('stats.missing_item', 0);
        $this->getJson($url.'&missing_default_item=1')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.order_line_type', 'physical')->assertJsonPath('stats.missing_item', 1);
        $this->getJson($url.'&order_line_type=unknown')->assertUnprocessable();
    }

    public function test_product_and_sku_matrix_roll_back_together_when_last_number_is_invalid(): void
    {
        $this->permissions = ['master.product.create', 'master.sku.create'];
        $unit = $this->unit();
        $productCode = $this->code(); $firstCode = $this->code(); $secondCode = $this->code();
        $payload = ['product_code' => $productCode, 'product_name' => $this->prefix, 'product_type' => 'standard', 'unit_id' => $unit->id, 'status' => 'draft',
            'sku_matrix' => [
                ['sku_code' => $firstCode, 'sku_name' => '红', 'spec_text' => '红 / 大', 'sale_price' => 10],
                ['sku_code' => $secondCode, 'sku_name' => '蓝', 'spec_text' => '蓝 / 大', 'sale_price' => 12, 'reservation_token' => (string) Str::uuid()],
            ]];
        $this->postJson('/api/v1/erp/master/products', $payload)->assertUnprocessable();
        $this->assertDatabaseMissing('erp_products', ['product_code' => $productCode]);
        $this->assertDatabaseMissing('erp_skus', ['sku_code' => $firstCode]);
        $this->assertDatabaseMissing('erp_skus', ['sku_code' => $secondCode]);
        unset($payload['sku_matrix'][1]['reservation_token']);
        $productId = $this->postJson('/api/v1/erp/master/products', $payload)->assertCreated()->json('data.id');
        $this->assertSame(2, Sku::where('product_id', $productId)->where('status', 'draft')->count());
        $this->assertSame(2, Sku::where('product_id', $productId)->where('sales_unit_id', $unit->id)->count());
    }

    public function test_existing_product_matrix_checks_all_specs_and_requires_sku_create_permission(): void
    {
        $product = $this->product(['unit_id' => $this->unit()->id]);
        $matrix = [['sku_code' => $this->code(), 'sku_name' => '红', 'spec_text' => '红 / 大', 'sale_price' => 10]];
        $url = '/api/v1/erp/master/products/'.$product->id.'/sku-matrix';
        $this->postJson($url, ['sku_matrix' => $matrix])->assertForbidden();
        $this->permissions = ['master.sku.create'];
        $this->postJson($url, ['sku_matrix' => $matrix])->assertCreated();
        $matrix[0]['sku_code'] = $this->code();
        $matrix[0]['spec_text'] = '红/大';
        $this->postJson($url, ['sku_matrix' => $matrix])->assertUnprocessable();
        $this->assertSame(1, $product->skus()->count());
        $this->assertDatabaseMissing('erp_skus', ['sku_code' => $matrix[0]['sku_code']]);
    }

    public function test_product_statistics_are_complete_and_follow_the_same_filters_on_every_page(): void
    {
        foreach (['enabled', 'disabled', 'draft'] as $status) {
            for ($i = 0; $i < 3; $i++) $this->product(['status' => $status]);
        }
        $url = '/api/v1/erp/master/products?keyword='.$this->prefix.'&include_stats=1&per_page=5';
        $first = $this->getJson($url)->assertOk()->assertJsonPath('total', 9)->assertJsonCount(5, 'data')
            ->assertJsonPath('stats.enabled', 3)->assertJsonPath('stats.disabled', 3)->assertJsonPath('stats.draft', 3)->json();
        $second = $this->getJson($url.'&page=2')->assertOk()->assertJsonCount(4, 'data')->json();
        $this->assertSame($first['stats'], $second['stats']);
        $this->assertEmpty(array_intersect(array_column($first['data'], 'id'), array_column($second['data'], 'id')));
        $this->getJson($url.'&status=enabled')->assertOk()->assertJsonPath('total', 3)->assertJsonPath('stats.disabled', 0);
    }

    public function test_item_cutting_and_unit_statistics_are_not_limited_to_the_page(): void
    {
        $unit = $this->unit();
        foreach (['none', 'sheet', 'length'] as $mode) {
            for ($i = 0; $i < 3; $i++) $this->item($unit, ['cutting_mode' => $mode]);
        }
        $this->getJson('/api/v1/erp/master/items?keyword='.$this->prefix.'&include_stats=1&per_page=5')
            ->assertOk()->assertJsonPath('total', 9)->assertJsonPath('stats.cutting', 6)->assertJsonCount(5, 'data');
        for ($i = 0; $i < 3; $i++) $this->unit(['unit_type' => 'length']);
        for ($i = 0; $i < 3; $i++) $this->unit();
        $this->getJson('/api/v1/erp/master/units?keyword='.$this->prefix.'&include_stats=1&per_page=5')
            ->assertOk()->assertJsonPath('total', 7)->assertJsonPath('stats.quantity', 4)->assertJsonPath('stats.measure', 3);
    }

    public function test_category_list_counts_and_leaf_flag_match_the_tree(): void
    {
        $this->permissions = ['item_category.view'];
        $parent = ItemCategory::create(['category_code' => $this->code(), 'category_name' => $this->prefix.'父', 'category_type' => 'item', 'status' => 'enabled']);
        $child = ItemCategory::create(['category_code' => $this->code(), 'category_name' => $this->prefix.'子', 'category_type' => 'item', 'parent_id' => $parent->id, 'status' => 'enabled']);
        $this->item($this->unit(), ['category_id' => $child->id]);
        $url = '/api/v1/erp/master/item-categories';
        $this->getJson($url.'?parent_id='.$parent->id)->assertOk()->assertJsonPath('data.0.direct_item_count', 1)
            ->assertJsonPath('data.0.direct_child_count', 0)->assertJsonPath('data.0.is_leaf', true);
        $this->getJson($url.'?keyword='.$this->prefix.'父')->assertOk()->assertJsonPath('data.0.direct_child_count', 1)
            ->assertJsonPath('data.0.is_leaf', false)->assertJsonPath('data.0.subtree_item_count', 1);
        $this->getJson($url.'/'.$child->id)->assertOk()->assertJsonPath('data.direct_item_count', 1);
    }

    public function test_warehouse_location_pages_are_scoped_and_profile_counts_cover_all_rows(): void
    {
        $first = $this->warehouse(); $target = $this->warehouse();
        $rows = [];
        for ($i = 0; $i < 101; $i++) $rows[] = [
            'warehouse_id' => $first->id, 'location_code' => $this->code(), 'location_name' => $this->prefix.$i,
            'area' => $i < 60 ? ' A ' : 'B', 'aisle' => '通道一', 'rack' => '货架一', 'status' => 'enabled',
            'standard_capacity' => 2, 'allow_mixed' => false, 'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('erp_locations')->insert($rows);
        $location = $this->location($target);
        $this->getJson('/api/v1/erp/master/locations?warehouse_id='.$target->id.'&include_stats=1')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $location->id)->assertJsonPath('warehouse_stats.total', 1);
        $url = '/api/v1/erp/master/locations?warehouse_id='.$first->id.'&include_stats=1&per_page=50';
        $this->getJson($url.'&page=3')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('total', 101)
            ->assertJsonPath('warehouse_stats.total', 101)->assertJsonPath('warehouse_stats.enabled', 101)
            ->assertJsonPath('warehouse_stats.area_count', 2)->assertJsonPath('warehouse_stats.capacity', '202.0000');
        $this->getJson($url.'&area=A')->assertOk()->assertJsonPath('total', 60)->assertJsonPath('warehouse_stats.total', 101);
        $this->getJson('/api/v1/erp/master/warehouses/'.$first->id.'?include_location_summary=1')->assertOk()
            ->assertJsonPath('locations_count', 101)->assertJsonPath('area_count', 2)->assertJsonMissingPath('locations');
    }

    public function test_inventory_quantity_summary_covers_more_than_one_page_and_keeps_units_separate(): void
    {
        $warehouse = $this->warehouse(); $location = $this->location($warehouse);
        $unit = $this->unit(); $item = $this->item($unit);
        $otherUnit = $this->unit(['unit_name' => '千克']); $otherItem = $this->item($otherUnit);
        for ($i = 0; $i < 101; $i++) InventoryBalance::create([
            'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'batch_no' => 'B'.$i,
            'unit_id' => $unit->id, 'quantity_on_hand' => '1.2500', 'quantity_available' => '1.2500', 'inventory_value' => 10,
        ]);
        InventoryBalance::create(['item_id' => $otherItem->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id,
            'batch_no' => 'OTHER', 'unit_id' => $otherUnit->id, 'quantity_on_hand' => '2.5000', 'inventory_value' => 20]);
        $data = $this->getJson('/api/v1/erp/inventory/balances?warehouse_id='.$warehouse->id.'&include_quantity_summary=1&per_page=5')
            ->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('stats.balance_line_count', 102)->assertJsonPath('stats.item_count', 2)->json();
        $quantities = collect($data['stats']['quantity_by_unit'])->keyBy('unit_id');
        $this->assertCount(2, $quantities);
        $this->assertSame('126.2500', $quantities[$unit->id]['quantity']);
        $this->assertSame('2.5000', $quantities[$otherUnit->id]['quantity']);
        $this->assertEquals(1030, $data['stats']['inventory_value']);
    }

    public function test_archive_statistics_count_all_filtered_payment_and_category_rows(): void
    {
        $this->permissions = ['finance.payment_method.view'];
        for ($i = 0; $i < 7; $i++) PaymentMethod::create(['method_code' => $this->code(), 'method_name' => $this->prefix.$i,
            'status' => $i < 6 ? 'enabled' : 'disabled', 'available_for_sales' => $i < 4, 'available_for_receipt' => true,
            'available_for_payment' => $i >= 4]);
        $this->getJson('/api/v1/erp/finance/payment-methods?keyword='.$this->prefix.'&per_page=5&include_stats=1')
            ->assertOk()->assertJsonPath('total', 7)->assertJsonPath('stats.enabled', 6)->assertJsonPath('stats.sales', 4)->assertJsonPath('stats.payment', 3);
        $root = ItemCategory::create(['category_code' => $this->code(), 'category_name' => $this->prefix, 'category_type' => 'product', 'status' => 'enabled']);
        for ($i = 0; $i < 6; $i++) ItemCategory::create(['category_code' => $this->code(), 'category_name' => $this->prefix.$i,
            'category_type' => 'product', 'status' => 'enabled', 'parent_id' => $root->id]);
        $this->getJson('/api/v1/erp/master/categories?category_type=product&keyword='.$this->prefix.'&per_page=5&include_stats=1')
            ->assertOk()->assertJsonPath('total', 7)->assertJsonPath('stats.root', 1)->assertJsonPath('stats.sub', 6);
    }

    public function test_import_row_pages_are_complete_and_error_download_is_authenticated_utf8(): void
    {
        $this->permissions = ['master.import.upload'];
        $batch = ImportBatch::create(['batch_no' => $this->code(), 'import_type' => 'Product', 'file_name' => '中文预检.csv',
            'stored_path' => 'not-used-by-this-test', 'status' => 'previewed', 'total_rows' => 105]);
        for ($i = 1; $i <= 105; $i++) ImportRow::create(['batch_id' => $batch->id, 'row_no' => $i + 1,
            'raw_data' => ['名称' => '中文'.$i], 'validation_status' => $i > 100 ? 'error' : 'valid', 'error_reason' => $i > 100 ? '字段缺失' : null]);
        $url = '/api/v1/erp/master/imports/'.$batch->id;
        $this->getJson($url.'/rows?per_page=50&page=3')->assertOk()->assertJsonPath('total', 105)->assertJsonCount(5, 'data')->assertJsonPath('data.0.row_no', 102);
        $this->getJson($url.'/rows?status=error&per_page=50')->assertOk()->assertJsonPath('total', 5);
        $response = $this->get($url.'/errors/export')->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('字段缺失', $csv);
        $this->assertStringContainsString('中文105', $csv);
        $this->permissions = [];
        $this->get($url.'/errors/export')->assertForbidden();
    }

    private function code(): string { return 'LOGIC-'.Str::upper(Str::random(18)); }
    private function unit(array $values = []): Unit
    {
        return Unit::create([...['unit_code' => $this->code(), 'unit_name' => $this->prefix, 'unit_type' => 'quantity', 'status' => 'enabled', 'is_legacy' => false], ...$values]);
    }
    private function product(array $values = []): Product
    {
        return Product::create([...['product_code' => $this->code(), 'product_name' => $this->prefix, 'product_type' => 'standard', 'status' => 'enabled'], ...$values]);
    }
    private function item(Unit $unit, array $values = []): Item
    {
        return Item::create([...['item_code' => $this->code(), 'item_name' => $this->prefix, 'item_type' => 'raw_material', 'unit_id' => $unit->id,
            'spec' => '304 板件1件＋管件2件／套', 'status' => 'enabled', 'cutting_mode' => 'none'], ...$values]);
    }
    private function warehouse(): Warehouse
    {
        return Warehouse::create(['warehouse_code' => $this->code(), 'warehouse_name' => $this->prefix, 'status' => 'enabled']);
    }
    private function location(Warehouse $warehouse): Location
    {
        return Location::create(['warehouse_id' => $warehouse->id, 'location_code' => $this->code(), 'location_name' => $this->prefix, 'status' => 'enabled']);
    }
}
