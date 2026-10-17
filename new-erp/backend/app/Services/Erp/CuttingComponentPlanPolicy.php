<?php

namespace App\Services\Erp;

/** Custom cut pieces are dimensioned BOM rows referring to raw material, never new Items. */
final class CuttingComponentPlanPolicy
{
    public static function isCutting(array $node): bool { return ($node['stage_code'] ?? null) === 'CUTTING'; }

    public static function resolve(array $node, array $bomLines, string $quantity, int $terminalId): array
    {
        $issues = []; $components = [];
        if (($node['output_rules'] ?? []) !== [] || ! empty($node['output_item_id'])) $issues[] = ['code' => 'cutting_component_item_not_allowed', 'message' => '下料件按原材料、尺寸和件数记录，请清空下料工序的独立产出物料及物料产出规则。'];
        if (($node['output_mode'] ?? 'flow_only') !== 'flow_only' || (int) ($node['routing_operation_id'] ?? 0) === $terminalId) $issues[] = ['code' => 'cutting_component_destination_invalid', 'message' => '下料组件必须交给同一工单的后续工序，不能作为最终成品入库。'];
        $supplies = collect($node['material_supply_rules'] ?? [])->keyBy('component_item_id');
        foreach ($bomLines as $line) {
            $calculation = $line['cutting_quantity_snapshot'] ?? null;
            $dimensions = $calculation['dimensions'] ?? null;
            $supply = $supplies->get((int) ($line['component_item_id'] ?? 0));
            if (! $supply || ! in_array($calculation['mode'] ?? null, ['length', 'sheet'], true)) continue;
            $pieces = $dimensions['piece_qty'] ?? null;
            $ratio = $supply['required_qty_ratio'] ?? null;
            if (FrozenCuttingStockPolicy::isTotalLength($calculation ?? [])) {
                $total = FrozenCuttingStockPolicy::totalLength($calculation);
                if (! $total || ! self::positive($quantity) || ! self::positive($ratio)) {
                    $issues[] = ['code' => 'cutting_component_requirement_missing', 'message' => '管材总长度明细必须冻结真实原材料、每份总长度和本工序用料比例。']; continue;
                }
                $requiredLength = bcmul(bcmul($total, $quantity, 16), (string) $ratio, 16);
                if (bccomp($requiredLength, '0', 16) <= 0 || bccomp($requiredLength, bcadd($requiredLength, '0', 5), 16) !== 0) {
                    $issues[] = ['code' => 'cutting_component_quantity_invalid', 'message' => '按工单数量和用料比例计算的总长度必须能精确记录为毫米五位小数或米八位小数。']; continue;
                }
                $components[] = ['bom_item_id' => (int) $line['id'], 'bom_line_no' => (int) ($line['line_no'] ?? 0),
                    'configuration_requirement_index' => $line['configuration_requirement_index'] ?? null,
                    'raw_item_id' => (int) $line['component_item_id'], 'raw_item_code' => $line['component_item_code'] ?? null,
                    'raw_item_name' => $line['component_item_name'] ?? null, 'cutting_mode' => 'length',
                    'quantity_basis' => 'total_length', 'quantity_unit' => 'm', 'dimensions' => $dimensions,
                    'required_total_length_mm' => bcadd($requiredLength, '0', 8),
                    'required_piece_qty' => null, 'per_output_piece_qty' => null,
                    'required_qty_ratio' => (string) $ratio, 'cutting_quantity_snapshot' => $calculation];
                continue;
            }
            if (! is_array($dimensions) || ! self::positive($pieces) || ! self::positive($quantity) || ! self::positive($ratio)) {
                $issues[] = ['code' => 'cutting_component_requirement_missing', 'message' => '每条下料明细必须冻结真实原材料、尺寸、整数件数和本工序用料比例。']; continue;
            }
            $required = bcmul(bcmul((string) $pieces, $quantity, 8), (string) $ratio, 8);
            if (bccomp($required, bcadd($required, '0', 0), 8) !== 0 || bccomp($required, '0', 8) <= 0) {
                $issues[] = ['code' => 'cutting_component_quantity_invalid', 'message' => '按工单数量和用料比例计算的下料件数必须是明确的正整数。']; continue;
            }
            $components[] = ['bom_item_id' => (int) $line['id'], 'bom_line_no' => (int) ($line['line_no'] ?? 0),
                'configuration_requirement_index' => $line['configuration_requirement_index'] ?? null,
                'raw_item_id' => (int) $line['component_item_id'],
                'raw_item_code' => $line['component_item_code'] ?? null, 'raw_item_name' => $line['component_item_name'] ?? null,
                'cutting_mode' => $calculation['mode'], 'dimensions' => $dimensions,
                'required_piece_qty' => bcadd($required, '0', 0), 'per_output_piece_qty' => (string) $pieces,
                'required_qty_ratio' => (string) $ratio, 'cutting_quantity_snapshot' => $calculation];
        }
        if ($components === []) $issues[] = ['code' => 'cutting_component_requirements_missing', 'message' => '下料工序没有属于本工序的有效原料及尺寸明细。'];
        return ['component_requirements' => $components, 'issues' => $issues];
    }

    private static function positive(mixed $value): bool
    {
        return is_scalar($value) && preg_match('/^\d+(?:\.\d{1,8})?$/D', (string) $value) && bccomp((string) $value, '0', 8) > 0;
    }
}
