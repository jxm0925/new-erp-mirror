<?php

namespace App\Services\Erp\Inventory;

use App\Models\Erp\InventoryAdjustmentSerial;
use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventorySerial;
use App\Models\Erp\InventoryStocktakeEntry;
use App\Models\Erp\InventoryStocktakeLine;
use App\Models\Erp\Item;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 盘点逐件处理：序列号物料逐个编号、实物物料逐张盘点。
 * 开盘拍快照（在账件 + 已被业务占用的只读件），录入时标记在/不在、补录账外新件，
 * 完成时生成最多两条“盘点差异”调整明细（减少行带编号/实物，增加行带新编号/新实物），复用调整单的校验与过账。
 */
class InventoryStocktakeEntryService
{
    public const MAX_ENTRIES = 20000;
    /** 仍在库但已被业务占用的序列号状态：只读显示，按账面计，不参与差异。 */
    public const LOCKED_SERIAL_STATUSES = ['shipment_reserved', 'staged', 'quality_hold', 'repair'];
    public const LOCKED_PHYSICAL_STATUSES = ['RESERVED'];

    public function countMode(Item $item): string
    {
        if ($item->materialManagementMode() === 'physical') return 'physical';
        return in_array($item->serialTrackingMode(), ['required', 'optional'], true) ? 'serial' : 'quantity';
    }

    /** 开盘：为逐件行写入快照，返回写入件数；超过上限时整笔回滚，提示缩小范围。 */
    public function snapshot(InventoryStocktakeLine $line, InventoryBalance $balance, int $written): int
    {
        $now = now();
        if ($line->count_mode === 'serial') {
            $rows = InventorySerial::query()->where('inventory_balance_id', $balance->id)
                ->whereIn('serial_status', array_merge(['available'], self::LOCKED_SERIAL_STATUSES))
                ->orderBy('serial_no')->get(['id', 'serial_no', 'serial_status'])
                ->map(fn ($serial) => [
                    'inventory_serial_id' => $serial->id, 'serial_no' => $serial->serial_no, 'book_status' => $serial->serial_status,
                    'is_locked' => $serial->serial_status !== 'available',
                ]);
        } else {
            $rows = DB::table('erp_material_physicals as physical')
                ->join('erp_material_holdings as holding', 'holding.id', '=', 'physical.current_holding_id')
                ->where('holding.inventory_balance_id', $balance->id)->where('holding.position_type', 'WAREHOUSE')->where('holding.status', 'ACTIVE')
                ->whereIn('physical.status', array_merge(['AVAILABLE'], self::LOCKED_PHYSICAL_STATUSES))
                ->orderBy('physical.physical_no')->get(['physical.id', 'physical.physical_no', 'physical.dimensions', 'physical.total_cost', 'physical.status'])
                ->map(fn ($physical) => [
                    'material_physical_id' => $physical->id, 'physical_label' => mb_substr($physical->physical_no.' '.$this->dimensionText(json_decode((string) $physical->dimensions, true)), 0, 160),
                    'dimensions' => $physical->dimensions, 'total_cost' => $physical->total_cost, 'book_status' => $physical->status,
                    'is_locked' => $physical->status !== 'AVAILABLE',
                ]);
        }
        if ($written + $rows->count() > self::MAX_ENTRIES) {
            throw ValidationException::withMessages(['warehouse_id' => '盘点范围内逐件管理的序列号/实物超过 '.self::MAX_ENTRIES.' 件，请按库位分批盘点。']);
        }
        foreach ($rows->chunk(500) as $chunk) {
            InventoryStocktakeEntry::query()->insert($chunk->map(fn ($row) => [
                'stocktake_id' => $line->stocktake_id, 'line_id' => $line->id, 'entry_type' => $line->count_mode,
                'inventory_serial_id' => $row['inventory_serial_id'] ?? null, 'serial_no' => $row['serial_no'] ?? null,
                'material_physical_id' => $row['material_physical_id'] ?? null, 'physical_label' => $row['physical_label'] ?? null,
                'book_present' => true, 'counted_present' => null, 'is_new' => false, 'is_locked' => $row['is_locked'],
                'book_status' => $row['book_status'], 'dimensions' => $row['dimensions'] ?? null, 'total_cost' => $row['total_cost'] ?? null,
                'created_at' => $now, 'updated_at' => $now,
            ])->values()->all());
        }
        $line->update(['unnumbered_book_qty' => max(0, round((float) $line->book_qty - $rows->count(), 4))]);
        return $rows->count();
    }

