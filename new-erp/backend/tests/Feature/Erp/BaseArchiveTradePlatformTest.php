<?php

namespace Tests\Feature\Erp;

use App\Services\Erp\AuthContextService;
use App\Services\Erp\TradePlatformApplicationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BaseArchiveTradePlatformTest extends TestCase
{
    use DatabaseTransactions;

    private array $permissions = ['master.base_archive', 'master.base_archive.create'];

    protected function setUp(): void
    {
        parent::setUp();
        $actor = (object) ['legacy_id' => 1, 'username' => 'archive-test', 'nickname' => '档案测试'];
        $auth = \Mockery::mock(AuthContextService::class);
        $auth->shouldReceive('currentUser')->andReturn($actor);
        $auth->shouldReceive('isSuperAdmin')->andReturnFalse();
        $auth->shouldReceive('permissionCodes')->andReturnUsing(fn () => $this->permissions);
        $this->app->instance(AuthContextService::class, $auth);
    }

    public function test_create_replay_keeps_one_sales_option_and_one_audit_record(): void
    {
        $payload = $this->payload();
        $first = $this->postJson('/api/v1/erp/master/trade-platforms', $payload)->assertCreated()->json('data');
        $this->postJson('/api/v1/erp/master/trade-platforms', $payload)->assertCreated()->assertJsonPath('data.id', $first['id']);
        $this->assertSame(1, DB::table('erp_sales_order_trade_platforms')->where('name', $payload['name'])->count());
        $this->assertSame(1, DB::table('erp_operation_logs')->where('module', 'trade_platform')->where('target_id', $first['id'])->count());
        $this->assertGreaterThan(0, $first['legacy_id']);
        $this->assertArrayNotHasKey('legacy_payload', $first);
        $this->postJson('/api/v1/erp/master/trade-platforms', [...$payload, 'name' => $payload['name'].'改'])->assertUnprocessable()->assertJsonValidationErrors('client_request_id');
    }

    public function test_enabled_children_block_parent_disable_and_disabled_parent_blocks_child_enable(): void
    {
        $parent = $this->createPlatform();
        $child = $this->createPlatform(['parent_legacy_id' => $parent['legacy_id']]);
        $this->postJson("/api/v1/erp/master/trade-platforms/{$parent['id']}/status", ['status' => 'disabled', 'expected_version' => $parent['business_version']])->assertUnprocessable()->assertJsonValidationErrors('status');
        $disabledChild = $this->postJson("/api/v1/erp/master/trade-platforms/{$child['id']}/status", ['status' => 'disabled', 'expected_version' => $child['business_version']])->assertOk()->json('data');
        $this->postJson("/api/v1/erp/master/trade-platforms/{$parent['id']}/status", ['status' => 'disabled', 'expected_version' => $parent['business_version']])->assertOk()->assertJsonPath('data.enabled', false);
        $this->postJson("/api/v1/erp/master/trade-platforms/{$child['id']}/status", ['status' => 'enabled', 'expected_version' => $disabledChild['business_version']])->assertUnprocessable()->assertJsonValidationErrors('parent_legacy_id');
        $this->assertDatabaseHas('erp_sales_order_trade_platforms', ['id' => $child['id'], 'enabled' => false]);
    }

    public function test_stale_edits_and_third_level_platforms_are_rejected(): void
    {
        $this->freezeTime();
        $parent = $this->createPlatform();
        $child = $this->createPlatform(['parent_legacy_id' => $parent['legacy_id']]);
        $this->postJson('/api/v1/erp/master/trade-platforms', $this->payload(['parent_legacy_id' => $child['legacy_id']]))->assertUnprocessable()->assertJsonValidationErrors('parent_legacy_id');
        $update = $this->payload(['name' => $child['name'].'修改', 'parent_legacy_id' => $parent['legacy_id'], 'expected_version' => $child['business_version']]);
        unset($update['client_request_id']);
        $updated = $this->putJson("/api/v1/erp/master/trade-platforms/{$child['id']}", $update)->assertOk()->json('data');
        $this->assertSame($child['legacy_id'], $updated['legacy_id']);
        $this->assertNotSame($child['business_version'], $updated['business_version']);
        $this->putJson("/api/v1/erp/master/trade-platforms/{$child['id']}", $update)->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->putJson("/api/v1/erp/master/trade-platforms/{$child['id']}", [...$update, 'expected_version' => $updated['business_version'], 'parent_legacy_id' => 0])->assertUnprocessable()->assertJsonValidationErrors('parent_legacy_id');
    }

    public function test_pagination_uses_real_rows_and_endpoints_enforce_archive_permission(): void
    {
        $prefix = '档案分页'.Str::random(8);
        foreach ([1, 2, 3] as $n) $this->createPlatform(['name' => $prefix.$n]);
        $this->getJson('/api/v1/erp/master/trade-platforms?keyword='.$prefix.'&per_page=2&page=2')->assertOk()->assertJsonPath('total', 3)->assertJsonPath('current_page', 2)->assertJsonCount(1, 'data');
        $this->permissions = [];
        $this->getJson('/api/v1/erp/master/trade-platforms')->assertForbidden();
        $this->postJson('/api/v1/erp/master/trade-platforms', $this->payload())->assertForbidden();
    }

    private function payload(array $overrides = []): array
    {
        return [...['name' => '档案测试'.Str::random(10), 'short_name' => '', 'trade_type' => '', 'parent_legacy_id' => 0, 'sort' => 0, 'status' => 'enabled', 'client_request_id' => (string) Str::uuid()], ...$overrides];
    }

    private function createPlatform(array $overrides = []): array
    {
        return $this->postJson('/api/v1/erp/master/trade-platforms', $this->payload($overrides))->assertCreated()->json('data');
    }
}
