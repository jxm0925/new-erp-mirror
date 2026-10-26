<?php

namespace App\Services\Erp\Inventory;

use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventoryStocktake;
use App\Models\Erp\InventoryStocktakeLine;
use App\Models\Erp\Item;
use App\Models\Erp\Location;
use App\Models\Erp\Warehouse;
use App\Services\Erp\DocumentNumberService;
use App\Services\Erp\InventoryAdjustmentApplicationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 库存盘点：开盘（快照账面、冻结范围）→ 录入实盘数 → 完成（差异生成“盘点差异”调整单并过账）/ 取消。
 * 盘点本身不直接写库存，差异统一复用手工调整的校验与过账，冻结由 StocktakeFreezeGuard 在过账内核入口执行。
 */
class InventoryStocktakeService
{
    public const STATUSES = ['counting' => '盘点中', 'completed' => '已完成', 'cancelled' => '已取消'];

    public function __construct(
        private readonly InventoryAdjustmentApplicationService $adjustments,
        private readonly WarehouseInventoryPosting $posting,
        private readonly DocumentNumberService $numbers,
    )
    {
    }

    public function create(array $data, ?int $operatorId): InventoryStocktake
    {
        return DB::transaction(function () use ($data, $operatorId): InventoryStocktake {
            $warehouse = Warehouse::query()->whereKey((int) $data['warehouse_id'])->lockForUpdate()->first();
            if (! $warehouse || ! in_array($warehouse->status, ['enabled', 'active'], true)) {
                throw ValidationException::withMessages(['warehouse_id' => '仓库不存在或已停用。']);
            }
            $locationId = ! empty($data['location_id']) ? (int) $data['location_id'] : null;
            if ($locationId && ! Location::query()->whereKey($locationId)->where('warehouse_id', $warehouse->id)->exists()) {
                throw ValidationException::withMessages(['location_id' => '库位不属于所选仓库。']);
            }
            $overlap = InventoryStocktake::query()->where('warehouse_id', $warehouse->id)->where('stocktake_status', 'counting')
                ->when($locationId, fn ($query) => $query->where(fn ($q) => $q->whereNull('location_id')->orWhere('location_id', $locationId)))
                ->lockForUpdate()->value('stocktake_no');
            if ($overlap) throw ValidationException::withMessages(['warehouse_id' => "盘点范围与进行中的盘点单 {$overlap} 重叠。"]);

            $balances = InventoryBalance::query()->where('warehouse_id', $warehouse->id)
                ->when($locationId, fn ($query) => $query->where('location_id', $locationId))
                ->where('quantity_on_hand', '<>', 0)->orderBy('location_id')->orderBy('item_id')->orderBy('batch_no')
                ->lockForUpdate()->get();
            if ($balances->isEmpty()) throw ValidationException::withMessages(['warehouse_id' => '所选范围没有在库库存，无需盘点。']);

            $stocktake = InventoryStocktake::create([
                'stocktake_no' => $this->numbers->next('inventory_stocktake', 'PD'),
                'warehouse_id' => $warehouse->id,
                'location_id' => $locationId,
                'stocktake_status' => 'counting',
                'stocktake_date' => $data['stocktake_date'] ?? now()->toDateString(),
                'remark' => $data['remark'] ?? null,
                'line_count' => $balances->count(),
                'created_by' => $operatorId,
            ]);
            foreach ($balances as $balance) {
                InventoryStocktakeLine::create([
                    'stocktake_id' => $stocktake->id,
                    'inventory_balance_id' => $balance->id,
                    'item_id' => $balance->item_id,
                    'warehouse_id' => $balance->warehouse_id,
                    'location_id' => $balance->location_id,
                    'batch_no' => $balance->batch_no,
                    'unit_id' => $balance->unit_id,
                    'book_qty' => $balance->quantity_on_hand,
                    'unit_cost' => $balance->average_unit_cost ?? 0,
                ]);
            }
            return $stocktake;
        });
    }

    /** 录入实盘数：counted_qty 传 null 表示清空，可多次保存。 */
    public function saveCounts(int $id, array $lines, ?int $operatorId): InventoryStocktake
    {
        return DB::transaction(function () use ($id, $lines, $operatorId): InventoryStocktake {
            $stocktake = $this->lockCounting($id);
            $rows = InventoryStocktakeLine::query()->where('stocktake_id', $stocktake->id)->lockForUpdate()->get()->keyBy('id');
            foreach ($lines as $input) {
                $line = $rows->get((int) $input['id']);
                if (! $line) throw ValidationException::withMessages(['lines' => '盘点明细不属于当前盘点单。']);
                $counted = array_key_exists('counted_qty', $input) && $input['counted_qty'] !== null && $input['counted_qty'] !== ''
                    ? round((float) $input['counted_qty'], 4) : null;
                if ($counted !== null && $counted < 0) throw ValidationException::withMessages(['lines' => '实盘数不能小于 0。']);
                $difference = $counted === null ? null : round($counted - (float) $line->book_qty, 4);
                $line->update([
                    'counted_qty' => $counted,
                    'difference_qty' => $difference,
                    'difference_amount' => $difference === null ? null : round($difference * (float) $line->unit_cost, 4),
                    'counted_by' => $counted === null ? null : $operatorId,
                    'counted_at' => $counted === null ? null : now(),
                    'remark' => $input['remark'] ?? $line->remark,
                ]);
            }
            $stocktake->increment('business_version');
            return $stocktake->fresh();
        });
    }

