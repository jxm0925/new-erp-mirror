<?php

namespace App\Services\Erp;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Suggestions are calculated facts; only an explicit review creates measured remnants. */
final class CuttingRemnantBalanceService
{
    private function rows(int $batchId): Collection
    {
        return DB::table('erp_cutting_results')->where('settlement_batch_id', $batchId)
            ->whereNotIn('status', ['VOIDED', 'SUPERSEDED', 'REVERSED'])->orderBy('id')->get();
    }

    private function json(mixed $value): array
    {
        return is_array($value) ? $value : ($value ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : []);
    }

    private function facts(Collection $rows, bool $includeRemnants): array
    {
        $facts = [];
        foreach ($rows as $row) {
            $row = (object) $row;
            if (! $includeRemnants && ($row->result_type ?? null) === 'usable_remnant') continue;
            $fact = ['client_row_id' => $row->client_row_id ?? null, 'result_type' => $row->result_type,
                'allowed_output_id' => isset($row->allowed_output_id) ? (int) $row->allowed_output_id : null,
                'actual_qty' => isset($row->actual_qty) ? bcadd((string) $row->actual_qty, '0', 8) : null,
                'measurements' => $this->json($row->measurements ?? null)];
            if ($includeRemnants) $fact += ['piece_qty' => isset($row->piece_qty) ? bcadd((string) $row->piece_qty, '0', 8) : null,
                'cut_length_mm' => isset($row->cut_length_mm) ? bcadd((string) $row->cut_length_mm, '0', 8) : null,
                'cutting_requirement_snapshot' => $this->json($row->cutting_requirement_snapshot ?? null), 'measurement_status' => $row->measurement_status ?? null];
            $facts[] = $fact;
        }
        usort($facts, fn ($a, $b) => (string) $a['client_row_id'] <=> (string) $b['client_row_id']);
        return $facts;
    }

    private function sources(object $batch): array
    {
        $input = DB::table('erp_production_cutting_inputs')->where('settlement_batch_id', $batch->id)->first();
        $snapshot = $this->json($input?->requirement_snapshot);
        $sources = $snapshot['sources'] ?? [];
        if (! $sources) {
            $actual = app(CuttingOutputEligibilityService::class)->source($batch);
            if ($batch->physical_material_id) $sources = [['source_key' => 'physical:'.$batch->physical_material_id,
                'physical_material_id' => (int) $batch->physical_material_id, 'dimensions' => ['shape' => $actual['shape'],
                    'length_mm' => $actual['length_mm'], 'width_mm' => $actual['width_mm'], 'thickness_mm' => $actual['actual_thickness_mm'], 'area_mm2' => $actual['area_mm2']]]];
            else {
                $count = (int) $batch->input_qty;
                if ($count <= 0 || bccomp((string) $batch->input_qty, (string) $count, 8) !== 0) app(CuttingCommandService::class)->fail('remnant_source_missing', '实际原根身份不完整，不能自动计算逐根余料。');
                for ($index = 0; $index < $count; $index++) $sources[] = ['source_key' => 'root:'.$index, 'dimensions' => ['length_mm' => $batch->standard_stock_length_mm]];
            }
        }
        foreach ($sources as &$source) {
            if ($batch->physical_material_id) {
                $physical = DB::table('erp_material_physicals')->where('id', $source['physical_material_id'])->first();
                if (! $physical || ! FrozenCuttingStockPolicy::sameDimensions($source['dimensions'], ['shape' => $physical->shape] + $this->json($physical->dimensions)))
                    app(CuttingCommandService::class)->fail('remnant_source_changed', '实际板材面积或尺寸与已收来源不一致。', 409);
                $source['physical_no'] = $physical->physical_no;
            }
        }
        unset($source);
        return $sources;
    }

