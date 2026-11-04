<?php

namespace App\Services\Erp;

use App\Models\Erp\Bom;
use App\Models\Erp\BomOperationStandard;
use App\Models\Erp\ProductionRouting;
use App\Models\Erp\ProductionRoutingOperation;
use Illuminate\Validation\ValidationException;

/**
 * BOM 选用路线后，按每道路线节点维护工时与提成（第 1 步：只覆盖本产品自身工序和发货工序）。
 * 多款产品可共用同一条路线，各自在 BOM 里维护；工单下达时由 resolveForPlan 解析并冻结。
 *
 * 规则：
 * - 工时 NULL=未设、0=明确为 0，二者分开存，未设不阻止生产。
 * - 提成 inherit 沿用模板兜底（商品分类 → 默认模板）、override 指定比例、zero 明确不计且停止兜底。
 * - 没有提成编辑权限时，服务端忽略提交里的 mode/rate 并保留原值，不把缺字段当作清空。
 */
final class BomOperationStandardService
{
    public function __construct(private readonly ProductionPerformanceRateResolver $resolver) {}

    /** 校验 BOM 选用的路线：必须有效（draft/active）且适用于产出物料。 */
    public function assertRouting(?int $routingId, int $outputItemId): ?ProductionRouting
    {
        if (! $routingId) return null;
        $routing = ProductionRouting::query()->whereIn('status', ['draft', 'active'])->applicableToItem($outputItemId)->find($routingId);
        if (! $routing) throw ValidationException::withMessages(['routing_id' => '所选工艺路线已停用或不适用于这个产出物料，请重新选择。']);
        return $routing;
    }

    /** BOM 详情中的“工艺路线与工时提成”：canView=false 时不返回任何提成字段。 */
    public function describe(Bom $bom, bool $canView): array
    {
        $routing = $bom->routing_id ? ProductionRouting::query()->find($bom->routing_id) : null;
        if (! $routing) return ['routing' => null, 'nodes' => [], 'summary' => null, 'newer_routing' => null];
        $nodes = $this->nodes($routing);
        $saved = BomOperationStandard::query()->where('bom_id', $bom->id)->get()->keyBy('routing_operation_id');
        $fallback = $canView ? $this->resolver->forItem((int) $bom->output_item_id, $bom->product_id ? (int) $bom->product_id : null) : [];
        $rows = [];
        foreach ($nodes as $node) {
            $row = $saved->get($node->id);
            $item = [
                'routing_operation_id' => (int) $node->id, 'operation_id' => (int) $node->operation_id,
                'operation_name' => $node->operation?->operation_name, 'sequence' => (int) $node->sequence,
                'execution_context' => $node->execution_context ?: 'production', 'shipment_operation_type' => $node->shipment_operation_type,
                'setup_minutes' => $row && $row->setup_minutes !== null ? (float) $row->setup_minutes : null,
                'unit_minutes' => $row && $row->unit_minutes !== null ? (float) $row->unit_minutes : null,
            ];
            if ($canView) {
                $mode = $row?->performance_mode ?: 'inherit';
                $item += ['performance_mode' => $mode, 'performance_rate' => $mode === 'override' && $row->performance_rate !== null ? (float) $row->performance_rate : null,
                    'resolved' => $this->resolveOne($mode, $row?->performance_rate, $fallback[(int) $node->operation_id] ?? null)];
            }
            $rows[] = $item;
        }
        $newer = ProductionRouting::query()->where('routing_no', $routing->routing_no)->where('status', 'active')->where('version', '>', $routing->version)->orderByDesc('version')->first(['id', 'version']);
        return [
            'routing' => ['id' => (int) $routing->id, 'routing_no' => $routing->routing_no, 'routing_name' => $routing->routing_name,
                'version' => (int) $routing->version, 'status' => $routing->status],
            'nodes' => $rows,
            'summary' => $this->summary($rows, $canView),
            'newer_routing' => $newer ? ['id' => (int) $newer->id, 'version' => (int) $newer->version] : null,
            'default_template' => $canView ? $this->resolver->defaultTemplate() : null,
        ];
    }

