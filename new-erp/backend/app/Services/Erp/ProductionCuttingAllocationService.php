<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{InventoryBalance, MaterialPickingTaskLine, WorkOrderMaterialRequirement};
use Illuminate\Support\Facades\DB;

/** Existing JSON identity snapshots freeze whole-stock coverage without another quantity ledger. */
final class ProductionCuttingAllocationService
{
    public function unitKind(WorkOrderMaterialRequirement $requirement): ?string
    {
        return match ($this->stockUnitKind($requirement)) { 'm', 'root' => 'root', 'm2', 'sheet' => 'sheet', default => null };
    }

    public function stockUnitKind(WorkOrderMaterialRequirement $requirement): ?string
    {
        $kind = $requirement->cutting_requirement_snapshot['quantity_calculation']['unit_kind'] ?? null;
        return in_array($kind, ['m', 'root', 'm2', 'sheet'], true) ? $kind : null;
    }

    public function plan(WorkOrderMaterialRequirement $requirement, object $target, InventoryBalance $balance, string $quantity, ?int $pieces, bool $unambiguous, ?array $physicalIds = null, array $members = [], ?string $totalLength = null, ?array $sourcePlan = null, bool $preview = false): ?array
    {
        $kind = $this->unitKind($requirement);
        if (! $kind) {
            if ($members !== []) $this->fail('组合领料只适用于冻结为按整根或整张领料的下料需求。');
            return null;
        }
        if (FrozenCuttingStockPolicy::isTotalLength($requirement->cutting_requirement_snapshot ?? [])) {
            if ($pieces !== null || $physicalIds !== null || $members !== []) $this->fail('管材总长度配料须逐条引用需求，不能混入件数、板材或组合尺寸。');
            return $this->planTotalLength($requirement, $target, $balance, $quantity, $totalLength, $unambiguous);
        }
        if ($totalLength !== null) $this->fail('只有冻结为总长度的管材需求可以填写计划覆盖总长度。');
        if ($sourcePlan !== null && $kind !== 'sheet') $this->fail('逐板计划只适用于实际板材。');
        if ($members !== [] || $sourcePlan !== null) return $this->planMembers($requirement, $target, $balance, $quantity,
            $members ?: [['target' => $target, 'requirement' => $requirement, 'planned_cut_piece_qty' => $pieces ?? $this->remaining($requirement, $target)['target_piece_qty']]], $physicalIds, $sourcePlan, $preview);
        $stockKind = $this->stockUnitKind($requirement);
        $count = in_array($stockKind, ['root', 'sheet'], true) ? $this->whole($quantity) : 1000;
        if ((int) $balance->unit_id !== (int) $requirement->base_unit_id) $this->fail('下料实际领料单位必须与冻结库存单位一致。');
        $targetPieces = $this->whole((string) ($target->required_piece_qty_snapshot ?? '0'), false, false);
        $formalPieces = $this->whole((string) $requirement->required_piece_qty, false, false);
        $remaining = $this->remaining($requirement, $target);
        if ($pieces === null && ! $unambiguous) $this->fail('跨批次或分组配料必须明确本来源覆盖的段或块数。');
        $pieces ??= $remaining['target_piece_qty'];
        if ($pieces <= 0 || $pieces > $remaining['target_piece_qty'] || $pieces > $remaining['formal_piece_qty']) $this->fail('计划覆盖件数超过当前目标或正式需求的剩余件数。');
        $stock = app(ProductionPickingStockService::class);
        $geometry = $stock->geometry($requirement, $balance->item?->cuttingMode() ?? 'none');
        $sources = [];
        if ($kind === 'sheet') {
            if ($balance->item?->materialManagementMode() !== 'physical') $this->fail('按张下料必须引用逐张实物库存，不能按数量猜测尺寸。');
            if ($physicalIds !== null && ((in_array($stockKind, ['sheet'], true) && count($physicalIds) !== $count) || count($physicalIds) !== count(array_unique($physicalIds)))) $this->fail('计划所选实物张数与实际计划数量不一致。');
            $query = $stock->physicalQuery($balance, $requirement)->whereNotIn('physical.id', $this->reservedPhysicalIds());
            if ($physicalIds !== null) $query->whereIn('physical.id', $physicalIds);
            $physicals = $query->orderBy('physical.id')->limit($count)->lockForUpdate()->get(['physical.id', 'physical.shape', 'physical.dimensions']);
            if ($stockKind === 'sheet' && $physicals->count() !== $count) $this->fail('所选批次没有足够符合冻结尺寸且未被其他计划占用的整张实物。');
            foreach ($physicals as $physical) {
                $dimensions = ['shape' => $physical->shape] + json_decode($physical->dimensions ?: '{}', true, 512, JSON_THROW_ON_ERROR);
                $sources[] = ['physical_material_id' => (int) $physical->id, 'dimensions' => $dimensions, 'actual_stock_qty' => CuttingStockQuantityPolicy::sourceQuantity($stockKind, $dimensions),
                    'capacity_piece_qty' => CuttingStockAllocationPolicy::capacity($geometry, $dimensions)];
            }
            $sources = $this->selectSourceDefinitions($sources, $quantity, $physicalIds);
            $count = count($sources);
        } else {
            $lot = $balance->material_lot_id ? DB::table('erp_material_lots')->where('id', $balance->material_lot_id)->first() : null;
            $dimensions = $stock->lengthSource($balance, $requirement, $lot);
            $stockQuantity = CuttingStockQuantityPolicy::sourceQuantity($stockKind, $dimensions);
            $count = CuttingStockQuantityPolicy::count($quantity, $stockQuantity);
            $capacity = CuttingStockAllocationPolicy::capacity($geometry, $dimensions);
            if ($capacity <= 0) $this->fail('所选整根批次缺少可证明的实际长度，或无法容纳冻结下料尺寸。');
            for ($index = 0; $index < $count; $index++) $sources[] = ['dimensions' => $dimensions, 'actual_stock_qty' => $stockQuantity, 'capacity_piece_qty' => $capacity];
        }
        $allocation = CuttingStockAllocationPolicy::plan($sources, $pieces, $targetPieces, (string) $target->required_base_qty, $formalPieces, (string) $requirement->required_qty);
        if (bccomp($allocation['planned_coverage_qty'], $remaining['target_coverage_qty'], 8) > 0
            || bccomp($allocation['planned_formal_coverage_qty'], $remaining['formal_coverage_qty'], 8) > 0) $this->fail('本次分配的理论覆盖超过剩余需求。');
        $member = $this->memberFacts($requirement, $target, $pieces);
        foreach ($allocation['sources'] as &$source) $source['members'] = [[...$member, 'covered_piece_qty' => $source['covered_piece_qty'],
            'covered_base_qty' => $source['coverage_qty'], 'coverage_qty' => $source['coverage_qty'], 'formal_coverage_qty' => $source['formal_coverage_qty']]];
        unset($source);
        $allocation = CuttingMemberAllocationPolicy::attach($allocation);
        return [...$allocation, ...$this->stockFields($stockKind, $quantity, count($sources)), 'unit_kind' => $kind, 'material_requirement_id' => (int) $requirement->id,
            'target_material_requirement_id' => (int) $target->id, 'inventory_balance_id' => (int) $balance->id];
    }

