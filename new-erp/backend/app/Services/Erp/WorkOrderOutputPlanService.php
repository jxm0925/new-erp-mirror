<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{Item, Unit, WorkOrder, WorkOrderPlannedOutput};
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/** Resolves declared route yields without creating reports, stock or a second BOM calculation. */
final class WorkOrderOutputPlanService
{
    public function __construct(
        private readonly WorkOrderPlannedOutputService $plannedOutputs,
        private readonly ProductionDataScopeResolver $scopeResolver,
    ) {}

    public function preview(int $id, object $user, array $permissions, bool $superAdmin = false): array
    {
        // An administrator flag controls data scope; it cannot replace an explicit business permission.
        if (! in_array('production.work_order.view', $permissions, true)) {
            $this->fail('permission_denied', '当前用户没有工单查看权限。', 403, ['permission' => 'production.work_order.view']);
        }
        $workOrder = WorkOrder::query()->find($id);
        if (! $workOrder) $this->fail('not_found', '工单不存在。', 404);
        $scope = $this->scopeResolver->resolve($user, 'production.work_order.view', $permissions, $superAdmin);
        if (! $this->scopeResolver->workOrderVisible($workOrder, $scope)) {
            $this->fail('data_scope_denied', '工单不在当前生产数据范围内。', 403);
        }
        return $this->historical($workOrder) ? $this->projection($workOrder) : $this->prepare($workOrder);
    }

    /** Lists expose historical evidence without resolving current master data for every draft row. */
    public function projection(WorkOrder $workOrder): ?array
    {
        $frozen = data_get($workOrder->routing_snapshot, 'output_plan');
        if (is_array($frozen)) return $frozen;
        if (! $this->historical($workOrder)) return null;

        // Historical releases predate this contract. Show only their stored facts, never retrofit
        // current route rules or current item/unit names into an apparently frozen plan.
        $rows = $this->plannedOutputs->tableAvailable()
            ? ($workOrder->relationLoaded('plannedOutputs') ? $workOrder->plannedOutputs
                : WorkOrderPlannedOutput::query()->where('work_order_id', $workOrder->id)->orderBy('line_no')->get())
            : collect();
        $outputs = $rows->where('status', 'ACTIVE')->map(fn ($row): array => [
            'line_uuid' => $row->line_uuid, 'is_reference' => (bool) $row->is_reference,
            'output_role' => $row->output_role, 'item_id' => (int) $row->item_id,
            'item_code' => $row->item_code_snapshot, 'item_name' => $row->item_name_snapshot,
            'spec' => $row->spec_snapshot, 'base_unit_id' => (int) $row->base_unit_id,
            'base_unit_name' => $row->base_unit_name_snapshot,
            'base_unit_decimal_places' => (int) $row->base_unit_decimal_places_snapshot,
            'planned_base_qty' => (string) $row->planned_base_qty, 'remark' => $row->remark,
            'business_version' => (int) $row->business_version,
        ])->values()->all();
        if ($outputs === []) {
            $itemId = (int) ($workOrder->effective_output_item_id_snapshot ?: $workOrder->output_item_id);
            $route = (array) ($workOrder->routing_snapshot ?? []);
            $node = collect($route['operations'] ?? [])->firstWhere('routing_operation_id', (int) $workOrder->target_routing_operation_id);
            $facts = (int) ($node['output_item_id'] ?? 0) === $itemId ? $node
                : ((int) ($route['output_item_id'] ?? 0) === $itemId ? $route : []);
            $sameHeader = $itemId === (int) $workOrder->output_item_id;
            $outputs[] = [
                'line_uuid' => $this->referenceUuid($workOrder), 'is_reference' => true, 'output_role' => 'product',
                'item_id' => $itemId ?: null, 'item_code' => $facts['output_item_code'] ?? null,
                'item_name' => $facts['output_item_name'] ?? null, 'spec' => null,
                'base_unit_id' => $sameHeader && $workOrder->base_unit_id ? (int) $workOrder->base_unit_id : null,
                'base_unit_name' => $sameHeader ? $workOrder->base_unit_name_snapshot : null,
                'base_unit_decimal_places' => null,
                'planned_base_qty' => $workOrder->target_base_qty === null ? null : (string) $workOrder->target_base_qty,
                'remark' => null, 'business_version' => null,
            ];
        }
        return [
            'schema_version' => 1, 'work_order_id' => (int) $workOrder->id,
            'work_order_version' => (int) $workOrder->business_version, 'immutable' => true,
            'status' => 'legacy_snapshot', 'plan_source' => 'legacy_snapshot', 'reference_basis' => null,
            'outputs' => $outputs, 'operations' => $this->nodes($workOrder)->map(fn (array $node): array => $this->nodeProjection($node))->all(),
            'has_explicit_rules' => false, 'matched' => null, 'execution_supported' => null,
            'issues' => [], 'execution_blockers' => [],
        ];
    }

