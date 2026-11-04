<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\Bom;
use App\Models\Erp\BomPositionPerformance;
use App\Models\Erp\ProductionRoutingOperation;

/**
 * 半成品工序提成（整机整套）：位置由根 BOM 行的 position_uid 向下按 '/' 拼接，
 * 节点由子 BOM 路线的车间工序按 (operation_id, 第几次出现) 标识。
 * inherit 走根产出的分类/默认模板兜底；override/zero 由本 BOM 明确。
 */
final class BomPositionPerformanceService
{
    private const MADE_TYPES = ['semi_finished', 'finished_product'];
    private const MAX_DEPTH = 6;

    public function __construct(
        private readonly BomMatcher $bomMatcher,
        private readonly ProductionPerformanceRateResolver $resolver,
    ) {}

    /** 半成品位置树：每个自制件位置给出其子 BOM 及路线车间节点（带本 BOM 的整套提成解析）。 */
    public function tree(Bom $bom, bool $canView): array
    {
        $fallback = $canView ? $this->resolver->forItem($bom->output_item_id ? (int) $bom->output_item_id : null, $bom->product_id ? (int) $bom->product_id : null) : [];
        $saved = BomPositionPerformance::query()->where('bom_id', $bom->id)->get()
            ->keyBy(fn ($r) => $r->position_path.'|'.$r->operation_id.'|'.$r->occurrence);
        return ['positions' => $this->positions($bom, $saved, $fallback, $canView)];
    }

    /** 保存整套提成：保留当前树内编辑，略过本次组成修改移除的旧节点；其余无效位置拒绝，树外已存行删除。 */
    public function save(Bom $bom, array $rows, bool $canEditRate, object $actor, array $previousPositions = []): void
    {
        if (! $canEditRate) return;
        $valid = [];
        $previousValid = [];
        $collect = function (array $positions, array &$keys) use (&$collect) {
            foreach ($positions as $p) {
                foreach ($p['nodes'] ?? [] as $n) $keys[$p['position_path'].'|'.$n['operation_id'].'|'.$n['occurrence']] = true;
                $collect($p['children'] ?? [], $keys);
            }
        };
        $collect($this->tree($bom, false)['positions'], $valid);
        $collect($previousPositions, $previousValid);
        $incoming = [];
        foreach (array_values($rows) as $i => $row) {
            $n = $i + 1;
            $path = (string) ($row['position_path'] ?? '');
            $opId = (int) ($row['operation_id'] ?? 0);
            $occ = (int) ($row['occurrence'] ?? 0);
            $key = $path.'|'.$opId.'|'.$occ;
            if (! isset($valid[$key])) {
                // 页面尚持有保存前的位置树；只允许忽略本次组成变更移除的旧合法节点。
                if (isset($previousValid[$key])) continue;
                throw new WorkOrderDomainException('pos_perf_invalid', "第 {$n} 行整套提成位置或工序不在当前树中，请刷新后重试。", 422);
            }
            $mode = (string) ($row['performance_mode'] ?? '');
            if (! in_array($mode, BomPositionPerformance::MODES, true)) throw new WorkOrderDomainException('pos_perf_invalid', "第 {$n} 行提成方式不正确。", 422);
            $rate = null;
            if ($mode === 'override') {
                $v = $row['performance_rate'] ?? null;
                if (! is_numeric($v) || (float) $v <= 0 || (float) $v > 1) throw new WorkOrderDomainException('pos_perf_invalid', "第 {$n} 行指定比例必须大于 0% 且不超过 100%。", 422);
                $rate = bcadd((string) $v, '0', 6);
            }
            $incoming[$key] = ['performance_mode' => $mode, 'performance_rate' => $rate];
        }
        $actorId = (int) ($actor->legacy_id ?? 0) ?: null;
        $existing = BomPositionPerformance::query()->where('bom_id', $bom->id)->get();
        foreach ($existing as $row) {
            $key = $row->position_path.'|'.$row->operation_id.'|'.$row->occurrence;
            if (! isset($valid[$key])) $row->delete();
        }
        foreach ($incoming as $key => $val) {
            [$path, $opId, $occ] = explode('|', $key);
            $old = $existing->first(fn ($r) => $r->position_path === $path && (int) $r->operation_id === (int) $opId && (int) $r->occurrence === (int) $occ);
            BomPositionPerformance::query()->updateOrCreate(
                ['bom_id' => $bom->id, 'position_path' => $path, 'operation_id' => (int) $opId, 'occurrence' => (int) $occ],
                $val + ['updated_by_legacy_id' => $actorId] + ($old ? [] : ['created_by_legacy_id' => $actorId]));
        }
    }