    public function netLength(WorkOrderMaterialRequirement $requirement, ?object $target = null): string
    {
        $snapshot = $target ? $this->decode($target->cutting_requirement_snapshot ?? null) : ($requirement->cutting_requirement_snapshot ?? []);
        $length = FrozenCuttingStockPolicy::requiredTotalLength($snapshot);
        if (! $length) $this->fail('管材需求缺少发布时冻结的净总长度，不能用当前物料档案补写。');
        return $length;
    }

    public function totalLengthCoverage(array $allocation, ?int $requirementId = null, ?int $targetId = null, bool $pickedOnly = false, array $excludedKeys = []): string
    {
        $total = '0.00000000'; $seen = [];
        foreach ($allocation['sources'] ?? [] as $source) {
            $key = $source['source_key'] ?? null;
            $identity = isset($source['picking_task_line_id']) ? (int) $source['picking_task_line_id'].'|'.$key : $key;
            if (! $key || in_array($identity, $seen, true)) $this->fail('管材来源身份缺失或重复。');
            $seen[] = $identity;
            if (($pickedOnly && ! ($source['picked'] ?? false)) || in_array($key, $excludedKeys, true)) continue;
            foreach ($source['members'] ?? [] as $member) {
                if (($requirementId !== null && (int) $member['material_requirement_id'] !== $requirementId)
                    || ($targetId !== null && (int) $member['target_material_requirement_id'] !== $targetId)) continue;
                $length = FrozenCuttingStockPolicy::positiveDecimal($member['covered_total_length_mm'] ?? null);
                if (! $length || ($member['quantity_basis'] ?? null) !== 'total_length') $this->fail('原管材来源缺少冻结的净覆盖总长度。');
                $total = bcadd($total, $length, 8);
            }
        }
        return $total;
    }

