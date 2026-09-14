<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\Bom;
use App\Models\Erp\DocumentNumberReservation;
use App\Models\Erp\Item;
use App\Models\Erp\Product;
use App\Models\Erp\Sku;
use App\Models\Erp\SkuItemRelation;
use App\Models\Erp\Unit;
use App\Services\Erp\BomMatcher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

class BomItemOnlyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $user = (object) [
            'legacy_id' => 910001,
            'username' => 'bom_item_only_test',
            'nickname' => 'BOM 测试员',
            'auth_group_names' => '[]',
            'status' => 'normal',
        ];
        $this->mock(\App\Services\Erp\AuthContextService::class, function (MockInterface $mock) use ($user): void {
            $mock->shouldReceive('currentUser')->andReturn($user);
            $mock->shouldReceive('currentLegacyId')->andReturn($user->legacy_id);
        });
    }

    public function test_finished_product_bom_with_complete_product_sku_relation_is_created(): void
    {
        $unit = $this->unit();
        $output = $this->item($unit, 'FG', true, 'finished_product');
        $component = $this->item($unit, 'RM', false, 'raw_material');
        [$product, $sku] = $this->productAndSku($unit);

        SkuItemRelation::create([
            'sku_id' => $sku->id,
            'item_id' => $output->id,
            'relation_type' => 'finished_product',
            'qty' => 1,
            'unit_id' => $unit->id,
            'is_primary' => true,
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/v1/erp/bom/boms', $this->createPayload(
            $output,
            $component,
            $product->id,
            $sku->id
        ));

        $response->assertCreated()
            ->assertJsonPath('data.product_id', $product->id)
            ->assertJsonPath('data.sku_id', $sku->id)
            ->assertJsonPath('data.output_item_id', $output->id);
    }

    public function test_item_only_semi_finished_bom_can_be_created_and_updated(): void
    {
        $unit = $this->unit();
        $output = $this->item($unit, 'SFG', true, 'semi_finished');
        $component = $this->item($unit, 'RM', false, 'raw_material');

        $created = $this->postJson('/api/v1/erp/bom/boms', $this->createPayload(
            $output,
            $component,
            null,
            null
        ));

        $created->assertCreated()
            ->assertJsonPath('data.product_id', null)
            ->assertJsonPath('data.sku_id', null)
            ->assertJsonPath('data.output_item_id', $output->id);

        $bomId = (int) $created->json('data.id');
        $updatePayload = $this->basePayload($output, $component, null, null);
        $updatePayload['bom_name'] = 'Item-only 半成品 BOM（已编辑）';

        $this->putJson("/api/v1/erp/bom/boms/{$bomId}", $updatePayload)
            ->assertOk()
            ->assertJsonPath('data.bom_name', 'Item-only 半成品 BOM（已编辑）')
            ->assertJsonPath('data.product_id', null)
            ->assertJsonPath('data.sku_id', null);
    }

    public function test_finished_product_bom_still_rejects_wrong_sku_output_item_relation(): void
    {
        $unit = $this->unit();
        $relatedOutput = $this->item($unit, 'FG-PRIMARY', true, 'finished_product');
        $wrongOutput = $this->item($unit, 'FG-WRONG', true, 'finished_product');
        $component = $this->item($unit, 'RM', false, 'raw_material');
        [$product, $sku] = $this->productAndSku($unit);

        SkuItemRelation::create([
            'sku_id' => $sku->id,
            'item_id' => $relatedOutput->id,
            'relation_type' => 'finished_product',
            'qty' => 1,
            'unit_id' => $unit->id,
            'is_primary' => true,
            'status' => 'active',
        ]);

        $this->postJson('/api/v1/erp/bom/boms', $this->createPayload(
            $wrongOutput,
            $component,
            $product->id,
            $sku->id
        ))->assertStatus(422)
            ->assertJsonPath('message', '产出 Item 必须是该 SKU 当前有效的默认生产/履约 Item。');
    }

    public function test_product_and_sku_must_be_both_present_or_both_null(): void
    {
        $unit = $this->unit();
        $output = $this->item($unit, 'SFG', true, 'semi_finished');
        $component = $this->item($unit, 'RM', false, 'raw_material');
        [$product, $sku] = $this->productAndSku($unit);

        $productOnly = $this->createPayload($output, $component, $product->id, null);
        unset($productOnly['sku_id']);
        $this->postJson('/api/v1/erp/bom/boms', $productOnly)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sku_id');

        $skuOnly = $this->createPayload($output, $component, null, $sku->id);
        unset($skuOnly['product_id']);
        $this->postJson('/api/v1/erp/bom/boms', $skuOnly)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('product_id');
    }

    public function test_item_only_bom_rejects_disabled_or_non_production_output_item(): void
    {
        $unit = $this->unit();
        $nonProductionOutput = $this->item($unit, 'NON-PROD', false, 'semi_finished');
        $disabledOutput = $this->item($unit, 'DISABLED', true, 'semi_finished');
        $disabledOutput->update(['status' => 'disabled']);
        $component = $this->item($unit, 'RM', false, 'raw_material');

        $this->postJson('/api/v1/erp/bom/boms', $this->createPayload(
            $nonProductionOutput,
            $component,
            null,
            null
        ))->assertStatus(422)
            ->assertJsonPath('message', 'Item-only BOM 的产出 Item 必须已启用且可用于生产。');

        $this->postJson('/api/v1/erp/bom/boms', $this->createPayload(
            $disabledOutput,
            $component,
            null,
            null
        ))->assertStatus(422)
            ->assertJsonPath('message', 'Item-only BOM 的产出 Item 必须已启用且可用于生产。');
    }

    public function test_null_product_and_sku_match_only_item_only_bom_for_same_output_item(): void
    {
        $unit = $this->unit();
        $output = $this->item($unit, 'MATCH', true, 'semi_finished');
        [$product, $sku] = $this->productAndSku($unit);

        $finishedBom = Bom::create([
            'bom_no' => 'BOM-MATCH-FG-'.strtoupper(Str::random(10)),
            'bom_name' => '同产出 Item 成品 BOM',
            'product_id' => $product->id,
            'sku_id' => $sku->id,
            'output_item_id' => $output->id,
            'bom_type' => 'standard',
            'version' => 'V1.0',
            'is_default' => true,
            'status' => 'active',
            'audit_status' => 'approved',
        ]);
        $itemOnlyBom = Bom::create([
            'bom_no' => 'BOM-MATCH-ITEM-'.strtoupper(Str::random(10)),
            'bom_name' => '同产出 Item 的 Item-only BOM',
            'product_id' => null,
            'sku_id' => null,
            'output_item_id' => $output->id,
            'bom_type' => 'standard',
            'version' => 'V1.0',
            'is_default' => false,
            'status' => 'active',
            'audit_status' => 'approved',
        ]);

        $matcher = app(BomMatcher::class);
        $this->assertSame($itemOnlyBom->id, $matcher->match(null, null, $output->id)['bom_id']);
        $this->assertSame($finishedBom->id, $matcher->match($product->id, $sku->id, $output->id)['bom_id']);
    }

    private function createPayload(
        Item $output,
        Item $component,
        ?int $productId,
        ?int $skuId
    ): array {
        $sessionId = (string) Str::uuid();
        $token = (string) Str::uuid();
        DocumentNumberReservation::create([
            'document_type' => 'bom',
            'creation_session_id' => $sessionId,
            'document_no' => 'BOM-TEST-'.strtoupper(Str::random(12)),
            'reservation_token' => $token,
            'status' => 'reserved',
            'expires_at' => now()->addHour(),
        ]);

        return $this->basePayload($output, $component, $productId, $skuId) + [
            'reservation_token' => $token,
            'creation_session_id' => $sessionId,
        ];
    }

    private function basePayload(
        Item $output,
        Item $component,
        ?int $productId,
        ?int $skuId
    ): array {
        return [
            'bom_name' => 'Item-only BOM 测试',
            'product_id' => $productId,
            'sku_id' => $skuId,
            'output_item_id' => $output->id,
            'bom_type' => 'standard',
            'version' => 'V1.0',
            'effective_date' => now()->toDateString(),
            'items' => [[
                'line_no' => 10,
                'component_item_id' => $component->id,
                'qty' => 1,
                'loss_rate' => 0,
                'fixed_qty' => 0,
                'replaceable' => false,
            ]],
        ];
    }

    private function unit(): Unit
    {
        $suffix = strtoupper(Str::random(10));

        return Unit::create([
            'unit_code' => 'BOM-U-'.$suffix,
            'unit_name' => '件',
            'unit_type' => 'quantity',
            'decimal_places' => 0,
            'is_base' => true,
            'status' => 'enabled',
        ]);
    }

    private function item(Unit $unit, string $label, bool $productionCapable, string $type): Item
    {
        $suffix = strtoupper(Str::random(10));

        return Item::create([
            'item_code' => 'BOM-'.$label.'-'.$suffix,
            'item_name' => 'BOM 测试 '.$label,
            'item_type' => $type,
            'unit_id' => $unit->id,
            'is_stock_item' => true,
            'is_production_item' => $productionCapable,
            'status' => 'enabled',
        ]);
    }

    private function productAndSku(Unit $unit): array
    {
        $suffix = strtoupper(Str::random(10));
        $product = Product::create([
            'product_code' => 'BOM-P-'.$suffix,
            'product_name' => 'BOM 测试成品',
            'unit_id' => $unit->id,
            'status' => 'enabled',
        ]);
        $sku = Sku::create([
            'product_id' => $product->id,
            'sales_unit_id' => $unit->id,
            'sku_code' => 'BOM-S-'.$suffix,
            'sku_name' => 'BOM 测试 SKU',
            'order_line_type' => 'physical',
            'fulfillment_type' => 'physical',
            'status' => 'enabled',
        ]);

        return [$product, $sku];
    }
}
