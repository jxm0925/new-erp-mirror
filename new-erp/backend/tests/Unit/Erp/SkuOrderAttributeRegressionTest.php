<?php

namespace Tests\Unit\Erp;

use App\Http\Controllers\Api\V1\Erp\SalesOrderController;
use App\Models\Erp\{Item, Product, SalesOrderLine, Sku, SkuItemRelation};
use App\Services\Erp\SkuItemMatcher;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Support\Collection;
use ReflectionMethod;
use Tests\TestCase;

class SkuOrderAttributeRegressionTest extends TestCase
{
    public function test_order_line_electric_and_pump_values_never_change_primary_item_match(): void
    {
        [$matcher, $product, $sku, $item] = $this->physicalMatcher();
        $skuBefore = $sku->getAttributes();

        foreach ([
            ['electric' => '220V', 'need_pump' => true],
            ['electric' => '380V', 'need_pump' => false],
            ['electric' => null, 'need_pump' => null],
        ] as $attributes) {
            $result = $matcher->match(['product_id' => $product->id, 'sku_id' => $sku->id] + $attributes);
            $this->assertSame('matched', $result['match_status']);
            $this->assertSame($item->id, $result['matched_item_id']);
            $this->assertSame('sku_primary', $result['match_rule']);
        }

        // 属性属于订单行快照：SKU、本次默认 Item 关系均不被匹配过程改写。
        $this->assertSame($skuBefore, $sku->getAttributes());
        $this->assertCount(1, $matcher->relationsFor($sku->id));
    }

    public function test_service_and_no_delivery_skus_without_item_are_explicitly_not_required(): void
    {
        foreach (['service', 'no_delivery', 'virtual'] as $type) {
            [$matcher, $product, $sku] = $this->physicalMatcher($type, false);
            $result = $matcher->match(['product_id' => $product->id, 'sku_id' => $sku->id]);
            $this->assertSame('not_required', $result['match_status']);
            $this->assertNull($result['matched_item_id']);
        }
    }

    public function test_physical_sku_without_primary_item_or_with_disabled_primary_is_not_found(): void
    {
        [$matcher, $product, $sku] = $this->physicalMatcher('physical', false);
        $this->assertSame('not_found', $matcher->match(['product_id' => $product->id, 'sku_id' => $sku->id])['match_status']);

        [$matcher, $product, $sku] = $this->physicalMatcher('physical', true, 'disabled');
        $this->assertSame('not_found', $matcher->match(['product_id' => $product->id, 'sku_id' => $sku->id])['match_status']);
    }

    public function test_multiple_enabled_primary_items_are_a_conflict(): void
    {
        [$matcher, $product, $sku, $item] = $this->physicalMatcher();
        $secondItem = new Item(['id' => 202, 'item_code' => 'ITEM-2', 'item_name' => '第二 Item', 'status' => 'enabled']);
        $matcher->addRelation($this->relation($sku, $secondItem, 'enabled'));

        $result = $matcher->match(['product_id' => $product->id, 'sku_id' => $sku->id]);
        $this->assertSame('conflict', $result['match_status']);
        $this->assertCount(2, $result['conflict_candidates']);
        $this->assertSame($item->id, $result['conflict_candidates'][0]['item_id']);
    }

    public function test_required_order_attributes_are_allowed_in_draft_but_block_at_confirmation_gate(): void
    {
        [$matcher, $product, $sku] = $this->physicalMatcher();
        $sku->setAttribute('electric_mode', 'required');
        $sku->setAttribute('need_pump_mode', 'required');

        // 草稿阶段只维护快照；不因属性尚未填写而阻塞 Item 匹配。
        $this->assertSame('matched', $matcher->match(['product_id' => $product->id, 'sku_id' => $sku->id])['match_status']);

        $line = new SalesOrderLine(['line_no' => 1, 'electric' => null, 'need_pump' => null]);
        $line->setRelation('sku', $sku);
        $method = new ReflectionMethod(SalesOrderController::class, 'assertLineOrderAttributes');
        $method->setAccessible(true);
        try {
            $method->invoke(app(SalesOrderController::class), $line);
            $this->fail('确认门禁应阻止缺少必填电压的订单行。');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $line->electric = '220V';
        $line->need_pump = false; // false 是明确“不需要”，不是“未填写”。
        $method->invoke(app(SalesOrderController::class), $line);
        $this->assertFalse((bool) $line->need_pump);
    }

    private function physicalMatcher(string $fulfillmentType = 'physical', bool $withPrimary = true, string $relationStatus = 'enabled'): array
    {
        $product = new Product(['id' => 11, 'product_code' => 'P-1', 'product_name' => '测试产品']);
        $sku = new Sku(['id' => 101, 'product_id' => 11, 'sku_code' => 'SKU-1', 'sku_name' => '测试 SKU', 'fulfillment_type' => $fulfillmentType, 'status' => 'enabled']);
        $sku->setRelation('product', $product);
        $item = new Item(['id' => 201, 'item_code' => 'ITEM-1', 'item_name' => '测试 Item', 'status' => 'enabled']);
        $relations = $withPrimary ? collect([$this->relation($sku, $item, $relationStatus)]) : collect();
        return [new InMemorySkuItemMatcher([$sku], [$product], $relations), $product, $sku, $item];
    }

    private function relation(Sku $sku, Item $item, string $status): SkuItemRelation
    {
        $relation = new SkuItemRelation(['id' => random_int(1000, 9999), 'sku_id' => $sku->id, 'item_id' => $item->id, 'is_primary' => true, 'status' => $status]);
        $relation->setRelation('item', $item);
        return $relation;
    }
}

class InMemorySkuItemMatcher extends SkuItemMatcher
{
    public function __construct(private array $skus, private array $products, private Collection $relations) {}

    public function addRelation(SkuItemRelation $relation): void
    {
        $this->relations->push($relation);
    }

    public function relationsFor(int $skuId): Collection
    {
        return $this->primaryRelations($skuId);
    }

    protected function findSku(int $skuId): ?Sku
    {
        return collect($this->skus)->first(fn (Sku $sku) => (int) $sku->id === $skuId);
    }

    protected function findProduct(int $productId): ?Product
    {
        return collect($this->products)->first(fn (Product $product) => (int) $product->id === $productId);
    }

    protected function primaryRelations(int $skuId)
    {
        return $this->relations
            ->filter(fn (SkuItemRelation $relation) => (int) $relation->sku_id === $skuId && $relation->status === 'enabled' && $relation->is_primary && $relation->item && $relation->item->status === 'enabled')
            ->values();
    }
}
