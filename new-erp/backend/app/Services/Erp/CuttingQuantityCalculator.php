<?php

namespace App\Services\Erp;

use App\Models\Erp\Item;
use App\Models\Erp\Unit;
use Illuminate\Validation\ValidationException;

/** BOM 几何耗用只表达理论份额；实际整根/整板由实体库存领料，不在这里取整。 */
final class CuttingQuantityCalculator
{
    public function calculate(Item $item, Unit $unit, array $line, ?array $stock = null, string $path = 'items'): array
    {
        $mode = $item->cuttingMode();
        if (! in_array($mode, ['length', 'sheet'], true)) $this->fail($path, '当前物料不是长度或板材下料物料。');
        $basis = $line['cutting_quantity_basis'] ?? $line['cutting_quantity_snapshot']['quantity_basis'] ?? 'dimensions_pieces';
        if (! in_array($basis, ['dimensions_pieces', 'total_length'], true) || ($basis === 'total_length' && $mode !== 'length')) {
            $this->fail($path, '总长度用量只适用于管材；板材仍须维护单件尺寸和件数。');
        }
        if ($basis === 'dimensions_pieces' && isset($line['total_length_mm'])) $this->fail($path, '按单件尺寸和件数登记时不能同时填写总长度。');
        $totalLength = $basis === 'total_length'
            ? $this->dimension($line['total_length_mm'] ?? $line['cutting_quantity_snapshot']['total_length_mm'] ?? null, "$path.total_length_mm") : null;
        if ($totalLength !== null && (isset($line['cut_length_mm']) || isset($line['piece_qty']) || isset($line['cut_width_mm'])
            || isset($line['cut_thickness_mm']) || ! empty($line['allow_cut_rotation']))) {
            $this->fail($path, '按总长度登记时不能同时填写单段尺寸或件数。');
        }
        $length = $totalLength === null ? $this->dimension($line['cut_length_mm'] ?? null, "$path.cut_length_mm") : null;
        $pieces = $totalLength === null ? $this->pieces($line['piece_qty'] ?? null, "$path.piece_qty") : null;
        $width = $mode === 'sheet' ? $this->dimension($line['cut_width_mm'] ?? null, "$path.cut_width_mm") : null;
        $thickness = $mode === 'sheet' ? $this->dimension($line['cut_thickness_mm'] ?? null, "$path.cut_thickness_mm") : null;
        if ($mode === 'length' && (isset($line['cut_width_mm']) || isset($line['cut_thickness_mm']) || ! empty($line['allow_cut_rotation']))) {
            $this->fail($path, '只有板材下料可以填写宽度、厚度和旋转要求。');
        }
        $unitKind = $this->unitKind($unit, $path);
        if (($mode === 'length' && ! in_array($unitKind, ['m', 'root'], true))
            || ($mode === 'sheet' && ! in_array($unitKind, ['m2', 'sheet'], true))) {
            $this->fail("$path.unit_id", '下料方式与库存单位不一致，请维护长度单位米/根或面积单位平方米/张。');
        }

        $stockDimensions = (array) ($stock['dimensions'] ?? []);
        $stockSource = $stock['source'] ?? null;
        if ($mode === 'length' && ! isset($stockDimensions['length_mm']) && (float) $item->standard_stock_length_mm > 0) {
            $stockDimensions['length_mm'] = $item->standard_stock_length_mm;
            $stockSource = ['type' => 'item_standard_length', 'item_id' => (int) $item->id];
        }
        $stockLength = isset($stockDimensions['length_mm']) ? $this->dimension($stockDimensions['length_mm'], "$path.stock_dimensions.length_mm") : null;
        $stockWidth = $mode === 'sheet' && isset($stockDimensions['width_mm']) ? $this->dimension($stockDimensions['width_mm'], "$path.stock_dimensions.width_mm") : null;
        if ($unitKind === 'root' && $stockLength === null) $this->fail("$path.stock_dimensions", '按根计算理论耗用必须明确标准原料长度。');
        if ($unitKind === 'sheet' && ($stockLength === null || $stockWidth === null)) $this->fail("$path.stock_dimensions", '按张计算理论耗用必须明确标准原料长、宽，不能使用本次裁切尺寸代替。');
        if ($mode === 'length' && $totalLength === null && $stockLength !== null && bccomp($length, $stockLength, 2) > 0) {
            $this->fail("$path.cut_length_mm", '下料长度不能超过所选标准原料长度。');
        }
        if ($mode === 'sheet' && $stockLength !== null && $stockWidth !== null) {
            $fits = bccomp($length, $stockLength, 2) <= 0 && bccomp($width, $stockWidth, 2) <= 0;
            $rotated = ! empty($line['allow_cut_rotation']) && bccomp($length, $stockWidth, 2) <= 0 && bccomp($width, $stockLength, 2) <= 0;
            if (! $fits && ! $rotated) $this->fail($path, '单件裁切长宽超过所选标准原料尺寸。');
        }
        $stockThickness = $stockDimensions['nominal_thickness_mm'] ?? $stockDimensions['thickness_mm'] ?? null;
        if ($mode === 'sheet' && $stockThickness !== null && bccomp($thickness, $this->dimension($stockThickness, "$path.stock_dimensions.thickness_mm"), 2) !== 0) {
            $this->fail("$path.cut_thickness_mm", '裁切要求厚度与所选原料规格厚度不一致。');
        }

        $geometry = $totalLength ?? bcmul($length, $pieces, 12);
        if ($mode === 'sheet') $geometry = bcmul($geometry, $width, 12);
        $denominator = match ($unitKind) {
            'm' => '1000', 'm2' => '1000000', 'root' => $stockLength,
            'sheet' => bcmul($stockLength, $stockWidth, 12),
        };
        $theoretical = $this->round(bcdiv($geometry, $denominator, 12), 8);
        if (bccomp($theoretical, '0', 8) <= 0) $this->fail($path, '理论下料耗用低于可记录精度，请核对尺寸和件数。');
        $stored = $this->round($theoretical, 4);
        if (bccomp($stored, '0', 4) <= 0 || bccomp($stored, '9999999999.9999', 4) > 0) {
            $this->fail($path, '理论下料耗用超出 BOM 用量四位小数的可记录范围，请调整业务计量单位后再保存。');
        }

        return ($totalLength === null ? [] : ['quantity_basis' => 'total_length', 'total_length_mm' => $totalLength]) + [
            'version' => $totalLength === null ? 1 : 2, 'mode' => $mode, 'unit_kind' => $unitKind,
            'unit' => ['id' => (int) $unit->id, 'code' => $unit->unit_code, 'name' => $unit->unit_name],
            'dimensions' => ['length_mm' => $length, 'width_mm' => $width, 'thickness_mm' => $thickness,
                'piece_qty' => $pieces === null ? null : (int) $pieces, 'allow_rotation' => (bool) ($line['allow_cut_rotation'] ?? false)],
            'stock_dimensions' => $stockDimensions ? array_filter(array_replace($stockDimensions, ['length_mm' => $stockLength,
                'width_mm' => $stockWidth]), fn ($v) => $v !== null) : null,
            'stock_source' => $stockSource,
            'theoretical_qty' => $theoretical,
            // Existing BOM qty is DECIMAL(14,4). The snapshot retains the 8-place
            // result so release calculations do not multiply the storage rounding.
            'stored_qty' => $stored,
        ];
    }

