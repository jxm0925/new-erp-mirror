<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;

/** Stock meters/area remain ledger quantities; source identities remain whole roots/boards. */
final class CuttingStockQuantityPolicy
{
    public static function sourceQuantity(string $stockUnitKind, array $dimensions): string
    {
        if (in_array($stockUnitKind, ['root', 'sheet'], true)) return '1.00000000';
        if ($stockUnitKind === 'm2' && ($dimensions['shape'] ?? null) === 'IRREGULAR') {
            $area = FrozenCuttingStockPolicy::area($dimensions);
            if ($area === null) self::fail('异形板材缺少实际面积，不能换算平方米库存。');
            $exact = bcdiv($area, '1000000', 16);
            $quantity = bcadd($exact, '0', 8);
            if (bccomp($exact, $quantity, 16) !== 0 || bccomp($quantity, '0', 8) <= 0) self::fail('实际面积超出库存精度。');
            return $quantity;
        }
        $length = FrozenCuttingStockPolicy::positiveDecimal($dimensions['length_mm'] ?? null);
        $width = FrozenCuttingStockPolicy::positiveDecimal($dimensions['width_mm'] ?? null);
        if (! $length || ($stockUnitKind === 'm2' && ! $width)) self::fail('米或平方米领料缺少实际整根长度或整张长宽。');
        $exact = match ($stockUnitKind) {
            'm' => bcdiv($length, '1000', 16), 'm2' => bcdiv(bcmul($length, $width, 16), '1000000', 16),
            default => self::fail('缺少冻结的库存单位，不能换算整件来源。'),
        };
        $quantity = bcadd($exact, '0', 8);
        if (bccomp($exact, $quantity, 16) !== 0 || bccomp($quantity, '0', 8) <= 0 || bccomp($quantity, '9999999999.99999999', 8) > 0)
            self::fail('真实整件库存数量超出八位小数精度，不能舍入或默认换算。');
        return $quantity;
    }

    public static function count(string $quantity, string $perSource): int
    {
        $count = bcdiv($quantity, $perSource, 8);
        if (bccomp($count, bcadd($count, '0', 0), 8) !== 0 || bccomp($count, '1', 8) < 0 || bccomp($count, '1000', 8) > 0
            || bccomp(bcmul(bcadd($count, '0', 0), $perSource, 8), $quantity, 8) !== 0) self::fail('实际库存数量必须精确对应整根或整张，不允许截断份额。');
        return (int) $count;
    }

    public static function select(array $allocation, string $quantity, ?array $physicalIds = null, array $excludedKeys = [], bool $actualOnly = false, ?array $sourceKeys = null): array
    {
        $positive = $quantity === '0' || bccomp($quantity, '0', 8) === 0 ? '0.00000000' : FrozenCuttingStockPolicy::positiveDecimal($quantity);
        if ($positive === null) self::fail('实际库存数量必须是八位小数内的非负值。');
        if ($physicalIds !== null && count($physicalIds) !== count(array_unique($physicalIds))) self::fail('所选实物身份重复。');
        if ($sourceKeys !== null && count($sourceKeys) !== count(array_unique($sourceKeys))) self::fail('所选整件来源重复。');
        $selected = []; $sum = '0.00000000';
        foreach ($allocation['sources'] ?? [] as $source) {
            if (in_array($source['source_key'], $excludedKeys, true) || ($actualOnly && ! ($source['picked'] ?? false))) continue;
            if ($physicalIds !== null && ! in_array($source['physical_material_id'] ?? 0, $physicalIds, true)) continue;
            if ($sourceKeys !== null && ! in_array($source['source_key'], $sourceKeys, true)) continue;
            if ($physicalIds === null && $sourceKeys === null && bccomp($sum, $positive, 8) === 0) break;
            $selected[] = $source; $sum = bcadd($sum, (string) ($source['actual_stock_qty'] ?? 1), 8);
        }
        if (bccomp($sum, $positive, 8) !== 0 || ($physicalIds !== null && count($selected) !== count($physicalIds))
            || ($sourceKeys !== null && count($selected) !== count($sourceKeys))) self::fail('实际库存数量与本次冻结的整件来源不一致，请明确选择实际来源。');
        $members = CuttingMemberAllocationPolicy::members($selected);
        return (($allocation['quantity_basis'] ?? null) === 'total_length' ? ['quantity_basis' => 'total_length'] : []) + ['version' => 1, 'sources' => $selected, 'source_count' => count($selected), 'actual_stock_qty' => $sum,
            'members' => $members, 'covered_piece_qty' => ($allocation['quantity_basis'] ?? null) === 'total_length' ? null : array_sum(array_column($selected, 'covered_piece_qty')),
            'coverage_qty' => CuttingStockAllocationPolicy::sum($selected, 'coverage_qty'), 'formal_coverage_qty' => CuttingStockAllocationPolicy::sum($selected, 'formal_coverage_qty')];
    }

    private static function fail(string $message): never { throw new WorkOrderDomainException('cutting_stock_quantity_invalid', $message, 422); }
}