    private function planTotalLength(WorkOrderMaterialRequirement $requirement, object $target, InventoryBalance $balance, string $quantity, ?string $totalLength, bool $unambiguous): array
    {
        $stockKind = $this->stockUnitKind($requirement);
        if (! in_array($stockKind, ['root', 'm'], true) || (int) $balance->unit_id !== (int) $requirement->base_unit_id) $this->fail('管材总长度必须使用已冻结的米或根库存单位。');
        $remaining = $this->remaining($requirement, $target);
        if ($totalLength === null && ! $unambiguous) $this->fail('跨库存批次配料必须明确每笔来源覆盖的管材总长度。');
        $length = CuttingDecimal::value($totalLength ?? $remaining['target_total_length_mm'], 8);
        if (bccomp($length, bcadd($length, '0', 5), 8) !== 0) $this->fail('计划总长度最多保留五位毫米小数，以便准确记录八位小数的米数。');
        if (bccomp($length, $remaining['target_total_length_mm'], 8) > 0 || bccomp($length, $remaining['formal_total_length_mm'], 8) > 0) $this->fail('计划覆盖总长度超过目标或正式需求的剩余总长度。');
        $stock = app(ProductionPickingStockService::class);
        $lot = $balance->material_lot_id ? DB::table('erp_material_lots')->where('id', $balance->material_lot_id)->first() : null;
        $dimensions = $stock->lengthSource($balance, $requirement, $lot);
        $sourceLength = FrozenCuttingStockPolicy::positiveDecimal($dimensions['length_mm'] ?? null);
        if (! $sourceLength) $this->fail('管材总长度配料必须引用具有实际长度的原根或余料批次。');
        $perSource = CuttingStockQuantityPolicy::sourceQuantity($stockKind, $dimensions);
        $count = CuttingStockQuantityPolicy::count($quantity, $perSource);
        if (bccomp($length, bcmul($sourceLength, (string) $count, 8), 8) > 0) $this->fail('本笔实际原根总长度不足以覆盖计划管材用量。');
        $targetNet = $this->netLength($requirement, $target); $formalNet = $this->netLength($requirement);
        $targetCoverage = bcdiv(bcmul((string) $target->required_base_qty, $length, 16), $targetNet, 8);
        $formalCoverage = bcdiv(bcmul((string) $requirement->required_qty, $length, 16), $formalNet, 8);
        if (bccomp($length, $remaining['target_total_length_mm'], 8) === 0) $targetCoverage = $remaining['target_coverage_qty'];
        if (bccomp($length, $remaining['formal_total_length_mm'], 8) === 0) $formalCoverage = $remaining['formal_coverage_qty'];
        if (bccomp($targetCoverage, '0', 8) <= 0 || bccomp($formalCoverage, '0', 8) <= 0
            || bccomp($targetCoverage, $remaining['target_coverage_qty'], 8) > 0 || bccomp($formalCoverage, $remaining['formal_coverage_qty'], 8) > 0) $this->fail('本笔总长度的理论覆盖超出剩余需求或可记录精度。');
        $fact = ['target_material_requirement_id' => (int) $target->id, 'material_requirement_id' => (int) $requirement->id,
            'bom_item_id' => (int) $requirement->bom_item_id, 'mode' => 'length', 'quantity_basis' => 'total_length',
            'target_required_qty' => (string) $target->required_base_qty, 'formal_required_qty' => (string) $requirement->required_qty,
            'required_total_length_mm' => $targetNet, 'formal_total_length_mm' => $formalNet,
            'dimensions' => ['quantity_basis' => 'total_length', 'total_length_mm' => FrozenCuttingStockPolicy::totalLength($requirement->cutting_requirement_snapshot)]];
        $sources = []; $targetBefore = '0.00000000'; $formalBefore = '0.00000000'; $assignedLength = '0.00000000';
        for ($index = 0; $index < $count; $index++) {
            // Coverage is a bounded theoretical share of the real root, never an invented cut segment.
            $leftLength = bcsub($length, $assignedLength, 8);
            if (bccomp($leftLength, '0', 8) <= 0) $this->fail('所选原根数量超过本次总长度需要，请减少原根数量。');
            $coveredLength = bccomp($leftLength, $sourceLength, 8) > 0 ? $sourceLength : $leftLength;
            $assignedLength = bcadd($assignedLength, $coveredLength, 8);
            $targetAfter = $index === $count - 1 ? $targetCoverage : bcdiv(bcmul((string) $target->required_base_qty, $assignedLength, 16), $targetNet, 8);
            $formalAfter = $index === $count - 1 ? $formalCoverage : bcdiv(bcmul((string) $requirement->required_qty, $assignedLength, 16), $formalNet, 8);
            $covered = bcsub($targetAfter, $targetBefore, 8); $formal = bcsub($formalAfter, $formalBefore, 8);
            if (bccomp($covered, '0', 8) <= 0 || bccomp($formal, '0', 8) <= 0) $this->fail('单根理论覆盖低于可记录精度，请调整本次来源或覆盖总长度。');
            // Net length is immutable source proof; inverse division of an eight-place coverage can lose millimetres.
            $member = $fact + ['covered_total_length_mm' => $coveredLength, 'covered_piece_qty' => null, 'covered_base_qty' => $covered, 'coverage_qty' => $covered, 'formal_coverage_qty' => $formal];
            $sources[] = ['source_key' => 'root:'.$index, 'dimensions' => $dimensions, 'actual_stock_qty' => $perSource,
                'quantity_basis' => 'total_length', 'covered_piece_qty' => null, 'coverage_qty' => $covered, 'formal_coverage_qty' => $formal, 'members' => [$member]];
            $targetBefore = $targetAfter; $formalBefore = $formalAfter;
        }
        $allocation = CuttingMemberAllocationPolicy::attach(['version' => 2, 'quantity_basis' => 'total_length', 'sources' => $sources,
            'planned_cut_piece_qty' => null, 'planned_coverage_qty' => $targetCoverage, 'planned_formal_coverage_qty' => $formalCoverage,
            'actual_cut_piece_qty' => null, 'actual_coverage_qty' => '0.00000000', 'actual_formal_coverage_qty' => '0.00000000',
            'received_cut_piece_qty' => null, 'received_coverage_qty' => '0.00000000', 'received_formal_coverage_qty' => '0.00000000']);
        return [...$allocation, ...$this->stockFields($stockKind, $quantity, $count), 'unit_kind' => 'root',
            'material_requirement_id' => (int) $requirement->id, 'target_material_requirement_id' => (int) $target->id, 'inventory_balance_id' => (int) $balance->id];
    }

