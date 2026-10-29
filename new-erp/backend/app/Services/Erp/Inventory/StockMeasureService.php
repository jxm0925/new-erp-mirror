<?php

namespace App\Services\Erp\Inventory;

use App\Models\Erp\Item;
use App\Services\Erp\MaterialPhysicalService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 管材／板材的实际长度、面积：库存数量按根／张记账，这里补算对应的米数、平方米，
 * 并负责修正入库时录错的尺寸（板材逐张实物、管材余料批次）。
 */
class StockMeasureService
{
    /** 物料的计量画像：mode=length|sheet，kind=root|m|sheet|m2；不是下料物料返回 null。 */
    public function profiles(iterable $itemIds): array
    {
        $ids = collect($itemIds)->filter()->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) return [];
        return Item::query()->with('unit')->whereIn('id', $ids)->get()->mapWithKeys(fn (Item $item) => [(int) $item->id => $this->profile($item)])
            ->filter()->all();
    }

    public function profile(Item $item): ?array
    {
        $mode = $item->cuttingMode();
        if (! in_array($mode, ['length', 'sheet'], true)) return null;
        $unit = $item->unit;
        $code = strtoupper(trim((string) ($unit?->unit_code)));
        $symbol = strtoupper(str_replace(['²', '㎡', '^'], ['2', 'M2', ''], trim((string) ($unit?->symbol ?: $unit?->unit_symbol))));
        $kind = match (true) {
            $code === 'M' || $symbol === 'M' => 'm',
            in_array($code, ['M2', 'SQM'], true) || $symbol === 'M2' => 'm2',
            $code === 'ROOT' || $symbol === '根' => 'root',
            $code === 'SHEET' || $symbol === '张' => 'sheet',
            default => null,
        };
        $standard = (float) $item->standard_stock_length_mm > 0 ? (float) $item->standard_stock_length_mm : null;
        return ['mode' => $mode, 'kind' => $kind, 'standard_length_mm' => $standard, 'physical' => $item->materialManagementMode() === 'physical'];
    }

    /** 管材每个库存单位的米数：按根的取批次实际长度，没有就取物料标准长度；按米的就是 1。 */
    public function metersPerUnit(?array $profile, ?object $lot): ?float
    {
        if (! $profile || $profile['mode'] !== 'length') return null;
        if ($profile['kind'] === 'm') return 1.0;
        if ($profile['kind'] !== 'root') return null;
        $mm = $lot && (float) $lot->cut_length_mm > 0 ? (float) $lot->cut_length_mm : $profile['standard_length_mm'];
        return $mm ? $mm / 1000 : null;
    }

    public function lots(iterable $lotIds): Collection
    {
        $ids = collect($lotIds)->filter()->map(fn ($id) => (int) $id)->unique()->values();
        return $ids->isEmpty() ? collect() : DB::table('erp_material_lots')->whereIn('id', $ids)->get(['id', 'cut_length_mm', 'material_form'])->keyBy('id');
    }

    /**
     * 给一组库存余额补上米数／面积。
     * 返回 balance_id => [mode, per_unit_m, length_m, available_length_m, area_m2, available_area_m2, material_form, lot_id, lot_length_mm]
     */
    public function balanceMeasures(Collection $balances): array
    {
        $profiles = $this->profiles($balances->pluck('item_id'));
        $balances = $balances->filter(fn ($b) => isset($profiles[(int) $b->item_id]));
        if ($balances->isEmpty()) return [];
        $lots = $this->lots($balances->pluck('material_lot_id'));
        $sheetBalanceIds = $balances->filter(fn ($b) => $profiles[(int) $b->item_id]['mode'] === 'sheet')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $areas = [];
        if ($sheetBalanceIds) {
            DB::table('erp_material_physicals as p')->join('erp_material_holdings as h', 'h.id', '=', 'p.current_holding_id')
                ->whereIn('h.inventory_balance_id', $sheetBalanceIds)->where('h.position_type', 'WAREHOUSE')->where('p.status', 'AVAILABLE')
                ->get(['h.inventory_balance_id', 'p.shape', 'p.dimensions'])
                ->each(function ($row) use (&$areas) {
                    $areas[(int) $row->inventory_balance_id] = ($areas[(int) $row->inventory_balance_id] ?? 0) + $this->area($row->shape, $row->dimensions);
                });
        }
        $out = [];
        foreach ($balances as $b) {
            $profile = $profiles[(int) $b->item_id];
            $lot = $b->material_lot_id ? $lots->get((int) $b->material_lot_id) : null;
            $row = ['mode' => $profile['mode'], 'kind' => $profile['kind'], 'material_form' => $lot?->material_form,
                'lot_id' => $lot ? (int) $lot->id : null, 'lot_length_mm' => $lot && (float) $lot->cut_length_mm > 0 ? (float) $lot->cut_length_mm : null];
            if ($profile['mode'] === 'length') {
                $per = $this->metersPerUnit($profile, $lot);
                $row += ['per_unit_m' => $per,
                    'length_m' => $per === null ? null : round((float) $b->quantity_on_hand * $per, 4),
                    'available_length_m' => $per === null ? null : round((float) $b->quantity_available * $per, 4)];
            } else {
                $area = $areas[(int) $b->id] ?? 0;
                $row += ['area_m2' => round($area, 4)];
            }
            $out[(int) $b->id] = $row;
        }
        return $out;
    }

    /** 实物面积（平方米）。 */
    public function area(?string $shape, $dimensions): float
    {
        $d = is_array($dimensions) ? $dimensions : (json_decode((string) $dimensions, true) ?: []);
        if (strtoupper((string) $shape) === 'AREA_ONLY') return (float) ($d['area_m2'] ?? 0);
        return (float) ($d['length_mm'] ?? 0) * (float) ($d['width_mm'] ?? 0) / 1000000;
    }

    /** 某物料在仓库里的实物（板材逐张）。 */
    public function physicals(int $itemId, array $filters = []): array
    {
        $rows = DB::table('erp_material_physicals as p')
            ->join('erp_material_holdings as h', 'h.id', '=', 'p.current_holding_id')
            ->join('erp_inventory_balances as b', 'b.id', '=', 'h.inventory_balance_id')
            ->join('erp_warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->join('erp_locations as l', 'l.id', '=', 'b.location_id')
            ->leftJoin('erp_material_lots as lot', 'lot.id', '=', 'p.material_lot_id')
            ->where('p.item_id', $itemId)->where('h.position_type', 'WAREHOUSE')->where('p.status', 'AVAILABLE')
            ->when($filters['batch_no'] ?? null, fn ($q, $batch) => $q->where('b.batch_no', $batch))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('b.warehouse_id', $id))
            ->orderBy('b.batch_no')->orderBy('p.id')->limit(500)
            ->get(['p.id', 'p.physical_no', 'p.shape', 'p.dimensions', 'p.status', 'p.first_cut_at', 'p.business_version', 'p.total_cost',
                'lot.material_form', 'b.batch_no', 'b.warehouse_id', 'w.warehouse_name', 'b.location_id', 'l.location_name']);
        return $rows->map(function ($row) {
            $d = json_decode((string) $row->dimensions, true) ?: [];
            return ['id' => (int) $row->id, 'physical_no' => $row->physical_no, 'shape' => $row->shape, 'dimensions' => $d,
                'area_m2' => round($this->area($row->shape, $d), 4), 'material_form' => $row->material_form, 'batch_no' => $row->batch_no,
                'warehouse_id' => (int) $row->warehouse_id, 'warehouse_name' => $row->warehouse_name,
                'location_id' => (int) $row->location_id, 'location_name' => $row->location_name,
                'business_version' => (int) $row->business_version, 'total_cost' => (string) $row->total_cost,
                'editable' => $row->first_cut_at === null];
        })->all();
    }

    /** 修改一张板（实物）的长宽厚：只能改还在仓库、没切过、没被未完成单据占用的。 */
    public function updatePhysicalDimensions(int $id, array $data, object $actor): array
    {
        return DB::transaction(function () use ($id, $data, $actor) {
            $physical = DB::table('erp_material_physicals')->where('id', $id)->lockForUpdate()->first();
            if (! $physical) $this->fail('physical', '实物不存在。');
            if (isset($data['expected_version']) && (int) $data['expected_version'] !== (int) $physical->business_version) $this->fail('expected_version', '这张板刚被别人改过，请刷新后再改。');
            $item = Item::query()->with('unit')->findOrFail((int) $physical->item_id);
            if ($item->cuttingMode() !== 'sheet') $this->fail('physical', '只有板材实物可以修改长宽厚。');
            if ($physical->status !== 'AVAILABLE' || $physical->first_cut_at !== null) $this->fail('physical', '这张板已经领去开料或切过了，不能再改尺寸。');
            $holding = DB::table('erp_material_holdings')->where('id', $physical->current_holding_id)->lockForUpdate()->first();
            if (! $holding || $holding->position_type !== 'WAREHOUSE' || ! $holding->inventory_balance_id) $this->fail('physical', '这张板不在仓库里，不能改尺寸。');
            app(MaterialPhysicalService::class)->assertPhysicalNotInOpenDocument($id);
            $balance = DB::table('erp_inventory_balances')->where('id', $holding->inventory_balance_id)->first();
            $this->assertNotCounting($balance);
            $old = json_decode((string) $physical->dimensions, true) ?: [];
            $new = $old;
            unset($new['area_m2']);
            foreach (['length_mm', 'width_mm', 'thickness_mm'] as $key) $new[$key] = $this->mm($data[$key] ?? null, $key);
            if (isset($old['nominal_thickness_mm']) && ! isset($data['nominal_thickness_mm'])) $new['nominal_thickness_mm'] = $old['nominal_thickness_mm'];
            if (isset($data['nominal_thickness_mm']) && $data['nominal_thickness_mm'] !== '') $new['nominal_thickness_mm'] = $this->mm($data['nominal_thickness_mm'], 'nominal_thickness_mm');
            DB::table('erp_material_physicals')->where('id', $id)->update([
                'dimensions' => json_encode($new, JSON_THROW_ON_ERROR), 'shape' => 'RECTANGLE',
                'business_version' => (int) $physical->business_version + 1, 'updated_at' => now(),
            ]);
            $this->log('material_physical_dimensions', 'erp_material_physicals', $id,
                ['physical_no' => $physical->physical_no, 'shape' => $physical->shape, 'dimensions' => $old],
                ['physical_no' => $physical->physical_no, 'shape' => 'RECTANGLE', 'dimensions' => $new], $data['reason'] ?? null, $actor);
            return ['id' => $id, 'physical_no' => $physical->physical_no, 'dimensions' => $new, 'area_m2' => round($this->area('RECTANGLE', $new), 4),
                'business_version' => (int) $physical->business_version + 1];
        });
    }

    /** 修改一批管材余料（或带实际长度的批次）的每根长度。 */
    public function updateLotLength(int $lotId, array $data, object $actor): array
    {
        return DB::transaction(function () use ($lotId, $data, $actor) {
            $lot = DB::table('erp_material_lots')->where('id', $lotId)->lockForUpdate()->first();
            if (! $lot) $this->fail('lot', '材料批次不存在。');
            $item = Item::query()->with('unit')->findOrFail((int) $lot->item_id);
            $profile = $this->profile($item);
            if (! $profile || $profile['mode'] !== 'length' || $profile['kind'] !== 'root') $this->fail('lot', '只有按根记账的管材批次可以修改每根长度。');
            if ((float) $lot->cut_length_mm <= 0) $this->fail('lot', '这批整根管按物料档案的「标准原料长度」计算，请到物料档案里改。');
            $balances = DB::table('erp_inventory_balances')->where('material_lot_id', $lotId)->lockForUpdate()->get();
            if ($balances->sum(fn ($b) => (float) $b->quantity_on_hand) <= 0) $this->fail('lot', '这批管已经没有库存了，不用再改长度。');
            foreach ($balances as $balance) {
                if ((float) $balance->quantity_locked > 0 || (float) $balance->quantity_pending > 0) $this->fail('lot', '这批管有被锁定或待处理的数量，处理完再改长度。');
                $this->assertNotCounting($balance);
            }
            $length = $this->mm($data['length_mm'] ?? null, 'length_mm');
            $old = (string) $lot->cut_length_mm;
            DB::table('erp_material_lots')->where('id', $lotId)->update(['cut_length_mm' => $length, 'updated_at' => now()]);
            $this->log('material_lot_length', 'erp_material_lots', $lotId, ['lot_no' => $lot->lot_no, 'cut_length_mm' => $old],
                ['lot_no' => $lot->lot_no, 'cut_length_mm' => $length], $data['reason'] ?? null, $actor);
            return ['lot_id' => $lotId, 'lot_no' => $lot->lot_no, 'cut_length_mm' => (float) $length];
        });
    }

    private function assertNotCounting(?object $balance): void
    {
        if (! $balance) return;
        $stocktake = DB::table('erp_inventory_stocktakes')->where('stocktake_status', 'counting')->where('warehouse_id', $balance->warehouse_id)
            ->where(fn ($q) => $q->whereNull('location_id')->orWhere('location_id', $balance->location_id))->value('stocktake_no');
        if ($stocktake) $this->fail('stock', "该仓库/库位正在盘点（{$stocktake}），盘点完成或取消后再改尺寸。");
    }

    private function mm($value, string $field): string
    {
        $value = trim((string) $value);
        if (! preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $value) || bccomp($value, '0', 2) <= 0) $this->fail($field, '尺寸必须大于 0（单位毫米），最多两位小数。');
        return bcadd($value, '0', 2);
    }

    private function log(string $action, string $table, int $id, array $old, array $new, ?string $reason, object $actor): void
    {
        DB::table('erp_operation_logs')->insert([
            'module' => 'inventory', 'action' => $action, 'target_type' => $table, 'target_id' => $id,
            'old_snapshot' => json_encode($old, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'new_snapshot' => json_encode($new, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'reason' => mb_substr(trim((string) $reason) ?: '修正入库尺寸', 0, 200), 'operator_id' => $actor->legacy_id ?? $actor->id ?? null,
            'operator_name' => $actor->nickname ?? $actor->username ?? null, 'created_at' => now(),
        ]);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
