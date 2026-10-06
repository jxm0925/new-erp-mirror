<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\Item;
use App\Models\Erp\Unit;
use App\Models\Erp\PurchaseRequest;
use App\Models\Erp\PurchasePlan;
use App\Services\Erp\AuthContextService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PurchaseRequestSpecModelTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(AuthContextService::class, function ($mock) {
            $mock->shouldReceive('currentUser')->andReturn((object) ['legacy_id' => 1, 'nickname' => '采购测试', 'username' => 'purchase-test']);
            $mock->shouldReceive('isSuperAdmin')->andReturn(true);
            $mock->shouldReceive('permissionCodes')->andReturn(['purchase.request.create', 'purchase.request.edit', 'purchase.request.delete', 'purchase.plan.create', 'purchase.plan.edit']);
        });
    }

    public function test_purchase_request_carries_over_and_allows_custom_spec_model(): void
    {
        $unit = Unit::firstOrCreate(['unit_code' => 'TEST_PCS_' . uniqid()], ['unit_name' => '件', 'unit_type' => 'count', 'symbol' => '件']);
        $item = Item::create([
            'item_code' => 'TEST_SPEC_' . uniqid(),
            'item_name' => '规格测试物料',
            'spec' => '标准规格-260L-机械',
            'model' => 'STD-260L',
            'unit_id' => $unit->id,
            'is_purchase_item' => true,
            'is_stock_item' => true,
            'status' => 'enabled',
        ]);

        // 1. Create request with default spec carried over
        $resDefault = $this->postJson('/api/v1/erp/purchase/requests', [
            'items' => [
                [
                    'item_id' => $item->id,
                    'purchase_quantity' => 5,
                ]
            ]
        ])->assertCreated()->json('data');

        $this->assertEquals('标准规格-260L-机械', $resDefault['items'][0]['spec_model']);

        // 2. Create request with custom modified spec
        $resCustom = $this->postJson('/api/v1/erp/purchase/requests', [
            'items' => [
                [
                    'item_id' => $item->id,
                    'spec_model' => '特规-超滤膜增强型-定制版',
                    'purchase_quantity' => 10,
                ]
            ]
        ])->assertCreated()->json('data');

        $this->assertEquals('特规-超滤膜增强型-定制版', $resCustom['items'][0]['spec_model']);

        // 3. Update request with another modified spec
        $resUpdate = $this->putJson("/api/v1/erp/purchase/requests/{$resCustom['id']}", [
            'items' => [
                [
                    'id' => $resCustom['items'][0]['id'],
                    'item_id' => $item->id,
                    'spec_model' => '特规-再次修改的规格V2',
                    'purchase_quantity' => 12,
                ]
            ]
        ])->assertOk()->json('data');

        $this->assertEquals('特规-再次修改的规格V2', $resUpdate['items'][0]['spec_model']);

        // 4. Submit and convert to plan, verify spec_model transfers to plan items
        $this->postJson("/api/v1/erp/purchase/requests/{$resCustom['id']}/submit")->assertOk();
        $planRes = $this->postJson("/api/v1/erp/purchase/requests/{$resCustom['id']}/to-plan")->assertOk()->json('data');

        $this->assertEquals('特规-再次修改的规格V2', $planRes['items'][0]['spec_model']);
    }
}