    /**
     * 冻结到工单的整套提成计划：按 BOM 当前整套配置解析出每个位置每个节点的最终比例与来源。
     * 内部结算用途，不看操作者提成查看权限。
     */
    public function freezePlan(Bom $bom): array
    {
        $fallback = $this->resolver->forItem($bom->output_item_id ? (int) $bom->output_item_id : null, $bom->product_id ? (int) $bom->product_id : null);
        $saved = BomPositionPerformance::query()->where('bom_id', $bom->id)->get()
            ->keyBy(fn ($r) => $r->position_path.'|'.$r->operation_id.'|'.$r->occurrence);
        $positions = [];
        $flatten = function (array $list) use (&$flatten, &$positions) {
            foreach ($list as $p) {
                $nodes = [];
                foreach ($p['nodes'] ?? [] as $n) {
                    $nodes[] = ['operation_id' => $n['operation_id'], 'occurrence' => $n['occurrence'],
                        'operation_name' => $n['operation_name'], 'rate' => $n['resolved']['rate'] ?? null, 'source' => $n['resolved']['source'] ?? 'missing'];
                }
                $positions[$p['position_path']] = ['item_id' => $p['item_id'], 'item_name' => $p['item_name'], 'nodes' => $nodes];
                $flatten($p['children'] ?? []);
            }
        };
        $flatten($this->positions($bom, $saved, $fallback, true));
        return ['bom_id' => (int) $bom->id, 'bom_no' => $bom->bom_no, 'frozen_at' => now()->toDateTimeString(), 'positions' => $positions];
    }

    /** 半成品单独销售：冻结其默认 BOM 自己的节点提成（步骤 1）+ 其下位置的整套提成（步骤 3）。 */
    public function freezeAloneSale(Bom $bom): array
    {
        $standards = $bom->routing_id ? app(BomOperationStandardService::class)
            ->resolveForPlan((int) $bom->id, (int) $bom->routing_id, (int) $bom->output_item_id, $bom->product_id ? (int) $bom->product_id : null) : [];
        $occurrences = [];
        $ownNodes = [];
        if ($bom->routing_id) {
            foreach (ProductionRoutingOperation::query()->with('operation')->where('routing_id', $bom->routing_id)->orderBy('sequence')->get() as $node) {
                $occurrenceKey = ($node->execution_context ?: 'production').':'.$node->operation_id;
                $occ = ($occurrences[$occurrenceKey] ?? 0) + 1;
                $occurrences[$occurrenceKey] = $occ;
                $row = $standards[(int) $node->id] ?? [];
                $ownNodes[] = ['operation_id' => (int) $node->operation_id, 'occurrence' => $occ,
                    'routing_operation_id' => (int) $node->id, 'execution_context' => $node->execution_context ?: 'production',
                    'operation_name' => $node->operation?->operation_name,
                    'rate' => $row['performance_rate'] ?? null, 'source' => $row['performance_source'] ?? 'missing'];
            }
        }
        $plan = $this->freezePlan($bom);
        return ['source' => 'alone_sale', 'bom_id' => (int) $bom->id, 'bom_no' => $bom->bom_no,
            'own_nodes' => $ownNodes, 'positions' => $plan['positions'], 'frozen_at' => $plan['frozen_at']];
    }

    /** 复制版本：根段 position_uid 按新旧行映射改写，更深层段属于子 BOM 原样保留。 */
    public function copy(Bom $from, Bom $to, array $uidMap): void
    {
        foreach (BomPositionPerformance::query()->where('bom_id', $from->id)->get() as $row) {
            $segments = explode('/', $row->position_path);
            if (empty($uidMap[$segments[0]])) continue;
            $segments[0] = $uidMap[$segments[0]];
            BomPositionPerformance::create([
                'bom_id' => $to->id, 'position_path' => implode('/', $segments),
                'operation_id' => $row->operation_id, 'occurrence' => $row->occurrence,
                'performance_mode' => $row->performance_mode, 'performance_rate' => $row->performance_rate,
            ]);
        }
    }

