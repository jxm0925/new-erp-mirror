<?php

namespace App\Services\Erp;

use App\Models\Erp\Item;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Match structured BOM requirements against one actual input, never free-text specs. */
final class CuttingOutputEligibilityService
{
    public function __construct(private readonly CuttingCommandService $commands) {}

    public function validateBomLine(Item $item, array $line): void
    {
        $mode = $item->cuttingMode();
        if ($mode === 'sheet') {
            foreach (['cut_length_mm', 'cut_width_mm', 'cut_thickness_mm', 'piece_qty'] as $field) {
                if (! isset($line[$field]) || bccomp((string) $line[$field], '0', 2) <= 0) {
                    throw ValidationException::withMessages(['items' => "板材 {$item->item_name} 必须维护单件长、宽、厚和每份产出的件数。"]);
                }
            }
        } elseif (isset($line['cut_width_mm']) || isset($line['cut_thickness_mm']) || ! empty($line['allow_cut_rotation'])) {
            throw ValidationException::withMessages(['items' => '只有板材下料可以填写板件宽度、厚度和旋转要求。']);
        }
    }

    public function source(object $batch): array
    {
        if ($batch->physical_material_id) {
            $physical = DB::table('erp_material_physicals')->where('id', $batch->physical_material_id)->first();
            if (! $physical || (int) $physical->item_id !== (int) $batch->input_item_id) {
                $this->commands->fail('input_physical_conflict', '当前板材实物与实际投入不一致。', 409);
            }
            $size = json_decode($physical->dimensions, true, 512, JSON_THROW_ON_ERROR);
            return ['mode' => 'sheet', 'item_id' => (int) $batch->input_item_id,
                'physical_material_id' => (int) $physical->id, 'shape' => $physical->shape,
                'length_mm' => $size['length_mm'] ?? null, 'width_mm' => $size['width_mm'] ?? null,
                // Older identities contain only measured thickness. Exact equality is
                // the only safe fallback; no rounding to a guessed commercial gauge.
                'thickness_mm' => $size['nominal_thickness_mm'] ?? $size['thickness_mm'] ?? null,
                'actual_thickness_mm' => $size['thickness_mm'] ?? null];
        }
        return ['mode' => 'length', 'item_id' => (int) $batch->input_item_id,
            'length_mm' => $batch->standard_stock_length_mm, 'input_qty' => $batch->input_qty];
    }

    /** The caller owns Item/configuration visibility; aliases i and c must exist. */
    public function constrain(Builder $query, object $batch): Builder
    {
        $source = $this->source($batch);
        $query->join('erp_boms as cutting_bom', 'cutting_bom.output_item_id', '=', 'i.id')
            ->join('erp_bom_items as cutting_component', 'cutting_component.bom_id', '=', 'cutting_bom.id')
            ->where('cutting_component.component_item_id', $batch->input_item_id)
            ->where('cutting_bom.status', 'active')->where('cutting_bom.audit_status', 'approved')
            ->where(fn (Builder $q) => $q->whereNull('cutting_bom.effective_date')->orWhere('cutting_bom.effective_date', '<=', now()->toDateString()))
            ->where(fn (Builder $q) => $q->whereNull('cutting_bom.expire_date')->orWhere('cutting_bom.expire_date', '>=', now()->toDateString()))
            ->where('cutting_component.piece_qty', '>', 0);
        $length = $this->dimensionSql('length');
        $query->whereRaw("{$length} > 0");
        if ($source['mode'] === 'sheet') {
            $width = $this->dimensionSql('width'); $thickness = $this->dimensionSql('thickness');
            $query->whereRaw("{$width} > 0")->whereRaw("{$thickness} = ?", [$source['thickness_mm']])
                ->where(function (Builder $q) use ($source, $length, $width): void {
                    $q->where(fn (Builder $normal) => $normal->whereRaw("{$length} <= ?", [$source['length_mm']])
                        ->whereRaw("{$width} <= ?", [$source['width_mm']]))
                        ->orWhere(fn (Builder $rotated) => $rotated->where('cutting_component.allow_cut_rotation', true)
                            ->whereRaw("{$length} <= ?", [$source['width_mm']])->whereRaw("{$width} <= ?", [$source['length_mm']]));
                });
        } else {
            $query->whereRaw("{$length} <= ?", [$source['length_mm']]);
        }
        return $query;
    }

    public function columns(): array
    {
        return ['cutting_bom.id as bom_id', 'cutting_bom.version as bom_version', 'cutting_bom.bom_no',
            'cutting_component.id as bom_item_id', 'cutting_component.piece_qty as per_output_piece_qty',
            'cutting_component.allow_cut_rotation',
            DB::raw($this->dimensionSql('length').' AS required_length_mm'),
            DB::raw($this->dimensionSql('width').' AS required_width_mm'),
            DB::raw($this->dimensionSql('thickness').' AS required_thickness_mm')];
    }

    public function match(object $batch, int $itemId, ?int $configurationId, ?int $bomItemId): array
    {
        $q = DB::table('erp_items as i')->leftJoin('erp_custom_configurations as c', fn ($join) =>
            $join->on('c.item_id', '=', 'i.id')->where('c.id', '=', $configurationId ?? 0));
        $this->constrain($q, $batch)->where('i.id', $itemId);
        if ($bomItemId) $q->where('cutting_component.id', $bomItemId);
        $rows = $q->select($this->columns())->orderBy('cutting_bom.id')->orderBy('cutting_component.id')->limit(2)->lockForUpdate()->get();
        if ($rows->isEmpty()) $this->commands->fail('output_input_mismatch', '所选产出的有效已审核 BOM 用料、厚度或加工尺寸不符合当前实际材料。');
        if ($rows->count() > 1) $this->commands->fail('output_requirement_ambiguous', '该产出有多条下料要求，请重新选择明确的规格及 BOM 版本。');
        return (array) $rows->first() + ['input' => $this->source($batch), 'item_id' => $itemId, 'configuration_id' => $configurationId];
    }

