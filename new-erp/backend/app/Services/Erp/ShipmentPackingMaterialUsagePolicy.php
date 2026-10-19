<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\Unit;

/** Actual packing consumption keeps warehouse quantities and measured-use notes separate. */
final class ShipmentPackingMaterialUsagePolicy
{
    public function snapshot(array $row): ?array
    {
        $dimensions = $row['usage_dimensions'] ?? [];
        if (! is_array($dimensions) || array_diff(array_keys($dimensions), ['length_mm', 'width_mm', 'thickness_mm']) !== []) {
            $this->fail('实际用料尺寸仅支持明确的长、宽、厚毫米字段。');
        }
        $normalized = [];
        foreach ($dimensions as $axis => $value) {
            if ($value === null || $value === '') continue;
            $text = is_float($value) && is_finite($value) ? rtrim(rtrim(sprintf('%.10F', $value), '0'), '.') : (is_string($value) || is_int($value) ? trim((string) $value) : '');
            if (! preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $text) || bccomp($text, '0', 2) <= 0) {
                $this->fail('实际用料尺寸必须大于0，且最多保留两位小数；未知尺寸请留空。');
            }
            $normalized[$axis] = bcadd($text, '0', 2);
        }
        $remark = $row['usage_remark'] ?? null;
        if ($remark !== null && ! is_string($remark)) $this->fail('实际用料备注必须是文字。');
        $remark = trim((string) $remark);
        if (mb_strlen($remark) > 500) $this->fail('实际用料备注最多500字。');
        // Recording the cut/use shape does not resize or split a warehouse
        // physical identity, compute stock usage, or create a remnant fact.
        return $normalized === [] && $remark === '' ? null : ['dimensions' => $normalized ?: null, 'remark' => $remark ?: null];
    }

    /**
     * 木板按面积扣库存：所有裁切块面积之和 ÷ 整张板面积 = 耗用张数，成本仍按所选批次单价过账。
     * 余板“回库”只扣用掉的面积（按库存单位精度向上取），余下的留在原批次；“损耗”按开用的整张扣，余板记为损耗面积。
     */
    public function areaUsage(array $row, string $quantity, ?Unit $unit): ?array
    {
        $area = $row['area_usage'] ?? null;
        if ($area === null) return null;
        if (! is_array($area) || array_diff(array_keys($area), ['board_length_mm', 'board_width_mm', 'pieces', 'remnant']) !== [])
            $this->fail('按面积用料只支持整板长宽、裁切块和余板处理方式。');
        if (! empty($row['physical_material_ids'])) $this->fail('实物板料按整张领用，不能再按面积扣减。');
        if (! in_array($area['remnant'] ?? null, ['return', 'loss'], true)) $this->fail('请选择余板回库或算损耗。');
        $boardLength = $this->positive($area['board_length_mm'] ?? null, '整板长度');
        $boardWidth = $this->positive($area['board_width_mm'] ?? null, '整板宽度');
        $pieces = $area['pieces'] ?? null;
        if (! is_array($pieces) || ! array_is_list($pieces) || $pieces === [] || count($pieces) > 50) $this->fail('请登记1至50条裁切块。');
        $used = '0'; $normalized = [];
        foreach ($pieces as $piece) {
            if (! is_array($piece)) $this->fail('裁切块格式不正确。');
            $length = $this->positive($piece['length_mm'] ?? null, '裁切块长度');
            $width = $this->positive($piece['width_mm'] ?? null, '裁切块宽度');
            $count = filter_var($piece['qty'] ?? null, FILTER_VALIDATE_INT);
            if (! $count || $count < 1 || $count > 10000) $this->fail('裁切块数量必须是1至10000的整数。');
            $used = bcadd($used, bcmul(bcmul($length, $width, 4), (string) $count, 4), 4);
            $normalized[] = ['length_mm' => $length, 'width_mm' => $width, 'qty' => $count];
        }
        $board = bcmul($boardLength, $boardWidth, 4);
        $boards = bcdiv($used, $board, 8);
        $places = $unit && $unit->allow_decimal ? (int) $unit->decimal_places : 0;
        $expected = $area['remnant'] === 'loss' ? $this->ceil($boards, 0) : $this->ceil($boards, $places);
        if (bccomp($quantity, $expected, 8) !== 0)
            $this->fail('按面积计算本次应耗用 '.rtrim(rtrim($expected, '0'), '.').'（'.($area['remnant'] === 'loss' ? '余板算损耗，按整张扣' : '余板回库，按面积扣').'），请核对实际耗用数量。');
        $consumedArea = bcmul($expected, $board, 4);
        return ['board_length_mm' => $boardLength, 'board_width_mm' => $boardWidth, 'pieces' => $normalized, 'remnant' => $area['remnant'],
            'used_area_m2' => bcdiv($used, '1000000', 4), 'consumed_boards' => $expected,
            'loss_area_m2' => $area['remnant'] === 'loss' ? bcdiv(bcsub($consumedArea, $used, 4), '1000000', 4) : '0.0000'];
    }

    private function positive(mixed $value, string $label): string
    {
        $text = is_int($value) || is_float($value) || is_string($value) ? trim((string) $value) : '';
        if (! preg_match('/^\d{1,6}(?:\.\d{1,2})?$/D', $text) || bccomp($text, '0', 2) <= 0) $this->fail($label.'必须大于0（毫米，最多两位小数）。');
        return bcadd($text, '0', 2);
    }

    private function ceil(string $value, int $places): string
    {
        $rounded = bcadd($value, '0', $places);
        if (bccomp($rounded, $value, 8) < 0) $rounded = bcadd($rounded, $places > 0 ? '0.'.str_repeat('0', $places - 1).'1' : '1', $places);
        return bcadd($rounded, '0', 8);
    }

    public function assertQuantity(string $quantity, string $available, ?Unit $unit): void
    {
        if (bccomp($quantity, '0', 8) <= 0 || bccomp($quantity, $available, 8) > 0) $this->fail('实际包装耗用必须大于0且不能超过所选库存来源的可用数量。');
        if (! $unit || $unit->status !== 'enabled' || ! is_numeric($unit->decimal_places)
            || (int) $unit->decimal_places < 0 || (int) $unit->decimal_places > 8) $this->fail('实际包装耗用必须使用已维护计量精度的启用库存单位。');
        $places = $unit->allow_decimal ? (int) $unit->decimal_places : 0;
        if (bccomp($quantity, bcadd($quantity, '0', $places), 8) !== 0) {
            $this->fail("库存单位 {$unit->unit_name} 的实际耗用最多保留{$places}位小数，不能用理论份额代替实物耗用。");
        }
    }

    private function fail(string $message): never
    {
        throw new WorkOrderDomainException('packing_material_usage_invalid', $message, 422);
    }
}