    /** 录入逐件结果后按快照重算行实盘数；未全部确认时实盘数为空，完成盘点会提示未盘。 */
    public function saveLine(InventoryStocktakeLine $line, array $input, ?int $operatorId): void
    {
        $now = now();
        $entries = InventoryStocktakeEntry::query()->where('line_id', $line->id);
        if (array_key_exists('mark_all_present', $input) && $input['mark_all_present'] !== null) {
            (clone $entries)->where('is_new', false)->where('is_locked', false)
                ->update(['counted_present' => (bool) $input['mark_all_present'], 'counted_by' => $operatorId, 'counted_at' => $now, 'updated_at' => $now]);
        }
        foreach ($input['entries'] ?? [] as $entry) {
            $present = $entry['counted_present'] ?? null;
            $updated = (clone $entries)->whereKey((int) $entry['id'])->where('is_new', false)->where('is_locked', false)->update([
                'counted_present' => $present === null ? null : (bool) $present, 'counted_by' => $present === null ? null : $operatorId,
                'counted_at' => $present === null ? null : $now, 'updated_at' => $now,
            ]);
            if (! $updated) throw ValidationException::withMessages(['entries' => '逐件明细不属于该盘点行，或已被业务占用不能盘点。']);
        }
        if (! empty($input['delete_entry_ids'])) {
            (clone $entries)->whereIn('id', array_map('intval', $input['delete_entry_ids']))->where('is_new', true)->delete();
        }
        $item = Item::query()->findOrFail($line->item_id);
        foreach ($input['new_entries'] ?? [] as $new) {
            $line->count_mode === 'serial' ? $this->addSerial($line, $item, $new, $operatorId) : $this->addPhysical($line, $new, $operatorId);
        }
        $unnumbered = $line->unnumbered_counted_qty;
        if ($line->count_mode === 'serial' && array_key_exists('unnumbered_counted_qty', $input)) {
            $value = $input['unnumbered_counted_qty'];
            if ($value !== null && $value !== '' && abs((float) $value - round((float) $value)) > 0.00000001) {
                throw ValidationException::withMessages(['unnumbered_counted_qty' => "{$item->item_code} 无编号实盘数必须为整数。"]);
            }
            $unnumbered = $value === null || $value === '' ? null : round((float) $value, 4);
        }
        $this->refreshLine($line, $unnumbered, $operatorId);
    }

    public function refreshLine(InventoryStocktakeLine $line, mixed $unnumbered, ?int $operatorId): void
    {
        $stats = $this->stats([$line->id])[$line->id] ?? $this->emptyStats();
        $unnumberedBook = (float) ($line->unnumbered_book_qty ?? 0);
        $complete = $stats['undecided'] === 0 && ($line->count_mode !== 'serial' || $unnumberedBook <= 0 || $unnumbered !== null);
        $counted = null;
        if ($complete) {
            $counted = $stats['present'] + $stats['new'] + $stats['locked']
                + ($line->count_mode === 'serial' ? (float) ($unnumbered ?? 0) : $unnumberedBook);
            $counted = round($counted, 4);
        }
        $difference = $counted === null ? null : round($counted - (float) $line->book_qty, 4);
        $line->update([
            'unnumbered_counted_qty' => $line->count_mode === 'serial' ? $unnumbered : null,
            'counted_qty' => $counted, 'difference_qty' => $difference,
            'difference_amount' => $difference === null ? null : round($difference * (float) $line->unit_cost, 4),
            'counted_by' => $counted === null ? null : $operatorId, 'counted_at' => $counted === null ? null : now(),
        ]);
    }