    public function assertMeasurements(array $requirement, array $measurements, mixed $length, mixed $pieces, string $quantity): void
    {
        $c = $this->commands; $mode = $requirement['input']['mode'];
        $actualLength = $mode === 'length' ? ($length ?? $measurements['length_mm'] ?? null) : ($measurements['length_mm'] ?? null);
        if ($length !== null && isset($measurements['length_mm']) && bccomp(CuttingDecimal::value($length), (string) $measurements['length_mm'], 8) !== 0) {
            $c->fail('output_length_mismatch', '产出长度字段必须与实际尺寸一致。');
        }
        if ($actualLength === null || bccomp(CuttingDecimal::value($actualLength), (string) $requirement['required_length_mm'], 8) !== 0) {
            $c->fail('output_length_mismatch', '产出长度必须符合所选 BOM 或已发布配置要求，填写重量不能代替尺寸匹配。');
        }
        if ($mode === 'sheet') {
            if (! isset($measurements['width_mm']) || bccomp(CuttingDecimal::value($measurements['width_mm']), (string) $requirement['required_width_mm'], 8) !== 0) {
                $c->fail('output_width_mismatch', '产出宽度必须符合所选 BOM 或已发布配置要求。');
            }
            // Actual thickness is inherited from the source, not editable into a
            // different gauge. Nominal thickness was matched in constrain().
            if (isset($measurements['thickness_mm']) && bccomp((string) $measurements['thickness_mm'], (string) $requirement['input']['actual_thickness_mm'], 8) !== 0) {
                $c->fail('output_thickness_mismatch', '产出实际厚度必须与来源板材一致。');
            }
        }
        $expectedPieces = bcmul($quantity, (string) $requirement['per_output_piece_qty'], 8);
        if (bccomp($expectedPieces, bcadd($expectedPieces, '0', 0), 8) !== 0) $c->fail('piece_quantity_invalid', '下料件数必须是整数。');
        if ($pieces !== null && bccomp(CuttingDecimal::value($pieces), $expectedPieces, 8) !== 0) $c->fail('piece_quantity_mismatch', '下料件数与产出数量及 BOM 每份件数不一致。');
    }

    /** Geometry limits are independent of the chosen cost basis (including weight). */
    public function assertBatchFits(object $batch, Collection $rows): void
    {
        $source = $this->source($batch); $used = '0';
        foreach ($rows as $row) {
            $m = $row->measurements ? json_decode($row->measurements, true, 512, JSON_THROW_ON_ERROR) : [];
            if (in_array($row->result_type, ['recyclable_scrap', 'process_loss'], true)) continue;
            $length = $source['mode'] === 'sheet' || $row->result_type !== 'product'
                ? ($m['length_mm'] ?? null) : ($row->cut_length_mm ?? $m['length_mm'] ?? null);
            $qty = (string) ($row->result_type === 'product' ? ($row->piece_qty ?? $row->actual_qty ?? '0') : ($row->actual_qty ?? '0'));
            if ($source['mode'] === 'length') {
                if ($length !== null) {
                    if (bccomp((string) $length, (string) $source['length_mm'], 8) > 0) $this->commands->fail('output_too_large', '单段长度超过实际投入材料。');
                    $used = bcadd($used, bcmul((string) $length, $qty, 8), 8);
                }
                continue;
            }
            if (isset($m['thickness_mm']) && bccomp((string) $m['thickness_mm'], (string) $source['actual_thickness_mm'], 8) !== 0) {
                $this->commands->fail('remnant_thickness_mismatch', '余料或产出的实际厚度不能改变来源板材厚度。');
            }
            $width = $m['width_mm'] ?? null;
            if ($length !== null && $width !== null && $source['length_mm'] !== null && $source['width_mm'] !== null) {
                $normal = bccomp((string) $length, (string) $source['length_mm'], 8) <= 0 && bccomp((string) $width, (string) $source['width_mm'], 8) <= 0;
                $rotated = bccomp((string) $width, (string) $source['length_mm'], 8) <= 0 && bccomp((string) $length, (string) $source['width_mm'], 8) <= 0;
                if (! $normal && ! $rotated) $this->commands->fail('output_too_large', '产出或余料的单件尺寸超过来源板材。');
                // An irregular outline's bounding rectangle is not its usable area.
                if ($row->result_type === 'product' || ($m['shape'] ?? 'RECTANGLE') === 'RECTANGLE') $used = bcadd($used, bcmul(bcmul((string) $length, (string) $width, 8), $qty, 8), 8);
            }
        }
        $available = $source['mode'] === 'length' ? bcmul((string) $source['length_mm'], (string) $batch->input_qty, 8)
            : ($source['length_mm'] !== null && $source['width_mm'] !== null ? bcmul((string) $source['length_mm'], (string) $source['width_mm'], 8) : null);
        if ($available !== null && bccomp($used, $available, 8) > 0) $this->commands->fail('cutting_material_exceeded', '产出与余料合计超过实际投入材料，请检查数量和尺寸。');
    }

    private function dimensionSql(string $axis): string
    {
        // Only internal axis names are interpolated. Values always use bindings.
        return "CAST(COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(c.dimensions, '$.{$axis}_mm')), 'null'), cutting_component.cut_{$axis}_mm) AS DECIMAL(12,2))";
    }
}