    /**
     * 保存 BOM 的路线节点设置（与 BOM 主表同一事务、同一行锁内调用）。
     * @param array<int, array> $rows 前端节点行；未出现的节点删除设置
     */
    public function save(Bom $bom, array $rows, bool $canEditRate, object $actor): void
    {
        if (! $bom->routing_id) {
            BomOperationStandard::query()->where('bom_id', $bom->id)->delete();
            return;
        }
        $nodeIds = ProductionRoutingOperation::query()->where('routing_id', $bom->routing_id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        // 换路线后旧节点设置不再适用，删除而不是猜测迁移到新节点。
        BomOperationStandard::query()->where('bom_id', $bom->id)->whereNotIn('routing_operation_id', $nodeIds ?: [0])->delete();
        $existing = BomOperationStandard::query()->where('bom_id', $bom->id)->get()->keyBy('routing_operation_id');
        $seen = [];
        foreach (array_values($rows) as $index => $row) {
            $nodeId = (int) ($row['routing_operation_id'] ?? 0);
            $label = '第 '.($index + 1).' 道工序';
            if (! in_array($nodeId, $nodeIds, true) || isset($seen[$nodeId])) throw ValidationException::withMessages(["operation_standards.$index" => $label.'不属于所选工艺路线，请刷新后重试。']);
            $seen[$nodeId] = true;
            $setup = $this->minutes($row['setup_minutes'] ?? null, "operation_standards.$index.setup_minutes", $label.'准备工时');
            $unit = $this->minutes($row['unit_minutes'] ?? null, "operation_standards.$index.unit_minutes", $label.'单件工时');
            $old = $existing->get($nodeId);
            $mode = $old?->performance_mode ?: 'inherit';
            $rate = $old?->performance_rate;
            if ($canEditRate && array_key_exists('performance_mode', $row)) {
                $mode = (string) $row['performance_mode'];
                if (! in_array($mode, BomOperationStandard::MODES, true)) throw ValidationException::withMessages(["operation_standards.$index.performance_mode" => $label.'提成方式不正确。']);
                $rate = null;
                if ($mode === 'override') {
                    $value = $row['performance_rate'] ?? null;
                    if ($value === null || $value === '' || ! is_numeric($value) || (float) $value <= 0 || (float) $value > 1) {
                        throw ValidationException::withMessages(["operation_standards.$index.performance_rate" => $label.'指定比例必须大于 0% 且不超过 100%。']);
                    }
                    $rate = bcadd((string) $value, '0', 6);
                }
            }
            BomOperationStandard::query()->updateOrCreate(['bom_id' => $bom->id, 'routing_operation_id' => $nodeId], [
                'setup_minutes' => $setup, 'unit_minutes' => $unit, 'performance_mode' => $mode, 'performance_rate' => $rate,
                'updated_by_legacy_id' => (int) ($actor->legacy_id ?? 0) ?: null,
            ] + ($old ? [] : ['created_by_legacy_id' => (int) ($actor->legacy_id ?? 0) ?: null]));
        }
    }

    /** 复制 BOM 时连同路线节点设置一起复制。 */
    public function copy(Bom $from, Bom $to): void
    {
        if (! $from->routing_id || (int) $from->routing_id !== (int) $to->routing_id) return;
        foreach (BomOperationStandard::query()->where('bom_id', $from->id)->get() as $row) {
            BomOperationStandard::query()->create(['bom_id' => $to->id, 'routing_operation_id' => $row->routing_operation_id,
                'setup_minutes' => $row->setup_minutes, 'unit_minutes' => $row->unit_minutes,
                'performance_mode' => $row->performance_mode, 'performance_rate' => $row->performance_rate,
                'created_by_legacy_id' => $row->created_by_legacy_id, 'updated_by_legacy_id' => $row->updated_by_legacy_id]);
        }
    }

    /**
     * 工单规划使用：按 routing_operation_id 返回工时与最终比例（含来源），供冻结到工单工序。
     * BOM 未选这条路线时，工时全部视为未设，比例只走模板兜底。
     * @return array<int, array{setup_minutes: ?float, unit_minutes: ?float, performance_rate: ?string, performance_source: string}>
     */
    public function resolveForPlan(?int $bomId, int $routingId, ?int $itemId, ?int $productId, ?array $overrides = null): array
    {
        $bom = $bomId ? Bom::query()->find($bomId, ['id', 'routing_id']) : null;
        $saved = $bom && (int) $bom->routing_id === $routingId ? BomOperationStandard::query()->where('bom_id', $bom->id)->get()->keyBy('routing_operation_id') : collect();
        $fallback = $this->resolver->forItem($itemId, $productId);
        // 本单覆盖值按 routing_operation_id 传入；JSON 对象键可能是数字字符串，统一按 int 归一。
        $custom = collect($overrides ?? [])->keyBy(fn ($row, $key) => (int) $key);
        $result = [];
        foreach (ProductionRoutingOperation::query()->where('routing_id', $routingId)->get(['id', 'operation_id']) as $node) {
            $row = $saved->get($node->id);
            $entry = $custom->get((int) $node->id);
            // 工时与提成可各自覆盖；缺少提成字段必须继续用 BOM，不能把仅改工时变成沿用模板。
            $setup = is_array($entry) && array_key_exists('setup_minutes', $entry) ? $entry['setup_minutes'] : $row?->setup_minutes;
            $unit = is_array($entry) && array_key_exists('unit_minutes', $entry) ? $entry['unit_minutes'] : $row?->unit_minutes;
            $hasRate = is_array($entry) && array_key_exists('performance_mode', $entry);
            $mode = $hasRate ? (string) $entry['performance_mode'] : ($row?->performance_mode ?: 'inherit');
            $rate = $hasRate ? ($entry['performance_rate'] ?? null) : $row?->performance_rate;
            $resolved = $this->resolveOne($mode, $rate, $fallback[(int) $node->operation_id] ?? null);
            $result[(int) $node->id] = [
                'setup_minutes' => $setup !== null ? (float) $setup : null,
                'unit_minutes' => $unit !== null ? (float) $unit : null,
                'performance_rate' => $resolved['rate'], 'performance_source' => $resolved['source'],
                'performance_template' => $resolved['template'] ?? null,
            ];
        }
        return $result;
    }

    private function resolveOne(string $mode, mixed $rate, ?array $fallback): array
    {
        if ($mode === 'override' && $rate !== null) return ['rate' => bcadd((string) $rate, '0', 6), 'source' => 'bom', 'template' => null];
        if ($mode === 'zero') return ['rate' => '0.000000', 'source' => 'zero', 'template' => null];
        if ($fallback) return ['rate' => $fallback['rate'], 'source' => $fallback['source'], 'template' => $fallback['template']];
        return ['rate' => null, 'source' => 'missing', 'template' => null];
    }

    private function nodes(ProductionRouting $routing)
    {
        return ProductionRoutingOperation::query()->with('operation')->where('routing_id', $routing->id)->orderBy('sequence')->get();
    }

    private function summary(array $rows, bool $canView): array
    {
        $unset = collect($rows)->filter(fn ($r) => $r['setup_minutes'] === null || $r['unit_minutes'] === null)->count();
        $summary = [
            'node_count' => count($rows),
            'standards_status' => $unset === 0 ? 'complete' : ($unset === count($rows) ? 'unset' : 'partial'),
            'unset_count' => $unset,
            'unit_minutes_total' => round(collect($rows)->sum(fn ($r) => (float) ($r['unit_minutes'] ?? 0)), 4),
            'setup_minutes_total' => round(collect($rows)->sum(fn ($r) => (float) ($r['setup_minutes'] ?? 0)), 4),
        ];
        if ($canView) {
            $resolved = collect($rows)->pluck('resolved');
            $summary += ['performance_configured' => $resolved->where('source', '!=', 'missing')->count(),
                'performance_missing' => $resolved->where('source', 'missing')->count(),
                'performance_total_rate' => (float) $resolved->sum(fn ($r) => (float) ($r['rate'] ?? 0))];
        }
        return $summary;
    }

    private function minutes(mixed $value, string $field, string $label): ?string
    {
        if ($value === null || $value === '') return null;
        if (! is_numeric($value) || (float) $value < 0 || (float) $value > 999999) throw ValidationException::withMessages([$field => $label.'必须是 0 或正数。']);
        return bcadd((string) $value, '0', 4);
    }
}
