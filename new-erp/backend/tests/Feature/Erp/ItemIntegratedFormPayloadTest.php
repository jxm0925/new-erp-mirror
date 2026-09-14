<?php

namespace Tests\Feature\Erp;

use App\Http\Controllers\Api\V1\Erp\ItemIntegratedFormController;
use App\Models\Erp\ItemCategory;
use App\Models\Erp\Unit;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\ItemIntegratedFormApplicationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

class ItemIntegratedFormPayloadTest extends TestCase
{
    use DatabaseTransactions;

    public function test_create_ignores_read_only_fields_sent_by_item_form(): void
    {
        $suffix = strtoupper(Str::random(8));
        $category = ItemCategory::create([
            'category_code' => 'CAT-'.$suffix,
            'category_name' => '集成表单测试类目-'.$suffix,
            'category_type' => 'item',
            'status' => 'enabled',
        ]);
        $unit = Unit::query()->where('status', 'enabled')->where('is_legacy', false)->firstOrFail();

        $request = Request::create('/api/v1/erp/master/items/integrated-form', 'POST', [
            'activate' => true,
            'item' => [
                'item_code' => 'ITEM-'.$suffix,
                'item_name' => '只读字段过滤测试-'.$suffix,
                'item_type' => 'finished_product',
                'category_id' => $category->id,
                'unit_id' => $unit->id,
                'spec' => 'TEST',
                'is_purchase_item' => false,
                'is_stock_item' => true,
                'is_production_item' => true,
                'serial_tracking_mode' => 'required',
                'serial_number_prefix' => 'QA',
                'cost_method' => 'weighted_average',
                'status' => 'enabled',
                'remark' => '模拟 ItemForm.vue 的完整表单载荷',
                'base_unit_locked' => false,
            ],
            'policy' => [
                'template_code' => 'inventory_goods',
                'is_stock_managed' => true,
                'inventory_management_mode' => 'standard',
                'requires_custodian' => false,
                'is_returnable' => false,
                'requires_capitalization' => false,
                'serial_tracking_mode' => 'required',
                'production_execution_mode' => 'unit',
                'serial_generation_stage' => 'before_finished_goods_posting',
                'post_purchase_action' => 'inventory_receipt',
                'consumption_confirmation_mode' => 'none',
                'future_route' => 'inventory',
                'future_bearer_type' => 'company',
                'change_reason' => '回归测试',
            ],
        ]);

        $response = app(ItemIntegratedFormController::class)->store(
            $request,
            app(ItemIntegratedFormApplicationService::class),
            app(AuthContextService::class),
        );

        $this->assertSame(201, $response->getStatusCode());
        $this->assertDatabaseHas('erp_items', [
            'item_code' => 'ITEM-'.$suffix,
            'item_name' => '只读字段过滤测试-'.$suffix,
        ]);
    }
}
