<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;

/** One actual root/board can cover several frozen BOM rows in the same production target. */
final class CuttingMemberAllocationPolicy
{
    public static function plan(array $sources, array $members, ?array $sourcePlan = null): array
    {
        $total = array_sum(array_column($members, 'planned_cut_piece_qty'));
        if ($total < count($sources) || $total > 100000) self::fail('组合领料必须为每根或每张分配下料件数，单次合计最多十万件。');
        $remaining = array_column($members, 'planned_cut_piece_qty', 'target_material_requirement_id');
        $ids = array_filter(array_column($sources, 'physical_material_id'), fn ($id) => $id !== null);
        if (count($ids) !== count(array_unique($ids))) self::fail('组合领料不能重复使用同一张实物。');
        $manual = null;
        if ($sourcePlan !== null) {
            $manual = [];
            foreach ($sourcePlan['sources'] ?? [] as $row) {
                $physicalId = (int) ($row['physical_material_id'] ?? 0);
                if (! in_array($physicalId, $ids, true) || isset($manual[$physicalId])) self::fail('逐板计划与本次所选实物身份不一致。');
                $manual[$physicalId] = [];
                foreach ($row['members'] ?? [] as $member) {
                    $id = (int) ($member['target_material_requirement_id'] ?? 0); $pieces = $member['planned_cut_piece_qty'] ?? null;
                    if (! array_key_exists($id, $remaining) || isset($manual[$physicalId][$id]) || ! is_numeric($pieces)
                        || (string) (int) $pieces !== (string) $pieces || (int) $pieces <= 0 || (int) $pieces > 100000)
                        self::fail('逐板计划只接受所选需求的不重复正整数件数，零件数可以留空。');
                    $manual[$physicalId][$id] = (int) $pieces;
                }
                if (! $manual[$physicalId]) self::fail('每张所选板材至少需要分配一件。');
            }
            if (count($manual) !== count($sources)) self::fail('逐板计划必须包含每一张所选实物。');
        }
        foreach ($sources as $index => &$source) {
            $source['source_key'] = isset($source['physical_material_id']) ? 'physical:'.$source['physical_material_id'] : 'root:'.$index;
            $source['members'] = []; $source['layout'] = []; $source['shelves'] = []; $source['used_length_mm'] = '0'; $source['used_area_mm2'] = '0.00000000';
            if ($manual !== null) {
                foreach ($members as $member) {
                    $id = $member['target_material_requirement_id'];
                    $count = $manual[$source['physical_material_id']][$id] ?? 0;
                    if ($count > $remaining[$id]) self::fail('逐板件数合计超过本次所选需求件数。');
                    for ($piece = 0; $piece < $count; $piece++) if (! self::place($source, $member)) self::fail('该板的人工分配超过实际板材可用容量。');
                    $remaining[$id] -= $count;
                }
                continue;
            }
            $placed = false;
            foreach ($members as $member) {
                $id = $member['target_material_requirement_id'];
                if ($remaining[$id] > 0 && self::place($source, $member)) { $remaining[$id]--; $placed = true; break; }
            }
            if (! $placed) self::fail('所选来源不能为每根或每张明确分配一个符合冻结尺寸的下料件。');
        }
        unset($source);
        foreach ($members as $member) {
            $id = $member['target_material_requirement_id'];
            if ($manual !== null) {
                if ($remaining[$id] !== 0) self::fail('逐板件数合计必须与本次各需求的总件数完全一致。');
                continue;
            }
            foreach ($sources as &$source) {
                while ($remaining[$id] > 0 && self::place($source, $member)) $remaining[$id]--;
                if ($remaining[$id] === 0) break;
            }
            unset($source);
            if ($remaining[$id] > 0) self::fail('组合尺寸与件数不能在本次实际领料中完成可证明的摆放，请调整实际来源或件数。');
        }
        foreach ($members as $member) {
            $id = $member['target_material_requirement_id']; $assigned = 0; $targetBefore = '0'; $formalBefore = '0';
            foreach ($sources as &$source) {
                $pieces = $source['members'][$id] ?? 0;
                if ($pieces === 0) continue;
                $assigned += $pieces;
                $targetAfter = bcdiv(bcmul($member['target_required_qty'], (string) $assigned, 16), (string) $member['target_required_piece_qty'], 8);
                $formalAfter = bcdiv(bcmul($member['formal_required_qty'], (string) $assigned, 16), (string) $member['formal_required_piece_qty'], 8);
                $source['members'][$id] = [...$member, 'covered_piece_qty' => $pieces, 'covered_base_qty' => bcsub($targetAfter, $targetBefore, 8),
                    'coverage_qty' => bcsub($targetAfter, $targetBefore, 8), 'formal_coverage_qty' => bcsub($formalAfter, $formalBefore, 8)];
                if (bccomp($source['members'][$id]['coverage_qty'], '0', 8) <= 0 || bccomp($source['members'][$id]['formal_coverage_qty'], '0', 8) <= 0)
                    self::fail('组合成员的单根或单张覆盖低于可记录精度。');
                $targetBefore = $targetAfter; $formalBefore = $formalAfter;
            }
            unset($source);
        }
        foreach ($sources as &$source) {
            $source['members'] = array_values($source['members']);
            $source['covered_piece_qty'] = array_sum(array_column($source['members'], 'covered_piece_qty'));
            $source['coverage_qty'] = CuttingStockAllocationPolicy::sum($source['members'], 'coverage_qty');
            $source['formal_coverage_qty'] = CuttingStockAllocationPolicy::sum($source['members'], 'formal_coverage_qty');
            if (FrozenCuttingStockPolicy::area($source['dimensions']) !== null) {
                if (($source['dimensions']['shape'] ?? null) !== 'IRREGULAR') {
                    $source['used_area_mm2'] = '0.00000000';
                    foreach ($source['members'] as $member) $source['used_area_mm2'] = bcadd($source['used_area_mm2'], bcmul(bcmul($member['dimensions']['length_mm'], $member['dimensions']['width_mm'], 8), (string) $member['covered_piece_qty'], 8), 8);
                }
                $source['suggested_remnant_area_mm2'] = bcsub(FrozenCuttingStockPolicy::area($source['dimensions']), $source['used_area_mm2'], 8);
            }
            unset($source['shelves'], $source['used_length_mm']);
        }
        unset($source);
        return self::attach(['version' => 1, 'sources' => $sources, 'planned_cut_piece_qty' => $total,
            'planned_coverage_qty' => CuttingStockAllocationPolicy::sum($sources, 'coverage_qty'),
            'planned_formal_coverage_qty' => CuttingStockAllocationPolicy::sum($sources, 'formal_coverage_qty'),
            'actual_cut_piece_qty' => 0, 'actual_coverage_qty' => '0.00000000', 'actual_formal_coverage_qty' => '0.00000000',
            'received_cut_piece_qty' => 0, 'received_coverage_qty' => '0.00000000', 'received_formal_coverage_qty' => '0.00000000']);
    }

