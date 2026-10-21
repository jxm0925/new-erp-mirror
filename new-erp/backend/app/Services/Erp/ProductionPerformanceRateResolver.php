<?php

namespace App\Services\Erp;

use App\Models\Erp\ProductionPerformanceBinding;
use App\Models\Erp\ProductionPerformanceTemplate;

/**
 * 工序提成比例的唯一来源：路线指定模板 → 默认模板 → 无（不计提成）。
 * 路线不再保存比例；工单和发货包装在生成时按这里的结果快照，模板之后再改不追溯。
 */
final class ProductionPerformanceRateResolver
{
    /** @return array{template: ?array, source: ?string, rates: array<int, string>} */
    public function forRouting(int $routingId): array
    {
        $bindings = ProductionPerformanceBinding::query()->with('template.lines')
            ->whereIn('binding_key', [ProductionPerformanceBinding::routingKey($routingId), ProductionPerformanceBinding::DEFAULT_KEY])->get()->keyBy('binding_key');
        foreach ([ProductionPerformanceBinding::routingKey($routingId) => 'routing', ProductionPerformanceBinding::DEFAULT_KEY => 'default'] as $key => $source) {
            $template = $bindings->get($key)?->template;
            if ($template && $template->status === 'enabled') return $this->result($template, $source);
        }
        return ['template' => null, 'source' => null, 'rates' => []];
    }

    private function result(ProductionPerformanceTemplate $template, string $source): array
    {
        return ['template' => ['id' => (int) $template->id, 'template_name' => $template->template_name, 'business_version' => (int) $template->business_version],
            'source' => $source,
            'rates' => $template->lines->mapWithKeys(fn ($line) => [(int) $line->operation_id => bcadd((string) $line->performance_rate, '0', 6)])->all()];
    }
}
