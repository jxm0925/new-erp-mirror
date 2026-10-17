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