    /** Hashes deliberately omit lifecycle versions/statuses changed by normal settlement. */
    public function projection(object $batch, ?Collection $rows = null): array
    {
        $rows ??= $this->rows((int) $batch->id);
        $sources = $this->sources($batch);
        $sourceFacts = array_map(fn ($source) => array_intersect_key($source, array_flip(['source_key', 'physical_material_id', 'dimensions', 'members'])), $sources);
        $fingerprint = ProductionCuttingAllocationService::fingerprint(['sources' => $sourceFacts, 'results' => $this->facts($rows, false)]);
        $contentHash = ProductionCuttingAllocationService::fingerprint(['source_fingerprint' => $fingerprint, 'results' => $this->facts($rows, true)]);
        $remaining = [];
        foreach ($rows as $row) {
            $row = (object) $row;
            if ($row->result_type !== 'product') continue;
            $measure = $this->json($row->measurements ?? null);
            $requirement = $this->json($row->cutting_requirement_snapshot ?? null);
            $id = (int) ($requirement['target_material_requirement_id'] ?? 0);
            if (! $id && isset($row->allowed_output_id)) $id = (int) ($this->json(DB::table('erp_cutting_allowed_outputs')->where('id', $row->allowed_output_id)->value('rule_snapshot'))['target_material_requirement_id'] ?? 0);
            $remaining[$id] = bcadd($remaining[$id] ?? '0', isset($measure['total_length_mm']) ? (string) $measure['total_length_mm'] : (string) ($row->piece_qty ?? $row->actual_qty ?? 0), 8);
        }
        $projected = [];
        foreach ($sources as $source) {
            $area = FrozenCuttingStockPolicy::area($source['dimensions']);
            $basis = $batch->physical_material_id ? 'area' : 'length';
            $inputValue = $basis === 'area' ? $area : FrozenCuttingStockPolicy::positiveDecimal($source['dimensions']['length_mm'] ?? null);
            if ($inputValue === null) app(CuttingCommandService::class)->fail('remnant_source_missing', '实际来源缺少可核对的长度或面积。');
            $used = '0.00000000';
            if ($source['members'] ?? []) foreach ($source['members'] as $member) {
                $id = (int) $member['target_material_requirement_id'];
                $limit = (string) (($member['quantity_basis'] ?? null) === 'total_length' ? $member['covered_total_length_mm'] : $member['covered_piece_qty']);
                $actual = bccomp($remaining[$id] ?? '0', $limit, 8) < 0 ? ($remaining[$id] ?? '0') : $limit;
                $remaining[$id] = bcsub($remaining[$id] ?? '0', $actual, 8);
                $size = $member['dimensions'] ?? [];
                $used = bcadd($used, ($member['quantity_basis'] ?? null) === 'total_length' ? $actual
                    : bcmul($basis === 'area' ? bcmul((string) $size['length_mm'], (string) $size['width_mm'], 8) : (string) $size['length_mm'], $actual, 8), 8);
            } else {
                // Older item-output batches have one shared result; consume their actual geometry in source order.
                foreach ($rows as $row) {
                    $row = (object) $row;
                    if ($row->result_type !== 'product') continue;
                    $m = $this->json($row->measurements ?? null);
                    $total = isset($m['total_length_mm']) ? (string) $m['total_length_mm']
                        : bcmul($basis === 'area' ? bcmul((string) ($m['length_mm'] ?? 0), (string) ($m['width_mm'] ?? 0), 8) : (string) ($row->cut_length_mm ?? $m['length_mm'] ?? 0), (string) ($row->piece_qty ?? $row->actual_qty ?? 0), 8);
                    $key = 'legacy:'.($row->client_row_id ?? $row->id ?? '');
                    $left = $remaining[$key] ?? $total; $room = bcsub($inputValue, $used, 8);
                    $take = bccomp($left, $room, 8) < 0 ? $left : $room;
                    $used = bcadd($used, $take, 8); $remaining[$key] = bcsub($left, $take, 8);
                }
            }
            $suggestion = bcsub($inputValue, $used, 8);
            if (bccomp($suggestion, '0', 8) < 0) app(CuttingCommandService::class)->fail('remnant_material_exceeded', '实际产出用量超过该来源，不能自动改成零余料。');
            $projected[] = ['source_key' => $source['source_key'], 'source_label' => $source['physical_no'] ?? '原根 '.(count($projected) + 1),
                'quantity_basis' => $basis, 'input_'.$basis.'_'.($basis === 'area' ? 'mm2' : 'mm') => $inputValue,
                'used_'.$basis.'_'.($basis === 'area' ? 'mm2' : 'mm') => $used,
                'suggested_'.($basis === 'area' ? 'area_mm2' : 'length_mm') => $suggestion,
                'thickness_mm' => $source['dimensions']['thickness_mm'] ?? null, 'rows' => [], 'no_remnant' => false];
        }
        foreach ($rows as $row) {
            $row = (object) $row;
            $measure = $this->json($row->measurements ?? null);
            if (isset($measure['source_key']) && ! in_array($measure['source_key'], array_column($projected, 'source_key'), true))
                app(CuttingCommandService::class)->fail('remnant_source_invalid', '加工结果的来源不属于本批实际材料。');
            if ($row->result_type !== 'scrapped_output' || ($row->measurement_status ?? null) !== 'MEASURED' || ! isset($row->actual_qty)) continue;
            $basis = $batch->physical_material_id ? 'area' : 'length';
            $perPiece = $basis === 'area' ? FrozenCuttingStockPolicy::area($measure) : FrozenCuttingStockPolicy::positiveDecimal($measure['length_mm'] ?? null);
            // Weight alone cannot prove how much source length or area was consumed.
            if ($perPiece === null) continue;
            $remainingScrap = bcmul($perPiece, (string) $row->actual_qty, 8);
            foreach ($projected as &$source) {
                if (isset($measure['source_key']) && $measure['source_key'] !== $source['source_key']) continue;
                $field = 'suggested_'.($basis === 'area' ? 'area_mm2' : 'length_mm');
                $room = $source[$field];
                $taken = bccomp($remainingScrap, $room, 8) <= 0 ? $remainingScrap : $room;
                $source[$field] = bcsub($room, $taken, 8);
                $source['known_other_consumption'] = bcadd($source['known_other_consumption'] ?? '0', $taken, 8);
                $remainingScrap = bcsub($remainingScrap, $taken, 8);
                if (bccomp($remainingScrap, '0', 8) === 0) break;
            }
            unset($source);
            if (bccomp($remainingScrap, '0', 8) > 0) app(CuttingCommandService::class)->fail('remnant_material_exceeded', '已实测报废产出的用量超过其实际来源剩余长度或面积。');
        }
        $event = DB::table('erp_cutting_events')->where('aggregate_type', 'batch')->where('aggregate_id', $batch->id)
            ->where('action', 'remnant_review_saved')->orderByDesc('id')->first();
        $review = $this->json($event?->after_snapshot);
        foreach ($projected as &$source) {
            $previous = collect($review['sources'] ?? [])->firstWhere('source_key', $source['source_key']);
            $source['rows'] = $previous['rows'] ?? [];
            $source['no_remnant'] = (bool) ($previous['no_remnant'] ?? false);
            $source['confirmed_processing_consumption'] = $previous['confirmed_processing_consumption'] ?? null;
        }
        unset($source);
        $confirmed = ($review['confirmed'] ?? false) && hash_equals($contentHash, (string) ($review['content_hash'] ?? ''));
        return ['required' => true, 'source_fingerprint' => $fingerprint, 'content_hash' => $contentHash, 'confirmed' => $confirmed,
            'confirmed_by' => $confirmed ? ($review['confirmed_by'] ?? null) : null, 'confirmed_at' => $confirmed ? $event?->occurred_at : null,
            'sources' => $projected];
    }

