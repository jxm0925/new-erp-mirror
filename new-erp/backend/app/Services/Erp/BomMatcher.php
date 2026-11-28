<?php

namespace App\Services\Erp;

use App\Models\Erp\Bom;

class BomMatcher
{
    public function __construct(private readonly BomExplosionEngine $engine)
    {
    }

    public function eligibleQuery(?string $businessDate = null): \Illuminate\Database\Eloquent\Builder
    {
        return $this->engine->eligibleQuery($businessDate);
    }

    public function match(?int $productId, ?int $skuId, ?int $itemId, ?array $configuration = null, ?int $pinnedBomId = null, bool $lock = false, bool $requireMaterials = false): array
    {
        if (!$itemId) {
            return $this->blocked('not_checked', 'Item 未匹配，不能匹配 BOM');
        }

        $selection = $this->engine->select($productId, $skuId, $itemId, $pinnedBomId, lock: $lock, requireMaterials: $requireMaterials);
        if ($selection['status'] !== 'matched') {
            $result = $this->blocked($selection['status'], $selection['block_reason']);
            $result['candidates'] = array_map(fn (Bom $bom) => $this->candidate($bom), $selection['candidates']);
            return $result;
        }
        $bom = $selection['bom'];
        $resolvedItems = $this->engine->lines($bom, $configuration);
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

    public static function emptyMaterialsMessage(string $bomNo): string
    {
        return BomExplosionEngine::emptyMaterialsMessage($bomNo);
    }

    public function loadLockedLines(Bom $bom): void
    {
        $this->engine->loadLockedLines($bom);
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