    public static function attach(array $allocation): array
    {
        $members = self::members($allocation['sources']);
        foreach ($members as &$member) {
            $member['planned_cut_piece_qty'] = $member['covered_piece_qty'];
            $member['planned_coverage_qty'] = $member['covered_base_qty'];
            $member['planned_formal_coverage_qty'] = $member['formal_coverage_qty'];
            foreach (['actual', 'received'] as $prefix) {
                $member[$prefix.'_cut_piece_qty'] = ($member['quantity_basis'] ?? null) === 'total_length' ? null : 0; $member[$prefix.'_coverage_qty'] = '0.00000000'; $member[$prefix.'_formal_coverage_qty'] = '0.00000000';
            }
        }
        unset($member);
        $allocation['members'] = $members;
        return self::maps($allocation);
    }

    public static function members(array $sources): array
    {
        $members = [];
        foreach ($sources as $source) foreach ($source['members'] ?? [] as $member) {
            $key = $member['target_material_requirement_id'];
            if (! isset($members[$key])) $members[$key] = [...$member, ...(($member['quantity_basis'] ?? null) === 'total_length' ? ['covered_total_length_mm' => '0.00000000'] : []), 'covered_piece_qty' => ($member['quantity_basis'] ?? null) === 'total_length' ? null : 0, 'covered_base_qty' => '0.00000000', 'coverage_qty' => '0.00000000', 'formal_coverage_qty' => '0.00000000'];
            if (($member['quantity_basis'] ?? null) !== 'total_length') $members[$key]['covered_piece_qty'] += $member['covered_piece_qty'];
            else $members[$key]['covered_total_length_mm'] = bcadd($members[$key]['covered_total_length_mm'], (string) $member['covered_total_length_mm'], 8);
            foreach (['covered_base_qty', 'coverage_qty', 'formal_coverage_qty'] as $field) $members[$key][$field] = bcadd($members[$key][$field], $member[$field], 8);
        }
        return array_values($members);
    }