    public function plannedOutputProjection(WorkOrder $workOrder, bool $editable = false): array
    {
        $history = $this->projection($workOrder);
        if ($history === null) return $this->plannedOutputs->projection($workOrder, $editable);
        return [
            'work_order_id' => (int) $workOrder->id, 'business_version' => (int) $workOrder->business_version,
            'editable' => false, 'plan_source' => $history['status'] === 'frozen' ? 'frozen_plan' : 'legacy_snapshot',
            'multi_output_execution_available' => false, 'outputs' => $history['outputs'],
        ];
    }

    /** Caller owns the WO lock at release. The same resolver supplies preview and authority checks. */
    public function prepare(WorkOrder $workOrder, bool $lock = false): array
    {
        if ($this->historical($workOrder)) return $this->projection($workOrder);
        if ($lock && $this->plannedOutputs->tableAvailable()) {
            // Planned-line writers serialize on this WO as well. Use current locked reads after
            // acquiring it so an older transaction snapshot cannot freeze a preceding plan.
            $workOrder->setRelation('plannedOutputs', WorkOrderPlannedOutput::query()
                ->where('work_order_id', $workOrder->id)->orderBy('line_no')->orderBy('id')->lockForUpdate()->get());
        }
        $plan = $this->plannedOutputs->projection($workOrder);
        $outputs = $plan['outputs'];
        foreach ($outputs as &$output) if (! $output['line_uuid']) $output['line_uuid'] = $this->referenceUuid($workOrder);
        unset($output);
        $nodes = $this->nodes($workOrder);
        $hasRules = $nodes->contains(fn (array $node): bool => ! empty($node['output_rules']));
        $issues = [];
        $executionBlockers = [];
        $items = [];
        foreach ($outputs as $output) {
            $item = $this->item((int) $output['item_id'], $lock, $items);
            $this->validateOutput($output, $item, $issues);
        }
        if ($outputs === []) $this->issue($issues, 'work_order_reference_output_missing', '工单缺少真实的计划参考产出。');

        $reference = collect($outputs)->firstWhere('is_reference', true);
        $basis = $hasRules ? $this->referenceBasis($workOrder, $nodes, $reference, $lock, $items, $issues) : null;
        $operations = [];
        $terminalId = (int) ($nodes->last()['routing_operation_id'] ?? 0);
        foreach ($nodes as $node) {
            $operation = $this->nodeProjection($node);
            $rules = $node['output_rules'] ?? [];
            if (! is_array($rules) || ! array_is_list($rules) || count($rules) > 100) {
                $this->issue($issues, 'operation_output_rules_invalid', '冻结工序的产出规则明细格式无效。', $node);
                $operations[] = $operation;
                continue;
            }
            if ($rules !== []) $operation['rule_source'] = 'routing_rules';
            $seen = [];
            foreach ($rules as $rule) {
                if (! is_array($rule)) {
                    $this->issue($issues, 'operation_output_rule_invalid', '冻结工序的产出规则缺少完整事实。', $node);
                    continue;
                }
                $key = (string) ($rule['output_rule_key'] ?? '');
                if (! Str::isUuid($key) || isset($seen[strtolower($key)])) {
                    $this->issue($issues, 'operation_output_rule_identity_invalid', '同一工序的产出规则必须有独立且有效的稳定标识。', $node, $key);
                    continue;
                }
                $seen[strtolower($key)] = true;
                $itemId = (int) ($rule['item_id'] ?? 0);
                $item = $this->item($itemId, $lock, $items);
                $output = $this->ruleProjection($workOrder, $node, $rule);
                $this->validateOutput($output, $item, $issues, $node, $key, false);
                $ratio = $this->positiveDecimal($rule['base_qty_per_reference_unit'] ?? null);
                if ($ratio === null) $this->issue($issues, 'operation_output_ratio_invalid', '产出系数必须为明确的正十进制数，最多允许八位小数。', $node, $key);
                $output['base_qty_per_reference_unit'] = $ratio;
                if (! in_array($output['output_role'], ['product', 'by_product'], true)
                    || ! in_array($output['quality_mode'], ['none', 'required'], true)
                    || ! in_array($output['output_mode'], ['flow_only', 'warehouse_optional', 'warehouse_required'], true)
                    || ! is_bool($output['allow_continue_without_warehouse'])) {
                    $this->issue($issues, 'operation_output_policy_invalid', '产出用途、质检或去向规则缺少有效的冻结值。', $node, $key);
                }
                if (! $basis || (int) ($rule['reference_item_id'] ?? 0) !== (int) $basis['reference_item_id']
                    || (int) ($rule['reference_base_unit_id'] ?? 0) !== (int) $basis['reference_base_unit_id']) {
                    $this->issue($issues, 'operation_output_reference_mismatch', '工序产出规则的参考物料或库存基本单位与工单参考基准不一致。', $node, $key);
                }
                if ($basis && ! $this->fitsPrecision($basis['reference_base_qty'], $rule['reference_base_unit_decimal_places_snapshot'] ?? null)) {
                    $this->issue($issues, 'operation_output_reference_precision_invalid', '路线参考数量无法按该条产出规则冻结的参考库存单位精度精确表达。', $node, $key,
                        ['reference_base_qty' => $basis['reference_base_qty'],
                            'reference_base_unit_decimal_places' => $rule['reference_base_unit_decimal_places_snapshot'] ?? null]);
                }
                if ($basis && $ratio !== null) {
                    $total = bcmul($basis['reference_base_qty'], $ratio, 16);
                    $output['planned_base_qty'] = $this->positiveDecimal($total);
                    if ($output['planned_base_qty'] === null) $this->issue($issues, 'operation_output_quantity_not_exact', '按产出规则计算的总量无法在八位精度内精确表达。', $node, $key);
                    elseif (! $this->fitsPrecision($output['planned_base_qty'], $output['base_unit_decimal_places'])) {
                        $this->issue($issues, 'operation_output_quantity_precision', '按产出规则计算的总量超过该产出库存单位允许的小数位数。', $node, $key);
                    }
                }
                // Only the actual endpoint promises the WO output. Intermediate stock has its own
                // stable identity and must not be invented as another final WO requirement.
                if ((int) $node['routing_operation_id'] === $terminalId) {
                    $matched = collect($outputs)->filter(fn (array $line): bool => (int) $line['item_id'] === $itemId);
                    if ($matched->count() !== 1) $this->issue($issues, 'operation_output_work_order_line_missing', '目标工序产出没有唯一对应的工单计划产出明细。', $node, $key, ['item_id' => $itemId]);
                    else {
                        $line = $matched->first();
                        $output['work_order_output_line_uuid'] = $line['line_uuid'];
                        $output['output_scope'] = 'work_order';
                        if ((int) $line['base_unit_id'] !== (int) $output['base_unit_id'] || $line['output_role'] !== $output['output_role']) {
                            $this->issue($issues, 'operation_output_work_order_line_mismatch', '目标工序产出的库存单位或用途与工单计划明细不一致。', $node, $key);
                        }
                        if ($output['planned_base_qty'] !== null && bccomp($line['planned_base_qty'], $output['planned_base_qty'], 8) !== 0) {
                            $this->issue($issues, 'operation_output_work_order_quantity_mismatch', '目标工序规则计算的总量与工单计划产出数量不一致。', $node, $key,
                                ['work_order_output_line_uuid' => $line['line_uuid'], 'planned_base_qty' => $line['planned_base_qty'], 'rule_base_qty' => $output['planned_base_qty']]);
                        }
                    }
                }
                $operation['outputs'][] = $output;
            }
            if ($rules !== []) $this->validateExecution($workOrder, $node, $operation['outputs'], $rules, $basis, $executionBlockers);
            $operations[] = $operation;
        }
        return [
            'schema_version' => 1, 'work_order_id' => (int) $workOrder->id,
            'work_order_version' => (int) $workOrder->business_version, 'immutable' => false,
            'status' => 'preview', 'plan_source' => $plan['plan_source'], 'reference_basis' => $basis,
            'outputs' => $outputs, 'operations' => $operations, 'has_explicit_rules' => $hasRules,
            'matched' => $issues === [], 'execution_supported' => $executionBlockers === [] && count($outputs) <= 1,
            'issues' => $issues, 'execution_blockers' => $executionBlockers,
        ];
    }

