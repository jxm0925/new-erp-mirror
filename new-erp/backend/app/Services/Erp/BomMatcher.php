<?php

namespace App\Services\Erp;

use App\Models\Erp\Bom;

class BomMatcher
{
    public function match(?int $productId, ?int $skuId, ?int $itemId): array
    {
        if (!$itemId) {
            return $this->blocked('not_checked', 'Item 未匹配，不能匹配 BOM');
        }

        $query = Bom::with('items')
            ->where('output_item_id', $itemId)
            ->where('audit_status', 'approved')
            ->whereIn('status', ['active', 'enabled', 'published'])
            ->where(function ($q) {
                $q->whereNull('effective_date')->orWhere('effective_date', '<=', now()->toDateString());
            })
            ->where(function ($q) {
                $q->whereNull('expire_date')->orWhere('expire_date', '>=', now()->toDateString());
            });

        $matches = $query->where(function ($q) use ($productId, $skuId) {
            $q->where(function ($exact) use ($productId, $skuId) {
                if ($productId) $exact->where('product_id', $productId);
                if ($skuId) $exact->where('sku_id', $skuId);
            })->orWhere(function ($itemOnly) {
                $itemOnly->whereNull('product_id')->whereNull('sku_id');
            });
        })->orderByDesc('is_default')->orderByDesc('id')->get();

        if ($matches->isEmpty()) return $this->blocked('missing', '未找到已审核且有效的 BOM');
        if ($matches->count() > 1 && $matches->where('is_default', true)->count() !== 1) {
            return [
                'status' => 'conflict',
                'block_reason' => '存在多个可用 BOM，且默认 BOM 不唯一',
                'bom_id' => null,
                'bom_version_id' => null,
                'bom_version' => null,
                'bom_snapshot' => null,
                'candidates' => $matches->map(fn ($bom) => $this->candidate($bom))->all(),
            ];
        }

        $bom = $matches->where('is_default', true)->first() ?: $matches->first();
        return [
            'status' => 'matched',
            'block_reason' => null,
            'bom_id' => $bom->id,
            'bom_version_id' => $bom->id,
            'bom_version' => $bom->version,
            'bom_snapshot' => [
                'id' => $bom->id,
                'bom_no' => $bom->bom_no,
                'bom_name' => $bom->bom_name,
                'version' => $bom->version,
                'items' => $bom->items->map(fn ($item) => [
                    'line_no' => $item->line_no,
                    'component_item_id' => $item->component_item_id,
                    'component_item_code' => $item->component_item_code,
                    'component_item_name' => $item->component_item_name,
                    'qty' => $item->qty,
                    'unit_id' => $item->unit_id,
                    'loss_rate' => $item->loss_rate,
                ])->all(),
            ],
            'candidates' => [$this->candidate($bom)],
        ];
    }

    private function blocked(string $status, string $reason): array
    {
        return [
            'status' => $status,
            'block_reason' => $reason,
            'bom_id' => null,
            'bom_version_id' => null,
            'bom_version' => null,
            'bom_snapshot' => null,
            'candidates' => [],
        ];
    }

    private function candidate(Bom $bom): array
    {
        return ['id' => $bom->id, 'bom_no' => $bom->bom_no, 'bom_name' => $bom->bom_name, 'version' => $bom->version, 'is_default' => $bom->is_default];
    }
}