    public static function maps(array $allocation): array
    {
        $allocation['target_coverages'] = []; $allocation['formal_coverages'] = [];
        foreach ($allocation['members'] ?? [] as $member) {
            $allocation['target_coverages'][(string) $member['target_material_requirement_id']] = $member;
            $id = (string) $member['material_requirement_id'];
            if (! isset($allocation['formal_coverages'][$id])) $allocation['formal_coverages'][$id] = $member;
            else foreach (['planned', 'actual', 'received'] as $prefix) {
                if (($member['quantity_basis'] ?? null) !== 'total_length') $allocation['formal_coverages'][$id][$prefix.'_cut_piece_qty'] += $member[$prefix.'_cut_piece_qty'];
                $allocation['formal_coverages'][$id][$prefix.'_formal_coverage_qty'] = bcadd($allocation['formal_coverages'][$id][$prefix.'_formal_coverage_qty'], $member[$prefix.'_formal_coverage_qty'], 8);
            }
        }
        return $allocation;
    }

    private static function place(array &$source, array $member): bool
    {
        $geometry = FrozenCuttingStockPolicy::requirement($member['dimensions'], null, $member['mode']);
        if (! FrozenCuttingStockPolicy::allows($geometry, $source['dimensions'])) return false;
        $length = $geometry['length_mm']; $width = $geometry['width_mm'];
        if ($geometry['mode'] === 'length') {
            if (bccomp(bcadd($source['used_length_mm'], $length, 8), (string) $source['dimensions']['length_mm'], 8) > 0) return false;
            $source['layout'][] = ['target_material_requirement_id' => $member['target_material_requirement_id'], 'x_mm' => $source['used_length_mm'], 'length_mm' => $length];
            $source['used_length_mm'] = bcadd($source['used_length_mm'], $length, 8);
        } elseif (($source['dimensions']['shape'] ?? null) === 'IRREGULAR') {
            $area = bcmul($length, $width, 8);
            $used = bcadd($source['used_area_mm2'], $area, 8);
            if (bccomp($used, FrozenCuttingStockPolicy::area($source['dimensions']), 8) > 0) return false;
            $source['used_area_mm2'] = $used;
            $source['layout'][] = ['target_material_requirement_id' => $member['target_material_requirement_id'], 'area_mm2' => $area, 'capacity_basis' => 'area'];
        } else {
            $orientations = [[$length, $width, false]];
            if ($geometry['allow_rotation']) $orientations[] = [$width, $length, true];
            $placement = null;
            foreach ($orientations as [$l, $w, $rotated]) {
                if (bccomp($l, (string) $source['dimensions']['length_mm'], 8) > 0 || bccomp($w, (string) $source['dimensions']['width_mm'], 8) > 0) continue;
                foreach ($source['shelves'] as $index => $shelf) {
                    if (bccomp($w, $shelf['height'], 8) <= 0 && bccomp(bcadd($shelf['used'], $l, 8), (string) $source['dimensions']['length_mm'], 8) <= 0) {
                        $placement = [$index, $shelf['used'], $shelf['y'], $l, $w, $rotated]; break 2;
                    }
                }
                $height = '0'; foreach ($source['shelves'] as $shelf) $height = bcadd($height, $shelf['height'], 8);
                if (bccomp(bcadd($height, $w, 8), (string) $source['dimensions']['width_mm'], 8) <= 0) {
                    $source['shelves'][] = ['height' => $w, 'used' => '0', 'y' => $height];
                    $placement = [count($source['shelves']) - 1, '0', $height, $l, $w, $rotated]; break;
                }
            }
            if (! $placement) return false;
            [$index, $x, $y, $l, $w, $rotated] = $placement;
            $source['shelves'][$index]['used'] = bcadd($source['shelves'][$index]['used'], $l, 8);
            $source['layout'][] = ['target_material_requirement_id' => $member['target_material_requirement_id'], 'x_mm' => $x, 'y_mm' => $y,
                'length_mm' => $l, 'width_mm' => $w, 'rotated' => $rotated];
        }
        $id = $member['target_material_requirement_id']; $source['members'][$id] = ($source['members'][$id] ?? 0) + 1;
        return true;
    }

    private static function fail(string $message): never { throw new WorkOrderDomainException('cutting_allocation_invalid', $message, 422); }
}
