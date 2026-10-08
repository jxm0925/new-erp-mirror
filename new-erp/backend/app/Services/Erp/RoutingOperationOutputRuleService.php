<?php

namespace App\Services\Erp;

use App\Models\Erp\{Item, ProductionRouting, ProductionRoutingOperation, RoutingOperationOutputRule, Unit};
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Route rules describe expected outputs; no report, inventory or cost is created here. */
final class RoutingOperationOutputRuleService
{
    private const INPUT_FIELDS = [
        'output_rule_key', 'item_id', 'output_role', 'base_qty_per_reference_unit',
        'quality_mode', 'output_mode', 'allow_continue_without_warehouse', 'remark',
    ];

    private const FROZEN_FIELDS = [
        'output_rule_key', 'line_no', 'business_version', 'item_id', 'output_role', 'base_unit_id',
        'item_code_snapshot', 'item_name_snapshot', 'spec_snapshot', 'base_unit_name_snapshot',
        'base_unit_decimal_places_snapshot', 'reference_item_id', 'reference_base_unit_id',
        'reference_item_code_snapshot', 'reference_item_name_snapshot', 'reference_base_unit_name_snapshot',
        'reference_base_unit_decimal_places_snapshot', 'base_qty_per_reference_unit',
        'quality_mode', 'output_mode', 'allow_continue_without_warehouse', 'remark',
    ];

    /** Caller holds the draft route lock. Validate every row before rebuilding any node. */
    public function prepare(ProductionRouting $routing, array $rows, ?ProductionRoutingOperation $previousNode): array
    {
        $rows = Validator::make(['output_rules' => $rows], [
            'output_rules' => 'present|array|max:100',
            'output_rules.*' => 'required|array:'.implode(',', self::INPUT_FIELDS),
            'output_rules.*.output_rule_key' => 'required|uuid|distinct',
            'output_rules.*.item_id' => 'required|integer|min:1|distinct',
            'output_rules.*.output_role' => 'required|in:product,by_product',
            'output_rules.*.base_qty_per_reference_unit' => 'required',
            'output_rules.*.quality_mode' => 'required|in:none,required',
            'output_rules.*.output_mode' => 'required|in:flow_only,warehouse_optional,warehouse_required',
            'output_rules.*.allow_continue_without_warehouse' => 'required|boolean',
            'output_rules.*.remark' => 'nullable|string|max:500',
        ])->validate()['output_rules'];
        if (! array_is_list($rows)) $this->fail('产出规则必须为连续明细列表。');
        if ($rows === []) return [];
        $previous = $previousNode?->outputRules->keyBy('output_rule_key') ?? collect();
        $reference = $this->validItem((int) $routing->output_item_id, 'product');
        $referenceUnit = $this->baseUnit($reference);
        $prepared = [];
        foreach ($rows as $index => $row) {
            $key = strtolower($row['output_rule_key']);
            if (isset($prepared[$key])) $this->fail('同一工序的产出规则标识不能重复。');
            $old = $previous->get($key);
            // The UUID follows one logical node across draft rebuilds and copied route versions.
            // Reusing a different node's UUID would silently change a frozen output-line association.
            if (! $old && RoutingOperationOutputRule::query()->where('output_rule_key', $key)->lockForUpdate()->exists()) {
                $this->fail('该产出规则标识已属于其他工序或路线，请为新规则使用新的标识。');
            }
            $item = $this->validItem((int) $row['item_id'], $row['output_role']);
            $unit = $this->baseUnit($item);
            if ($old && ((int) $old->item_id !== (int) $item->id
                || (int) $old->reference_item_id !== (int) $reference->id)) {
                $this->fail('已保存规则的产出或参考物料身份不能改变；请核实数量关系并建立新规则。');
            }
            if ($old && ((int) $old->base_unit_id !== (int) $unit->id
                || (int) $old->reference_base_unit_id !== (int) $referenceUnit->id)) {
                $this->fail('已保存规则的库存基本单位已改变，不能用新单位解释原数量关系。');
            }
            $facts = [
                'output_rule_key' => $key, 'line_no' => $index + 1,
                'item_id' => (int) $item->id, 'output_role' => $row['output_role'], 'base_unit_id' => (int) $unit->id,
                'item_code_snapshot' => $item->item_code, 'item_name_snapshot' => $item->item_name,
                'spec_snapshot' => $item->spec, 'base_unit_name_snapshot' => $unit->unit_name,
                'base_unit_decimal_places_snapshot' => $this->precision($unit),
                'reference_item_id' => (int) $reference->id, 'reference_base_unit_id' => (int) $referenceUnit->id,
                'reference_item_code_snapshot' => $reference->item_code, 'reference_item_name_snapshot' => $reference->item_name,
                'reference_base_unit_name_snapshot' => $referenceUnit->unit_name,
                'reference_base_unit_decimal_places_snapshot' => $this->precision($referenceUnit),
                'base_qty_per_reference_unit' => $this->ratio($row['base_qty_per_reference_unit']),
                'quality_mode' => $row['quality_mode'], 'output_mode' => $row['output_mode'],
                'allow_continue_without_warehouse' => (bool) $row['allow_continue_without_warehouse'],
                'remark' => $row['remark'] ?? null,
            ];
            if ($old) {
                // Label/precision changes must not relabel quantities already declared under this UUID.
                foreach (['item_code_snapshot', 'item_name_snapshot', 'spec_snapshot', 'base_unit_name_snapshot',
                    'base_unit_decimal_places_snapshot', 'reference_item_code_snapshot', 'reference_item_name_snapshot',
                    'reference_base_unit_name_snapshot', 'reference_base_unit_decimal_places_snapshot'] as $field) {
                    $facts[$field] = $old->{$field};
                }
            }
            $changed = $old && collect($facts)->contains(fn ($value, $field) => (string) $old->{$field} !== (string) $value);
            $prepared[$key] = $facts + [
                'business_version' => $old ? (int) $old->business_version + (int) $changed : 1,
                'created_by_legacy_id' => $old?->created_by_legacy_id,
                'created_at' => $old?->created_at,
            ];
        }
        return array_values($prepared);
    }

