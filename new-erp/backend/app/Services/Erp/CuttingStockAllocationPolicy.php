<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;

/** Whole-stock movements and frozen cutting coverage are different quantities. */
final class CuttingStockAllocationPolicy
{
    public static function capacity(array $geometry, array $dimensions): int
    {
        if (($geometry['quantity_basis'] ?? null) === 'total_length') return 0;
        if (! FrozenCuttingStockPolicy::allows($geometry, $dimensions) || $geometry['mode'] === 'none') return 0;
        if ($geometry['mode'] === 'sheet' && ($dimensions['shape'] ?? null) === 'IRREGULAR')
            return (int) bcdiv(FrozenCuttingStockPolicy::area($dimensions), bcmul($geometry['length_mm'], $geometry['width_mm'], 8), 0);
        $length = FrozenCuttingStockPolicy::positiveDecimal($dimensions['length_mm'] ?? null);
        $normal = (int) bcdiv($length, $geometry['length_mm'], 0);
        if ($geometry['mode'] === 'length') return $normal;
        $width = FrozenCuttingStockPolicy::positiveDecimal($dimensions['width_mm'] ?? null);
        $normal *= (int) bcdiv($width, $geometry['width_mm'], 0);
        $rotated = $geometry['allow_rotation']
            ? (int) bcdiv($length, $geometry['width_mm'], 0) * (int) bcdiv($width, $geometry['length_mm'], 0) : 0;
        // A rectangular grid proves this capacity; no unrecorded nesting or kerf is invented.
        return max($normal, $rotated);
    }

    public static function plan(array $sources, int $pieces, int $targetPieces, string $targetRequired, int $formalPieces, string $formalRequired): array
    {
        if ($pieces <= 0 || $pieces > $targetPieces || $pieces > $formalPieces || count($sources) > $pieces
            || array_sum(array_column($sources, 'capacity_piece_qty')) < $pieces) self::fail('所选整根或整张不能覆盖本次分配的下料件数。');
        $ids = array_filter(array_column($sources, 'physical_material_id'), fn ($id) => $id !== null);
        if (count($ids) !== count(array_unique($ids))) self::fail('同一实物不能在本次分配中重复使用。');
        $remaining = $pieces; $assigned = 0; $targetBefore = '0'; $formalBefore = '0';
        foreach ($sources as $index => &$source) {
            $source['source_key'] = isset($source['physical_material_id']) ? 'physical:'.$source['physical_material_id'] : 'root:'.$index;
            $source['covered_piece_qty'] = min((int) $source['capacity_piece_qty'], $remaining - (count($sources) - $index - 1));
            if ($source['covered_piece_qty'] <= 0) self::fail('每一根或每一张必须明确覆盖至少一件。');
            $assigned += $source['covered_piece_qty']; $remaining -= $source['covered_piece_qty'];
            $targetAfter = bcdiv(bcmul($targetRequired, (string) $assigned, 16), (string) $targetPieces, 8);
            $formalAfter = bcdiv(bcmul($formalRequired, (string) $assigned, 16), (string) $formalPieces, 8);
            $source['coverage_qty'] = bcsub($targetAfter, $targetBefore, 8);
            $source['formal_coverage_qty'] = bcsub($formalAfter, $formalBefore, 8);
            if (bccomp($source['coverage_qty'], '0', 8) <= 0 || bccomp($source['formal_coverage_qty'], '0', 8) <= 0)
                self::fail('单根或单张分配的理论覆盖低于可记录精度，请合并覆盖件数后再配料。');
            $targetBefore = $targetAfter; $formalBefore = $formalAfter;
        }
        unset($source);
        return ['version' => 1, 'sources' => array_values($sources), 'planned_cut_piece_qty' => $pieces,
            'planned_coverage_qty' => $targetBefore, 'planned_formal_coverage_qty' => $formalBefore,
            'actual_cut_piece_qty' => 0, 'actual_coverage_qty' => '0.00000000', 'actual_formal_coverage_qty' => '0.00000000',
            'received_cut_piece_qty' => 0, 'received_coverage_qty' => '0.00000000', 'received_formal_coverage_qty' => '0.00000000'];
    }

    public static function select(array $allocation, int $quantity, ?array $physicalIds = null, array $excludedKeys = [], bool $actualOnly = false): array
    {
        $candidates = array_values(array_filter($allocation['sources'] ?? [], fn ($source) => ! in_array($source['source_key'], $excludedKeys, true)
            && (! $actualOnly || ($source['picked'] ?? false))));
        if ($physicalIds !== null) {
            if (count($physicalIds) !== $quantity || count($physicalIds) !== count(array_unique($physicalIds))) self::fail('实物数量或身份重复，不能分配下料覆盖。');
            $candidates = array_values(array_filter($candidates, fn ($source) => in_array($source['physical_material_id'] ?? 0, $physicalIds, true)));
        }
        $selected = array_slice($candidates, 0, $quantity);
        if ($quantity < 0 || count($selected) !== $quantity) self::fail('实际领料必须引用本次冻结且尚未交接的整根或整张。');
        return ['version' => 1, 'sources' => $selected, 'members' => CuttingMemberAllocationPolicy::members($selected), 'covered_piece_qty' => array_sum(array_column($selected, 'covered_piece_qty')),
            'coverage_qty' => self::sum($selected, 'coverage_qty'), 'formal_coverage_qty' => self::sum($selected, 'formal_coverage_qty')];
    }

    public static function sum(array $sources, string $key): string
    {
        $sum = '0.00000000'; foreach ($sources as $source) $sum = bcadd($sum, (string) ($source[$key] ?? 0), 8); return $sum;
    }

    private static function fail(string $message): never { throw new WorkOrderDomainException('cutting_allocation_invalid', $message, 422); }
}