    /** The plan was resolved by the authoritative Gate inside this same publish transaction. */
    public function freezeLocked(WorkOrder $workOrder, array $plan, object $user, int $releasedVersion): void
    {
        if ($workOrder->status !== WorkOrderApplicationService::WAIT_RELEASE
            || (int) ($plan['work_order_id'] ?? 0) !== (int) $workOrder->id
            || (int) ($plan['work_order_version'] ?? 0) !== (int) $workOrder->business_version
            || ! ($plan['matched'] ?? false) || ! ($plan['execution_supported'] ?? false)) {
            $this->fail('operation_output_plan_not_releasable', '工单产出计划尚未通过当前版本的完整预检，不能冻结。', 409);
        }
        $snapshot = (array) ($workOrder->routing_snapshot ?? []);
        if (isset($snapshot['output_plan'])) $this->fail('operation_output_plan_already_frozen', '工单已有冻结产出计划，禁止覆盖历史事实。', 409);
        $plan['immutable'] = true;
        $plan['status'] = 'frozen';
        $plan['evaluated_work_order_version'] = (int) $workOrder->business_version;
        $plan['work_order_version'] = $releasedVersion;
        $plan['frozen_at'] = now()->toISOString();
        $plan['frozen_by_legacy_id'] = (int) ($user->legacy_id ?? $user->id ?? 0);
        $snapshot['output_plan'] = $plan;
        $workOrder->routing_snapshot = $snapshot;
    }

