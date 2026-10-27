<?php

namespace App\Services\Erp\Manufacturing;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\Bom;
use App\Models\Erp\Item;
use App\Models\Erp\ProductionRouting;
use App\Services\Erp\BomMatcher;
use App\Services\Erp\InventoryAvailabilityService;
use App\Services\Erp\LengthCutRequirementService;
use App\Services\Erp\ProductionMasterDataService;
use Illuminate\Support\Collection;

/**
 * 工单“怎么做”：按产品匹配 BOM 和工艺路线，把 BOM 用量按路线分到各工序。
 * 只算不写库；建单、换版本、预览都走这里，保证同一口径。
 */
final class WorkOrderPlanBuilder
{
    public function __construct(
        private readonly BomMatcher $bomMatcher,
        private readonly LengthCutRequirementService $lengthCut,
        private readonly ProductionMasterDataService $masterData,
        private readonly InventoryAvailabilityService $availability,
    ) {}

    /**
     * @return array{bom: ?array, routing: ?array, operations: array, materials: array, block_reasons: array}
     */
    public function build(Item $item, ?int $productId, ?int $skuId, ?array $configuration, float $qty, ?int $bomId = null, ?int $routingId = null, ?array $bomSnapshot = null): array
    {
        $blocks = [];
        // 已保存工单的用量依据来自自身快照，换路线不能重新解析已修改的 BOM 主档。
        if ($item->isPhantom()) $blocks[] = '这个物料是虚拟件，不单独建工单，用料会在上级工单里直接展开';
        $bom = $bomSnapshot ?? $this->bom($item, $productId, $skuId, $configuration, $bomId, $blocks);
        // 虚拟件在冻结 BOM 时展开成其下层用料；已保存工单沿用自身快照，不再重新展开。
        if ($bom && $bomSnapshot === null) $bom['items'] = $this->explodePhantoms($bom['items'], [(int) $item->id], $blocks);
        $routing = $this->routing($item, $productId, $skuId, $routingId, $blocks);
        $operations = $routing ? $routing['operations'] : [];
        $production = array_values(array_filter($operations, fn ($op) => $op['execution_context'] === 'production'));
        if ($routing && ! $production) $blocks[] = '工艺路线里没有车间工序，请在路线里补上生产工序';
        $materials = $bom ? $this->materials($bom['items'], $operations, $qty, $blocks) : [];

        return ['bom' => $bom, 'routing' => $routing, 'operations' => $operations, 'materials' => $materials, 'block_reasons' => $blocks];
    }

    public function isBomEligible(int $bomId, int $itemId): bool
    {
        // 下达事务与主档停用/编辑共用 BOM 行锁，资格校验须读取当前已提交状态。
        return $this->bomMatcher->eligibleQuery()->whereKey($bomId)->where('output_item_id', $itemId)->lockForUpdate()->first(['id']) !== null;
    }

    /**
     * 每道工序料够不够：已收的料 + 当前可用库存。
     * 按数量管理的料要在工单里“收料”才扣库存（issue_mode=receive）；按实物管理的板料走开料领用（issue_mode=cutting），按在库板料估算。
     */
    public function kitting(array $materials, array $operations): array
    {
        $ids = array_values(array_unique(array_map('intval', array_column($materials, 'component_item_id'))));
        [$available, $physical] = $this->availableForKitting($ids);
        $remaining = $available;
        $result = [];
        foreach ($operations as $op) {
            if ($op['execution_context'] !== 'production') continue;
            $key = $op['key'];
            $rows = [];
            foreach ($materials as $m) {
                if ($m['operation_key'] !== $key) continue;
                $need = max(0, (float) $m['required_qty'] - (float) ($m['issued_qty'] ?? 0));
                $have = (float) ($remaining[$m['component_item_id']] ?? 0);
                $use = min($need, $have);
                if ($m['participates_in_kitting']) $remaining[$m['component_item_id']] = $have - $use;
                $rows[] = $m + [
                    'issue_mode' => in_array($m['cutting_mode'] ?? null, ['sheet', 'length'], true) || in_array((int) $m['component_item_id'], $physical, true) ? 'cutting' : 'receive',
                    'available_qty' => round((float) ($available[$m['component_item_id']] ?? 0), 4),
                    'covered_qty' => round($use + (float) ($m['issued_qty'] ?? 0), 4),
                    'shortage_qty' => $m['participates_in_kitting'] ? round(max(0, $need - $use), 4) : 0,
                ];
            }
            $short = array_values(array_filter($rows, fn ($r) => $r['shortage_qty'] > 0.00001));
            $result[] = ['operation_key' => $key, 'operation_name' => $op['operation_name'], 'sequence' => $op['sequence'],
                'status' => ! $rows ? 'no_material' : ($short ? 'short' : 'ready'), 'shortage_count' => count($short), 'materials' => $rows];
        }
        return $result;
    }