    public function unitKind(Unit $unit, string $path = 'items'): string
    {
        $code = strtoupper(trim((string) $unit->unit_code));
        $symbol = strtoupper(str_replace(['²', '㎡', '^'], ['2', 'M2', ''], trim((string) ($unit->symbol ?: $unit->unit_symbol))));
        $kind = match (true) {
            $code === 'M' || $symbol === 'M' => 'm',
            in_array($code, ['M2', 'SQM'], true) || $symbol === 'M2' => 'm2',
            $code === 'ROOT' || $symbol === '根' => 'root',
            $code === 'SHEET' || $symbol === '张' => 'sheet',
            default => null,
        };
        if ($kind === null) $this->fail("$path.unit_id", '下料库存单位仅支持已明确维护的米、平方米、根或张，不能默认换算。');
        return $kind;
    }

    public function round(string $value, int $scale): string
    {
        return bcadd($value, '0.'.str_repeat('0', $scale).'5', $scale);
    }

    private function dimension(mixed $value, string $path): string
    {
        $text = is_float($value) && is_finite($value) ? rtrim(rtrim(sprintf('%.10F', $value), '0'), '.') : (is_scalar($value) ? trim((string) $value) : '');
        if (! preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $text) || bccomp($text, '0', 2) <= 0) {
            $this->fail($path, '下料和标准原料尺寸必须大于 0，且最多保留两位小数。');
        }
        return bcadd($text, '0', 2);
    }

    private function pieces(mixed $value, string $path): string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';
        if (! preg_match('/^[1-9]\d{0,8}$/D', $text)) $this->fail($path, '下料件数必须为正整数。');
        return $text;
    }

    private function fail(string $path, string $message): never
    {
        throw ValidationException::withMessages([$path => $message]);
    }
}