    public function historical(WorkOrder $workOrder): bool
    {
        return $workOrder->released_at !== null || in_array((string) $workOrder->status, [
            WorkOrderApplicationService::RELEASED, WorkOrderApplicationService::IN_PROGRESS,
            WorkOrderApplicationService::COMPLETED, WorkOrderApplicationService::CLOSED,
        ], true);
    }

    private function referenceBasis(WorkOrder $workOrder, Collection $nodes, ?array $reference, bool $lock, array &$items, array &$issues): ?array
    {
        $rule = $nodes->flatMap(fn (array $node) => is_array($node['output_rules'] ?? null) ? $node['output_rules'] : [])
            ->first(fn ($row): bool => is_array($row));
        if (! $rule || ! $reference) return null;
        $routeItemId = (int) data_get($workOrder->routing_snapshot, 'output_item_id');
        $routeItem = $this->item($routeItemId, $lock, $items);
        $referenceUnitId = (int) ($rule['reference_base_unit_id'] ?? 0);
        if ($routeItemId < 1 || ! $routeItem || (int) ($rule['reference_item_id'] ?? 0) !== $routeItemId
            || $referenceUnitId < 1 || (int) ($routeItem['base_unit_id'] ?? 0) !== $referenceUnitId
            || ! $routeItem['eligible']) {
            $this->issue($issues, 'operation_output_reference_invalid', '冻结路线的参考物料或库存基本单位已缺失、停用或发生身份变化。');
            return null;
        }
        $quantity = $this->positiveDecimal($workOrder->target_base_qty);
        $source = 'work_order_reference';
        $differentTarget = $workOrder->source_type === 'stock_prebuild' && (int) $reference['item_id'] !== $routeItemId;
        if ($differentTarget) {
            $target = $nodes->last();
            $matching = collect(is_array($target['output_rules'] ?? null) ? $target['output_rules'] : [])
                ->filter(fn ($row): bool => is_array($row) && (int) ($row['item_id'] ?? 0) === (int) $reference['item_id']);
            $ratio = $matching->count() === 1 ? $this->positiveDecimal($matching->first()['base_qty_per_reference_unit'] ?? null) : null;
            $targetQty = $this->positiveDecimal($reference['planned_base_qty']);
            if ($ratio === null || $targetQty === null) {
                $this->issue($issues, 'operation_output_reference_basis_missing', '备货目标产出没有唯一有效的规则，不能反推路线参考数量。', $target ?? []);
                return null;
            }
            $quantity = bcdiv($targetQty, $ratio, 8);
            if (bccomp(bcmul($quantity, $ratio, 16), $targetQty, 16) !== 0) {
                $this->issue($issues, 'operation_output_reference_basis_not_exact', '备货目标数量除以产出系数后无法在八位精度内精确表达路线参考数量。', $target ?? []);
                return null;
            }
            $source = 'stock_prebuild_target_rule';
        } elseif ((int) $reference['item_id'] !== $routeItemId || (int) $reference['base_unit_id'] !== $referenceUnitId) {
            $this->issue($issues, 'operation_output_reference_mismatch', '工单计划参考物料或库存基本单位与冻结路线头不一致。');
            return null;
        }
        $precision = $rule['reference_base_unit_decimal_places_snapshot'] ?? null;
        if ($quantity === null || ! $this->fitsPrecision($quantity, $precision)) {
            $this->issue($issues, 'operation_output_reference_quantity_invalid', '路线参考数量缺失、为零或超过冻结参考单位允许的精度。');
            return null;
        }
        return [
            'reference_item_id' => $routeItemId,
            'reference_item_code' => $rule['reference_item_code_snapshot'] ?? null,
            'reference_item_name' => $rule['reference_item_name_snapshot'] ?? null,
            'reference_base_unit_id' => $referenceUnitId,
            'reference_base_unit_name' => $rule['reference_base_unit_name_snapshot'] ?? null,
            'reference_base_unit_decimal_places' => (int) $precision,
            'reference_base_qty' => bcadd($quantity, '0', 8), 'resolution_source' => $source,
        ];
    }