    public function inputRows(ProductionRoutingOperation $node): array
    {
        return $node->outputRules->map(fn ($rule) => $rule->only(self::INPUT_FIELDS))->values()->all();
    }

    public function persist(ProductionRoutingOperation $node, array $prepared, object $user): void
    {
        foreach ($prepared as $facts) {
            $facts['created_by_legacy_id'] ??= $this->actor($user);
            $facts['created_at'] ??= now();
            $node->outputRules()->create($facts + ['routing_id' => $node->routing_id, 'updated_by_legacy_id' => $this->actor($user)]);
        }
    }

    public function copy(ProductionRoutingOperation $source, ProductionRoutingOperation $target, object $user): void
    {
        foreach ($source->outputRules as $rule) {
            $target->outputRules()->create($rule->only(self::FROZEN_FIELDS) + [
                'routing_id' => $target->routing_id, 'created_by_legacy_id' => $this->actor($user),
                'updated_by_legacy_id' => $this->actor($user),
            ]);
        }
    }

    public function snapshot(ProductionRoutingOperation $node): array
    {
        return $node->outputRules->map(fn ($rule) => ['routing_operation_output_rule_id' => (int) $rule->id]
            + $rule->only(self::FROZEN_FIELDS))->values()->all();
    }

    public function auditSnapshot(ProductionRouting $routing): array
    {
        $routing->loadMissing('operations.outputRules');
        return $routing->operations->map(fn ($node) => [
            'routing_operation_id' => (int) $node->id, 'operation_id' => (int) $node->operation_id,
            'sequence' => (int) $node->sequence, 'output_rules' => $this->snapshot($node),
        ])->values()->all();
    }

    public function validateRouting(ProductionRouting $routing): void
    {
        $routing->loadMissing('operations.outputRules');
        foreach ($routing->operations as $node) {
            if (($node->execution_context ?: 'production') === 'shipment' && $node->outputRules->isNotEmpty()) {
                $this->fail('发货包装工序不能配置生产产出规则。');
            }
            foreach ($node->outputRules as $rule) {
                $item = $this->validItem((int) $rule->item_id, $rule->output_role);
                $reference = $this->validItem((int) $routing->output_item_id, 'product');
                if ((int) $rule->reference_item_id !== (int) $routing->output_item_id
                    || (int) $rule->reference_base_unit_id !== (int) $this->baseUnit($reference)->id
                    || (int) $rule->base_unit_id !== (int) $this->baseUnit($item)->id) {
                    $this->fail('产出规则的物料或库存基本单位与原数量关系不一致，请修订规则后再生效。');
                }
                $this->ratio($rule->base_qty_per_reference_unit);
            }
        }
    }

    private function validItem(int $id, string $role): Item
    {
        $item = Item::query()->lockForUpdate()->find($id);
        if ($item) app(ItemManagementScopeService::class)->assertProductionAllowed($item, 'operations');
        if (! $item || $item->status !== 'enabled' || ! $item->is_stock_item || $item->item_type === 'service'
            || ($role === 'product' && ! $item->is_production_item)) {
            $this->fail('产品须选择已启用、可生产且可库存的物料；副产品须为已启用的真实库存物料。');
        }
        return $item;
    }

    private function baseUnit(Item $item): Unit
    {
        $unit = Unit::query()->lockForUpdate()->find($item->unit_id);
        if (! $unit || $unit->status !== 'enabled') $this->fail('产出或参考物料缺少有效的库存基本单位。');
        if ($unit->is_legacy) {
            $unit = $unit->standard_unit_id ? Unit::query()->lockForUpdate()->find($unit->standard_unit_id) : null;
            if (! $unit || $unit->is_legacy || $unit->status !== 'enabled') $this->fail('产出或参考物料的库存基本单位映射无效。');
        }
        $this->precision($unit);
        return $unit;
    }

    private function precision(Unit $unit): int
    {
        $precision = (int) $unit->decimal_places;
        if ($precision < 0 || $precision > 8) $this->fail('库存基本单位的数量精度必须为0至8位。');
        return $precision;
    }

    private function ratio(mixed $value): string
    {
        // A fractional per-reference ratio is valid even for whole-piece stock units.
        // Only the computed WO total can be checked against the output unit precision.
        if ((! is_string($value) && ! is_int($value)) || ! preg_match('/^\d{1,20}(?:\.\d{1,8})?$/D', (string) $value)
            || bccomp((string) $value, '0', 8) <= 0) {
            $this->fail('每参考单位产出数量必须为明确的正十进制数，最多8位小数，不能使用浮点数或科学计数法。');
        }
        return bcadd((string) $value, '0', 8);
    }

    private function actor(object $user): ?int { return isset($user->legacy_id) ? (int) $user->legacy_id : null; }
    private function fail(string $message): never { throw ValidationException::withMessages(['operations' => $message]); }
}