    public function prepareRows(object $batch, array $rows, array $review): array
    {
        $c = app(CuttingCommandService::class);
        $rows = array_values(array_filter($rows, fn ($row) => ($row['result_type'] ?? null) !== 'usable_remnant'));
        foreach ($rows as $row) {
            if (! is_array($row)) $c->fail('results_invalid', '加工结果必须是结构化记录。');
            if (array_diff(array_keys($row), ['client_row_id', 'result_type', 'allowed_output_id', 'item_id', 'configuration_id', 'bom_item_id', 'actual_qty', 'piece_qty', 'cut_length_mm', 'measurement_status', 'measurements', 'reported_quality'])) $c->fail('result_fields_invalid', '加工结果包含不允许的字段。');
            if (! is_string($row['client_row_id'] ?? null) || strlen($row['client_row_id']) < 1 || strlen($row['client_row_id']) > 80) $c->fail('row_id_invalid', '结果行标识为空或不合法。');
            if (isset($row['allowed_output_id']) && (! is_int($row['allowed_output_id']) && (! is_string($row['allowed_output_id']) || ! ctype_digit($row['allowed_output_id'])))) $c->fail('output_not_allowed', '产出身份必须引用正式允许的产出记录。');
            foreach (['actual_qty', 'piece_qty', 'cut_length_mm'] as $field) if (isset($row[$field])) CuttingDecimal::value($row[$field], 8, true);
            $measure = $row['measurements'] ?? null;
            if ($measure !== null && ! is_array($measure)) $c->fail('measurement_invalid', '实测记录必须使用结构化长度、面积或重量。');
            foreach ($measure ?? [] as $field => $value) {
                if ($field === 'shape') {
                    if (! in_array($value, ['RECTANGLE', 'IRREGULAR'], true)) $c->fail('shape_invalid', '余料形状不合法。');
                } elseif ($field === 'source_key') {
                    if (! is_string($value) || ! in_array($value, array_column($this->sources($batch), 'source_key'), true)) $c->fail('remnant_source_invalid', '加工结果的来源不属于本批实际材料。');
                } elseif (in_array($field, ['length_mm', 'width_mm', 'thickness_mm', 'weight_kg', 'area_mm2', 'total_length_mm'], true)) CuttingDecimal::value($value, 8, true);
                else $c->fail('measurement_invalid', '实测记录包含不允许的字段。');
            }
        }
        $projection = $this->projection($batch, collect($rows));
        $confirmed = (bool) ($review['confirmed'] ?? false);
        if ($confirmed && ! hash_equals($projection['source_fingerprint'], (string) ($review['source_fingerprint'] ?? '')))
            $c->fail('remnant_review_stale', '实际产出或来源已变化，请先保存产出并刷新建议，再确认余料。', 409);
        $seen = []; $rowIds = array_column($rows, 'client_row_id');
        foreach ($review['sources'] ?? [] as $source) {
            $key = $source['source_key'] ?? '';
            $actual = collect($projection['sources'])->firstWhere('source_key', $key);
            if (! $actual || isset($seen[$key])) $c->fail('remnant_source_invalid', '余料确认必须逐项引用本次实际材料来源。');
            $seen[$key] = true; $sum = '0.00000000';
            $noRemnant = (bool) ($source['no_remnant'] ?? false);
            if ($noRemnant && ($source['rows'] ?? [])) $c->fail('remnant_review_conflict', '无可用余料不能同时填写余料段或块。');
            if ($confirmed && ! $noRemnant && ! ($source['rows'] ?? [])) $c->fail('remnant_review_required', '请填写实际余料，或明确确认无可用余料。');
            foreach ($source['rows'] ?? [] as $row) {
                $id = $row['client_row_id'] ?? '';
                if (! is_string($id) || ! preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $id) || in_array($id, $rowIds, true)) $c->fail('remnant_row_invalid', '余料行标识为空或重复。');
                $rowIds[] = $id; $field = $actual['quantity_basis'] === 'area' ? 'area_mm2' : 'length_mm';
                $value = $row[$field] ?? null;
                $blank = $value === null || $value === '';
                if ((! $blank || $confirmed) && (! preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', (string) $value) || bccomp((string) $value, '0', 2) <= 0)) $c->fail('remnant_precision_invalid', '实际余料必须大于零，最多保留两位毫米或平方毫米小数。');
                if (! $blank) $sum = bcadd($sum, (string) $value, 8);
                $measurements = [$field => $blank ? null : bcadd((string) $value, '0', 2), 'source_key' => $key];
                if ($field === 'area_mm2') $measurements += ['shape' => 'IRREGULAR', 'thickness_mm' => $actual['thickness_mm']];
                $rows[] = ['client_row_id' => $id, 'result_type' => 'usable_remnant', 'actual_qty' => $confirmed ? '1.00000000' : null,
                    'measurement_status' => $confirmed ? 'MEASURED' : 'NOT_MEASURED', 'measurements' => $confirmed ? $measurements : null];
            }
            $maximum = $actual['suggested_'.($actual['quantity_basis'] === 'area' ? 'area_mm2' : 'length_mm')];
            if ($confirmed && bccomp($sum, $maximum, 8) > 0) $c->fail('remnant_material_exceeded', '该来源的实际余料超过扣除产出后的可用长度或面积，请保留记录核对。');
        }
        if ($confirmed && count($seen) !== count($projection['sources'])) $c->fail('remnant_review_required', '每个实际来源都必须分别确认余料。');
        if (count($rows) > 100) $c->fail('results_invalid', '本批次产出和余料合计最多一百行。');
        return $rows;
    }

    public function saveReview(object $batch, array $review, object $user): array
    {
        $projection = $this->projection($batch);
        $confirmed = (bool) ($review['confirmed'] ?? false);
        $sources = [];
        foreach ($projection['sources'] as $actual) {
            $submitted = collect($review['sources'] ?? [])->firstWhere('source_key', $actual['source_key']);
            $sum = '0.00000000';
            foreach ($submitted['rows'] ?? [] as $row) $sum = bcadd($sum, (string) (($row[$actual['quantity_basis'] === 'area' ? 'area_mm2' : 'length_mm'] ?? '') ?: '0'), 8);
            $maximum = $actual['suggested_'.($actual['quantity_basis'] === 'area' ? 'area_mm2' : 'length_mm')];
            $sources[] = ['source_key' => $actual['source_key'], 'no_remnant' => (bool) ($submitted['no_remnant'] ?? false),
                'rows' => $submitted['rows'] ?? [], 'confirmed_processing_consumption' => $confirmed ? bcsub($maximum, $sum, 8) : null];
        }
        $actor = app(CuttingCommandService::class)->actor($user);
        $label = DB::table('erp_legacy_admin_users')->where('legacy_id', $actor)->first(['nickname', 'username']);
        $fact = ['confirmed' => $confirmed, 'source_fingerprint' => $projection['source_fingerprint'], 'content_hash' => $projection['content_hash'],
            'sources' => $sources, 'confirmed_by' => $confirmed ? ['id' => $actor, 'label' => $label?->nickname ?: $label?->username] : null];
        app(CuttingCommandService::class)->event('batch', (int) $batch->id, 'remnant_review_saved', $user, null, $fact);
        return $this->projection($batch);
    }

    public function assertConfirmed(object $batch): array
    {
        $review = $this->projection($batch);
        if (! $review['confirmed']) app(CuttingCommandService::class)->fail('remnant_review_required', '请先人工确认每根或每块的可用余料及剩余加工耗用，再完成本工序。', 409);
        return $review;
    }
}