    private function validateExecution(WorkOrder $workOrder, array $node, array $outputs, array $rules, ?array $basis, array &$blockers): void
    {
        if (count($rules) !== 1) {
            $this->issue($blockers, 'multi_output_execution_not_available', '工序声明了多项产出，当前执行底座尚未开放，不能按单产出发布。', $node);
            return;
        }
        if (count($outputs) !== 1) return; // Invalid rule facts are reported by the matching check.
        $output = $outputs[0];
        $target = $workOrder->source_type === 'stock_prebuild'
            && (int) $workOrder->target_routing_operation_id === (int) $node['routing_operation_id'];
        $legacyItem = $target ? (int) ($workOrder->effective_output_item_id_snapshot ?: ($node['output_item_id'] ?? 0)) : (int) ($node['output_item_id'] ?? 0);
        $legacyMode = $target ? (string) ($workOrder->effective_output_mode_snapshot ?: ($node['output_mode'] ?? 'flow_only')) : (string) ($node['output_mode'] ?? 'flow_only');
        // Public-stock prebuild has an explicit WO-level destination override. Compare the declared
        // rule to the configured node, then freeze that authorized override in the resolved row.
        $configuredMode = (string) ($node['output_mode'] ?? 'flow_only');
        if ((int) $output['item_id'] !== $legacyItem || $output['configured_output_mode'] !== $configuredMode
            || $output['output_mode'] !== $legacyMode
            || $output['quality_mode'] !== (string) ($node['quality_mode'] ?? 'none')
            || $output['allow_continue_without_warehouse'] !== (bool) ($node['allow_continue_without_warehouse'] ?? true)) {
            $this->issue($blockers, 'operation_output_legacy_policy_mismatch', '产出规则与现有执行工序的物料、质检或去向不一致，不能通过旧执行底座发布。', $node, $output['output_rule_key']);
        }
        if ($output['planned_base_qty'] !== null && bccomp($output['planned_base_qty'], (string) $workOrder->target_base_qty, 8) !== 0) {
            $this->issue($blockers, 'operation_output_legacy_quantity_mismatch', '产出规则总量与现有执行底座的工序目标数量不同，当前不能发布。', $node, $output['output_rule_key'],
                ['rule_base_qty' => $output['planned_base_qty'], 'executor_target_base_qty' => (string) $workOrder->target_base_qty]);
        }
        if ($basis && (int) $output['base_unit_id'] !== (int) $basis['reference_base_unit_id']) {
            $this->issue($blockers, 'operation_output_legacy_unit_mismatch', '工序产出与当前执行数量使用不同的库存基本单位，当前执行底座不能发布。', $node, $output['output_rule_key']);
        }
    }