    /** 完成盘点：按当前账面重算差异，差异行生成“盘点差异”调整单并立即过账，随后解除冻结。 */
    public function complete(int $id, ?int $operatorId): InventoryStocktake
    {
        return DB::transaction(function () use ($id, $operatorId): InventoryStocktake {
            $stocktake = $this->lockCounting($id);
            $lines = InventoryStocktakeLine::query()->where('stocktake_id', $stocktake->id)->lockForUpdate()->get();
            $uncounted = $lines->whereNull('counted_qty')->count();
            if ($uncounted > 0) throw ValidationException::withMessages(['lines' => "还有 {$uncounted} 行未录入实盘数。"]);

            $items = Item::query()->whereIn('id', $lines->pluck('item_id')->unique())->get()->keyBy('id');
            $differences = [];
            $blocked = [];
            foreach ($lines as $line) {
                $balance = InventoryBalance::query()->whereKey($line->inventory_balance_id)->lockForUpdate()->first();
                $book = (float) ($balance->quantity_on_hand ?? 0);
                $difference = round((float) $line->counted_qty - $book, 4);
                $line->update(['difference_qty' => $difference, 'difference_amount' => round($difference * (float) $line->unit_cost, 4)]);
                if (abs($difference) < 0.00005) continue;
                $item = $items->get($line->item_id);
                if ($item && ($item->serialTrackingMode() !== 'none' || $item->materialManagementMode() !== 'quantity')) {
                    $blocked[] = $item->item_code.'（'.$line->batch_no.'）';
                    continue;
                }
                $differences[] = ['line' => $line, 'difference' => $difference];
            }
            if ($blocked) {
                throw ValidationException::withMessages(['lines' => '以下物料按序列号/实物管理，差异须先用“手工调整（原因：盘点差异）”逐个处理后再完成盘点：'.implode('、', $blocked)]);
            }

            $adjustmentId = null;
            if ($differences) {
                $adjustment = $this->adjustments->save([
                    'adjustment_date' => now()->toDateString(),
                    'reason' => StocktakeFreezeGuard::EXEMPT_ADJUSTMENT_REASON,
                    'remark' => "盘点单 {$stocktake->stocktake_no} 差异",
                    'items' => array_map(fn ($row) => [
                        'item_id' => $row['line']->item_id,
                        'warehouse_id' => $row['line']->warehouse_id,
                        'location_id' => $row['line']->location_id,
                        'batch_no' => $row['line']->batch_no,
                        'unit_id' => $row['line']->unit_id,
                        'change_qty' => $row['difference'],
                        'remark' => $row['line']->remark,
                    ], $differences),
                ], null, $operatorId);
                $this->adjustments->submit($adjustment->id, $operatorId);
                $this->posting->postAdjustment($adjustment->id, $operatorId);
                $adjustmentId = $adjustment->id;
            }

            $diffs = collect($differences)->pluck('difference');
            $stocktake->update([
                'stocktake_status' => 'completed',
                'adjustment_id' => $adjustmentId,
                'difference_count' => count($differences),
                'gain_qty' => round($diffs->filter(fn ($q) => $q > 0)->sum(), 4),
                'loss_qty' => round(abs($diffs->filter(fn ($q) => $q < 0)->sum()), 4),
                'difference_amount' => round(collect($differences)->sum(fn ($row) => $row['difference'] * (float) $row['line']->unit_cost), 4),
                'completed_by' => $operatorId,
                'completed_at' => now(),
                'business_version' => (int) $stocktake->business_version + 1,
            ]);
            return $stocktake->fresh();
        });
    }

    public function cancel(int $id, ?int $operatorId): InventoryStocktake
    {
        return DB::transaction(function () use ($id, $operatorId): InventoryStocktake {
            $stocktake = $this->lockCounting($id);
            $stocktake->update(['stocktake_status' => 'cancelled', 'cancelled_by' => $operatorId, 'cancelled_at' => now(),
                'business_version' => (int) $stocktake->business_version + 1]);
            return $stocktake->fresh();
        });
    }

    private function lockCounting(int $id): InventoryStocktake
    {
        $stocktake = InventoryStocktake::query()->lockForUpdate()->findOrFail($id);
        if ($stocktake->stocktake_status !== 'counting') {
            throw ValidationException::withMessages(['status' => '只有盘点中的单据可以操作。']);
        }
        return $stocktake;
    }
}