    /**
     * 齐套口径的可用量：按数量管理的取可出库量，按实物管理的板料/管料按在库实物估算。
     * @return array{0: array<int, float>, 1: int[]} 可用量（按物料），按实物管理的物料 id
     */
    public function availableForKitting(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $available = $this->availability->availableBaseQuantities($ids);
        $physical = $ids ? Item::query()->whereIn('id', $ids)->get()->filter(fn (Item $i) => $i->materialManagementMode() === 'physical')->pluck('id')->map(fn ($id) => (int) $id)->all() : [];
        if ($physical) {
            foreach ($this->availability->outboundBalanceQuery($physical)->get() as $b) {
                $available[(int) $b->item_id] = ($available[(int) $b->item_id] ?? 0) + $this->availability->calculate((float) $b->quantity_on_hand, (float) $b->quantity_locked, (float) $b->quantity_defective, (float) $b->quantity_pending);
            }
        }
        return [$available, $physical];
    }

    /** 每台预计材料成本：按库存加权平均成本，没有库存时用标准成本或最近采购价。 */
    public function estimateMaterialCost(array $materials, float $qty): ?float
    {
        if (! $materials || $qty <= 0) return null;
        $ids = array_values(array_unique(array_column($materials, 'component_item_id')));
        $balances = \App\Models\Erp\InventoryBalance::query()->whereIn('item_id', $ids)
            ->selectRaw('item_id, SUM(quantity_on_hand) AS qty, SUM(inventory_value) AS value')->groupBy('item_id')->get()->keyBy('item_id');
        $items = Item::query()->whereIn('id', $ids)->get(['id', 'standard_cost', 'last_purchase_price'])->keyBy('id');
        $total = 0.0;
        foreach ($materials as $m) {
            $b = $balances->get($m['component_item_id']);
            $price = $b && (float) $b->qty > 0 ? (float) $b->value / (float) $b->qty : 0.0;
            if ($price <= 0) {
                $standard = (float) ($items->get($m['component_item_id'])?->standard_cost ?? 0);
                $price = $standard > 0 ? $standard : (float) ($items->get($m['component_item_id'])?->last_purchase_price ?? 0);
            }
            $total += $price * (float) $m['required_qty'];
        }
        return round($total / $qty, 2);
    }

    private function bom(Item $item, ?int $productId, ?int $skuId, ?array $configuration, ?int $bomId, array &$blocks): ?array
    {
        if ($bomId) {
            $bom = $this->bomMatcher->eligibleQuery()->with(['items.componentItem.unit.standardUnit', 'items.unit.standardUnit'])
                ->whereKey($bomId)->where('output_item_id', $item->id)->first();
            if (! $bom) { $blocks[] = '所选 BOM 已失效或不是这个产品的 BOM'; return null; }
            return $this->bomArray($bom, $this->lengthCut->resolveBomLines($bom, $configuration)->all());
        }
        $match = $this->bomMatcher->match($productId, $skuId, $item->id, $configuration);
        if (($match['status'] ?? null) !== 'matched') {
            $blocks[] = ($match['status'] ?? null) === 'conflict' ? '有多个可用 BOM 且没有唯一默认版本，请指定 BOM' : '没有已审核的 BOM，请先建 BOM';
            return null;
        }
        $bom = Bom::query()->find($match['bom_id']);
        return $this->bomArray($bom, $match['bom_snapshot']['items']);
    }

    private function bomArray(Bom $bom, array $items): array
    {
        return ['id' => (int) $bom->id, 'bom_no' => $bom->bom_no, 'bom_name' => $bom->bom_name, 'version' => $bom->version, 'is_default' => (bool) $bom->is_default, 'items' => $items];
    }

    private function routing(Item $item, ?int $productId, ?int $skuId, ?int $routingId, array &$blocks): ?array
    {
        if ($routingId) {
            $routing = ProductionRouting::query()->where('status', 'active')->applicableToItem((int) $item->id)->find($routingId);
            if (! $routing) { $blocks[] = '所选工艺路线已失效或不是这个产品的路线'; return null; }
        } else {
            $matches = $this->masterData->defaultRoutingMatches((int) $item->id, $productId, $skuId);
            if ($matches->isEmpty()) { $blocks[] = '没有适用的生效工艺路线，请先建路线'; return null; }
            if ($matches->count() > 1) { $blocks[] = '适用工艺路线不唯一且没有唯一默认，请指定路线'; return null; }
            $routing = $matches->first();
        }
        $snapshot = $this->masterData->snapshot($routing, (int) $item->id);
        $snapshot['is_default'] = (bool) $routing->is_default;
        $snapshot['operations'] = array_map(fn ($op) => $op + ['key' => 'r'.$op['routing_operation_id']], $snapshot['operations']);
        return $snapshot;
    }

