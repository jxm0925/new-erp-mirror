<?php

namespace App\Services\Erp\Manufacturing;

/**
 * 节点身份 = (operation_id, 该工序在同一路线生产/发货节点中按顺序第几次出现)。
 * 根工单自己工序用生产或单次发货冻结的比例；子工单的工序比例一律回到该销售事件的 performance_plan 里
 * 按“位置路径 + 节点身份”查；查不到记 missing，不再退回子工单自身的比例。
 */
final class PerformancePlanRateService
{
    /** @return array<int, array{operation_id: int, occurrence: int}> wo_operation_id => 身份 */
    public function occurrenceMap(object $wo, string $context = 'production'): array
    {
        $ops = is_array($wo->operations ?? null) ? $wo->operations
            : \Illuminate\Support\Facades\DB::table('erp_mfg_work_order_operations')->where('work_order_id', $wo->id)
                ->where('execution_context', $context)->orderBy('sequence')->get()->all();
        $map = [];
        $seen = [];
        foreach ($ops as $op) {
            $op = (object) $op;
            if (($op->execution_context ?? 'production') !== $context) continue;
            $occ = ($seen[(int) $op->operation_id] ?? 0) + 1;
            $seen[(int) $op->operation_id] = $occ;
            $map[(int) $op->id] = ['operation_id' => (int) $op->operation_id, 'occurrence' => $occ];
        }
        return $map;
    }

    /** 按 (operation_id, occurrence) 在路线快照某执行域节点里找第几次出现的身份；找不到返回 null。 */
    public function snapshotIdentity(array $snapshotOps, int $operationId, string $context): ?array
    {
        $occ = 0;
        foreach ($snapshotOps as $op) {
            if (($op['execution_context'] ?? 'production') !== $context) continue;
            if ((int) ($op['operation_id'] ?? 0) !== $operationId) continue;
            $occ++;
        }
        return $occ ? ['operation_id' => $operationId, 'occurrence' => $occ] : null;
    }

    /** 查某个 identity（op_id 第 occ 次出现）在快照节点数组里的下标。 */
    public function snapshotIndex(array $snapshotOps, int $operationId, int $occurrence, string $context): ?int
    {
        $occ = 0;
        foreach ($snapshotOps as $i => $op) {
            if (($op['execution_context'] ?? 'production') !== $context) continue;
            if ((int) ($op['operation_id'] ?? 0) !== $operationId) continue;
            if (++$occ === $occurrence) return $i;
        }
        return null;
    }

    /** 根工单自己冻结的节点比例：routing_snapshot 里按身份找 operation 行。 */
    public function rootOwnRate(object $rootWo, int $operationId, int $occurrence): array
    {
        $ops = is_array($rootWo->routing_snapshot ?? null) ? ($rootWo->routing_snapshot['operations'] ?? []) : (json_decode((string) $rootWo->routing_snapshot, true)['operations'] ?? []);
        $index = $this->snapshotIndex($ops, $operationId, $occurrence, 'production');
        $op = $index !== null ? $ops[$index] : null;
        if (! $op) return ['rate' => null, 'source' => 'missing'];
        return ['rate' => $op['performance_rate'] ?? null, 'source' => $op['performance_source'] ?? ($op['performance_rate'] !== null ? 'frozen' : 'missing')];
    }

    /** 子工单节点比例 = 根工单 performance_plan.positions[path].nodes 里按身份查。 */
    public function positionRate(?array $rootPlan, string $positionPath, int $operationId, int $occurrence): array
    {
        $nodes = $rootPlan['positions'][$positionPath]['nodes'] ?? [];
        foreach ($nodes as $n) {
            if ((int) ($n['operation_id'] ?? 0) === $operationId && (int) ($n['occurrence'] ?? 0) === $occurrence) {
                return ['rate' => $n['rate'] ?? null, 'source' => $n['source'] ?? 'missing'];
            }
        }
        return ['rate' => null, 'source' => 'missing'];
    }

    /** 单独销售根节点按该次发货冻结的身份取值，未匹配也不能回退生产时的比例。 */
    public function ownPlanRate(?array $plan, int $operationId, int $occurrence, string $context = 'production'): array
    {
        foreach ($plan['own_nodes'] ?? [] as $node) {
            if (($node['execution_context'] ?? 'production') === $context
                && (int) ($node['operation_id'] ?? 0) === $operationId && (int) ($node['occurrence'] ?? 0) === $occurrence) {
                return ['rate' => $node['rate'] ?? null, 'source' => $node['source'] ?? 'missing'];
            }
        }
        return ['rate' => null, 'source' => 'missing'];
    }

    /**
     * 包装作业节点的比例：先按发货行批次找产出工单，用其 routing_snapshot 发货域节点身份匹配；
     * 半成品单独销售的行用行上冻结的 alone_sale own_nodes；都没有记 missing。
     * @return array{rate: ?string, source: string}
     */
    public function packingContentRate(object $content, object $packingOp, ?callable $woByBatch = null): array
    {
        $operationId = (int) ($packingOp->operation_id ?? 0);
        $packingSnapshot = is_array($content->routing_snapshot ?? null) ? $content->routing_snapshot
            : json_decode((string) ($content->routing_snapshot ?? ''), true);
        $occurrence = $this->packingOccurrence($packingOp, $packingSnapshot['operations'] ?? []);
        if ($operationId <= 0 || $occurrence === null) return ['rate' => null, 'source' => 'missing'];
        $linePlan = isset($content->performance_plan) && $content->performance_plan
            ? (is_array($content->performance_plan) ? $content->performance_plan : json_decode((string) $content->performance_plan, true))
            : null;
        if (is_array($linePlan) && ($linePlan['source'] ?? null) === 'alone_sale') {
            return $this->ownPlanRate($linePlan, $operationId, $occurrence, 'shipment');
        }
        $wo = null;
        if ($woByBatch && ! empty($content->batch_no)) $wo = $woByBatch((string) $content->batch_no, (int) ($content->item_id ?? 0));
        $snapshot = is_array($wo->routing_snapshot ?? null) ? $wo->routing_snapshot : (is_string($wo->routing_snapshot ?? null) ? json_decode($wo->routing_snapshot, true) : null);
        $ops = $snapshot['operations'] ?? [];
        $index = $this->snapshotIndex($ops, $operationId, $occurrence, 'shipment');
        $op = $index !== null ? $ops[$index] : null;
        return $op ? ['rate' => $op['performance_rate'] ?? null, 'source' => $op['performance_source'] ?? ($op['performance_rate'] !== null ? 'frozen' : 'missing')]
            : ['rate' => null, 'source' => 'missing'];
    }

    /** 先还原包装作业原路线中的出现次数，才能跨路线版本匹配，不能把所有同名工序当成第一次。 */
    public function packingOccurrence(object $packingOp, array $snapshotOps): ?int
    {
        $occurrence = 0;
        foreach ($snapshotOps as $node) {
            if (($node['execution_context'] ?? 'production') !== 'shipment'
                || (int) ($node['operation_id'] ?? 0) !== (int) ($packingOp->operation_id ?? 0)) continue;
            $occurrence++;
            if ((int) ($node['routing_operation_id'] ?? 0) > 0 && (int) $node['routing_operation_id'] === (int) ($packingOp->routing_operation_id ?? 0)) return $occurrence;
        }
        return null;
    }
}
