<?php

namespace App\Services\Erp\Manufacturing;

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
    public function build(Item $item, ?int $productId, ?int $skuId, ?array $configuration, float $qty, ?int $bomId = null, ?int $routingId = null): array
    {
        $blocks = [];
        $bom = $this->bom($item, $productId, $skuId, $configuration, $bomId, $blocks);
        $routing = $this->routing($item, $productId, $skuId, $routingId, $blocks);
        $operations = $routing ? $routing['operations'] : [];
        $production = array_values(array_filter($operations, fn ($op) => $op['execution_context'] === 'production'));
        if ($routing && ! $production) $blocks[] = '工艺路线里没有车间工序，请在路线里补上生产工序';
        $materials = $bom ? $this->materials($bom['items'], $operations, $qty) : [];

        return ['bom' => $bom, 'routing' => $routing, 'operations' => $operations, 'materials' => $materials, 'block_reasons' => $blocks];
    }

    /** 每道工序料够不够：只看当前可用库存（第 3 阶段配料后改为已配/已签收）。 */
    public function kitting(array $materials, array $operations): array
    {
        $available = $this->availability->availableBaseQuantities(array_column($materials, 'component_item_id'));
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
            if ($price <= 0) $price = (float) ($items->get($m['component_item_id'])?->standard_cost ?: $items->get($m['component_item_id'])?->last_purchase_price ?: 0);
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
            $routing = ProductionRouting::query()->where('status', 'active')->where('output_item_id', $item->id)->find($routingId);
            if (! $routing) { $blocks[] = '所选工艺路线已失效或不是这个产品的路线'; return null; }
        } else {
            $matches = $this->masterData->defaultRoutingMatches((int) $item->id, $productId, $skuId);
            if ($matches->isEmpty()) { $blocks[] = '没有生效的默认工艺路线，请先建路线'; return null; }
            if ($matches->count() > 1) { $blocks[] = '默认工艺路线不唯一，请指定路线'; return null; }
            $routing = $matches->first();
        }
        $snapshot = $this->masterData->snapshot($routing);
        $snapshot['is_default'] = (bool) $routing->is_default;
        $snapshot['operations'] = array_map(fn ($op) => $op + ['key' => 'r'.$op['routing_operation_id']], $snapshot['operations']);
        return $snapshot;
    }

    /** BOM 决定总量，路线的供料规则决定分到哪道工序；没有规则的料默认给第一道车间工序。 */
    private function materials(array $bomItems, array $operations, float $qty): array
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
            $total = round($unitQty * $qty * (1 + $loss / 100) + $fixed, 4);
            $componentRules = $rules->where('component_item_id', $componentId);
            $targets = $componentRules->isNotEmpty()
                ? $componentRules->map(fn ($rule) => ['key' => $byRouting->get($rule['target_routing_operation_id'])['key'] ?? $rule['host_key'],
                    'ratio' => (float) $rule['required_qty_ratio'], 'supply_mode' => $rule['supply_mode'] ?? null,
                    'kitting' => (bool) ($rule['participates_in_kitting'] ?? true)])->values()->all()
                : [['key' => $firstKey, 'ratio' => 1.0, 'supply_mode' => null, 'kitting' => true]];
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
                    'cutting_mode' => $item ? $item->cuttingMode() : null, 'remark' => $line['remark'] ?? null,
                ];
            }
        }
        return $rows;
    }
}
