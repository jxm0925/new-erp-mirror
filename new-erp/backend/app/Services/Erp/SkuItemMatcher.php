<?php

namespace App\Services\Erp;

use App\Models\Erp\Product;
use App\Models\Erp\Sku;
use App\Models\Erp\SkuItemRelation;

class SkuItemMatcher
{
    public function match(array $input): array
    {
        $productId = $input['product_id'] ?? null;
        $skuId = $input['sku_id'] ?? null;
        if (!$skuId) return $this->result('not_required', null, null, [], '未选择 SKU，无法匹配 Item');

        $sku = $this->findSku($skuId);
        if (!$sku) return $this->result('not_found', null, null, [], 'SKU 不存在');

        // 服务、虚拟/免发货 SKU 不进入 Item 匹配链路。这里的“无需”是
        // 业务结论，不是“没有匹配到”，避免后续生产误把服务行当成待维护 Item。
        if (in_array($this->orderLineType($sku), ['service', 'no_delivery'], true)) {
            return $this->result('not_required', null, 'not_required', [], null);
        }

        $product = $productId ? $this->findProduct($productId) : $sku->product;
        if (!$product) return $this->result('not_found', null, null, [], 'Product 不存在');
        if ((int) $sku->product_id !== (int) $product->id) {
            return $this->result('conflict', null, null, [], 'SKU 不属于当前 Product');
        }

        // The default Item is a direct SKU relationship. Order attributes such as
        // electric and need_pump never participate in this resolution.
        $relations = $this->primaryRelations($sku->id);

        if ($relations->count() === 1) {
            $rel = $relations->first();
            return $this->result('matched', $rel->item_id, 'sku_primary', [[
                'relation_id' => $rel->id,
                'item_id' => $rel->item_id,
                'item_code' => $rel->item->item_code,
                'item_name' => $rel->item->item_name,
            ]], null);
        }

        if ($relations->count() > 1) {
            return $this->result('conflict', null, 'sku_primary', $relations->map(fn ($rel) => [
                'relation_id' => $rel->id,
                'item_id' => $rel->item_id,
                'item_code' => $rel->item?->item_code,
                'item_name' => $rel->item?->item_name,
            ])->all(), 'SKU-Item 存在多个默认 Item，请维护唯一默认关系');
        }

        return $this->result('not_found', null, 'sku_primary', [], '当前 SKU 尚未维护可用默认 Item 关系');
    }

    private function result(string $status, ?int $itemId, ?string $rule, array $candidates, ?string $reason): array
    {
        return [
            'matched_item_id' => $itemId,
            'match_status' => $status,
            'match_rule' => $rule,
            'conflict_candidates' => $candidates,
            'block_reason' => $reason,
        ];
    }

    protected function findSku(int $skuId): ?Sku
    {
        return Sku::find($skuId);
    }

    protected function findProduct(int $productId): ?Product
    {
        return Product::find($productId);
    }

    protected function primaryRelations(int $skuId)
    {
        return SkuItemRelation::with('item')
            ->where('sku_id', $skuId)
            ->where('status', 'active')
            ->where('is_primary', true)
            ->whereNotNull('item_id')
            ->get()
            ->filter(fn ($rel) => $rel->item && $rel->item->status === 'enabled')
            ->values();
    }

    protected function orderLineType(Sku $sku): string
    {
        $type = $sku->order_line_type ?: $sku->fulfillment_type;
        return $type === 'virtual' ? 'no_delivery' : (string) $type;
    }
}
