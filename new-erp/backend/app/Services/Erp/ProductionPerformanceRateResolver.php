<?php

namespace App\Services\Erp;

use App\Models\Erp\Item;
use App\Models\Erp\ItemCategory;
use App\Models\Erp\Product;
use App\Models\Erp\ProductionPerformanceBinding;
use App\Models\Erp\ProductionPerformanceTemplate;

/**
 * 模板兜底比例：商品分类（本级 → 逐级上级）→ 默认模板 → 无。
 * BOM 节点上的 override/zero 优先于这里（见 BomOperationStandardService）。
 * 模板里没有的工序不算“已配置”，继续往下一层找；工单生成时快照结果，模板后改不追溯。
 */
final class ProductionPerformanceRateResolver
{
    private const MAX_DEPTH = 10;

    /**
     * 按销售对象分类解析：有商品档案取商品分类，没有商品时取物料分类；分类缺失直接走默认模板。
     * @return array<int, array{rate: string, source: string, template: array}> 以 operation_id 为键
     */
    public function forItem(?int $itemId, ?int $productId): array
    {
        $categoryId = $productId ? Product::query()->whereKey($productId)->value('category_id')
            : ($itemId ? Item::query()->whereKey($itemId)->value('category_id') : null);
        $chain = [];
        $seen = [];
        while ($categoryId && count($chain) < self::MAX_DEPTH && ! isset($seen[$categoryId])) {
            $seen[$categoryId] = true;
            $chain[] = (int) $categoryId;
            $categoryId = ItemCategory::query()->whereKey($categoryId)->value('parent_id');
        }
        $keys = array_merge(array_map(fn ($id) => ProductionPerformanceBinding::categoryKey($id), $chain), [ProductionPerformanceBinding::DEFAULT_KEY]);
        $bindings = ProductionPerformanceBinding::query()->with('template.lines')->whereIn('binding_key', $keys)->get()->keyBy('binding_key');
        $rates = [];
        foreach ($keys as $key) {
            $template = $bindings->get($key)?->template;
            if (! $template || $template->status !== 'enabled') continue;
            $source = $key === ProductionPerformanceBinding::DEFAULT_KEY ? 'default' : 'category';
            foreach ($template->lines as $line) {
                $operationId = (int) $line->operation_id;
                // 越靠前（越具体）的层级优先；已命中的工序不被上级或默认覆盖。
                $rates[$operationId] ??= ['rate' => bcadd((string) $line->performance_rate, '0', 6), 'source' => $source, 'template' => $this->meta($template)];
            }
        }
        return $rates;
    }

    /** 默认模板（用于“从模板快速填入”等展示）。 */
    public function defaultTemplate(): ?array
    {
        $template = ProductionPerformanceBinding::query()->with('template')->where('binding_key', ProductionPerformanceBinding::DEFAULT_KEY)->first()?->template;
        return $template && $template->status === 'enabled' ? $this->meta($template) : null;
    }

    private function meta(ProductionPerformanceTemplate $template): array
    {
        return ['id' => (int) $template->id, 'template_name' => $template->template_name, 'business_version' => (int) $template->business_version];
    }
}