    private function positions(Bom $bom, $saved, array $fallback, bool $canView, string $prefix = '', float $parentQty = 1.0, array $bomPath = [], int $depth = 0): array
    {
        if ($depth >= self::MAX_DEPTH || in_array((int) $bom->id, $bomPath, true)) return [];
        $bomPath[] = (int) $bom->id;
        $out = [];
        foreach ($bom->items()->with('componentItem')->get() as $line) {
            $component = $line->componentItem;
            if (! $component || ! in_array($component->item_type, self::MADE_TYPES, true) || ! $line->position_uid) continue;
            $path = $prefix === '' ? $line->position_uid : $prefix.'/'.$line->position_uid;
            $qtyPerRoot = $parentQty * ((float) $line->qty * (1 + (float) $line->loss_rate / 100) + (float) $line->fixed_qty);
            $childBom = null;
            $reason = null;
            if ($line->child_bom_id) {
                $candidate = Bom::query()->find($line->child_bom_id);
                if ($candidate && $this->bomMatcher->eligibleQuery()->whereKey($candidate->id)->where('output_item_id', (int) $line->component_item_id)->exists()) {
                    $childBom = $candidate;
                } else {
                    $reason = '指定的子 BOM 不可用';
                }
            } else {
                $match = $this->bomMatcher->match(null, null, (int) $line->component_item_id, null);
                $childBom = ($match['status'] ?? null) === 'matched' ? Bom::query()->find($match['bom_id']) : null;
                if (! $childBom) $reason = '未找到子 BOM';
            }
            $nodes = [];
            if ($childBom && ! $childBom->routing_id) $reason = '子 BOM 未选路线';
            if ($childBom && $childBom->routing_id) {
                $occurrences = [];
                $routingNodes = ProductionRoutingOperation::query()->with('operation')
                    ->where('routing_id', $childBom->routing_id)
                    ->where(fn ($q) => $q->whereNull('execution_context')->orWhere('execution_context', '!=', 'shipment'))
                    ->orderBy('sequence')->get();
                foreach ($routingNodes as $node) {
                    $occ = ($occurrences[(int) $node->operation_id] ?? 0) + 1;
                    $occurrences[(int) $node->operation_id] = $occ;
                    $n = [
                        'operation_id' => (int) $node->operation_id,
                        'occurrence' => $occ,
                        'operation_name' => $node->operation?->operation_name,
                        'sequence' => (int) $node->sequence,
                    ];
                    if ($canView) {
                        $row = $saved->get($path.'|'.$node->operation_id.'|'.$occ);
                        $mode = $row?->performance_mode ?: 'inherit';
                        $n += [
                            'mode' => $mode,
                            'rate' => $mode === 'override' ? $row?->performance_rate : null,
                            'resolved' => $this->resolveOne($mode, $row?->performance_rate, $fallback[(int) $node->operation_id] ?? null),
                        ];
                    }
                    $nodes[] = $n;
                }
            }
            $out[] = [
                'position_path' => $path,
                'bom_item_id' => (int) $line->id,
                'item_id' => (int) $line->component_item_id,
                'item_name' => $line->component_item_name,
                'qty_per_root' => round($qtyPerRoot, 6),
                'child_bom' => $childBom ? ['id' => (int) $childBom->id, 'bom_no' => $childBom->bom_no, 'version' => $childBom->version] : null,
                'reason' => $reason,
                'nodes' => $nodes,
                'children' => $childBom ? $this->positions($childBom, $saved, $fallback, $canView, $path, $qtyPerRoot, $bomPath, $depth + 1) : [],
            ];
        }
        return $out;
    }

    private function resolveOne(string $mode, mixed $rate, ?array $fallback): array
    {
        if ($mode === 'override' && $rate !== null) return ['rate' => bcadd((string) $rate, '0', 6), 'source' => 'bom', 'template' => null];
        if ($mode === 'zero') return ['rate' => '0.000000', 'source' => 'zero', 'template' => null];
        if ($fallback) return ['rate' => $fallback['rate'], 'source' => $fallback['source'], 'template' => $fallback['template']];
        return ['rate' => null, 'source' => 'missing', 'template' => null];
    }
}
