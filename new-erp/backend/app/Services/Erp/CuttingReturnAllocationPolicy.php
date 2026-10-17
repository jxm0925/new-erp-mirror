<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;

/** Reuse the exact received whole-stock identities, including their frozen coverage. */
final class CuttingReturnAllocationPolicy
{
    public static function freeze(array $batches, int $targetRequirementId, string $actualQuantity, array $returnedKeys): ?array
    {
        $kind = null; $stockKind = null; $sources = []; $stockQuantity = '0.00000000'; $hasOrdinary = false;
        foreach ($batches as $batch) {
            $allocation = $batch['allocation'] ?? null;
            if (! $allocation) { $hasOrdinary = true; continue; }
            $batchKind = $allocation['unit_kind'] ?? null;
            if (! in_array($batchKind, ['root', 'sheet'], true)
                || (int) ($allocation['target_material_requirement_id'] ?? 0) !== $targetRequirementId
                || ! ($pickLineId = (int) ($allocation['picking_task_line_id'] ?? 0))) self::fail('退料缺少同一生产目标的原领料覆盖依据。');
            if ($kind !== null && $kind !== $batchKind) self::fail('同一退料行不能混合整根与整张。');
            $kind = $batchKind; $quantity = CuttingDecimal::value((string) $batch['quantity']);
            $batchStockKind = $allocation['stock_unit_kind'] ?? $batchKind;
            if (! in_array($batchStockKind, ['m', 'm2', 'root', 'sheet'], true) || ($stockKind !== null && $stockKind !== $batchStockKind)) self::fail('退料库存计量单位不一致。');
            $stockKind = $batchStockKind;
            $candidates = [];
            foreach ($allocation['sources'] ?? [] as $source) {
                $source['picking_task_line_id'] = $pickLineId;
                $source['source_input_holding_id'] = (int) $batch['source_input_holding_id'];
                $source['actual_stock_qty'] = self::stockQuantity($source, $stockKind);
                $key = $pickLineId.'|'.($source['source_key'] ?? '');
                if (! in_array($key, $returnedKeys, true) && ! in_array($source['source_key'] ?? '', $batch['consumed_source_keys'] ?? [], true)) $candidates[] = $source;
            }
            if ($kind === 'sheet') {
                $ids = array_map('intval', $batch['physical_material_ids'] ?? []);
                $selected = self::selectStockSources($candidates, $quantity, $ids, $stockKind);
            } else {
                // Legacy integer root receipts may predate source keys. Decimal stock never
                // becomes a guessed root count; new processing always freezes source keys.
                $legacyConsumed = (int) ($batch['consumed_quantity'] ?? 0);
                if ($legacyConsumed > 0 && $stockKind !== 'root') self::fail('米计量退料缺少已加工原根身份，不能推算根数。');
                $selected = self::selectStockSources(array_slice($candidates, $legacyConsumed), $quantity, null, $stockKind);
            }
            if (! $selected) self::fail('退料必须引用尚未加工且未退回的原签收整根或整张。');
            foreach ($selected as $source) {
                $key = $pickLineId.'|'.$source['source_key'];
                if (in_array($key, $returnedKeys, true) || (($source['quantity_basis'] ?? null) !== 'total_length' && (int) ($source['covered_piece_qty'] ?? 0) <= 0)
                    || bccomp((string) ($source['coverage_qty'] ?? 0), '0', 8) <= 0
                    || bccomp((string) ($source['formal_coverage_qty'] ?? 0), '0', 8) <= 0) self::fail('退料来源或冻结覆盖数量无效。');
                $returnedKeys[] = $key; $sources[] = $source;
            }
            $stockQuantity = bcadd($stockQuantity, $quantity, 8);
        }
        if ($kind === null) return null;
        if ($hasOrdinary || bccomp($stockQuantity, $actualQuantity, 8) !== 0) self::fail('退料实际数量必须与冻结下料来源完整一致。');
        $members = CuttingMemberAllocationPolicy::members($sources); $targetCoverages = []; $formalCoverages = [];
        foreach ($members as $member) {
            $targetCoverages[(string) $member['target_material_requirement_id']] = $member;
            $key = (string) $member['material_requirement_id'];
            if (! isset($formalCoverages[$key])) $formalCoverages[$key] = $member;
            else {
                if (($member['quantity_basis'] ?? null) !== 'total_length') $formalCoverages[$key]['covered_piece_qty'] += $member['covered_piece_qty'];
                $formalCoverages[$key]['formal_coverage_qty'] = bcadd($formalCoverages[$key]['formal_coverage_qty'], $member['formal_coverage_qty'], 8);
            }
        }
        $total = ($sources[0]['quantity_basis'] ?? null) === 'total_length';
        return ($total ? ['quantity_basis' => 'total_length'] : []) + ['version' => 1, 'unit_kind' => $kind, 'stock_unit_kind' => $stockKind, 'actual_stock_qty' => $stockQuantity,
            'source_count' => count($sources), 'target_material_requirement_id' => $targetRequirementId,
            'members' => $members, 'target_coverages' => $targetCoverages, 'formal_coverages' => $formalCoverages,
            'sources' => $sources, 'covered_piece_qty' => $total ? null : array_sum(array_column($sources, 'covered_piece_qty')),
            'coverage_qty' => CuttingStockAllocationPolicy::sum($sources, 'coverage_qty'),
            'formal_coverage_qty' => CuttingStockAllocationPolicy::sum($sources, 'formal_coverage_qty')];
    }

    public static function stockQuantity(array $source, string $stockKind): string
    {
        $quantity = $source['actual_stock_qty'] ?? (in_array($stockKind, ['root', 'sheet'], true) ? '1.00000000' : null);
        if ($quantity === null) self::fail('真实原根或原张缺少冻结库存数量，不能用件数替代米或平方米。');
        return CuttingDecimal::value((string) $quantity);
    }

    public static function selectStockSources(array $sources, string $quantity, ?array $physicalIds, string $stockKind): array
    {
        $quantity = CuttingDecimal::value($quantity); $selected = []; $sum = '0.00000000';
        if ($physicalIds !== null && (count($physicalIds) !== count(array_unique($physicalIds)) || ! $physicalIds)) self::fail('退料实物身份不能为空或重复。');
        foreach ($sources as $source) {
            if ($physicalIds !== null && ! in_array((int) ($source['physical_material_id'] ?? 0), $physicalIds, true)) continue;
            $source['actual_stock_qty'] = self::stockQuantity($source, $stockKind);
            $selected[] = $source; $sum = bcadd($sum, $source['actual_stock_qty'], 8);
            if ($physicalIds === null && bccomp($sum, $quantity, 8) >= 0) break;
        }
        if (bccomp($sum, $quantity, 8) !== 0 || ($physicalIds !== null && count($selected) !== count($physicalIds)) || count($selected) > 1000) self::fail('库存数量必须精确对应尚未加工的原根或原张，不能拆分或四舍五入。');
        return $selected;
    }

    private static function fail(string $message): never
    { throw new WorkOrderDomainException('cutting_return_allocation_invalid', $message, 422); }
}