    private function validateOutput(array $output, ?array $item, array &$issues, array $node = [], ?string $key = null, bool $quantityRequired = true): void
    {
        if (! $item || ! $item['eligible'] || ($output['output_role'] === 'product' && ! $item['is_production_item'])) {
            $this->issue($issues, 'operation_output_item_invalid', '计划产出必须是已启用的真实库存物料；产品还须可生产。', $node, $key, ['item_id' => $output['item_id']]);
        }
        if (! $item || (int) ($output['base_unit_id'] ?? 0) < 1 || (int) ($item['base_unit_id'] ?? 0) !== (int) $output['base_unit_id']) {
            $this->issue($issues, 'operation_output_unit_changed', '产出的库存基本单位已缺失、停用或发生身份变化，不能重新解释原计划数量。', $node, $key, ['item_id' => $output['item_id']]);
        }
        if (! is_int($output['base_unit_decimal_places']) || $output['base_unit_decimal_places'] < 0 || $output['base_unit_decimal_places'] > 8) {
            $this->issue($issues, 'operation_output_unit_precision_missing', '产出缺少有效的库存单位精度快照。', $node, $key);
        }
        if ($quantityRequired && ($this->positiveDecimal($output['planned_base_qty']) === null
            || ! $this->fitsPrecision($output['planned_base_qty'], $output['base_unit_decimal_places']))) {
            $this->issue($issues, 'operation_output_quantity_invalid', '产出计划数量必须明确、大于零且符合冻结库存单位精度。', $node, $key);
        }
    }

    private function ruleProjection(WorkOrder $workOrder, array $node, array $rule): array
    {
        $target = $workOrder->source_type === 'stock_prebuild'
            && (int) $workOrder->target_routing_operation_id === (int) $node['routing_operation_id'];
        return [
            'operation_output_line_uuid' => $this->uuid('work-order/'.$workOrder->id.'/operation/'.$node['routing_operation_id'].'/rule/'.strtolower((string) ($rule['output_rule_key'] ?? ''))),
            'output_rule_key' => $rule['output_rule_key'] ?? null,
            'routing_operation_output_rule_id' => $rule['routing_operation_output_rule_id'] ?? null,
            'rule_business_version' => $rule['business_version'] ?? null,
            'work_order_output_line_uuid' => null, 'output_scope' => 'intermediate',
            'item_id' => (int) ($rule['item_id'] ?? 0), 'item_code' => $rule['item_code_snapshot'] ?? null,
            'item_name' => $rule['item_name_snapshot'] ?? null, 'spec' => $rule['spec_snapshot'] ?? null,
            'base_unit_id' => isset($rule['base_unit_id']) ? (int) $rule['base_unit_id'] : null,
            'base_unit_name' => $rule['base_unit_name_snapshot'] ?? null,
            // Missing or damaged precision is unknown; casting it to zero would invent a unit rule.
            'base_unit_decimal_places' => is_int($rule['base_unit_decimal_places_snapshot'] ?? null) ? $rule['base_unit_decimal_places_snapshot'] : null,
            'output_role' => $rule['output_role'] ?? null, 'base_qty_per_reference_unit' => null, 'planned_base_qty' => null,
            'quality_mode' => $rule['quality_mode'] ?? null,
            'configured_output_mode' => $rule['output_mode'] ?? null,
            'output_mode' => $target ? $workOrder->effective_output_mode_snapshot : ($rule['output_mode'] ?? null),
            'allow_continue_without_warehouse' => $rule['allow_continue_without_warehouse'] ?? null,
            'remark' => $rule['remark'] ?? null,
        ];
    }