    private function planMembers(WorkOrderMaterialRequirement $owner, object $target, InventoryBalance $balance, string $quantity, array $definitions, ?array $physicalIds, ?array $sourcePlan = null, bool $preview = false): array
    {
        $stockKind = $this->stockUnitKind($owner); $count = in_array($stockKind, ['root', 'sheet'], true) ? $this->whole($quantity) : 1000;
        $kind = $this->unitKind($owner); $facts = []; $requirements = []; $targets = []; $formalTotals = [];
        foreach ($definitions as $definition) {
            $memberTarget = $definition['target']; $requirement = $definition['requirement']; $pieces = (int) $definition['planned_cut_piece_qty'];
            if ((int) $memberTarget->work_order_id !== (int) $target->work_order_id || $memberTarget->target_type !== $target->target_type
                || (int) $memberTarget->target_id !== (int) $target->target_id || (int) $requirement->component_item_id !== (int) $owner->component_item_id
                || (int) $requirement->base_unit_id !== (int) $owner->base_unit_id || (int) $requirement->configuration_id !== (int) $owner->configuration_id
                || $this->stockUnitKind($requirement) !== $stockKind) $this->fail('组合领料成员必须属于同一工单、生产目标、原料、库存单位和配置。');
            if (isset($facts[$memberTarget->id])) $this->fail('组合领料不能重复引用同一目标物料需求。');
            $remaining = $this->remaining($requirement, $memberTarget);
            if ($pieces <= 0 || $pieces > $remaining['target_piece_qty']) $this->fail('组合成员的覆盖件数超过该目标的剩余件数。');
            $facts[$memberTarget->id] = $this->memberFacts($requirement, $memberTarget, $pieces);
            $requirements[$memberTarget->id] = $requirement;
            $targets[$memberTarget->id] = $memberTarget;
            $formalTotals[$requirement->id] = ($formalTotals[$requirement->id] ?? 0) + $pieces;
        }
        if (! isset($facts[$target->id])) $this->fail('组合领料必须包含当前owner配料行的目标需求。');
        foreach ($requirements as $requirement) if ($formalTotals[$requirement->id] > $this->remaining($requirement)['formal_piece_qty']) $this->fail('组合成员合计覆盖超过正式需求的剩余件数。');
        if ((int) $balance->unit_id !== (int) $owner->base_unit_id) $this->fail('实际库存单位与组合冻结单位不一致。');
        $stock = app(ProductionPickingStockService::class); $sources = [];
        if ($kind === 'sheet') {
            if ($balance->item?->materialManagementMode() !== 'physical') $this->fail('组合板材下料必须选择逐张实物。');
            $matching = null;
            foreach ($requirements as $requirement) {
                $candidate = $stock->physicalQuery($balance, $requirement)->select('physical.id');
                $matching = $matching ? $matching->union($candidate) : $candidate;
            }
            $query = $stock->physicalQuery($balance)->whereIn('physical.id', $matching)->whereNotIn('physical.id', $this->reservedPhysicalIds());
            if ($physicalIds !== null) {
                if (($stockKind === 'sheet' && count($physicalIds) !== $count) || count($physicalIds) !== count(array_unique($physicalIds))) $this->fail('组合所选实际板材身份与计划张数不一致。');
                $query->whereIn('physical.id', $physicalIds);
            }
            if (! $preview) $query->lockForUpdate();
            $physicals = $query->orderBy('physical.id')->limit($count)->get(['physical.id', 'physical.physical_no', 'physical.shape', 'physical.dimensions', 'physical.business_version']);
            if ($stockKind === 'sheet' && $physicals->count() !== $count) $this->fail('组合领料的合格实物数量不足或已被占用。');
            foreach ($physicals as $physical) {
                $dimensions = ['shape' => $physical->shape] + json_decode($physical->dimensions ?: '{}', true, 512, JSON_THROW_ON_ERROR);
                if ($sourcePlan !== null) {
                    $planned = collect($sourcePlan['sources'] ?? [])->firstWhere('physical_material_id', (int) $physical->id);
                    if (! $planned || (int) ($planned['expected_physical_version'] ?? 0) !== (int) $physical->business_version) $this->fail('板材状态或版本已变化，请保留原方案并重新预览确认。');
                }
                $sources[] = ['physical_material_id' => (int) $physical->id, 'physical_no' => $physical->physical_no,
                    'expected_physical_version' => (int) $physical->business_version, 'dimensions' => $dimensions,
                    'actual_stock_qty' => CuttingStockQuantityPolicy::sourceQuantity($stockKind, $dimensions)];
            }
            $sources = $this->selectSourceDefinitions($sources, $quantity, $physicalIds); $count = count($sources);
        } else {
            $lot = $balance->material_lot_id ? DB::table('erp_material_lots')->where('id', $balance->material_lot_id)->first() : null;
            $dimensions = $stock->lengthSource($balance, $owner, $lot);
            if (! FrozenCuttingStockPolicy::positiveDecimal($dimensions['length_mm'] ?? null)) $this->fail('组合整根来源缺少明确的冻结长度。');
            $stockQuantity = CuttingStockQuantityPolicy::sourceQuantity($stockKind, $dimensions); $count = CuttingStockQuantityPolicy::count($quantity, $stockQuantity);
            for ($index = 0; $index < $count; $index++) $sources[] = ['dimensions' => $dimensions, 'actual_stock_qty' => $stockQuantity];
        }
        $allocation = CuttingMemberAllocationPolicy::plan($sources, array_values($facts), $sourcePlan);
        foreach ($allocation['members'] as $member) {
            $remaining = $this->remaining($requirements[$member['target_material_requirement_id']], $targets[$member['target_material_requirement_id']]);
            if (bccomp($member['planned_coverage_qty'], $remaining['target_coverage_qty'], 8) > 0) $this->fail('组合成员理论覆盖超过当前目标余量。');
        }
        $allocation = [...$allocation, ...$this->stockFields($stockKind, $quantity, count($sources)), 'unit_kind' => $kind, 'material_requirement_id' => (int) $owner->id,
            'target_material_requirement_id' => (int) $target->id, 'inventory_balance_id' => (int) $balance->id];
        $fingerprint = self::fingerprint($allocation);
        if ($sourcePlan !== null && ! $preview && ! hash_equals($fingerprint, (string) ($sourcePlan['source_fingerprint'] ?? ''))) $this->fail('板材或需求已变化，请保留原方案并重新预览确认。');
        $allocation['source_fingerprint'] = $fingerprint;
        return $allocation;
    }

