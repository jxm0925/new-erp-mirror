<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\ItemCategory;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\DocumentNumberService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

class ItemCategoryPermissionClosureTest extends TestCase
{
    use DatabaseTransactions;

    public function test_user_without_item_category_permissions_is_forbidden_everywhere(): void
    {
        $category = $this->category('NONE');
        $this->mockPermissions([]);

        $this->getJson('/api/v1/erp/master/item-categories')->assertForbidden();
        $this->getJson('/api/v1/erp/master/item-categories/tree')->assertForbidden();
        $this->getJson("/api/v1/erp/master/item-categories/{$category->id}")->assertForbidden();
        $this->postJson('/api/v1/erp/master/item-categories')->assertForbidden();
        $this->putJson("/api/v1/erp/master/item-categories/{$category->id}", [])->assertForbidden();
        $this->postJson("/api/v1/erp/master/item-categories/{$category->id}/disable")->assertForbidden();
        $this->postJson("/api/v1/erp/master/item-categories/{$category->id}/enable")->assertForbidden();
    }

    public function test_view_only_user_can_read_but_all_writes_are_forbidden(): void
    {
        $category = $this->category('VIEW');
        $this->mockPermissions(['item_category.view']);

        $this->getJson('/api/v1/erp/master/item-categories')->assertOk();
        $this->getJson('/api/v1/erp/master/item-categories/tree')->assertOk();
        $this->getJson("/api/v1/erp/master/item-categories/{$category->id}")->assertOk();
        $this->postJson('/api/v1/erp/master/item-categories')->assertForbidden();
        $this->putJson("/api/v1/erp/master/item-categories/{$category->id}", [])->assertForbidden();
        $this->postJson("/api/v1/erp/master/item-categories/{$category->id}/disable")->assertForbidden();
        $this->postJson("/api/v1/erp/master/item-categories/{$category->id}/enable")->assertForbidden();
    }

    public function test_manage_user_can_complete_all_item_category_actions(): void
    {
        $this->mockPermissions(['item_category.view', 'item_category.manage']);

        $root = $this->createThroughApi('权限管理一级类目');
        $child = $this->createThroughApi('权限管理子类目', $root['id']);

        $this->getJson('/api/v1/erp/master/item-categories')->assertOk();
        $this->getJson('/api/v1/erp/master/item-categories/tree')->assertOk();
        $this->getJson("/api/v1/erp/master/item-categories/{$child['id']}")->assertOk();

        $this->putJson("/api/v1/erp/master/item-categories/{$child['id']}", [
            'category_code' => $child['category_code'],
            'category_name' => '权限管理子类目（已编辑）',
            'parent_id' => $root['id'],
            'sort_order' => 8,
            'status' => 'enabled',
            'remark' => '管理权限编辑与排序验收',
        ])->assertOk()
            ->assertJsonPath('data.category_code', $child['category_code'])
            ->assertJsonPath('data.sort_order', 8);

        $this->postJson("/api/v1/erp/master/item-categories/{$child['id']}/disable")
            ->assertOk()
            ->assertJsonPath('data.status', 'disabled');
        $this->postJson("/api/v1/erp/master/item-categories/{$child['id']}/enable")
            ->assertOk()
            ->assertJsonPath('data.status', 'enabled');
    }

    public function test_legacy_edit_role_grants_are_merged_into_manage_permission(): void
    {
        $roleId = DB::table('erp_rbac_roles')->insertGetId([
            'code' => 'legacy_item_category_manager',
            'name' => '历史类目管理员',
            'data_scope' => 'self',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $viewId = DB::table('erp_rbac_permissions')->where('code', 'item_category.view')->value('id');
        $legacyId = DB::table('erp_rbac_permissions')->insertGetId([
            'parent_id' => $viewId,
            'code' => 'item_category.edit',
            'name' => '历史维护 Item 类目',
            'type' => 'button',
            'sort' => 1,
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('erp_rbac_role_permissions')->insert([
            'role_id' => $roleId,
            'permission_id' => $legacyId,
        ]);

        $migration = require database_path('migrations/2026_08_03_100000_unify_item_category_manage_permission.php');
        $migration->up();

        $manageId = DB::table('erp_rbac_permissions')->where('code', 'item_category.manage')->value('id');
        $this->assertNotNull($manageId);
        $this->assertDatabaseHas('erp_rbac_role_permissions', [
            'role_id' => $roleId,
            'permission_id' => $manageId,
        ]);
        $this->assertDatabaseMissing('erp_rbac_permissions', ['code' => 'item_category.edit']);
    }

    public function test_frontend_menu_route_and_buttons_use_the_final_permission_names(): void
    {
        $page = file_get_contents(base_path('../frontend/src/views/erp/master/ItemCategoryList.vue'));
        $app = file_get_contents(base_path('../frontend/src/App.vue'));
        $router = file_get_contents(base_path('../frontend/src/main.js'));
        $bootstrap = file_get_contents(app_path('Services/Erp/RbacBootstrapService.php'));

        $this->assertStringContainsString("permissions.includes('item_category.manage')", $page);
        $this->assertStringContainsString('v-if="canManage"', $page);
        $this->assertStringNotContainsString('item_category.edit', $page.$app.$router.$bootstrap);
        $this->assertStringContainsString("permission: 'item_category.view'", $app);
        $this->assertStringContainsString("meta: { permission: 'item_category.view' }", $router);
        $this->assertStringContainsString('requiredPermission', $router);
        $this->assertStringContainsString("['item_category.manage', '管理 Item 类目'", $bootstrap);
    }

    private function createThroughApi(string $name, ?int $parentId = null): array
    {
        $sessionId = (string) Str::uuid();
        $reservation = app(DocumentNumberService::class)->reserve(
            'item_category',
            $sessionId,
            99,
            '/master/categories#create'
        );

        return $this->postJson('/api/v1/erp/master/item-categories', [
            'category_code' => $reservation->document_no,
            'category_name' => $name,
            'parent_id' => $parentId,
            'sort_order' => 0,
            'status' => 'enabled',
            'remark' => null,
            'reservation_token' => $reservation->reservation_token,
            'creation_session_id' => $sessionId,
        ])->assertCreated()->json('data');
    }

    private function category(string $suffix): ItemCategory
    {
        return ItemCategory::create([
            'category_code' => 'PERM-'.$suffix,
            'category_name' => '权限类目'.$suffix,
            'category_type' => 'item',
            'parent_id' => null,
            'sort_order' => 0,
            'status' => 'enabled',
        ]);
    }

    private function mockPermissions(array $permissions): void
    {
        $user = (object) [
            'legacy_id' => 99,
            'username' => 'permission_tester',
            'nickname' => '权限测试员',
            'auth_group_names' => '[]',
        ];

        $this->mock(AuthContextService::class, function (MockInterface $mock) use ($user, $permissions) {
            $mock->shouldReceive('currentUser')->andReturn($user);
            $mock->shouldReceive('isSuperAdmin')->andReturn(false);
            $mock->shouldReceive('permissionCodes')->andReturn($permissions);
        });
    }
}