    /** @return array<int, array> 每行逐件统计：在账件、已确认、在、不在、新增、占用、未确认。 */
    public function stats(array $lineIds): array
    {
        if (! $lineIds) return [];
        return DB::table('erp_inventory_stocktake_entries')->whereIn('line_id', $lineIds)->groupBy('line_id')->selectRaw(
            'line_id, SUM(book_present = 1 AND is_locked = 0) AS book, SUM(book_present = 1 AND is_locked = 0 AND counted_present = 1) AS present,
            SUM(book_present = 1 AND is_locked = 0 AND counted_present = 0) AS missing, SUM(book_present = 1 AND is_locked = 0 AND counted_present IS NULL) AS undecided,
            SUM(is_new = 1) AS new, SUM(is_locked = 1) AS locked')->get()
            ->mapWithKeys(fn ($row) => [(int) $row->line_id => array_map('intval', array_diff_key((array) $row, ['line_id' => 1]))])->all();
    }

    public function emptyStats(): array
    {
        return ['book' => 0, 'present' => 0, 'missing' => 0, 'undecided' => 0, 'new' => 0, 'locked' => 0];
    }

    /**
     * 完成盘点：把逐件结果转成调整明细（同一余额最多一减一增）。
     * 无编号部分的差异并入同方向的行；必须编号的物料不允许无编号差异。
     */
    public function adjustmentLines(InventoryStocktakeLine $line, Item $item, float $remaining): array
    {
        $entries = InventoryStocktakeEntry::query()->where('line_id', $line->id)->orderBy('id')->get();
        $missing = $entries->filter(fn ($e) => $e->book_present && ! $e->is_locked && $e->counted_present === false)->values();
        $new = $entries->filter(fn ($e) => $e->is_new)->values();
        $unnumberedDiff = $line->count_mode === 'serial'
            ? round((float) ($line->unnumbered_counted_qty ?? 0) - (float) ($line->unnumbered_book_qty ?? 0), 4) : 0.0;
        if (abs($unnumberedDiff) >= 0.00005 && $item->serialTrackingMode() === 'required') {
            throw ValidationException::withMessages(['lines' => "{$item->item_code}（批次 {$line->batch_no}）必须逐件编号，请把盘到的件录入编号，无编号数量须与账面一致。"]);
        }
        $decrease = -$missing->count() + min(0, $unnumberedDiff);
        $increase = $new->count() + max(0, $unnumberedDiff);
        if (abs(round($decrease + $increase - $remaining, 4)) >= 0.00005) {
            throw ValidationException::withMessages(['lines' => "{$item->item_code}（批次 {$line->batch_no}）逐件盘点结果与账面不一致（可能开盘后编号档案或实物发生变化），请取消后重新盘点。"]);
        }
        $base = ['item_id' => $line->item_id, 'warehouse_id' => $line->warehouse_id, 'location_id' => $line->location_id,
            'batch_no' => $line->batch_no, 'unit_id' => $line->unit_id];
        $rows = [];
        if ($decrease < 0) {
            $rows[] = $base + ['change_qty' => round($decrease, 4), 'remark' => $this->remark($line, '盘亏', $missing->count(), abs(min(0, $unnumberedDiff)))]
                + ($line->count_mode === 'serial'
                    ? ['serial_entries' => $missing->map(fn ($e) => ['serial_no' => $e->serial_no])->all()]
                    : ['physical_entries' => $missing->map(fn ($e) => ['physical_material_id' => $e->material_physical_id])->all()]);
        }
        if ($increase > 0) {
            $rows[] = $base + ['change_qty' => round($increase, 4), 'remark' => $this->remark($line, '盘盈', $new->count(), max(0, $unnumberedDiff))]
                + ($line->count_mode === 'serial'
                    ? ['serial_entries' => $new->map(fn ($e) => ['serial_no' => $e->serial_no, 'source' => 'manual'])->all()]
                    : ['physical_entries' => $new->map(fn ($e) => ['dimensions' => $e->dimensions, 'total_cost' => (string) $e->total_cost])->all()]);
        }
        return $rows;
    }

    private function remark(InventoryStocktakeLine $line, string $label, int $pieces, float $unnumbered): string
    {
        $text = $label.($line->count_mode === 'serial' ? "编号 {$pieces} 件" : "实物 {$pieces} 张");
        if ($unnumbered >= 0.00005) $text .= '，无编号 '.rtrim(rtrim(number_format($unnumbered, 4, '.', ''), '0'), '.').' 件';
        return trim(($line->remark ? $line->remark.'；' : '').$text);
    }

    private function addSerial(InventoryStocktakeLine $line, Item $item, array $new, ?int $operatorId): void
    {
        $serialNo = trim((string) ($new['serial_no'] ?? ''));
        if ($serialNo === '') throw ValidationException::withMessages(['new_entries' => '补录编号不能为空。']);
        // 规则与库存调整新增编号一致：全系统唯一，且不能被其他未完成调整单或本盘点单其他行占用。
        if (InventorySerial::query()->where('serial_no', $serialNo)->exists()) {
            throw ValidationException::withMessages(['new_entries' => "编号 {$serialNo} 已存在于序列号档案中，不能重复建档；如果是账上的件，请在列表中勾选“在”。"]);
        }
        if (InventoryAdjustmentSerial::query()->where('serial_no', $serialNo)
            ->whereHas('item.adjustment', fn ($q) => $q->whereIn('adjustment_status', ['draft', 'submitted']))->exists()) {
            throw ValidationException::withMessages(['new_entries' => "编号 {$serialNo} 已被其他未完成调整单占用。"]);
        }
        if (InventoryStocktakeEntry::query()->where('serial_no', $serialNo)->where('is_new', true)
            ->whereIn('stocktake_id', DB::table('erp_inventory_stocktakes')->where('stocktake_status', 'counting')->select('id'))->exists()) {
            throw ValidationException::withMessages(['new_entries' => "编号 {$serialNo} 已在进行中的盘点单中补录过。"]);
        }
        InventoryStocktakeEntry::create([
            'stocktake_id' => $line->stocktake_id, 'line_id' => $line->id, 'entry_type' => 'serial', 'serial_no' => $serialNo,
            'book_present' => false, 'counted_present' => true, 'is_new' => true, 'counted_by' => $operatorId, 'counted_at' => now(),
        ]);
    }

    private function addPhysical(InventoryStocktakeLine $line, array $new, ?int $operatorId): void
    {
        $dimensions = $new['dimensions'] ?? null;
        if (! is_array($dimensions) || ! isset($dimensions['length_mm'], $dimensions['width_mm'], $dimensions['thickness_mm'])) {
            throw ValidationException::withMessages(['new_entries' => '补录实物必须填写实际长、宽、厚。']);
        }
        $clean = [];
        foreach (['length_mm', 'width_mm', 'thickness_mm', 'nominal_thickness_mm'] as $key) {
            if (! isset($dimensions[$key]) || $dimensions[$key] === '') continue;
            $value = (string) $dimensions[$key];
            if (! preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $value) || bccomp($value, '0', 2) <= 0) {
                throw ValidationException::withMessages(['new_entries' => '实物长、宽、厚必须大于 0，最多两位小数。']);
            }
            $clean[$key] = bcadd($value, '0', 2);
        }
        $cost = (string) ($new['total_cost'] ?? '');
        if (! preg_match('/^\d{1,14}(?:\.\d{1,4})?$/', $cost)) {
            throw ValidationException::withMessages(['new_entries' => '实物总成本必须填写，且不能为负数，最多四位小数。']);
        }
        InventoryStocktakeEntry::create([
            'stocktake_id' => $line->stocktake_id, 'line_id' => $line->id, 'entry_type' => 'physical',
            'physical_label' => mb_substr(trim((string) ($new['physical_label'] ?? '')) ?: '补录 '.$this->dimensionText($clean), 0, 160),
            'book_present' => false, 'counted_present' => true, 'is_new' => true, 'dimensions' => $clean,
            'total_cost' => bcadd($cost, '0', 4), 'counted_by' => $operatorId, 'counted_at' => now(),
        ]);
    }

    private function dimensionText(?array $dimensions): string
    {
        if (! $dimensions) return '';
        $parts = array_filter([$dimensions['length_mm'] ?? null, $dimensions['width_mm'] ?? null, $dimensions['thickness_mm'] ?? null], fn ($v) => $v !== null);
        return $parts ? implode('×', array_map(fn ($v) => rtrim(rtrim((string) $v, '0'), '.'), $parts)).'mm' : '';
    }
}
