<?php

namespace App\Services\Erp;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Material-only cost: never use selling prices or infer dimensions from free text. */
final class CuttingAutomaticCostService
{
    public const RULE = 'MATERIAL_SHARE_V1';

    public function allocate(object $batch, Collection $rows): array
    {
        $c = app(CuttingCommandService::class);
        $total = CuttingDecimal::value($batch->original_total_cost,4,true);
        $dimensions = []; $weights = []; $area = []; $length = [];
        foreach ($rows as $row) {
            $measure = $row->measurements ? json_decode($row->measurements,true,512,JSON_THROW_ON_ERROR) : [];
            $config = $row->configuration_id ? DB::table('erp_custom_configurations')->where('id',$row->configuration_id)->value('dimensions') : null;
            $frozen = $config ? json_decode($config,true,512,JSON_THROW_ON_ERROR) : [];
            $dimensions[$row->id] = ['measurements'=>$measure,'configuration_dimensions'=>$frozen];
            $qty = $row->actual_qty;
            if ($qty === null) $c->fail('automatic_cost_measurement_required','已登记的余料、废料或损耗缺少实测依据，不能自动按0核算。');
            if (bccomp((string) $qty,'0',8) === 0) { $weights[$row->id] = $area[$row->id] = $length[$row->id] = '0'; continue; }
            // A measured weight is the whole result line's weight; configuration
            // weight/dimensions describe one piece and must be multiplied by qty.
            if (isset($measure['weight_kg'])) $weights[$row->id] = CuttingDecimal::value($measure['weight_kg']);
            elseif (isset($frozen['weight_kg'])) $weights[$row->id] = bcmul(CuttingDecimal::value($frozen['weight_kg']), (string) $qty,8);
            elseif (in_array($row->result_type,['recyclable_scrap','process_loss'],true) && $row->measurement_status === 'MEASURED') $weights[$row->id] = (string) $qty;
            $size = $measure + $frozen;
            $pieceArea = isset($size['area_mm2']) ? CuttingDecimal::value($size['area_mm2'])
                : (isset($size['length_mm'],$size['width_mm']) ? bcmul(CuttingDecimal::value($size['length_mm']),CuttingDecimal::value($size['width_mm']),8) : null);
            if ($pieceArea !== null) $area[$row->id] = bcmul($pieceArea,(string) $qty,8);
            $pieceLength = $row->cut_length_mm ?? $size['length_mm'] ?? null;
            if ($pieceLength !== null) $length[$row->id] = bcmul(CuttingDecimal::value($pieceLength),(string) ($row->piece_qty ?? $qty),8);
        }
        $basis = null; $shares = [];
        if (count($weights) === $rows->count()) { $basis = 'WEIGHT_KG'; $shares = $weights; }
        elseif ($batch->physical_material_id && count($area) === $rows->count()) { $basis = 'SHEET_AREA_MM2'; $shares = $area; }
        elseif (! $batch->physical_material_id && count($length) === $rows->count()) { $basis = 'LENGTH_MM'; $shares = $length; }
        // Scrap and process loss are weighed in the worker UI, while sheet
        // products/remnants are measured by area and bars by cut length.  They
        // are therefore not directly comparable.  Treat the source remainder
        // as the material consumed by those explicitly reported loss rows and
        // use their measured weights only to split that remainder between them.
        // This keeps the actual allocation basis in area/length and never falls
        // back to piece count.
        if (! $basis) {
            $lossRows = $rows->filter(fn ($row) => in_array($row->result_type, ['recyclable_scrap','process_loss'], true));
            $lossIds = $lossRows->pluck('id')->map(fn ($id) => (int) $id)->all();
            $otherIds = $rows->reject(fn ($row) => in_array((int) $row->id, $lossIds, true))->pluck('id')->map(fn ($id) => (int) $id)->all();
            $lossWeights = array_intersect_key($weights, array_flip($lossIds));
            if ($lossRows->isNotEmpty() && count($lossWeights) === $lossRows->count()) {
                if ($batch->physical_material_id && count(array_intersect_key($area, array_flip($otherIds))) === count($otherIds)) {
                    $source = DB::table('erp_material_physicals')->where('id',$batch->physical_material_id)->first();
                    $sourceSize = $source ? json_decode($source->dimensions,true,512,JSON_THROW_ON_ERROR) : [];
                    $sourceTotal = $sourceSize['area_mm2'] ?? (isset($sourceSize['length_mm'],$sourceSize['width_mm'])
                        ? bcmul((string) $sourceSize['length_mm'],(string) $sourceSize['width_mm'],8) : null);
                    if ($sourceTotal !== null) {
                        $shares = $this->withResidual($area, $lossIds, $lossWeights, (string) $sourceTotal, $c);
                        $basis = 'SHEET_AREA_MM2';
                    }
                } elseif (! $batch->physical_material_id && count(array_intersect_key($length, array_flip($otherIds))) === count($otherIds)) {
                    $sourceTotal = bcmul((string) $batch->standard_stock_length_mm,(string) $batch->input_qty,8);
                    $shares = $this->withResidual($length, $lossIds, $lossWeights, $sourceTotal, $c);
                    $basis = 'LENGTH_MM';
                }
            }
        }
        // A sole product owns all source cost, but known dimensions above still
        // take precedence so excess material cannot hide behind this fallback.
        if (! $basis && $rows->count() === 1 && $rows->first()->result_type === 'product')
            return ['rule'=>self::RULE,'basis'=>'SOLE_PRODUCT','weights'=>[], 'costs'=>[(int) $rows->first()->id=>$total]];
        if (! $basis) $c->fail('automatic_cost_basis_missing','无法自动分摊：请补全各产出的配置尺寸或实测重量；钢板使用面积，方管使用切段长度，不按件数均摊。');
        $sum = '0'; foreach ($shares as $share) $sum = bcadd($sum,$share,8);
        if (bccomp($sum,'0',8) <= 0) $c->fail('automatic_cost_basis_zero','自动分摊的用料依据合计必须大于0。');
        if ($basis === 'SHEET_AREA_MM2') {
            $source = DB::table('erp_material_physicals')->where('id',$batch->physical_material_id)->first();
            $size = json_decode($source->dimensions,true,512,JSON_THROW_ON_ERROR);
            foreach ($dimensions as $resultSize) {
                $thickness = $resultSize['measurements']['thickness_mm'] ?? $resultSize['configuration_dimensions']['thickness_mm'] ?? null;
                if ($thickness !== null && isset($size['thickness_mm']) && bccomp((string) $thickness,(string) $size['thickness_mm'],8) !== 0)
                    $c->fail('automatic_cost_thickness_mismatch','产出厚度与来源钢板不一致，不能按同板面积核算。');
            }
            $sourceArea = $size['area_mm2'] ?? (isset($size['length_mm'],$size['width_mm']) ? bcmul((string) $size['length_mm'],(string) $size['width_mm'],8) : null);
            if ($sourceArea === null || bccomp($sum,(string) $sourceArea,8) > 0) $c->fail('automatic_cost_material_exceeded','产出与余料面积超过实际投入，不能核算。');
        } elseif ($basis === 'LENGTH_MM' && bccomp($sum,bcmul((string) $batch->standard_stock_length_mm,(string) $batch->input_qty,8),8) > 0)
            $c->fail('automatic_cost_material_exceeded','产出与余料长度超过实际投入，不能核算。');
        // Unreported kerf/processing consumption stays in the material cost of
        // recorded results. Explicit measured loss rows participate separately.
        // Decimal residual allocation ensures the final positive row owns all tail.
        $remaining = $total; $left = $sum; $costs = [];
        foreach ($shares as $id => $share) {
            $cost = bccomp($share,'0',8) === 0 ? '0.0000' : CuttingDecimal::share($remaining,$left,$share);
            $costs[$id] = $cost; $remaining = bcsub($remaining,$cost,4); $left = bcsub($left,$share,8);
        }
        return ['rule'=>self::RULE,'basis'=>$basis,'weights'=>$shares,'dimensions'=>$dimensions,'costs'=>$costs];
    }