    /**
     * 虚拟件展开：上级行用量 c、损耗 lc、固定 fc，下层行用量 q、损耗 l、固定 f，
     * 下层总需求 = (c×Q×(1+lc)+fc)×q×(1+l)+f，即单件用量 c×(1+lc)×q、损耗 l、固定 fc×q×(1+l)+f。
     * 下层行未指定用料工序时沿用虚拟件行的工序。
     */
    private function explodePhantoms(array $lines, array $path, array &$blocks): array
    {
        $ids = array_values(array_unique(array_map(fn ($line) => (int) $line['component_item_id'], $lines)));
        $phantoms = $ids ? Item::query()->whereIn('id', $ids)->where('manufacturing_strategy', 'phantom')->get()->keyBy('id') : collect();
        if ($phantoms->isEmpty()) return $lines;
        $out = [];
        foreach ($lines as $line) {
            $phantom = $phantoms->get((int) $line['component_item_id']);
            if (! $phantom) { $out[] = $line; continue; }
            $name = $phantom->item_name ?: $phantom->item_code;
            if (in_array((int) $phantom->id, $path, true) || count($path) > 8) { $blocks[] = '虚拟件 '.$name.' 的 BOM 层级循环或过深，请检查 BOM'; continue; }
            $match = $this->bomMatcher->match(null, null, (int) $phantom->id, null);
            if (($match['status'] ?? null) !== 'matched') { $blocks[] = '虚拟件 '.$name.' 没有唯一可用的已审核 BOM（物料级），无法展开用料'; continue; }
            $children = $this->explodePhantoms($match['bom_snapshot']['items'], [...$path, (int) $phantom->id], $blocks);
            $multiplier = (float) $line['qty'] * (1 + (float) ($line['loss_rate'] ?? 0) / 100);
            $parentFixed = (float) ($line['fixed_qty'] ?? 0);
            foreach ($children as $child) {
                $childQty = (float) $child['qty'];
                $childLoss = (float) ($child['loss_rate'] ?? 0);
                $child['qty'] = (string) round($multiplier * $childQty, 6);
                $child['fixed_qty'] = (string) round($parentFixed * $childQty * (1 + $childLoss / 100) + (float) ($child['fixed_qty'] ?? 0), 6);
                if (is_array($child['cutting_quantity_snapshot'] ?? null)) {
                    if (abs($multiplier - round($multiplier)) > 0.000001 || $parentFixed > 0) {
                        $blocks[] = '虚拟件 '.$name.' 下的下料物料 '.($child['component_item_name'] ?? '').' 需要虚拟件用量为整数且无固定用量，请调整 BOM';
                    } else {
                        $times = (int) round($multiplier);
                        $snapshot = $child['cutting_quantity_snapshot'];
                        foreach (['theoretical_qty', 'total_length_mm'] as $key) if (isset($snapshot[$key])) $snapshot[$key] = round((float) $snapshot[$key] * $times, 6);
                        if (isset($snapshot['dimensions']['piece_qty'])) $snapshot['dimensions']['piece_qty'] = (int) $snapshot['dimensions']['piece_qty'] * $times;
                        $child['cutting_quantity_snapshot'] = $snapshot;
                        if (isset($child['piece_qty'])) $child['piece_qty'] = (int) $child['piece_qty'] * $times;
                        if (isset($child['total_length_mm'])) $child['total_length_mm'] = round((float) $child['total_length_mm'] * $times, 6);
                    }
                }
                if (empty($child['operation_id']) && empty($child['target_routing_operation_id'])) {
                    $child['operation_id'] = $line['operation_id'] ?? null;
                    if (! empty($line['target_routing_operation_id'])) $child['target_routing_operation_id'] = $line['target_routing_operation_id'];
                }
                $child['phantom_item_id'] = (int) $phantom->id;
                $child['phantom_item_name'] = trim(($child['phantom_item_name'] ?? '') ? $name.' / '.$child['phantom_item_name'] : $name);
                $out[] = $child;
            }
        }
        $duplicates = collect($out)->pluck('id')->filter()->duplicates();
        if ($duplicates->isNotEmpty()) $blocks[] = '同一个虚拟件在 BOM 里被重复引用，请合并为一行';
        return $out;
    }

    /** 正式需求和技术缺料提醒共用同一数量口径，固定用量只按整张工单加一次。 */
    public static function requiredQuantity(array $line, float $qty): float
    {
        $unitQty = (float) (data_get($line, 'cutting_quantity_snapshot.theoretical_qty') ?? $line['qty']);
        return round($unitQty * $qty * (1 + (float) ($line['loss_rate'] ?? 0) / 100) + (float) ($line['fixed_qty'] ?? 0), 4);
    }

