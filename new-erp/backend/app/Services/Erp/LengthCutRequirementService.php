<?php

namespace App\Services\Erp;

use App\Models\Erp\Bom;
use App\Models\Erp\Item;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class LengthCutRequirementService
{
    public function __construct(private readonly CuttingQuantityCalculator $calculator, private readonly UnitConversionDomainService $units) {}

    /** 配置只引用真实物料；尺寸与理论耗用冻结在配置中，不新增规格 Item。 */
    public function normalizeConfiguration(array $configuration): array
    {
        foreach (['technical_bom_lines', 'stock_bom_lines'] as $internal) {
            if (array_key_exists($internal, $configuration)) throw ValidationException::withMessages(['configuration_snapshot' => '技术尺寸和正式原料配置必须由工单技术确认生成，销售配置不能写入。']);
        }
        if (! array_key_exists('cut_requirements', $configuration)) return $configuration;
        if (! is_array($configuration['cut_requirements'])) {
            throw ValidationException::withMessages(['configuration_snapshot.cut_requirements' => '下料要求必须是数组。']);
        }
        $requirements = $configuration['cut_requirements'];
        $items = Item::query()->with('unit.standardUnit')->whereIn('id', collect($requirements)->pluck('component_item_id')->filter()->unique())->get()->keyBy('id');
        $seen = []; $normalized = [];
        foreach (array_values($requirements) as $index => $row) {
            $path = "configuration_snapshot.cut_requirements.$index";
            if (! is_array($row)) throw ValidationException::withMessages([$path => '每条下料要求必须是对象。']);
            $item = $items->get((int) ($row['component_item_id'] ?? 0));
            if (! $item || $item->status !== 'enabled') {
                throw ValidationException::withMessages(["$path.component_item_id" => '下料要求必须引用已启用的真实物料。']);
            }
            $unit = $this->units->canonicalUnit($item->unit);
            if (! $unit || $unit->status !== 'enabled') throw ValidationException::withMessages(["$path.unit_id" => '下料物料必须维护启用的库存单位。']);
            $stockDimensions = $row['stock_dimensions'] ?? data_get($row, 'cutting_quantity_snapshot.stock_dimensions');
            $snapshot = $this->calculator->calculate($item, $unit, $row,
                $stockDimensions ? ['dimensions' => $stockDimensions, 'source' => ['type' => 'configuration_explicit']] : null, $path);
            $dimensions = $snapshot['dimensions'];
            $key = implode('|', [(int) $item->id, $snapshot['quantity_basis'] ?? 'dimensions_pieces', $dimensions['length_mm'], $dimensions['width_mm'], $dimensions['thickness_mm']]);
            if (isset($seen[$key])) throw ValidationException::withMessages([$path => '同一物料的相同下料尺寸不能重复，请合并件数。']);
            $seen[$key] = true;
            $normalized[] = [
                'component_item_id' => (int) $item->id, 'component_item_code' => $item->item_code,
                'component_item_name' => $item->item_name, 'material_grade' => $item->material_grade,
                'standard_stock_length_mm' => $snapshot['stock_dimensions']['length_mm'] ?? null,
                'cut_length_mm' => $dimensions['length_mm'], 'cut_width_mm' => $dimensions['width_mm'],
                'cut_thickness_mm' => $dimensions['thickness_mm'], 'piece_qty' => $dimensions['piece_qty'],
                'allow_cut_rotation' => $dimensions['allow_rotation'], 'cutting_quantity_snapshot' => $snapshot,
                'cutting_quantity_basis' => $snapshot['quantity_basis'] ?? 'dimensions_pieces', 'total_length_mm' => $snapshot['total_length_mm'] ?? null,
                'remark' => filled($row['remark'] ?? null) ? trim((string) $row['remark']) : null,
            ];
        }
        $configuration['cut_requirements'] = $normalized;
        return $configuration;
    }

    /** 同一套尺寸计算同时用于 BOM、技术版本及发布；损耗和固定用量由发布层另计一次。 */
    public function resolveBomLines(Bom $bom, ?array $configuration): Collection
    {
        if (! $bom->relationLoaded('items') || $bom->items->contains(fn ($line) => ! $line->relationLoaded('componentItem')
            || ! $line->relationLoaded('unit') || ($line->componentItem && ! $line->componentItem->relationLoaded('unit')))) {
            $bom->loadMissing(['items.componentItem.unit.standardUnit', 'items.unit.standardUnit']);
        }
        $base = $bom->items->values();
        $resolved = $base->map(fn ($line) => $this->lineArray($line));
        $technical = collect((array) data_get($configuration, 'technical_bom_lines', []));
        $stockLines = collect((array) data_get($configuration, 'stock_bom_lines', []));
        if ($stockLines->pluck('bom_item_id')->duplicates()->isNotEmpty()) throw ValidationException::withMessages(['configuration_snapshot' => '每条 BOM 用料只能指定一个正式原料配置。']);
        $stockLines = $stockLines->keyBy('bom_item_id');
        if ($technical->pluck('bom_item_id')->merge($stockLines->keys())->diff($base->pluck('id'))->isNotEmpty()) {
            throw ValidationException::withMessages(['configuration_snapshot' => '工单技术尺寸不属于当前 BOM 版本。']);
        }
        if ($technical->isNotEmpty()) {
            if ($technical->pluck('bom_item_id')->duplicates()->isNotEmpty()) throw ValidationException::withMessages(['configuration_snapshot' => '每条 BOM 用料只能配置一条技术尺寸。']);
            $technical = $technical->keyBy('bom_item_id');
            $resolved = $resolved->map(function (array $line) use ($technical): array {
                if ($override = $technical->get($line['id'])) {
                    foreach (['cut_length_mm', 'cut_width_mm', 'cut_thickness_mm', 'piece_qty', 'allow_cut_rotation', 'cutting_quantity_basis', 'total_length_mm'] as $field) {
                        if (array_key_exists($field, $override)) $line[$field] = $override[$field];
                    }
                    $line['cut_requirement_source'] = 'work_order_technical_version';
                }
                return $line;
            });
        } else {
            $requirements = collect((array) data_get($configuration, 'cut_requirements', []));
            if ($requirements->isNotEmpty()) {
                $templates = $base->groupBy(fn ($line) => (int) $line->component_item_id);
                $configured = $requirements->pluck('component_item_id')->map(fn ($id) => (int) $id)->unique();
                if ($configured->diff($templates->keys())->isNotEmpty()) throw ValidationException::withMessages(['configuration_snapshot.cut_requirements' => '配置下料物料不在匹配 BOM 中。']);
                $resolved = $resolved->reject(fn ($line) => $configured->contains($line['component_item_id']))->values();
                foreach ($requirements->values() as $index => $requirement) {
                    $candidates = $templates->get((int) $requirement['component_item_id']);
                    $matches = $candidates->filter(fn ($row) => (float) $row->cut_length_mm === (float) ($requirement['cut_length_mm'] ?? 0)
                        && (float) $row->cut_width_mm === (float) ($requirement['cut_width_mm'] ?? 0)
                        && (float) $row->cut_thickness_mm === (float) ($requirement['cut_thickness_mm'] ?? 0));
                    $template = $matches->count() === 1 ? $matches->first() : ($candidates->count() === 1 ? $candidates->first() : null);
                    if (! $template) throw ValidationException::withMessages(['configuration_snapshot.cut_requirements' => '同一物料存在多条 BOM 下料要求，配置尺寸没有唯一匹配，不能自动套用其他尺寸行。']);
                    $line = $this->lineArray($template);
                    foreach (['cut_length_mm', 'cut_width_mm', 'cut_thickness_mm', 'piece_qty', 'allow_cut_rotation', 'cutting_quantity_snapshot', 'cutting_quantity_basis', 'total_length_mm'] as $field) {
                        if (array_key_exists($field, $requirement)) $line[$field] = $requirement[$field];
                    }
                    $line['configuration_requirement_index'] = $index;
                    $line['remark'] = ($requirement['remark'] ?? null) ?: $template->remark;
                    $line['cut_requirement_source'] = 'configuration_snapshot';
                    $resolved->push($line);
                }
                $resolved = $resolved->values()->map(function ($line, $index) { $line['line_no'] = ($index + 1) * 10; return $line; });
            }
        }
        return $resolved->map(function (array $line) use ($base, $stockLines): array {
            $template = $base->firstWhere('id', $line['id']);
            $item = $template->componentItem;
            if (! in_array($item?->cuttingMode(), ['length', 'sheet'], true)) return $line;
            $unit = $this->units->canonicalUnit($template->unit ?: $item->unit);
            $baseUnit = $this->units->canonicalUnit($item->unit);
            if (! $unit || ! $baseUnit || (int) $unit->id !== (int) $baseUnit->id) throw ValidationException::withMessages(['items' => '下料 BOM 必须使用物料真实库存基本单位。']);
            $stock = $this->stock($line, $stockLines->get($line['id']));
            $snapshot = $this->calculator->calculate($item, $unit, $line, $stock, 'items.'.$line['id']);
            $line['qty'] = $snapshot['theoretical_qty'];
            $line['unit_id'] = (int) $unit->id;
            $line['cutting_quantity_snapshot'] = $snapshot;
            return $line;
        });
    }

    private function stock(array $line, ?array $stockLine): ?array
    {
        if ($config = $stockLine['configuration'] ?? null) {
            return ['dimensions' => $config['dimensions'], 'source' => ['type' => 'material_configuration', 'id' => (int) $config['id'], 'version_no' => (int) $config['version_no']]];
        }
        $snapshot = $line['cutting_quantity_snapshot'] ?? null;
        return isset($snapshot['stock_dimensions']) ? ['dimensions' => $snapshot['stock_dimensions'], 'source' => $snapshot['stock_source'] ?? ['type' => 'bom_explicit']] : null;
    }

    private function lineArray($line): array
    {
        return [
            'id' => (int) $line->id, 'line_no' => (int) $line->line_no,
            'component_item_id' => (int) $line->component_item_id, 'component_item_code' => $line->component_item_code,
            'component_item_name' => $line->component_item_name, 'qty' => (string) $line->qty,
            'unit_id' => $line->unit_id ? (int) $line->unit_id : null, 'loss_rate' => (string) $line->loss_rate,
            'fixed_qty' => (string) $line->fixed_qty, 'replaceable' => (bool) $line->replaceable,
            'cut_length_mm' => $line->cut_length_mm === null ? null : (string) $line->cut_length_mm,
            'cut_width_mm' => $line->cut_width_mm === null ? null : (string) $line->cut_width_mm,
            'cut_thickness_mm' => $line->cut_thickness_mm === null ? null : (string) $line->cut_thickness_mm,
            'allow_cut_rotation' => (bool) $line->allow_cut_rotation, 'piece_qty' => $line->piece_qty,
            'cutting_quantity_snapshot' => $line->cutting_quantity_snapshot,
            'cutting_quantity_basis' => $line->cutting_quantity_snapshot['quantity_basis'] ?? 'dimensions_pieces',
            'total_length_mm' => $line->cutting_quantity_snapshot['total_length_mm'] ?? null,
            'remark' => $line->remark, 'cut_requirement_source' => $line->cutting_quantity_snapshot === null ? null : 'bom',
        ];
    }
}
