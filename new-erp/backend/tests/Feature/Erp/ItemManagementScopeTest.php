<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\{Bom, InventoryBalance, Item, ItemCategory, Product, Sku, Unit};
use App\Services\Erp\{AuthContextService, DocumentNumberService, ItemImportApplicationService, MasterDataApplicationService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Tests\TestCase;

class ItemManagementScopeTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;
    private Unit $unit;
    private array $permissions = ['master.item.view', 'master.item.create', 'master.item.edit', 'master.item.delete',
        'item_category.view', 'item_category.manage', 'master.import.upload', 'master.import.execute', 'master.import.delete'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'MSC-'.Str::upper(Str::random(8));
        $this->unit = Unit::create(['unit_code' => $this->code('U'), 'unit_name' => $this->code('单位'),
            'unit_type' => 'quantity', 'status' => 'enabled', 'is_legacy' => false, 'allow_decimal' => false, 'decimal_places' => 0]);
        $this->seed(\Database\Seeders\ErpDocumentNumberRuleSeeder::class);
        $user = (object) ['legacy_id' => 99, 'username' => 'scope_tester', 'nickname' => '范围测试员', 'auth_group_names' => '[]'];
        $this->mock(AuthContextService::class, function (MockInterface $mock) use ($user): void {
            $mock->shouldReceive('currentUser')->andReturn($user);
            $mock->shouldReceive('currentLegacyId')->andReturn(99);
            $mock->shouldReceive('isSuperAdmin')->andReturn(false);
            $mock->shouldReceive('permissionCodes')->andReturnUsing(fn () => $this->permissions);
        });
    }

    public function test_lists_and_statistics_keep_office_and_factory_separate_and_missing_scope_remains_compatible(): void
    {
        $factory = $this->item('factory', $this->category('factory'));
        $office = $this->item('office', $this->category('office'));
        $this->item('office', $office->category, ['item_type' => 'service', 'is_stock_item' => false]);
        $url = '/api/v1/erp/master/items?keyword='.$this->prefix.'&include_test_data=1&include_stats=1';
        $this->getJson($url.'&management_scope=factory')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $factory->id)->assertJsonPath('stats.stock_managed', 1);
        $this->getJson($url.'&management_scope=office')->assertOk()->assertJsonPath('total', 2)
            ->assertJsonPath('stats.stock_managed', 1);
        $this->getJson($url)->assertOk()->assertJsonPath('total', 3);
        foreach (['', 'all', 'unknown'] as $scope) $this->getJson($url.'&management_scope='.$scope)->assertUnprocessable();
    }

    public function test_an_explicit_scope_rejects_cross_scope_ids_for_detail_edit_and_status_actions(): void
    {
        $category = $this->category('office');
        $office = $this->item('office', $category);
        $base = '/api/v1/erp/master/items/'.$office->id;
        $this->getJson($base.'?management_scope=factory')->assertUnprocessable();
        $this->getJson($base.'/integrated-form?management_scope=factory')->assertUnprocessable();
        $this->putJson($base.'?management_scope=factory', $this->payload($office))->assertUnprocessable();
        $this->postJson($base.'/disable?management_scope=factory')->assertUnprocessable();
        $this->postJson($base.'/enable?management_scope=factory')->assertUnprocessable();
        $this->deleteJson($base.'?management_scope=factory')->assertUnprocessable();
        $this->getJson('/api/v1/erp/master/item-categories/'.$category->id.'?management_scope=factory')->assertNotFound();
        $this->getJson($base.'?management_scope=office')->assertOk()->assertJsonPath('id', $office->id);
        $this->assertSame('enabled', $office->fresh()->status);
    }

    public function test_category_hierarchy_inherits_scope_and_rejects_cross_scope_parent_or_scope_changes(): void
    {
        $factory = $this->category('factory');
        $office = $this->category('office');
        $child = $this->createCategoryViaApi($office->id, null)->assertCreated()->json('data');
        $this->assertSame('office', $child['management_scope']);
        $this->createCategoryViaApi($factory->id, 'office')->assertUnprocessable();
        $payload = ['category_code' => $child['category_code'], 'category_name' => $child['category_name'],
            'parent_id' => $factory->id, 'status' => 'enabled'];
        $this->putJson('/api/v1/erp/master/item-categories/'.$child['id'].'?management_scope=office', $payload)->assertUnprocessable();
        $payload['parent_id'] = $office->id;
        $payload['management_scope'] = 'factory';
        $this->putJson('/api/v1/erp/master/item-categories/'.$child['id'].'?management_scope=office', $payload)->assertUnprocessable();
        $this->getJson('/api/v1/erp/master/item-categories?keyword='.$this->prefix.'&management_scope=factory')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $factory->id);
        $tree = $this->getJson('/api/v1/erp/master/item-categories/tree?management_scope=office')->assertOk()->json('data');
        $this->assertContains($office->id, array_column($tree, 'id'));
        $this->assertNotContains($factory->id, array_column($tree, 'id'));
    }

    public function test_office_integrated_save_keeps_stock_custodian_and_asset_policy_independent(): void
    {
        $office = $this->item('office', $this->category('office'));
        $payload = $this->integratedPayload($office);
        $url = '/api/v1/erp/master/items/'.$office->id.'/integrated-form?management_scope=office';
        $this->putJson($url, $payload)->assertOk()->assertJsonPath('data.is_stock_item', true);
        $payload['policy'] = array_replace($payload['policy'], ['requires_custodian' => true, 'is_returnable' => true,
            'is_stock_managed' => false, 'inventory_management_mode' => 'none',
            'requires_capitalization' => true, 'post_purchase_action' => 'asset_acceptance',
            'consumption_confirmation_mode' => 'asset_acceptance', 'future_route' => 'asset']);
        $saved = $this->putJson($url, $payload)
            ->assertOk()->assertJsonPath('data.id', $office->id)->assertJsonPath('data.management_scope', 'office')->json('data');
        $this->assertFalse($saved['is_stock_item']);
        $policy = $office->materialPolicies()->where('status', 'draft')->latest('id')->firstOrFail();
        $this->assertTrue((bool) $policy->requires_custodian);
        $this->assertTrue((bool) $policy->requires_capitalization);
        $this->assertSame('asset', $policy->future_route);
        $this->assertFalse((bool) $office->fresh()->is_production_item);
    }

    public function test_office_creation_rejects_factory_categories_and_production_settings_without_partial_policy(): void
    {
        $office = $this->item('office', $this->category('office'));
        $factoryCategory = $this->category('factory');
        $beforePolicies = $office->materialPolicies()->count();
        foreach ([['category_id' => $factoryCategory->id], ['is_production_item' => true],
            ['cutting_mode' => 'sheet', 'material_management_mode' => 'physical'], ['management_scope' => '']] as $changes) {
            $payload = $this->integratedPayload($office);
            $payload['item'] = array_replace($payload['item'], $changes);
            $this->putJson('/api/v1/erp/master/items/'.$office->id.'/integrated-form?management_scope=office', $payload)->assertUnprocessable();
        }
        $this->assertSame($beforePolicies, $office->materialPolicies()->count());
        $this->assertSame($office->category_id, $office->fresh()->category_id);
        $this->assertSame('none', $office->fresh()->cutting_mode);
    }

    public function test_legacy_office_category_bridge_is_visible_and_only_an_unchanged_reference_survives(): void
    {
        $factory = $this->category('factory');
        $otherFactory = $this->category('factory');
        $office = $this->item('office', $factory);
        $url = '/api/v1/erp/master/items/'.$office->id.'?management_scope=office';
        $this->getJson($url)->assertOk()->assertJsonPath('category_scope_mismatch', true);
        $payload = $this->payload($office);
        $payload['item_name'] = '历史办公用品其他字段修改';
        $this->putJson($url, $payload)->assertOk()->assertJsonPath('data.category_scope_mismatch', true)
            ->assertJsonPath('data.category_id', $factory->id);
        $this->getJson('/api/v1/erp/master/item-categories/'.$factory->id.'?management_scope=factory')
            ->assertOk()->assertJsonPath('data.direct_item_count', 0)->assertJsonPath('data.subtree_item_count', 0);
        $payload['category_id'] = $otherFactory->id;
        $this->putJson($url, $payload)->assertUnprocessable();
        $officeCategory = $this->category('office');
        $payload['category_id'] = $officeCategory->id;
        $this->putJson($url, $payload)->assertOk()->assertJsonPath('data.category_scope_mismatch', false);
        $this->getJson('/api/v1/erp/master/item-categories/'.$factory->id.'?management_scope=factory')
            ->assertOk()->assertJsonPath('data.direct_item_count', 0)->assertJsonPath('data.subtree_item_count', 0);
    }

    public function test_manual_scope_correction_preserves_item_identity_inventory_and_audits_the_change(): void
    {
        $item = $this->item('factory', $this->category('factory'), ['item_type' => 'service', 'is_production_item' => false]);
        $warehouse = DB::table('erp_warehouses')->insertGetId(['warehouse_code' => $this->code('WH'),
            'warehouse_name' => '范围测试仓', 'warehouse_type' => 'general', 'status' => 'enabled', 'created_at' => now(), 'updated_at' => now()]);
        $location = DB::table('erp_locations')->insertGetId(['warehouse_id' => $warehouse, 'location_code' => $this->code('LOC'),
            'location_name' => '范围测试库位', 'status' => 'enabled', 'created_at' => now(), 'updated_at' => now()]);
        $balance = InventoryBalance::create(['item_id' => $item->id, 'warehouse_id' => $warehouse, 'location_id' => $location, 'batch_no' => $this->code('B'),
            'unit_id' => $this->unit->id, 'quantity_on_hand' => '7', 'quantity_available' => '7', 'inventory_value' => '21']);
        $before = $balance->refresh()->getRawOriginal();
        $payload = array_replace($this->payload($item), ['management_scope' => 'office', 'item_type' => 'office_consumable',
            'category_id' => $this->category('office')->id]);
        $this->putJson('/api/v1/erp/master/items/'.$item->id.'?management_scope=factory', $payload)
            ->assertOk()->assertJsonPath('data.id', $item->id)->assertJsonPath('data.management_scope', 'office');
        $this->assertSame($before, $balance->fresh()->getRawOriginal());
        $this->assertSame($item->item_code, $item->fresh()->item_code);
        $log = DB::table('erp_operation_logs')->where('target_type', 'erp_items')->where('target_id', $item->id)
            ->where('action', 'set_management_scope')->latest('id')->first();
        $this->assertSame('factory', json_decode($log->old_snapshot, true)['management_scope']);
        $this->assertSame('office', json_decode($log->new_snapshot, true)['management_scope']);
        $this->assertSame(99, (int) $log->operator_id);
        // A stale object checked before a concurrent correction must not bypass the locked write boundary.
        try {
            app(MasterDataApplicationService::class)->update('items', $item, ['item_name' => '陈旧入口写入'], 99, 'factory');
            $this->fail('The scope must be checked again after locking the current Item.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('management_scope', $error->errors());
        }
        $this->assertNotSame('陈旧入口写入', $item->fresh()->item_name);
    }

    public function test_a_real_bom_reference_blocks_manual_scope_correction_and_rolls_back_item_fields(): void
    {
        $item = $this->item('factory', $this->category('factory'));
        Bom::create(['bom_no' => $this->code('BOM'), 'bom_name' => '引用中 BOM', 'output_item_id' => $item->id,
            'version' => '1', 'status' => 'active', 'audit_status' => 'approved']);
        $payload = array_replace($this->payload($item), ['management_scope' => 'office', 'item_type' => 'office_consumable',
            'category_id' => $this->category('office')->id, 'item_name' => '不应保存']);
        $this->putJson('/api/v1/erp/master/items/'.$item->id.'?management_scope=factory', $payload)->assertUnprocessable();
        $this->assertSame('factory', $item->fresh()->management_scope);
        $this->assertSame($item->item_name, $item->fresh()->item_name);
    }

    public function test_item_import_uses_explicit_type_or_scope_and_same_named_categories_stay_in_separate_trees(): void
    {
        $factoryCategory = $this->category('factory', ['category_name' => $this->code('同名类目')]);
        $row = ['物料编码' => $this->code('IMPORT'), '物料名称' => '办公用品导入', '物料类型' => '办公用品',
            '单位编码' => $this->unit->unit_code, 'Item类目' => $factoryCategory->category_name];
        $office = app(ItemImportApplicationService::class)->create($row);
        $this->assertSame('office', $office->management_scope);
        $this->assertSame('office', $office->category->management_scope);
        $this->assertNotSame($factoryCategory->id, $office->category_id);
        $this->assertSame('factory', $factoryCategory->fresh()->management_scope);
        $before = Item::count();
        foreach ([['management_scope' => 'factory'], ['management_scope' => 'office', '管理范围' => '工厂物料'],
            ['category_code' => $factoryCategory->category_code]] as $conflict) {
            try {
                app(ItemImportApplicationService::class)->create(array_replace($row, ['物料编码' => $this->code('INVALID')], $conflict));
                $this->fail('Conflicting scope must fail before creating Item or linked master data.');
            } catch (ValidationException $error) { $this->assertSame(422, $error->status); }
        }
        $this->assertSame($before, Item::count());
    }

    public function test_public_item_import_preserves_typed_false_and_existing_blank_boolean_defaults(): void
    {
        $category = $this->category('office');
        $row = ['item_code' => $this->code('BOOL'), 'item_name' => '明确非库存办公服务', 'item_type' => 'service',
            'unit_code' => $this->unit->unit_code, 'category_code' => $category->category_code,
            'is_purchase_item' => false, 'is_stock_item' => false, 'is_production_item' => false];
        $item = app(ItemImportApplicationService::class)->create($row, 'office', 99)->refresh();
        $this->assertFalse($item->is_purchase_item);
        $this->assertFalse($item->is_stock_item);
        $this->assertFalse($item->is_production_item);
        $this->assertSame('office', $item->management_scope);
        $this->assertDatabaseHas('erp_items', ['id' => $item->id, 'item_type' => 'service', 'management_scope' => 'office',
            'category_id' => $category->id, 'is_purchase_item' => false, 'is_stock_item' => false, 'is_production_item' => false]);

        $blank = app(ItemImportApplicationService::class)->create(array_replace($row, ['item_code' => $this->code('BLANK'),
            'is_purchase_item' => ' ', 'is_stock_item' => '', 'is_production_item' => null]), 'office', 99)->refresh();
        $this->assertTrue($blank->is_purchase_item);
        $this->assertTrue($blank->is_stock_item);
        $this->assertFalse($blank->is_production_item);
    }

    public function test_upload_scope_is_frozen_for_preview_confirm_and_cross_scope_batch_access(): void
    {
        Storage::fake('local');
        $category = $this->category('office');
        $code = $this->code('BATCH');
        $csv = "item_code,item_name,item_type,unit_code,category_code\n{$code},办公服务,service,{$this->unit->unit_code},{$category->category_code}\n";
        $batch = $this->upload($csv, 'office')->assertCreated()->assertJsonPath('data.management_scope', 'office')->json('data');
        $url = '/api/v1/erp/master/imports/'.$batch['id'];
        $this->getJson($url.'/rows?management_scope=factory')->assertUnprocessable();
        $this->postJson($url.'/preview?management_scope=factory')->assertUnprocessable();
        $this->deleteJson($url.'?management_scope=factory')->assertUnprocessable();
        $this->postJson($url.'/preview?management_scope=office')->assertOk()->assertJsonPath('data.valid_rows', 1);
        $this->postJson($url.'/confirm?management_scope=office')->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->assertDatabaseHas('erp_items', ['item_code' => $code, 'management_scope' => 'office', 'category_id' => $category->id]);
        $this->postJson($url.'/confirm?management_scope=office')->assertUnprocessable();
    }

    public function test_a_real_utf8_csv_detected_as_plain_text_imports_with_the_multipart_office_scope(): void
    {
        Storage::fake('local');
        $category = $this->category('office');
        $code = $this->code('REALCSV');
        $csv = "item_code,item_name,item_type,unit_code,category_code,is_purchase_item,is_stock_item,is_production_item,cost_method,status\n"
            ."{$code},页面导入办公服务,service,{$this->unit->unit_code},{$category->category_code},true,false,false,weighted_average,enabled\n";
        $batch = $this->uploadRealTextFile($csv, 'office-service.csv', 'office')->assertCreated()
            ->assertJsonPath('data.management_scope', 'office')->assertJsonPath('data.file_name', 'office-service.csv')->json('data');
        $url = '/api/v1/erp/master/imports/'.$batch['id'];
        $this->postJson($url.'/preview?management_scope=office')->assertOk()->assertJsonPath('data.total_rows', 1)
            ->assertJsonPath('data.valid_rows', 1)->assertJsonPath('data.warning_rows', 0)->assertJsonPath('data.error_rows', 0);
        $this->postJson($url.'/confirm?management_scope=office')->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->assertDatabaseHas('erp_items', ['item_code' => $code, 'management_scope' => 'office', 'item_type' => 'service',
            'category_id' => $category->id, 'unit_id' => $this->unit->id, 'is_stock_item' => false, 'is_production_item' => false]);
    }

    public function test_plain_text_with_a_non_csv_extension_is_rejected_before_storing_a_file_or_import_batch(): void
    {
        Storage::fake('local');
        $before = DB::table('erp_import_batches')->count();
        $csv = "item_code,item_name,item_type,unit_code\n{$this->code('TEXT')},办公服务,service,{$this->unit->unit_code}\n";
        foreach (['office-service.txt', 'office-service.xls', 'office-service.xlsx'] as $name) {
            $this->uploadRealTextFile($csv, $name, 'office')->assertUnprocessable()->assertJsonValidationErrors('file');
        }
        $this->assertSame($before, DB::table('erp_import_batches')->count());
        $this->assertSame([], Storage::allFiles('erp-imports'));
    }

    public function test_a_batch_scope_conflict_marks_precheck_error_and_blocks_all_rows_on_confirmation(): void
    {
        Storage::fake('local');
        $category = $this->category('office');
        $good = $this->code('GOOD'); $bad = $this->code('BAD');
        $csv = "item_code,item_name,item_type,unit_code,category_code,management_scope\n"
            ."{$good},办公服务,service,{$this->unit->unit_code},{$category->category_code},office\n"
            ."{$bad},范围冲突,service,{$this->unit->unit_code},{$category->category_code},factory\n";
        $batch = $this->upload($csv, 'office')->assertCreated()->json('data');
        $url = '/api/v1/erp/master/imports/'.$batch['id'];
        $this->postJson($url.'/preview?management_scope=office')->assertOk()->assertJsonPath('data.valid_rows', 1)
            ->assertJsonPath('data.error_rows', 1)->assertJsonPath('rows.data.1.error_type', 'management_scope');
        $this->postJson($url.'/confirm?management_scope=office')->assertUnprocessable();
        $this->assertDatabaseMissing('erp_items', ['item_code' => $good]);
        $this->assertDatabaseMissing('erp_items', ['item_code' => $bad]);
        $this->assertDatabaseHas('erp_import_batches', ['id' => $batch['id'], 'status' => 'previewed', 'confirmed_at' => null]);
    }

    public function test_factory_multipart_scope_blocks_an_explicit_office_type_without_a_row_scope(): void
    {
        Storage::fake('local');
        $category = $this->category('factory');
        $good = $this->code('FACTORY'); $office = $this->code('OFFICE');
        $csv = "item_code,item_name,item_type,unit_code,category_code\n"
            ."{$good},工厂服务,service,{$this->unit->unit_code},{$category->category_code}\n"
            ."{$office},办公用品,office_consumable,{$this->unit->unit_code},{$category->category_code}\n";
        $batch = $this->upload($csv, 'factory')->assertCreated()->assertJsonPath('data.management_scope', 'factory')->json('data');
        $url = '/api/v1/erp/master/imports/'.$batch['id'];
        $this->postJson($url.'/preview?management_scope=factory')->assertOk()->assertJsonPath('data.valid_rows', 1)
            ->assertJsonPath('data.error_rows', 1)->assertJsonPath('rows.data.1.error_type', 'management_scope');
        $this->postJson($url.'/confirm?management_scope=factory')->assertUnprocessable();
        $this->assertDatabaseMissing('erp_items', ['item_code' => $good]);
        $this->assertDatabaseMissing('erp_items', ['item_code' => $office]);
        $this->assertDatabaseHas('erp_import_batches', ['id' => $batch['id'], 'status' => 'previewed', 'confirmed_at' => null]);
    }

    public function test_multipart_and_query_scope_conflict_or_invalid_values_do_not_create_an_import_batch(): void
    {
        Storage::fake('local');
        $before = DB::table('erp_import_batches')->count();
        $csv = "item_code,item_name,item_type,unit_code\n{$this->code('UPLOAD')},办公服务,service,{$this->unit->unit_code}\n";
        foreach ([['office', 'factory'], ['', null], ['unknown', null], ['office', '']] as [$body, $query]) {
            $this->upload($csv, $body, $query)->assertUnprocessable()->assertJsonValidationErrors('management_scope');
        }
        $this->assertSame($before, DB::table('erp_import_batches')->count());
        $this->assertSame([], Storage::allFiles('erp-imports'));
        // Query-only clients remain supported alongside the actual FormData body contract.
        $this->post('/api/v1/erp/master/imports/upload?management_scope=office', ['import_type' => 'Item',
            'file' => UploadedFile::fake()->createWithContent('scope.csv', $csv)], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.management_scope', 'office');
    }

    public function test_sku_relation_import_rechecks_current_item_scope_after_a_valid_preview(): void
    {
        Storage::fake('local');
        $item = $this->item('factory', $this->category('factory'));
        $product = Product::create(['product_code' => $this->code('P'), 'product_name' => '测试商品', 'product_type' => 'standard', 'status' => 'enabled']);
        $sku = Sku::create(['sku_code' => $this->code('SKU'), 'sku_name' => '测试 SKU', 'product_id' => $product->id,
            'order_line_type' => 'physical', 'fulfillment_type' => 'physical', 'status' => 'draft']);
        $csv = "sku_code,item_code,relation_type\n{$sku->sku_code},{$item->item_code},finished_product\n";
        $batch = $this->post('/api/v1/erp/master/imports/upload', ['import_type' => 'SKU-Item Relation',
            'file' => UploadedFile::fake()->createWithContent('scope.csv', $csv)], ['Accept' => 'application/json'])->assertCreated()->json('data');
        $url = '/api/v1/erp/master/imports/'.$batch['id'];
        $this->postJson($url.'/preview')->assertOk()->assertJsonPath('data.valid_rows', 1);
        $item->update(['management_scope' => 'office']);
        $this->postJson($url.'/confirm')->assertUnprocessable();
        $this->assertDatabaseMissing('erp_sku_item_relations', ['sku_id' => $sku->id, 'item_id' => $item->id]);
    }

    public function test_scope_does_not_grant_item_or_category_permissions(): void
    {
        $this->useRoleWithoutPermissions();
        $office = $this->item('office', $this->category('office'));
        // Generic Item GETs retain the shared-selector authorization baseline;
        // a scope value must never grant existing write or dedicated-read rights.
        $this->getJson('/api/v1/erp/master/items?management_scope=office')->assertOk();
        $this->getJson('/api/v1/erp/master/items/'.$office->id.'?management_scope=office')->assertOk();
        $this->getJson('/api/v1/erp/master/items/'.$office->id.'/integrated-form?management_scope=office')->assertForbidden();
        $this->getJson('/api/v1/erp/master/item-categories/tree?management_scope=office')->assertForbidden();
        $this->putJson('/api/v1/erp/master/items/'.$office->id.'?management_scope=office', $this->payload($office))->assertForbidden();
        $this->postJson('/api/v1/erp/master/items?management_scope=office', [])->assertForbidden();
        $this->postJson('/api/v1/erp/master/items/'.$office->id.'/disable?management_scope=office')->assertForbidden();
        $this->putJson('/api/v1/erp/master/items/'.$office->id.'/integrated-form?management_scope=office', [])->assertForbidden();
    }

    private function upload(string $csv, string $scope, ?string $queryScope = null)
    {
        $url = '/api/v1/erp/master/imports/upload'.($queryScope === null ? '' : '?management_scope='.$queryScope);
        return $this->post($url, ['import_type' => 'Item', 'management_scope' => $scope,
            'file' => UploadedFile::fake()->createWithContent('scope.csv', $csv)], ['Accept' => 'application/json']);
    }

    private function uploadRealTextFile(string $contents, string $name, string $scope)
    {
        $path = tempnam(sys_get_temp_dir(), 'erp-scope-upload-');
        $this->assertNotFalse($path);
        try {
            $this->assertSame(strlen($contents), file_put_contents($path, $contents));
            $file = new UploadedFile($path, $name, null, UPLOAD_ERR_OK, true);
            // No fake MIME: the same content detector used by upload validation must return text/plain.
            $this->assertSame('text/plain', $file->getMimeType());
            return $this->post('/api/v1/erp/master/imports/upload', ['import_type' => 'Item', 'management_scope' => $scope,
                'file' => $file], ['Accept' => 'application/json']);
        } finally {
            if (is_file($path)) unlink($path);
        }
    }

    private function useRoleWithoutPermissions(): void
    {
        $id = random_int(7400000, 7499999);
        DB::table('erp_legacy_admin_users')->insert(['legacy_id' => $id, 'username' => $this->code('USER'),
            'nickname' => '无权限范围验证员', 'status' => 'normal', 'auth_group_names' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        $role = DB::table('erp_rbac_roles')->insertGetId(['code' => $this->code('ROLE'), 'name' => '无主数据权限角色',
            'data_scope' => 'self', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('erp_rbac_user_roles')->insert(['user_legacy_id' => $id, 'role_id' => $role]);
        $token = $this->code('TOKEN');
        DB::table('erp_auth_tokens')->insert(['user_legacy_id' => $id, 'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        $realAuth = new AuthContextService();
        $this->app->instance(AuthContextService::class, $realAuth);
        $user = DB::table('erp_legacy_admin_users')->where('legacy_id', $id)->first();
        $this->assertFalse($realAuth->isSuperAdmin($user));
        $this->assertSame([], $realAuth->permissionCodes($user));
        $this->withToken($token);
    }

    private function createCategoryViaApi(int $parentId, ?string $scope)
    {
        $session = (string) Str::uuid();
        $reservation = app(DocumentNumberService::class)->reserve('item_category', $session, 99, '/master/categories#create');
        $payload = ['category_name' => $this->code('新子类目'), 'category_code' => $reservation->document_no,
            'parent_id' => $parentId, 'status' => 'enabled', 'reservation_token' => $reservation->reservation_token,
            'creation_session_id' => $session];
        if ($scope !== null) $payload['management_scope'] = $scope;
        return $this->postJson('/api/v1/erp/master/item-categories', $payload);
    }

    private function integratedPayload(Item $item): array
    {
        return ['activate' => false, 'item' => $this->payload($item), 'policy' => [
            'is_stock_managed' => true, 'inventory_management_mode' => 'standard', 'requires_custodian' => false,
            'is_returnable' => false, 'requires_capitalization' => false, 'serial_tracking_mode' => 'none',
            'post_purchase_action' => 'inventory_receipt', 'consumption_confirmation_mode' => 'none',
            'future_route' => 'inventory', 'future_bearer_type' => 'company', 'change_reason' => '范围测试']];
    }

    private function payload(Item $item): array
    {
        return ['item_code' => $item->item_code, 'item_name' => $item->item_name, 'item_type' => $item->item_type,
            'management_scope' => $item->managementScope(), 'category_id' => $item->category_id, 'unit_id' => $item->unit_id,
            'cutting_mode' => 'none', 'material_management_mode' => 'quantity', 'is_length_cut_material' => false,
            'is_purchase_item' => true, 'is_stock_item' => true, 'is_production_item' => false,
            'manufacturing_strategy' => 'unspecified', 'cost_method' => 'weighted_average', 'status' => 'enabled'];
    }

    private function category(string $scope, array $extra = []): ItemCategory
    {
        return ItemCategory::create(array_replace(['category_code' => $this->code('C'), 'category_name' => $this->code('类目'),
            'category_type' => 'item', 'management_scope' => $scope, 'parent_id' => null, 'status' => 'enabled'], $extra));
    }

    private function item(string $scope, ItemCategory $category, array $extra = []): Item
    {
        return Item::create(array_replace(['item_code' => $this->code('I'), 'item_name' => $this->code('物料'),
            'item_type' => $scope === 'office' ? 'office_consumable' : 'raw_material', 'management_scope' => $scope,
            'category_id' => $category->id, 'unit_id' => $this->unit->id, 'is_purchase_item' => true, 'is_stock_item' => true,
            'is_production_item' => false, 'cutting_mode' => 'none', 'material_management_mode' => 'quantity',
            'is_length_cut_material' => false, 'cost_method' => 'weighted_average', 'status' => 'enabled'], $extra));
    }

    private function code(string $kind): string { return $this->prefix.'-'.$kind.'-'.Str::upper(Str::random(5)); }
}