    /** BOM 决定总量，路线的供料规则决定分到哪道工序；没有规则的料默认给第一道车间工序。 */
    private function materials(array $bomItems, array $operations, float $qty, array &$blocks = []): array
    {
        $production = array_values(array_filter($operations, fn ($op) => $op['execution_context'] === 'production'));
        $firstKey = $production[0]['key'] ?? null;
        $rules = collect($operations)->flatMap(fn ($op) => collect($op['material_supply_rules'] ?? [])->map(fn ($rule) => $rule + ['host_key' => $op['key']]));
        $byRouting = collect($operations)->keyBy('routing_operation_id');
        $items = Item::query()->with('unit')->whereIn('id', array_column($bomItems, 'component_item_id'))->get()->keyBy('id');
        $rows = [];
        foreach ($bomItems as $line) {
            $componentId = (int) $line['component_item_id'];
            $unitQty = (float) (data_get($line, 'cutting_quantity_snapshot.theoretical_qty') ?? $line['qty']);
            $loss = (float) ($line['loss_rate'] ?? 0);
            $fixed = (float) ($line['fixed_qty'] ?? 0);
            $total = self::requiredQuantity($line, $qty);
            $componentRules = $rules->where('component_item_id', $componentId);
            // 定制 BOM 行可直接指定用在哪道工序。
            $lineTarget = (int) ($line['target_routing_operation_id'] ?? 0);
            if ($lineTarget && (! $byRouting->has($lineTarget) || $byRouting->get($lineTarget)['execution_context'] !== 'production')) {
                throw new WorkOrderDomainException('operation_invalid', '定制 BOM 的用料工序已不在当前车间路线中，请重新确认物料对应的工序。');
            }
            // 标准 BOM 行可按工序主档指定用料工序（共用路线时各规格用料不同也不用改路线），优先于路线供料规则。
            $bomOperationId = (int) ($line['operation_id'] ?? 0);
            $bomTarget = null;
            if (! $lineTarget && $bomOperationId) {
                $bomTarget = collect($production)->where('operation_id', $bomOperationId)->sortBy('sequence')->first();
                if (! $bomTarget) $blocks[] = 'BOM 物料 '.($line['component_item_name'] ?? '').' 指定的用料工序不在工艺路线的车间工序中，请调整 BOM 或路线';
            }
            $bomRule = $bomTarget ? $componentRules->first(fn ($rule) => (int) $rule['target_routing_operation_id'] === (int) $bomTarget['routing_operation_id']) : null;
            $targets = $lineTarget
                ? [['key' => $byRouting->get($lineTarget)['key'], 'ratio' => 1.0, 'supply_mode' => null, 'kitting' => true]]
                : ($bomTarget ? [['key' => $bomTarget['key'], 'ratio' => 1.0, 'supply_mode' => $bomRule['supply_mode'] ?? null,
                    'kitting' => (bool) ($bomRule['participates_in_kitting'] ?? true)]]
                : ($componentRules->isNotEmpty()
                ? $componentRules->map(fn ($rule) => ['key' => $byRouting->get($rule['target_routing_operation_id'])['key'] ?? $rule['host_key'],
                    'ratio' => (float) $rule['required_qty_ratio'], 'supply_mode' => $rule['supply_mode'] ?? null,
                    'kitting' => (bool) ($rule['participates_in_kitting'] ?? true)])->values()->all()
                : [['key' => $firstKey, 'ratio' => 1.0, 'supply_mode' => null, 'kitting' => true]]));
            $item = $items->get($componentId);
            foreach ($targets as $target) {
                $rows[] = [
                    'operation_key' => $target['key'], 'bom_item_id' => $line['id'] ?? null,
                    'component_item_id' => $componentId,
                    'component_item_code' => $line['component_item_code'] ?? $item?->item_code,
                    'component_item_name' => $line['component_item_name'] ?? $item?->item_name,
                    'unit_name' => $item?->unit?->unit_name,
                    'unit_qty' => $unitQty, 'loss_rate' => $loss, 'fixed_qty' => $fixed, 'qty_ratio' => $target['ratio'],
                    'required_qty' => round($total * $target['ratio'], 4), 'issued_qty' => 0,
                    'supply_mode' => $target['supply_mode'], 'participates_in_kitting' => $target['kitting'],
                    'cutting_mode' => $item ? $item->cuttingMode() : null,
                    'remark' => ($line['phantom_item_name'] ?? null) ? trim('来自虚拟件 '.$line['phantom_item_name'].' '.($line['remark'] ?? '')) : ($line['remark'] ?? null),
                ];
            }
        }
        return $rows;
    }
}