    public static function fingerprint(array $allocation): string
    {
        $sort = function (array $value) use (&$sort): array {
            if (! array_is_list($value)) ksort($value);
            foreach ($value as &$entry) if (is_array($entry)) $entry = $sort($entry);
            return $value;
        };
        return hash('sha256', json_encode($sort($allocation), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public function sourcePlan(array $allocation): array
    {
        return ['version' => 1, 'source_fingerprint' => $allocation['source_fingerprint'] ?? self::fingerprint($allocation),
            'planned_stock_qty' => $allocation['planned_stock_qty'], 'stock_unit_kind' => $allocation['stock_unit_kind'],
            'owner_target_material_requirement_id' => $allocation['target_material_requirement_id'], 'owner_material_requirement_id' => $allocation['material_requirement_id'],
            'sources' => array_map(fn ($source) => ['source_key' => $source['source_key'], 'physical_material_id' => $source['physical_material_id'],
                'expected_physical_version' => $source['expected_physical_version'], 'physical_no' => $source['physical_no'],
                'shape' => $source['dimensions']['shape'] ?? 'RECTANGLE', 'dimensions' => $source['dimensions'],
                'area_mm2' => FrozenCuttingStockPolicy::area($source['dimensions']), 'capacity_basis' => ($source['dimensions']['shape'] ?? null) === 'IRREGULAR' ? 'area' : 'rectangle',
                'members' => array_map(fn ($member) => ['target_material_requirement_id' => $member['target_material_requirement_id'], 'planned_cut_piece_qty' => $member['covered_piece_qty']], $source['members']),
                'used_area_mm2' => $source['used_area_mm2'] ?? null, 'suggested_remnant_area_mm2' => $source['suggested_remnant_area_mm2'] ?? null], $allocation['sources'])];
    }

    private function memberFacts(WorkOrderMaterialRequirement $requirement, object $target, int $pieces): array
    {
        $geometry = app(ProductionPickingStockService::class)->geometry($requirement, $this->unitKind($requirement) === 'sheet' ? 'sheet' : 'length');
        if (! $geometry['valid']) $this->fail('组合成员缺少发布时冻结的下料几何。');
        return ['target_material_requirement_id' => (int) $target->id, 'material_requirement_id' => (int) $requirement->id,
            'bom_item_id' => (int) $requirement->bom_item_id, 'mode' => $geometry['mode'],
            'dimensions' => array_intersect_key($geometry, array_flip(['length_mm', 'width_mm', 'thickness_mm', 'allow_rotation'])),
            'planned_cut_piece_qty' => $pieces, 'target_required_piece_qty' => $this->whole((string) $target->required_piece_qty_snapshot, false, false),
            'target_required_qty' => (string) $target->required_base_qty,
            'formal_required_piece_qty' => $this->whole((string) $requirement->required_piece_qty, false, false), 'formal_required_qty' => (string) $requirement->required_qty];
    }

    public function confirm(MaterialPickingTaskLine $line, string $quantity, ?array $physicalIds): void
    {
        $snapshot = $line->serial_snapshot ?? [];
        $allocation = $snapshot['cutting_allocation'] ?? null;
        if (! $allocation) return;
        $selected = CuttingStockQuantityPolicy::select($allocation, $quantity, $physicalIds);
        $keys = array_column($selected['sources'], 'source_key');
        foreach ($allocation['sources'] as &$source) $source['picked'] = in_array($source['source_key'], $keys, true);
        unset($source);
        $allocation['actual_cut_piece_qty'] = $selected['covered_piece_qty'];
        $allocation['actual_coverage_qty'] = $selected['coverage_qty'];
        $allocation['actual_formal_coverage_qty'] = $selected['formal_coverage_qty'];
        $allocation['actual_stock_qty'] = $selected['actual_stock_qty']; $allocation['actual_source_count'] = $selected['source_count'];
        foreach ($allocation['members'] ?? [] as $index => $member) {
            $actual = collect($selected['members'])->firstWhere('target_material_requirement_id', $member['target_material_requirement_id']);
            $allocation['members'][$index]['actual_cut_piece_qty'] = ($member['quantity_basis'] ?? null) === 'total_length' ? null : ($actual['covered_piece_qty'] ?? 0);
            $allocation['members'][$index]['actual_coverage_qty'] = $actual['covered_base_qty'] ?? '0.00000000';
            $allocation['members'][$index]['actual_formal_coverage_qty'] = $actual['formal_coverage_qty'] ?? '0.00000000';
        }
        $allocation = CuttingMemberAllocationPolicy::maps($allocation);
        $snapshot['cutting_allocation'] = $allocation;
        if ($allocation['unit_kind'] === 'sheet') $snapshot['physical_material_ids'] = array_column($selected['sources'], 'physical_material_id');
        $line->serial_snapshot = $snapshot;
    }

    public function receive(MaterialPickingTaskLine $line, string $quantity, ?array $physicalIds): ?array
    {
        $allocation = $line->serial_snapshot['cutting_allocation'] ?? null;
        if (! $allocation) return null;
        $used = [];
        foreach (DB::table('erp_material_receipt_lines')->where('picking_task_line_id', $line->id)->get(['accepted_serial_snapshot']) as $receipt) {
            $snapshot = $this->decode($receipt->accepted_serial_snapshot);
            $used = [...$used, ...array_column($snapshot['cutting_allocation']['sources'] ?? [], 'source_key')];
        }
        $received = CuttingStockQuantityPolicy::select($allocation, $quantity, $physicalIds, $used, true);
        if (($allocation['quantity_basis'] ?? null) !== 'total_length') $allocation['received_cut_piece_qty'] += $received['covered_piece_qty'];
        $allocation['received_coverage_qty'] = bcadd($allocation['received_coverage_qty'], $received['coverage_qty'], 8);
        $allocation['received_formal_coverage_qty'] = bcadd($allocation['received_formal_coverage_qty'], $received['formal_coverage_qty'], 8);
        $allocation['received_stock_qty'] = bcadd($allocation['received_stock_qty'] ?? '0', $received['actual_stock_qty'], 8);
        $allocation['received_source_count'] = ($allocation['received_source_count'] ?? 0) + $received['source_count'];
        foreach ($allocation['members'] ?? [] as $index => $member) {
            $share = collect($received['members'])->firstWhere('target_material_requirement_id', $member['target_material_requirement_id']);
            if (! $share) continue;
            if (($member['quantity_basis'] ?? null) !== 'total_length') $allocation['members'][$index]['received_cut_piece_qty'] += $share['covered_piece_qty'];
            $allocation['members'][$index]['received_coverage_qty'] = bcadd($member['received_coverage_qty'], $share['covered_base_qty'], 8);
            $allocation['members'][$index]['received_formal_coverage_qty'] = bcadd($member['received_formal_coverage_qty'], $share['formal_coverage_qty'], 8);
        }
        $allocation = CuttingMemberAllocationPolicy::maps($allocation);
        $line->serial_snapshot = [...$line->serial_snapshot, 'cutting_allocation' => $allocation];
        $line->save();
        return [...$received, ...(($allocation['quantity_basis'] ?? null) === 'total_length' ? ['quantity_basis' => 'total_length'] : []), 'unit_kind' => $allocation['unit_kind'], 'stock_unit_kind' => $allocation['stock_unit_kind'], 'picking_task_line_id' => (int) $line->id,
            'target_material_requirement_id' => $allocation['target_material_requirement_id']];
    }

    private function stockFields(string $kind, string $quantity, int $count): array
    {
        return ['stock_unit_kind' => $kind, 'planned_stock_qty' => bcadd($quantity, '0', 8), 'planned_source_count' => $count,
            'actual_stock_qty' => '0.00000000', 'actual_source_count' => 0, 'received_stock_qty' => '0.00000000', 'received_source_count' => 0];
    }

    private function selectSourceDefinitions(array $sources, string $quantity, ?array $physicalIds): array
    {
        foreach ($sources as $index => &$source) $source = [...$source, 'source_key' => isset($source['physical_material_id']) ? 'physical:'.$source['physical_material_id'] : 'root:'.$index,
            'covered_piece_qty' => 0, 'coverage_qty' => '0.00000000', 'formal_coverage_qty' => '0.00000000'];
        unset($source);
        return CuttingStockQuantityPolicy::select(['sources' => $sources], $quantity, $physicalIds)['sources'];
    }

    public function remaining(WorkOrderMaterialRequirement $requirement, ?object $target = null): array
    {
        $targetQty = (string) ($target->required_base_qty ?? 0); $formalQty = (string) $requirement->required_qty;
        $targetPieces = (int) ($target->required_piece_qty_snapshot ?? 0); $formalPieces = (int) $requirement->required_piece_qty;
        $total = FrozenCuttingStockPolicy::isTotalLength($requirement->cutting_requirement_snapshot ?? []);
        $targetLength = $total && $target ? $this->netLength($requirement, $target) : '0.00000000';
        $formalLength = $total ? $this->netLength($requirement) : '0.00000000';
        foreach ($this->lines((int) $requirement->id)->get(['line.*', 'task.status as task_status']) as $line) {
            $allocation = $this->decode($line->serial_snapshot)['cutting_allocation'] ?? null;
            if (! $allocation) {
                if (bccomp((string) $line->planned_pick_qty, '0', 8) > 0) $this->fail('已有配料行缺少冻结的下料覆盖依据，请先处理原配料，不能混合数量口径。');
                continue;
            }
            $pending = in_array($line->task_status, ['WAIT_PICK', 'PICKING'], true);
            $prefix = $pending ? 'planned' : 'actual';
            if ($total) {
                $formalLength = bcsub($formalLength, $this->totalLengthCoverage($allocation, (int) $requirement->id, null, ! $pending), 8);
                if ($target) $targetLength = bcsub($targetLength, $this->totalLengthCoverage($allocation, null, (int) $target->id, ! $pending), 8);
            }
            $formal = $allocation['formal_coverages'][(string) $requirement->id] ?? (! empty($allocation['members']) ? null : $allocation);
            if (! $formal) continue;
            $formalPieces -= (int) $formal[$prefix.'_cut_piece_qty'];
            $formalQty = bcsub($formalQty, (string) $formal[$prefix.'_formal_coverage_qty'], 8);
            $targetAllocation = $target ? ($allocation['target_coverages'][(string) $target->id] ?? (empty($allocation['members']) && (int) $allocation['target_material_requirement_id'] === (int) $target->id ? $allocation : null)) : null;
            if ($targetAllocation) {
                $targetPieces -= (int) $targetAllocation[$prefix.'_cut_piece_qty'];
                $targetQty = bcsub($targetQty, (string) $targetAllocation[$prefix.'_coverage_qty'], 8);
            }
        }
        foreach ($this->returns((int) $requirement->id)->get(['line.serial_snapshot']) as $line) {
            $allocation = $this->decode($line->serial_snapshot)['cutting_allocation'] ?? null;
            if (! $allocation) continue;
            if ($total) {
                $formalLength = bcadd($formalLength, $this->totalLengthCoverage($allocation, (int) $requirement->id), 8);
                if ($target) $targetLength = bcadd($targetLength, $this->totalLengthCoverage($allocation, null, (int) $target->id), 8);
            }
            $formal = $allocation['formal_coverages'][(string) $requirement->id] ?? (! empty($allocation['members']) ? null : $allocation);
            if (! $formal) continue;
            $formalPieces += (int) $formal['covered_piece_qty']; $formalQty = bcadd($formalQty, $formal['formal_coverage_qty'], 8);
            $targetAllocation = $target ? ($allocation['target_coverages'][(string) $target->id] ?? (empty($allocation['members']) && (int) $allocation['target_material_requirement_id'] === (int) $target->id ? $allocation : null)) : null;
            if ($targetAllocation) {
                $targetPieces += (int) $targetAllocation['covered_piece_qty']; $targetQty = bcadd($targetQty, $targetAllocation['covered_base_qty'] ?? $targetAllocation['coverage_qty'], 8);
            }
        }
        return ['target_piece_qty' => $total ? null : max(0, $targetPieces), 'formal_piece_qty' => $total ? null : max(0, $formalPieces),
            'target_coverage_qty' => $this->positive($targetQty), 'formal_coverage_qty' => $this->positive($formalQty),
            'target_total_length_mm' => $total && $target ? $this->positive($targetLength) : null,
            'formal_total_length_mm' => $total ? $this->positive($formalLength) : null];
    }

    public function formalRemaining(WorkOrderMaterialRequirement $requirement): string
    {
        // Pending plans do not change issued coverage. Use the same immutable sources after picking.
        $issued = '0.00000000';
        foreach ($this->lines((int) $requirement->id)->whereNotIn('task.status', ['WAIT_PICK', 'PICKING'])->get(['line.serial_snapshot']) as $line) {
            $allocation = $this->decode($line->serial_snapshot)['cutting_allocation'] ?? [];
            $issued = bcadd($issued, (string) ($allocation['formal_coverages'][(string) $requirement->id]['actual_formal_coverage_qty'] ?? (empty($allocation['members']) ? ($allocation['actual_formal_coverage_qty'] ?? 0) : 0)), 8);
        }
        $returned = '0.00000000';
        foreach ($this->returns((int) $requirement->id)->get(['line.serial_snapshot']) as $line) {
            $allocation = $this->decode($line->serial_snapshot)['cutting_allocation'] ?? [];
            $returned = bcadd($returned, (string) ($allocation['formal_coverages'][(string) $requirement->id]['formal_coverage_qty'] ?? (empty($allocation['members']) ? ($allocation['formal_coverage_qty'] ?? 0) : 0)), 8);
        }
        return $this->positive(bcadd(bcsub((string) $requirement->required_qty, $issued, 8), $returned, 8));
    }

    public function reservedPhysicalIds(): array
    {
        $ids = [];
        foreach (DB::table('erp_material_picking_task_lines as line')->join('erp_material_picking_tasks as task', 'task.id', '=', 'line.task_id')
            ->whereIn('task.status', ['WAIT_PICK', 'PICKING'])->get(['line.serial_snapshot']) as $line)
            $ids = [...$ids, ...array_column($this->decode($line->serial_snapshot)['cutting_allocation']['sources'] ?? [], 'physical_material_id')];
        return array_values(array_unique(array_map('intval', $ids)));
    }

    public function freezeReturnAllocations(int $returnLineId, int $targetRequirementId, string $quantity): ?array
    {
        $line = DB::table('erp_production_material_return_lines')->where('id', $returnLineId)->lockForUpdate()->first();
        if (! $line) $this->fail('退料明细不存在。');
        $requirement = WorkOrderMaterialRequirement::findOrFail($line->material_requirement_id);
        if (! $this->unitKind($requirement)) return null;
        $used = [];
        foreach (DB::table('erp_production_material_return_lines as line')->join('erp_production_material_returns as returned', 'returned.id', '=', 'line.return_id')
            ->where('line.material_requirement_id', $requirement->id)->where('line.id', '<>', $returnLineId)
            ->whereIn('returned.status', ['SUBMITTED', 'WAIT_QUALITY', 'COMPLETED', 'QUARANTINED'])->get(['line.serial_snapshot']) as $returned) {
            foreach ($this->decode($returned->serial_snapshot)['cutting_allocation']['sources'] ?? [] as $source)
                $used[] = (int) ($source['picking_task_line_id'] ?? 0).'|'.($source['source_key'] ?? '');
        }
        $batches = [];
        foreach (DB::table('erp_production_material_return_cost_allocations')->where('return_line_id', $returnLineId)->orderBy('id')->get() as $cost) {
            $input = DB::table('erp_production_input_holdings')->where('input_holding_id', $cost->source_input_holding_id)->first();
            $receipt = $input ? DB::table('erp_material_receipt_lines')->where('id', $input->material_receipt_line_id)->first() : null;
            if (! $receipt || (int) $input->target_material_requirement_id !== $targetRequirementId) $this->fail('退料成本分配缺少本生产目标的原签收明细。');
            $allocation = $this->decode($receipt->accepted_serial_snapshot)['cutting_allocation'] ?? null;
            if (! $allocation) $this->fail('整根或整张退料缺少原签收的冻结下料覆盖依据。');
            $consumedKeys = []; $legacyConsumed = 0;
            foreach (DB::table('erp_production_cutting_inputs')->where('production_input_holding_id', $input->id)->get(['quantity', 'requirement_snapshot']) as $usedInput) {
                $usedSnapshot = $this->decode($usedInput->requirement_snapshot);
                if (isset($usedSnapshot['source_keys'])) {
                    if (! is_array($usedSnapshot['source_keys']) || ! $usedSnapshot['source_keys']
                        || count($usedSnapshot['source_keys']) !== count(array_unique($usedSnapshot['source_keys']))
                        || (isset($usedSnapshot['actual_stock_qty']) && bccomp((string) $usedSnapshot['actual_stock_qty'], (string) $usedInput->quantity, 8) !== 0)) $this->fail('已加工原料的冻结身份或库存数量不一致。');
                    $consumedKeys = [...$consumedKeys, ...$usedSnapshot['source_keys']];
                }
                elseif (($allocation['stock_unit_kind'] ?? $allocation['unit_kind']) === 'root') $legacyConsumed += $this->whole((string) $usedInput->quantity, true, false);
                elseif (($allocation['unit_kind'] ?? null) === 'root') $this->fail('米计量加工记录缺少已用原根身份，不能推算退料。');
            }
            $batches[] = ['allocation' => $allocation, 'quantity' => (string) $cost->quantity,
                'source_input_holding_id' => (int) $cost->source_input_holding_id,
                'physical_material_ids' => $this->decode($cost->physical_material_ids),
                'consumed_source_keys' => $consumedKeys, 'consumed_quantity' => $legacyConsumed];
        }
        $allocation = CuttingReturnAllocationPolicy::freeze($batches, $targetRequirementId, $quantity, $used);
        if (! $allocation) $this->fail('退料没有完整的原签收下料来源。');
        $snapshot = [...$this->decode($line->serial_snapshot), 'cutting_allocation' => $allocation];
        DB::table('erp_production_material_return_lines')->where('id', $returnLineId)->update(['serial_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
        return $snapshot;
    }

    public function returnCoverage(array $snapshot): array
    {
        $allocation = $snapshot['cutting_allocation'] ?? null;
        return ['coverage_qty' => $allocation['coverage_qty'] ?? null,
            'formal_coverage_qty' => $allocation['formal_coverage_qty'] ?? null,
            'covered_piece_qty' => ($allocation['quantity_basis'] ?? null) === 'total_length' ? null : (int) ($allocation['covered_piece_qty'] ?? 0),
            'members' => $allocation['members'] ?? [], 'target_coverages' => $allocation['target_coverages'] ?? [],
            'formal_coverages' => $allocation['formal_coverages'] ?? []];
    }

    public function whole(string $quantity, bool $zero = false, bool $limit = true): int
    {
        if (! preg_match('/^\d+(?:\.\d{1,8})?$/D', $quantity) || bccomp($quantity, bcadd($quantity, '0', 0), 8) !== 0
            || bccomp($quantity, $zero ? '0' : '1', 8) < 0 || bccomp($quantity, $limit ? '1000' : '999999999', 8) > 0) $this->fail('实际下料领料数量与覆盖件数必须是可记录的整数。');
        return (int) $quantity;
    }

    public function decode(mixed $value): array { return is_array($value) ? $value : ($value ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : []); }
    private function lines(int $id) { return DB::table('erp_material_picking_task_lines as line')->join('erp_material_picking_tasks as task', 'task.id', '=', 'line.task_id')->where(fn ($q) => $q->where('line.material_requirement_id', $id)->orWhereRaw('JSON_EXTRACT(line.serial_snapshot, ?) IS NOT NULL', ['$.cutting_allocation.formal_coverages."'.$id.'"']))->where('task.status', '<>', 'CANCELLED'); }
    private function returns(int $id) { return DB::table('erp_production_material_return_lines as line')->join('erp_production_material_returns as returned', 'returned.id', '=', 'line.return_id')->where(fn ($q) => $q->where('line.material_requirement_id', $id)->orWhereRaw('JSON_EXTRACT(line.serial_snapshot, ?) IS NOT NULL', ['$.cutting_allocation.formal_coverages."'.$id.'"']))->whereIn('returned.status', ['WAIT_QUALITY', 'COMPLETED', 'QUARANTINED']); }
    private function positive(string $quantity): string { return bccomp($quantity, '0', 8) > 0 ? $quantity : '0.00000000'; }
    private function fail(string $message): never { throw new WorkOrderDomainException('cutting_allocation_invalid', $message, 422); }
}
