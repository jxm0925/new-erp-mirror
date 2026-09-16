<?php

namespace App\Services\Erp;

use App\Models\Erp\Bom;
use App\Models\Erp\Item;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class LengthCutRequirementService
{
    /**
     * Normalize the existing sales-line configuration snapshot.  Lengths are
     * production facts that reference a real Item; they never create Items.
     */
    public function normalizeConfiguration(array $configuration): array
    {
        if (! array_key_exists('cut_requirements', $configuration)) {
            return $configuration;
        }

        $requirements = $configuration['cut_requirements'];
        if (! is_array($requirements)) {
            throw ValidationException::withMessages([
                'configuration_snapshot.cut_requirements' => '下料要求必须是数组。',
            ]);
        }

        $itemIds = collect($requirements)->pluck('component_item_id')->filter()->map(fn ($id) => (int) $id)->unique();
        $items = Item::query()->whereIn('id', $itemIds)->get()->keyBy('id');
        $seen = [];
        $normalized = [];

        foreach (array_values($requirements) as $index => $row) {
            $path = "configuration_snapshot.cut_requirements.{$index}";
            if (! is_array($row)) {
                throw ValidationException::withMessages([$path => '每条下料要求必须是对象。']);
            }

            $itemId = (int) ($row['component_item_id'] ?? 0);
            $cutLength = round((float) ($row['cut_length_mm'] ?? 0), 2);
            $pieceQty = (int) ($row['piece_qty'] ?? 0);
            $item = $items->get($itemId);

            if (! $item || $item->status !== 'enabled') {
                throw ValidationException::withMessages(["{$path}.component_item_id" => '下料要求必须引用已启用的真实 Item。']);
            }
            if (! $item->is_length_cut_material) {
                throw ValidationException::withMessages(["{$path}.component_item_id" => '普通物料不能填写下料长度。']);
            }
            if ((float) $item->standard_stock_length_mm <= 0) {
                throw ValidationException::withMessages(["{$path}.component_item_id" => '长度下料类 Item 必须先维护标准原料长度。']);
            }
            if ($cutLength <= 0 || $cutLength > (float) $item->standard_stock_length_mm) {
                throw ValidationException::withMessages(["{$path}.cut_length_mm" => "下料长度必须大于 0 且不超过标准原料长度 {$item->standard_stock_length_mm}mm。"]);
            }
            if ($pieceQty <= 0 || (string) $pieceQty !== trim((string) ($row['piece_qty'] ?? ''))) {
                throw ValidationException::withMessages(["{$path}.piece_qty" => '段数必须是正整数。']);
            }

            $key = $itemId.'|'.number_format($cutLength, 2, '.', '');
            if (isset($seen[$key])) {
                throw ValidationException::withMessages([$path => '同一 Item 的相同下料长度不能重复；请合并段数。']);
            }
            $seen[$key] = true;
            $normalized[] = [
                'component_item_id' => $itemId,
                'component_item_code' => $item->item_code,
                'component_item_name' => $item->item_name,
                'material_grade' => $item->material_grade,
                'standard_stock_length_mm' => (float) $item->standard_stock_length_mm,
                'cut_length_mm' => $cutLength,
                'piece_qty' => $pieceQty,
                'remark' => filled($row['remark'] ?? null) ? trim((string) $row['remark']) : null,
            ];
        }

        $configuration['cut_requirements'] = $normalized;
        return $configuration;
    }

    /**
     * Resolve configuration dimensions onto BOM rows without changing the
     * approved BOM or calculating nesting, kerf or remnants.  Existing BOM
     * quantity fields remain the warehouse-consumption plan.
     */
    public function resolveBomLines(Bom $bom, ?array $configuration): Collection
    {
        $baseLines = $bom->items->values();
        $requirements = collect((array) data_get($configuration, 'cut_requirements', []));
        if ($requirements->isEmpty()) {
            return $baseLines->map(fn ($line) => $this->lineArray($line));
        }

        $configuredItemIds = $requirements->pluck('component_item_id')->map(fn ($id) => (int) $id)->unique();
        $templates = $baseLines->groupBy(fn ($line) => (int) $line->component_item_id);
        foreach ($configuredItemIds as $itemId) {
            if (! $templates->has($itemId)) {
                throw ValidationException::withMessages([
                    'configuration_snapshot.cut_requirements' => "配置单中的下料 Item {$itemId} 不在匹配 BOM 中。",
                ]);
            }
        }

        $resolved = $baseLines
            ->reject(fn ($line) => $configuredItemIds->contains((int) $line->component_item_id))
            ->map(fn ($line) => $this->lineArray($line))
            ->values();

        $fallbackIndexes = [];
        foreach ($requirements->values() as $index => $requirement) {
            $itemId = (int) $requirement['component_item_id'];
            $candidates = $templates->get($itemId)->values();
            $fallbackIndex = $fallbackIndexes[$itemId] ?? 0;
            $template = $candidates->first(fn ($line) => (float) $line->cut_length_mm === (float) $requirement['cut_length_mm'])
                ?: $candidates->get(min($fallbackIndex, $candidates->count() - 1));
            $fallbackIndexes[$itemId] = $fallbackIndex + 1;
            $resolved->push($this->lineArray($template) + [
                'configuration_requirement_index' => $index,
            ]);
            $last = $resolved->count() - 1;
            $resolved[$last] = array_replace($resolved[$last], [
                'cut_length_mm' => (float) $requirement['cut_length_mm'],
                'piece_qty' => (int) $requirement['piece_qty'],
                'remark' => $requirement['remark'] ?: $template->remark,
                'cut_requirement_source' => 'configuration_snapshot',
            ]);
        }

        return $resolved->values()->map(function (array $line, int $index): array {
            $line['line_no'] = ($index + 1) * 10;
            return $line;
        });
    }

    private function lineArray($line): array
    {
        return [
            'id' => (int) $line->id,
            'line_no' => (int) $line->line_no,
            'component_item_id' => (int) $line->component_item_id,
            'component_item_code' => $line->component_item_code,
            'component_item_name' => $line->component_item_name,
            'qty' => (float) $line->qty,
            'unit_id' => $line->unit_id ? (int) $line->unit_id : null,
            'loss_rate' => (float) $line->loss_rate,
            'fixed_qty' => (float) $line->fixed_qty,
            'replaceable' => (bool) $line->replaceable,
            'cut_length_mm' => $line->cut_length_mm === null ? null : (float) $line->cut_length_mm,
            'piece_qty' => $line->piece_qty === null ? null : (int) $line->piece_qty,
            'remark' => $line->remark,
            'cut_requirement_source' => $line->cut_length_mm === null ? null : 'bom',
        ];
    }
}
