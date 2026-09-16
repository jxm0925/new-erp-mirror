<?php

namespace App\Services\Erp;

use App\Models\Erp\Bom;

class BomMatcher
{
    public function __construct(private readonly LengthCutRequirementService $lengthCutRequirements)
    {
    }

    public function match(?int $productId, ?int $skuId, ?int $itemId, ?array $configuration = null): array
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

        $matches = $query
            ->when($productId === null && $skuId === null, function ($itemOnly) {
                $itemOnly->whereNull('product_id')->whereNull('sku_id');
            }, function ($matchedScope) use ($productId, $skuId) {
                $matchedScope->where(function ($q) use ($productId, $skuId) {
                    $q->where(function ($exact) use ($productId, $skuId) {
                        if ($productId) $exact->where('product_id', $productId);
                        if ($skuId) $exact->where('sku_id', $skuId);
                    })->orWhere(function ($itemOnly) {
                        $itemOnly->whereNull('product_id')->whereNull('sku_id');
                    });
                });
            });
        $matches = $matches->orderByDesc('is_default')->orderByDesc('id')->get();

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
        $resolvedItems = $this->lengthCutRequirements->resolveBomLines($bom, $configuration);
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
                'resolution_source' => collect((array) data_get($configuration, 'cut_requirements', []))->isEmpty()
                    ? 'approved_bom'
                    : 'approved_bom_with_configuration_cut_requirements',
                'configuration_cut_requirements' => data_get($configuration, 'cut_requirements', []),
                'items' => $resolvedItems->all(),
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
