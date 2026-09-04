<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\{Item, Product, Sku, SkuItemRelation, SkuItemRelationLog, Unit};
use App\Services\Erp\SkuItemDefaultRelationService;
use App\Services\Erp\SkuItemRelationAuditService;
use App\Services\Erp\AuthContextService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Tests\TestCase;

class SkuItemDefaultRelationServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_physical_sku_can_set_and_change_one_default_item_with_history(): void
    {
        [$sku, $first, $second] = $this->physicalFixture();
        $service = app(SkuItemDefaultRelationService::class);

        $initial = $service->setPrimary($sku->id, $first->id, null, null, 1, 'tester');
        $replacement = $service->setPrimary($sku->id, $second->id, '产品升级', '验证历史', 1, 'tester');

        $this->assertSame('inactive', $initial->fresh()->status);
        $this->assertSame('active', $replacement->fresh()->status);
        $this->assertSame(2, DB::table('erp_sku_item_relations')->where('sku_id', $sku->id)->count());
        $this->assertDatabaseHas('erp_sku_item_relation_logs', ['sku_id' => $sku->id, 'action' => 'set_primary']);
        $this->assertDatabaseHas('erp_sku_item_relation_logs', ['sku_id' => $sku->id, 'action' => 'change_primary', 'change_reason' => '产品升级']);
    }

    public function test_disabled_item_and_non_physical_sku_are_rejected(): void
    {
        [$sku, $enabled] = $this->physicalFixture();
        $disabled = Item::create(['item_code' => 'ITM-DISABLED', 'item_name' => '停用Item', 'item_type' => 'raw_material', 'unit_id' => $enabled->unit_id, 'status' => 'disabled']);
        $service = app(SkuItemDefaultRelationService::class);

        try { $service->setPrimary($sku->id, $disabled->id, null, null, 1, 'tester'); $this->fail('停用 Item 不应能设为默认'); }
        catch (ValidationException $exception) { $this->assertArrayHasKey('item_id', $exception->errors()); }

        $sku->update(['order_line_type' => 'service']);
        try { $service->setPrimary($sku->id, $enabled->id, null, null, 1, 'tester'); $this->fail('服务 SKU 不应能设默认 Item'); }
        catch (ValidationException $exception) { $this->assertArrayHasKey('sku_id', $exception->errors()); }

        $sku->update(['order_line_type' => 'no_delivery']);
        try { $service->setPrimary($sku->id, $enabled->id, null, null, 1, 'tester'); $this->fail('无需发货 SKU 不应能设默认 Item'); }
        catch (ValidationException $exception) { $this->assertArrayHasKey('sku_id', $exception->errors()); }
    }

    public function test_same_item_and_other_reason_without_remark_are_rejected(): void
    {
        [$sku, $first, $second] = $this->physicalFixture();
        $service = app(SkuItemDefaultRelationService::class);
        $service->setPrimary($sku->id, $first->id, null, null, 1, 'tester');

        try { $service->setPrimary($sku->id, $first->id, '主数据修正', null, 1, 'tester'); $this->fail('相同 Item 不应重复保存'); }
        catch (ValidationException $exception) { $this->assertArrayHasKey('item_id', $exception->errors()); }

        try { $service->setPrimary($sku->id, $second->id, '其他', null, 1, 'tester'); $this->fail('原因选择其他时备注应必填'); }
        catch (ValidationException $exception) { $this->assertArrayHasKey('remark', $exception->errors()); }
    }

    public function test_database_constraint_prevents_duplicate_active_primary_relations(): void
    {
        [$sku, $first, $second] = $this->physicalFixture();
        $firstRelation = $this->relation($sku, $first);
        $this->expectException(QueryException::class);
        $secondRelation = $this->relation($sku, $second);

        app(SkuItemDefaultRelationService::class)->resolveDuplicate(
            $sku->id,
            $secondRelation->id,
            '主数据修正',
            '保留第二个关系',
            1,
            'tester'
        );

        $this->assertSame('inactive', $firstRelation->fresh()->status);
        $this->assertSame('active', $secondRelation->fresh()->status);
        $this->assertDatabaseHas('erp_sku_item_relation_logs', [
            'sku_id' => $sku->id,
            'relation_id' => $firstRelation->id,
            'action' => 'resolve_duplicate',
            'operator_name' => 'tester',
        ]);
    }

    public function test_wrong_binding_on_service_sku_can_be_removed_and_audited(): void
    {
        [$sku, $first] = $this->physicalFixture();
        $sku->update(['order_line_type' => 'service']);
        $relation = $this->relation($sku, $first);
        $audit = app(SkuItemRelationAuditService::class);

        $before = $audit->inspect($sku->fresh()->load('itemRelations.item'));
        $this->assertSame('wrong_binding', $before['check_status']);

        app(SkuItemDefaultRelationService::class)->removeWrongBindings(
            $sku->id,
            '错误绑定修复',
            '服务SKU不应绑定实物Item',
            1,
            'tester'
        );

        $this->assertSame('inactive', $relation->fresh()->status);
        $after = $audit->inspect($sku->fresh()->load('itemRelations.item'));
        $this->assertSame('not_required', $after['check_status']);
        $this->assertDatabaseHas('erp_sku_item_relation_logs', [
            'relation_id' => $relation->id,
            'action' => 'remove_wrong_binding',
        ]);
    }

    public function test_integrity_audit_is_read_only(): void
    {
        [$sku, $first] = $this->physicalFixture();
        $this->relation($sku, $first);
        $beforeRelations = DB::table('erp_sku_item_relations')->get()->map(fn ($row) => (array) $row)->all();
        $beforeLogs = DB::table('erp_sku_item_relation_logs')->count();

        $result = app(SkuItemRelationAuditService::class)->inspect($sku->fresh()->load('itemRelations.item'));

        $this->assertSame('normal', $result['check_status']);
        $this->assertSame($beforeRelations, DB::table('erp_sku_item_relations')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame($beforeLogs, DB::table('erp_sku_item_relation_logs')->count());
    }

    public function test_change_rolls_back_when_log_write_fails(): void
    {
        [$sku, $first, $second] = $this->physicalFixture();
        $service = app(SkuItemDefaultRelationService::class);
        $initial = $service->setPrimary($sku->id, $first->id, null, null, 1, 'tester');
        $eventName = 'eloquent.creating: '.SkuItemRelationLog::class;
        Event::listen($eventName, static function (): void {
            throw new \RuntimeException('forced rollback');
        });

        try {
            $service->setPrimary($sku->id, $second->id, '产品升级', '触发事务回滚', 1, 'tester');
            $this->fail('日志失败时业务事务必须回滚');
        } catch (\RuntimeException) {
            $this->assertSame('active', $initial->fresh()->status);
            $this->assertSame(1, DB::table('erp_sku_item_relations')->where('sku_id', $sku->id)->count());
            $this->assertDatabaseMissing('erp_sku_item_relations', ['sku_id' => $sku->id, 'item_id' => $second->id]);
        } finally {
            Event::forget($eventName);
        }
    }

    public function test_stage_four_endpoints_enforce_button_permissions(): void
    {
        [$sku, $item] = $this->physicalFixture();
        $this->mockPermissions([]);

        $this->getJson('/api/v1/erp/master/sku-item-relations/defaults')->assertForbidden();
        $this->postJson('/api/v1/erp/master/sku-item-relations/audit')->assertForbidden();
        $this->postJson("/api/v1/erp/master/sku-item-relations/{$sku->id}/set-primary", ['item_id' => $item->id])->assertForbidden();
        $this->postJson("/api/v1/erp/master/sku-item-relations/{$sku->id}/resolve-duplicate", [])->assertForbidden();
    }

    public function test_list_and_audit_endpoints_return_real_pagination_without_writes(): void
    {
        [$sku, $item] = $this->physicalFixture();
        $this->relation($sku, $item);
        for ($index = 1; $index <= 11; $index++) {
            Sku::create([
                'product_id' => $sku->product_id,
                'sku_code' => "TEST-SKU-PAGE-{$index}",
                'sku_name' => "分页测试SKU {$index}",
                'fulfillment_type' => 'physical',
                'order_line_type' => 'physical',
                'status' => 'enabled',
            ]);
        }
        $this->mockPermissions(['sku_item_relation.view', 'sku_item_relation.audit']);
        $relationSnapshot = DB::table('erp_sku_item_relations')->get()->map(fn ($row) => (array) $row)->all();

        $this->getJson('/api/v1/erp/master/sku-item-relations/defaults?page=2&per_page=5&sku_keyword=TEST-SKU-')
            ->assertOk()
            ->assertJsonPath('total', 12)
            ->assertJsonPath('current_page', 2)
            ->assertJsonCount(5, 'data');

        $this->postJson('/api/v1/erp/master/sku-item-relations/audit?page=3&per_page=5&sku_keyword=TEST-SKU-', ['status' => 'all'])
            ->assertOk()
            ->assertJsonPath('total', 12)
            ->assertJsonPath('current_page', 3)
            ->assertJsonCount(2, 'data');

        $this->assertSame($relationSnapshot, DB::table('erp_sku_item_relations')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame(0, DB::table('erp_sku_item_relation_logs')->where('sku_id', $sku->id)->count());
    }

    private function physicalFixture(): array
    {
        $unit = Unit::firstOrCreate(
            ['unit_code' => 'EA'],
            ['unit_name' => '个', 'unit_type' => 'quantity', 'status' => 'enabled']
        );
        $product = Product::create(['product_code' => 'TEST-PRODUCT', 'product_name' => '测试产品', 'product_type' => 'standard', 'unit_id' => $unit->id, 'status' => 'enabled']);
        $sku = Sku::create(['product_id' => $product->id, 'sku_code' => 'TEST-SKU-PHYSICAL', 'sku_name' => '测试实物SKU', 'fulfillment_type' => 'physical', 'order_line_type' => 'physical', 'status' => 'enabled']);
        $first = Item::create(['item_code' => 'TEST-ITEM-A', 'item_name' => '测试Item A', 'item_type' => 'raw_material', 'unit_id' => $unit->id, 'status' => 'enabled']);
        $second = Item::create(['item_code' => 'TEST-ITEM-B', 'item_name' => '测试Item B', 'item_type' => 'raw_material', 'unit_id' => $unit->id, 'status' => 'enabled']);
        return [$sku, $first, $second];
    }

    private function relation(Sku $sku, Item $item): SkuItemRelation
    {
        return SkuItemRelation::create([
            'sku_id' => $sku->id,
            'item_id' => $item->id,
            'unit_id' => $item->unit_id,
            'relation_type' => 'primary',
            'qty' => 1,
            'is_primary' => true,
            'is_bundle_item' => false,
            'status' => 'active',
            'effective_at' => now(),
            'operator_name' => 'fixture',
            'change_reason' => '测试数据',
        ]);
    }

    private function mockPermissions(array $permissions): void
    {
        $user = (object) ['legacy_id' => 99, 'username' => 'tester', 'nickname' => '测试员', 'auth_group_names' => '[]'];
        $this->mock(AuthContextService::class, function (MockInterface $mock) use ($user, $permissions) {
            $mock->shouldReceive('currentUser')->andReturn($user);
            $mock->shouldReceive('isSuperAdmin')->andReturn(false);
            $mock->shouldReceive('permissionCodes')->andReturn($permissions);
        });
    }
}
