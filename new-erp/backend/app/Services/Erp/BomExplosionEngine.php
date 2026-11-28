<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\Bom;
use App\Models\Erp\Item;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** BOM 选版、路径展开和用量计算的唯一入口；只读取主档，不改写历史快照。 */
final class BomExplosionEngine
{
    public function __construct(private readonly LengthCutRequirementService $lengthCut) {}

    public function eligibleQuery(?string $businessDate = null): Builder
    {
        $date = $businessDate ?? now()->toDateString();
        return Bom::query()->where('audit_status', 'approved')->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('effective_date')->orWhere('effective_date', '<=', $date))
            ->where(fn ($q) => $q->whereNull('expire_date')->orWhere('expire_date', '>=', $date));
    }

    /** 顶层沿用销售/工单的适用范围和唯一默认规则；子行显式版本只校验指定产出，不跨版回退。 */
    public function select(?int $productId, ?int $skuId, int $itemId, ?int $pinnedBomId = null, ?string $businessDate = null, bool $lock = false, bool $requireMaterials = false, bool $matchScope = true): array
    {
        $query = $this->eligibleQuery($businessDate)->where('output_item_id', $itemId)
            ->when($lock, fn ($q) => $q->lockForUpdate())->withExists('items');
        if ($pinnedBomId !== null) $query->whereKey($pinnedBomId);
        if ($matchScope || $pinnedBomId === null) {
            if ($productId === null && $skuId === null) $query->whereNull('product_id')->whereNull('sku_id');
            else $query->where(fn ($q) => $q
                ->where(function ($scope) use ($productId, $skuId) {
                    if ($productId) $scope->where('product_id', $productId);
                    if ($skuId) $scope->where('sku_id', $skuId);
                })->orWhere(fn ($generic) => $generic->whereNull('product_id')->whereNull('sku_id')));
        }
        $matches = $query->orderByDesc('is_default')->orderByDesc('id')->get();
        if ($matches->isEmpty()) return $this->selection('missing', $pinnedBomId !== null
            ? '指定的 BOM 已失效或不适用于该产出，请先修订生产资料'
            : '未找到已审核、启用且有效的 BOM');
        if ($requireMaterials) {
            $first = $matches->first();
            $matches = $matches->where('items_exists', true)->values();
            if ($matches->isEmpty()) return $this->selection('empty_materials', self::emptyMaterialsMessage($first->bom_no));
        }
        if ($matches->count() > 1 && $matches->where('is_default', true)->count() !== 1) {
            return $this->selection('conflict', '存在多个可用 BOM，且默认 BOM 不唯一', null, $matches->all());
        }
        $bom = $matches->where('is_default', true)->first() ?: $matches->first();
        if ($lock) $this->loadLockedLines($bom);
        else $bom->loadMissing(['items.componentItem.unit.standardUnit', 'items.unit.standardUnit', 'items.operationBinding', 'outputItem.unit']);
        if ($requireMaterials && $bom->items->isEmpty()) return $this->selection('empty_materials', self::emptyMaterialsMessage($bom->bom_no));
        return $this->selection('matched', null, $bom, [$bom]);
    }

    public function child(array $line, ?string $businessDate = null, bool $lock = false, bool $requireMaterials = true): array
    {
        return $this->select(null, null, (int) $line['component_item_id'], ! empty($line['child_bom_id']) ? (int) $line['child_bom_id'] : null,
            $businessDate, $lock, $requireMaterials, matchScope: false);
    }

    public function shouldExpand(?Item $item, array $line): bool
    {
        return ! empty($line['child_bom_id']) || ($item && ($item->isPhantom()
            || (in_array($item->item_type, ['semi_finished', 'finished_product'], true)
                && $item->manufacturing_strategy !== 'purchase' && ! $item->isPurchaseFulfilledItem())));
    }

    /** 草稿允许先引用尚未生效的子版本；产出归属始终要正确，提交时再执行有效性校验。 */
    public function assertDraftChild(int $itemId, ?int $childBomId, bool $lock = false): void
    {
        if ($childBomId === null) return;
        $child = Bom::query()->whereKey($childBomId)->where('output_item_id', $itemId)
            ->when($lock, fn ($q) => $q->lockForUpdate())->first(['id']);
        if (! $child) throw new WorkOrderDomainException('bom_child_invalid', '指定的子 BOM 不属于该组成物料，或已不存在。');
    }

    /** 只沿正式选中的版本检查；保存草稿可逐步补资料，提交/启用必须阻断无效显式子件和虚拟件。 */
    public function assertGraph(int $outputItemId, array $lines, bool $strict = false, bool $lock = false, ?string $businessDate = null, array $path = [], ?string $label = null): void
    {
        $label ??= Item::query()->whereKey($outputItemId)->value('item_name') ?: ('Item#'.$outputItemId);
        $path = $this->enterPath($outputItemId, $label, $path);
        $items = Item::query()->whereIn('id', array_column($lines, 'component_item_id'))->when($lock, fn ($q) => $q->lockForUpdate())->get()->keyBy('id');
        foreach ($lines as $line) {
            $itemId = (int) $line['component_item_id'];
            if ($itemId === $outputItemId) throw new WorkOrderDomainException('bom_cycle', 'BOM 组成物料不能等于产出物料。');
            $this->assertDraftChild($itemId, ! empty($line['child_bom_id']) ? (int) $line['child_bom_id'] : null, $lock);
            $match = $this->resolveChild($line, $items->get($itemId), $businessDate, $lock);
            if ($match['status'] === 'leaf') continue;
            if ($match['status'] !== 'matched') {
                if ($strict) throw new WorkOrderDomainException('bom_child_unavailable', ($items->get($itemId)?->item_name ?: $itemId).'：'.$match['block_reason']);
                continue;
            }
            $child = $match['bom'];
            $this->assertGraph($itemId, $child->items->map(fn ($row) => $row->only(['component_item_id', 'child_bom_id']))->all(),
                $strict, $lock, $businessDate, $path, $items->get($itemId)?->item_name ?: $child->bom_no);
        }
    }

    /** 原料与采购件不因保留了历史 BOM 就自动穿透；虚拟件缺资料必须阻断，不能当成叶子漏料。 */
    public function resolveChild(array $line, ?Item $item, ?string $businessDate = null, bool $lock = false): array
    {
        if (! $this->shouldExpand($item, $line)) return $this->selection('leaf', null);
        if (! $item) return $this->selection('missing_item', '组成物料已不存在，请修订 BOM。');
        $match = $this->child($line, $businessDate, $lock);
        if ($match['status'] === 'missing' && ! $item->isPhantom() && empty($line['child_bom_id'])) return $this->selection('leaf', null);
        return $match;
    }

    public function loadLockedLines(Bom $bom): void
    {
        // 与主档编辑共用当前读；事务内不能复用此前普通查询加载的明细和工序绑定。
        $bom->load(['items' => fn ($q) => $q->lockForUpdate(), 'items.componentItem.unit.standardUnit', 'items.unit.standardUnit',
            'items.operationBinding' => fn ($q) => $q->lockForUpdate()]);
    }

    public function lines(Bom $bom, ?array $configuration = null): Collection
    {
        return $this->lengthCut->resolveBomLines($bom, $configuration);
    }

    public function snapshot(Bom $bom, ?array $configuration = null): array
    {
        return ['id' => (int) $bom->id, 'bom_no' => $bom->bom_no, 'bom_name' => $bom->bom_name, 'version' => $bom->version,
            'is_default' => (bool) $bom->is_default, 'items' => $this->lines($bom, $configuration)->all()];
    }

    /** 实际用料行只回到父工单自己的冻结位置，不从今天的标准 BOM 重建其销售替代或定制依据。 */
    public function materialPosition(array $snapshot, int $itemId, ?int $bomItemId, ?string $positionPath): array
    {
        $positions = collect($snapshot['items'] ?? [])->filter(fn ($line) => (int) ($line['component_item_id'] ?? 0) === $itemId);
        if ($positionPath) $positions = $positions->filter(fn ($line) => ($line['position_path'] ?? $line['position_uid'] ?? null) === $positionPath);
        elseif ($bomItemId) $positions = $positions->where('id', $bomItemId);
        if ($positions->count() !== 1) throw new WorkOrderDomainException('bom_position_ambiguous', '无法从父工单快照唯一确定半成品用料位置，请先核实工单资料。');
        return $positions->first();
    }

    public static function requiredQuantity(array $line, float $qty): float
    {
        $unitQty = (float) (data_get($line, 'cutting_quantity_snapshot.theoretical_qty') ?? $line['qty']);
        return round($unitQty * $qty * (1 + (float) ($line['loss_rate'] ?? 0) / 100) + (float) ($line['fixed_qty'] ?? 0), 4);
    }

    public static function emptyMaterialsMessage(string $bomNo): string
    {
        return "「{$bomNo}」没有有效用料，请选择其他 BOM 版本，或在 BOM 管理中补齐用料。";
    }

    /** 路径独立检查，兄弟位置重复引用同一半成品不构成循环。 */
    public function enterPath(int $itemId, string $label, array $path): array
    {
        if (array_key_exists($itemId, $path)) {
            $cycle = array_slice($path, array_search($itemId, array_keys($path), true));
            $cycle[] = $label;
            throw new WorkOrderDomainException('bom_cycle', '循环引用：'.implode(' → ', $cycle));
        }
        if (count($path) >= 64) throw new WorkOrderDomainException('bom_depth', 'BOM 层级超过 64 层，请检查组成关系。');
        $path[$itemId] = $label;
        return $path;
    }

    /** 页面完整需求树；实物半成品继续有独立工单，需求树只用于追溯其下层原料。 */
    public function expand(Bom $bom, float $plannedQty, ?string $businessDate = null): array
    {
        $snapshot = $this->freeze($this->snapshot($bom), (int) $bom->output_item_id, businessDate: $businessDate);
        if (($snapshot['phantom_expansion_complete'] ?? false) !== true)
            throw new WorkOrderDomainException('bom_child_unavailable', implode('；', $snapshot['phantom_expansion_block_reasons'] ?? []));
        return $this->expandTree($bom, $plannedQty, $businessDate, $snapshot, $plannedQty);
    }

    private function expandTree(Bom $bom, float $plannedQty, ?string $businessDate, array $snapshot, float $planQty, string $prefix = '', array $path = [], int $level = 0): array
    {
        $path = $this->enterPath((int) $bom->output_item_id, $bom->outputItem?->item_name ?: $bom->bom_no, $path);
        $tree = []; $aggregate = [];
        $planLines = collect($snapshot['items'])->keyBy(fn ($line) => $line['position_path'] ?? $line['position_uid'] ?? '');
        $models = $bom->items->keyBy('id');
        foreach ($this->lines($bom) as $line) {
            $model = $models->get($line['id']);
            $componentLabel = $line['component_item_name'] ?: $model?->componentItem?->item_name ?: $line['component_item_code'];
            $pathLabel = implode(' → ', [...array_values($path), $componentLabel]);
            $position = trim($prefix.'/'.($line['position_uid'] ?? ''), '/');
            // 虚拟件按工单冻结后的仿射用量算，避免逐层四舍五入与正式配料产生尾差。
            $planLine = $position !== '' ? $planLines->get($position) : null;
            $quantityLine = $planLine ?: $line;
            $quantityPlanQty = $planLine ? $planQty : $plannedQty;
            $match = $this->resolveChild($quantityLine, $model?->componentItem, $businessDate);
            if (! in_array($match['status'], ['matched', 'leaf'], true)) {
                throw new WorkOrderDomainException('bom_child_unavailable', $componentLabel.'：'.$match['block_reason']);
            }
            $child = $match['bom'];
            $demandQty = self::requiredQuantity($quantityLine, $quantityPlanQty);
            $calculation = $quantityLine['cutting_quantity_snapshot'] ?? null;
            $totalLength = ($calculation['quantity_basis'] ?? null) === 'total_length';
            $facts = [
                'cutting_quantity_basis' => $totalLength ? 'total_length' : 'dimensions_pieces',
                'total_length_mm' => $totalLength ? $calculation['total_length_mm'] : null,
                'required_total_length_mm' => $totalLength ? bcmul((string) $calculation['total_length_mm'], (string) $quantityPlanQty, 8) : null,
                'component_item_id' => $line['component_item_id'], 'component_item_code' => $line['component_item_code'],
                'component_item_name' => $componentLabel, 'unit_name' => $model?->unit?->unit_name ?: $model?->componentItem?->unit?->unit_name,
                'unit_qty' => (float) ($calculation['theoretical_qty'] ?? $quantityLine['qty']),
                'cut_length_mm' => $line['cut_length_mm'] === null ? null : (float) $line['cut_length_mm'],
                'cut_width_mm' => $line['cut_width_mm'] === null ? null : (float) $line['cut_width_mm'],
                'cut_thickness_mm' => $line['cut_thickness_mm'] === null ? null : (float) $line['cut_thickness_mm'],
                'piece_qty' => $quantityLine['piece_qty'] === null ? null : (int) $quantityLine['piece_qty'],
                'required_piece_qty' => $quantityLine['piece_qty'] === null ? null : round((float) $quantityLine['piece_qty'] * $quantityPlanQty, 4),
                'loss_rate' => (float) $quantityLine['loss_rate'], 'fixed_qty' => (float) $quantityLine['fixed_qty'], 'demand_qty' => $demandQty,
                'replaceable' => (bool) $line['replaceable'], 'remark' => $line['remark'],
            ];
            $tree[] = $facts + ['level' => $level + 1, 'path' => $pathLabel, 'parent_bom_id' => $bom->id, 'parent_bom_no' => $bom->bom_no,
                'is_leaf' => ! $child, 'child_bom_id' => $child?->id, 'child_bom_no' => $child?->bom_no];
            if ($child) {
                if ($model?->componentItem?->isPhantom()) {
                    $expanded = $this->expandTree($child, $demandQty, $businessDate, $snapshot, $planQty, $position, $path, $level + 1);
                } else {
                    $childSnapshot = $quantityLine['child_bom_snapshot'] ?? $this->snapshot($child);
                    $expanded = $this->expandTree($child, $demandQty, $businessDate, $childSnapshot, $demandQty, '', $path, $level + 1);
                }
                $tree = array_merge($tree, $expanded['tree_lines']);
                foreach ($expanded['aggregate_lines'] as $leaf) $this->mergeAggregate($aggregate, $leaf);
            } else $this->mergeAggregate($aggregate, $facts + ['paths' => [$pathLabel]]);
        }
        return ['tree_lines' => $tree, 'aggregate_lines' => array_values($aggregate)];
    }

    /** 新计划冻结选中的子版本；旧快照传入时直接沿用，不回查已修改的子件明细。 */
    public function freeze(array $bom, int $itemId, bool $lock = false, array $path = [], ?string $businessDate = null): array
    {
        $path = $this->enterPath($itemId, (string) ($bom['bom_name'] ?? $bom['bom_no'] ?? $itemId), $path);
        $blocks = [];
        $expanded = $this->freezeLines($bom['items'], $path, $blocks, $lock, $businessDate);
        $bom['phantom_expansion_complete'] = ! $blocks;
        if ($blocks) $bom['phantom_expansion_block_reasons'] = array_values(array_unique($blocks));
        else {
            $bom['items'] = $expanded;
            unset($bom['phantom_expansion_block_reasons']);
        }
        return $bom;
    }

    private function freezeLines(array $lines, array $path, array &$blocks, bool $lock, ?string $businessDate): array
    {
        $ids = array_values(array_unique(array_column($lines, 'component_item_id')));
        $items = Item::query()->whereIn('id', $ids)->when($lock, fn ($q) => $q->lockForUpdate())->get()->keyBy('id');
        $out = [];
        foreach ($lines as $line) {
            $item = $items->get((int) $line['component_item_id']);
            $phantom = $item?->isPhantom() ?? false;
            $name = $item?->item_name ?: $line['component_item_name'];
            $match = $this->resolveChild($line, $item, $businessDate, $lock);
            if ($match['status'] === 'leaf') { $out[] = $line; continue; }
            if ($match['status'] !== 'matched') {
                $blocks[] = $name.'：'.$match['block_reason'];
                $out[] = $line;
                continue;
            }
            $childBom = $match['bom'];
            // 已冻结的子快照与显式版本同源，不能用今天的主档替换当时的物料或销售替代。
            $childSnapshot = $line['child_bom_snapshot'] ?? null;
            if (! is_array($childSnapshot) || (int) ($childSnapshot['id'] ?? 0) !== (int) $childBom->id) $childSnapshot = $this->snapshot($childBom);
            $childSnapshot = $this->freeze($childSnapshot, (int) $item->id, $lock, $path, $businessDate);
            if (($childSnapshot['phantom_expansion_complete'] ?? false) !== true) $blocks = array_merge($blocks, $childSnapshot['phantom_expansion_block_reasons'] ?? []);
            if (! $phantom) {
                $line['child_bom_id'] = (int) $childBom->id;
                $line['child_bom_snapshot'] = $childSnapshot;
                $out[] = $line;
                continue;
            }
            foreach ($childSnapshot['items'] as $child) $out[] = $this->flattenPhantomLine($line, $child, $item, $blocks);
        }
        if (collect($out)->pluck('id')->filter()->duplicates()->isNotEmpty()) $blocks[] = '同一个虚拟件在 BOM 里被重复引用，请合并为一行';
        return $out;
    }

    private function flattenPhantomLine(array $line, array $child, Item $phantom, array &$blocks): array
    {
        // (c×Q×(1+lc)+fc)×q×(1+l)+f；固定用量在整张工单只计一次。
        $multiplier = (float) $line['qty'] * (1 + (float) ($line['loss_rate'] ?? 0) / 100);
        $parentFixed = (float) ($line['fixed_qty'] ?? 0);
        $childQty = (float) $child['qty'];
        $childLoss = (float) ($child['loss_rate'] ?? 0);
        $child['position_path'] = trim(($line['position_path'] ?? $line['position_uid'] ?? '').'/'.($child['position_path'] ?? $child['position_uid'] ?? ''), '/');
        $child['qty'] = (string) round($multiplier * $childQty, 6);
        $child['fixed_qty'] = (string) round($parentFixed * $childQty * (1 + $childLoss / 100) + (float) ($child['fixed_qty'] ?? 0), 6);
        if (is_array($child['cutting_quantity_snapshot'] ?? null)) {
            if (abs($multiplier - round($multiplier)) > 0.000001 || $parentFixed > 0) {
                $blocks[] = '虚拟件 '.$phantom->item_name.' 下的下料物料 '.($child['component_item_name'] ?? '').' 需要虚拟件用量为整数且无固定用量，请调整 BOM';
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
        $child['phantom_item_name'] = trim(($child['phantom_item_name'] ?? '') ? $phantom->item_name.' / '.$child['phantom_item_name'] : $phantom->item_name);
        return $child;
    }

    private function mergeAggregate(array &$aggregate, array $leaf): void
    {
        $totalLength = ($leaf['cutting_quantity_basis'] ?? null) === 'total_length';
        $lengthKey = $totalLength ? 'total_length' : ($leaf['cut_length_mm'] === null ? 'ordinary' : number_format((float) $leaf['cut_length_mm'], 2, '.', ''));
        $key = ((int) $leaf['component_item_id']).'|'.$lengthKey.'|'.number_format((float) ($leaf['cut_width_mm'] ?? 0), 2, '.', '').'|'.number_format((float) ($leaf['cut_thickness_mm'] ?? 0), 2, '.', '');
        if (! isset($aggregate[$key])) { $aggregate[$key] = $leaf; return; }
        $aggregate[$key]['demand_qty'] = round($aggregate[$key]['demand_qty'] + $leaf['demand_qty'], 4);
        if ($totalLength) {
            $aggregate[$key]['required_total_length_mm'] = bcadd((string) $aggregate[$key]['required_total_length_mm'], (string) $leaf['required_total_length_mm'], 8);
            $aggregate[$key]['total_length_mm'] = null;
        }
        if (($leaf['required_piece_qty'] ?? null) !== null) $aggregate[$key]['required_piece_qty'] = round((float) ($aggregate[$key]['required_piece_qty'] ?? 0) + (float) $leaf['required_piece_qty'], 4);
        $aggregate[$key]['paths'] = array_values(array_unique(array_merge($aggregate[$key]['paths'] ?? [], $leaf['paths'] ?? [])));
        $aggregate[$key]['replaceable'] = $aggregate[$key]['replaceable'] || ($leaf['replaceable'] ?? false);
    }

    private function selection(string $status, ?string $reason, ?Bom $bom = null, array $candidates = []): array
    {
        return ['status' => $status, 'block_reason' => $reason, 'bom' => $bom, 'candidates' => $candidates];
    }
}