    private function withResidual(array $known, array $lossIds, array $lossWeights, string $sourceTotal, CuttingCommandService $c): array
    {
        foreach ($lossIds as $id) unset($known[$id]);
        $used = '0'; foreach ($known as $share) $used = bcadd($used,$share,8);
        if (bccomp($used,$sourceTotal,8) > 0) $c->fail('automatic_cost_material_exceeded','产出与余料用料超过实际投入，不能核算。');
        $residual = bcsub($sourceTotal,$used,8);
        $weightTotal = '0'; foreach ($lossWeights as $weight) $weightTotal = bcadd($weightTotal,$weight,8);
        if (bccomp($residual,'0',8) > 0 && bccomp($weightTotal,'0',8) <= 0)
            $c->fail('automatic_cost_basis_missing','已登记损耗但缺少实测重量，无法按剩余用料拆分。');
        $remaining = $residual; $left = $weightTotal;
        foreach ($lossIds as $id) {
            $weight = $lossWeights[$id] ?? '0';
            $share = bccomp($weight,'0',8) === 0 ? '0' : (bccomp($left,$weight,8) === 0 ? $remaining : bcdiv(bcmul($remaining,$weight,8),$left,8));
            $known[$id] = $share; $remaining = bcsub($remaining,$share,8); $left = bcsub($left,$weight,8);
        }
        return $known;
    }
}