    private function nodes(WorkOrder $workOrder): Collection
    {
        $nodes = collect((array) data_get($workOrder->routing_snapshot, 'operations', []))
            ->filter(fn ($node): bool => is_array($node) && ($node['execution_context'] ?? 'production') === 'production')
            ->sortBy('sequence')->values();
        if ($workOrder->source_type === 'stock_prebuild' && $workOrder->target_routing_operation_id) {
            $target = $nodes->firstWhere('routing_operation_id', (int) $workOrder->target_routing_operation_id);
            if ($target) $nodes = $nodes->where('sequence', '<=', (int) $target['sequence'])->values();
        }
        return $nodes;
    }

    private function nodeProjection(array $node): array
    {
        return [
            'routing_operation_id' => (int) ($node['routing_operation_id'] ?? 0),
            'operation_id' => (int) ($node['operation_id'] ?? 0), 'sequence' => (int) ($node['sequence'] ?? 0),
            'operation_code' => $node['operation_no'] ?? $node['operation_code'] ?? null,
            'operation_name' => $node['operation_name'] ?? null, 'rule_source' => 'legacy_snapshot',
            'legacy_output' => [
                'item_id' => $node['output_item_id'] ?? null, 'output_mode' => $node['output_mode'] ?? 'flow_only',
                'quality_mode' => $node['quality_mode'] ?? 'none',
                'allow_continue_without_warehouse' => (bool) ($node['allow_continue_without_warehouse'] ?? true),
            ],
            'outputs' => [],
        ];
    }

    private function item(int $id, bool $lock, array &$items): ?array
    {
        if (array_key_exists($id, $items)) return $items[$id];
        $query = Item::query()->whereKey($id);
        $item = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $item) return $items[$id] = null;
        $query = Unit::query()->whereKey((int) $item->unit_id);
        $raw = ($lock ? $query->lockForUpdate() : $query)->first();
        $unit = $raw;
        if ($raw?->is_legacy) {
            $query = Unit::query()->whereKey((int) $raw->standard_unit_id);
            $unit = ($lock ? $query->lockForUpdate() : $query)->first();
        }
        return $items[$id] = [
            'eligible' => $item->managementScope() === 'factory' && $item->status === 'enabled' && $item->is_stock_item && $item->item_type !== 'service'
                && $raw?->status === 'enabled' && $unit?->status === 'enabled' && ! $unit->is_legacy,
            'is_production_item' => (bool) $item->is_production_item,
            'base_unit_id' => $unit?->id ? (int) $unit->id : null,
        ];
    }

    private function positiveDecimal(mixed $value): ?string
    {
        if ((! is_string($value) && ! is_int($value)) || ! preg_match('/^\d{1,20}(?:\.\d+)?$/D', (string) $value)) return null;
        $fraction = explode('.', (string) $value, 2)[1] ?? '';
        if (trim(substr($fraction, 8), '0') !== '' || bccomp((string) $value, '0', 8) <= 0) return null;
        return bcadd((string) $value, '0', 8);
    }

    private function fitsPrecision(mixed $value, mixed $precision): bool
    {
        if (! is_int($precision) || $precision < 0 || $precision > 8 || ! is_string($value)) return false;
        $fraction = explode('.', $value, 2)[1] ?? '';
        return trim(substr($fraction, $precision), '0') === '';
    }

    private function issue(array &$issues, string $code, string $message, array $node = [], ?string $key = null, array $details = []): void
    {
        $issues[] = ['code' => $code, 'message' => $message,
            'routing_operation_id' => isset($node['routing_operation_id']) ? (int) $node['routing_operation_id'] : null,
            'output_rule_key' => $key, 'details' => $details];
    }

    private function referenceUuid(WorkOrder $workOrder): string { return $this->uuid('work-order/'.$workOrder->id.'/reference-output'); }
    private function uuid(string $identity): string { return Uuid::uuid5(Uuid::NAMESPACE_URL, 'erp://production/'.$identity)->toString(); }
    private function fail(string $code, string $message, int $status = 422, array $details = []): never
    {
        throw new WorkOrderDomainException($code, $message, $status, $details);
    }
}
