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
use Illuminate\Database\QueryException;
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
        private readonly InventoryStocktakeEntryService $entries,
    )
    {
    }

    public function create(array $data, ?int $operatorId): InventoryStocktake
    {
        return $this->transaction(function () use ($data, $operatorId): InventoryStocktake {
            $warehouse = Warehouse::query()->whereKey((int) $data['warehouse_id'])->lockForUpdate()->first();
            if (! $warehouse || ! in_array($warehouse->status, ['enabled', 'active'], true)) {
                throw ValidationException::withMessages(['warehouse_id' => '仓库不存在或已停用。']);
            }
            $locationId = ! empty($data['location_id']) ? (int) $data['location_id'] : null;
            if (($data['scope'] ?? 'location') === 'location' && !$locationId) {
                throw ValidationException::withMessages(['location_id' => '请选择盘点库位；整仓盘点须明确选择整仓范围。']);
            }
            if (($data['scope'] ?? 'location') === 'warehouse' && $locationId) {
                throw ValidationException::withMessages(['location_id' => '整仓盘点不能同时指定库位。']);
            }
            if ($locationId && ! Location::query()->whereKey($locationId)->where('warehouse_id', $warehouse->id)->whereIn('status', ['enabled', 'active'])->lockForUpdate()->first()) {
                throw ValidationException::withMessages(['location_id' => '库位已停用或不属于所选仓库。']);
            }
            $overlap = InventoryStocktake::query()->where('warehouse_id', $warehouse->id)->where('stocktake_status', 'counting')
                ->when($locationId, fn ($query) => $query->where(fn ($q) => $q->whereNull('location_id')->orWhere('location_id', $locationId)))
                ->lockForUpdate()->value('stocktake_no');
            if ($overlap) throw ValidationException::withMessages(['warehouse_id' => "盘点范围与进行中的盘点单 {$overlap} 重叠。"]);

            $balances = InventoryBalance::query()->where('warehouse_id', $warehouse->id)
                ->when($locationId, fn ($query) => $query->where('location_id', $locationId))
                ->where('quantity_on_hand', '<>', 0)->orderBy('location_id')->orderBy('item_id')->orderBy('batch_no')
                ->lock('FOR UPDATE NOWAIT')->get();
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
            $items = Item::query()->whereIn('id', $balances->pluck('item_id')->unique())->get()->keyBy('id');
            $written = 0;
            foreach ($balances as $balance) {
                $item = $items->get($balance->item_id);
                $line = InventoryStocktakeLine::create([
                    'stocktake_id' => $stocktake->id,
                    'inventory_balance_id' => $balance->id,
                    'item_id' => $balance->item_id,
                    'warehouse_id' => $balance->warehouse_id,
                    'location_id' => $balance->location_id,
                    'batch_no' => $balance->batch_no,
                    'unit_id' => $balance->unit_id,
                    'book_qty' => $balance->quantity_on_hand,
                    'unit_cost' => $balance->average_unit_cost ?? 0,
                    'count_mode' => $item ? $this->entries->countMode($item) : 'quantity',
                ]);
                // 序列号/实物物料逐件拍快照，录入按件勾选，完成时自动生成带编号/实物的差异调整。
                if ($line->count_mode !== 'quantity') $written += $this->entries->snapshot($line, $balance, $written);
            }
            return $stocktake;
        });
    }

    /** 录入实盘数：counted_qty 传 null 表示清空，可多次保存。 */
    public function saveCounts(int $id, array $lines, ?int $operatorId, int $expectedVersion): InventoryStocktake
    {
        return $this->transaction(function () use ($id, $lines, $operatorId, $expectedVersion): InventoryStocktake {
            $stocktake = $this->lockCounting($id);
            if ((int) $stocktake->business_version !== $expectedVersion) throw ValidationException::withMessages(['expected_version' => '盘点单已被其他人修改，请重新打开后核对实盘数。']);
            $rows = InventoryStocktakeLine::query()->where('stocktake_id', $stocktake->id)->whereIn('id', array_column($lines, 'id'))->lockForUpdate()->get()->keyBy('id');
            foreach ($lines as $input) {
                $line = $rows->get((int) $input['id']);
                if (! $line) throw ValidationException::withMessages(['lines' => '盘点明细不属于当前盘点单。']);
                if ($line->count_mode !== 'quantity') {
                    if (array_key_exists('remark', $input)) $line->update(['remark' => $input['remark']]);
                    $this->entries->saveLine($line, $input, $operatorId);
                    continue;
                }
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

    /** 保留开盘差异，仅对尚未处理的数量过账；手工与自动处理均按正式流水核对。 */
    public function complete(int $id, ?int $operatorId): InventoryStocktake
    {
        return $this->transaction(function () use ($id, $operatorId): InventoryStocktake {
            $stocktake = $this->lockCounting($id);
            $lines = InventoryStocktakeLine::query()->where('stocktake_id', $stocktake->id)->lockForUpdate()->get();
            $uncounted = $lines->whereNull('counted_qty')->count();
            if ($uncounted > 0) throw ValidationException::withMessages(['lines' => "还有 {$uncounted} 行未录入实盘数。"]);

            // 适配器可能已预锁物料/余额，不持范围锁等待它们，失败后整笔盘点回滚重试。
            $items = Item::query()->whereIn('id', $lines->pluck('item_id')->unique())->orderBy('id')
                ->lock('FOR UPDATE NOWAIT')->get()->keyBy('id');
            $facts = $this->adjustmentFacts($stocktake);
            $adjustmentRows = [];
            $blocked = [];
            foreach ($lines as $line) {
                $balance = InventoryBalance::query()->whereKey($line->inventory_balance_id)->lock('FOR UPDATE NOWAIT')->firstOrFail();
                $processed = $facts->get($this->factKey($line), collect());
                if (abs(round((float) $balance->quantity_on_hand - (float) $line->book_qty - $processed->sum('change_qty'), 4)) >= 0.00005) {
                    throw ValidationException::withMessages(['lines' => '盘点账面变化与已关联的差异调整流水不一致，请核对后重试。']);
                }
                $difference = round((float) $line->counted_qty - (float) $line->book_qty, 4);
                $line->update(['difference_qty' => $difference, 'difference_amount' => round($difference * (float) $line->unit_cost, 4)]);
                $remaining = round((float) $line->counted_qty - (float) $balance->quantity_on_hand, 4);
                if (abs($remaining) < 0.00005) continue;
                $item = $items->get($line->item_id);
                if ($line->count_mode !== 'quantity') {
                    if (abs($processed->sum('change_qty')) >= 0.00005) {
                        throw ValidationException::withMessages(['lines' => "{$item->item_code}（批次 {$line->batch_no}）已有部分手工差异调整，不能再按逐件结果自动处理，请核对后取消重盘或手工处理剩余差异。"]);
                    }
                    array_push($adjustmentRows, ...$this->entries->adjustmentLines($line, $item, $remaining));
                    continue;
                }
                if ($item && ($item->serialTrackingMode() !== 'none' || $item->materialManagementMode() !== 'quantity')) {
                    // 历史盘点单（逐件快照上线前开盘）仍需手工处理。
                    $blocked[] = $item->item_code.'（'.$line->batch_no.'）';
                    continue;
                }
                $adjustmentRows[] = [
                    'item_id' => $line->item_id, 'warehouse_id' => $line->warehouse_id, 'location_id' => $line->location_id,
                    'batch_no' => $line->batch_no, 'unit_id' => $line->unit_id, 'change_qty' => $remaining, 'remark' => $line->remark,
                ];
            }
            if ($blocked) {
                throw ValidationException::withMessages(['lines' => '以下物料按序列号/实物管理，差异须先用“手工调整（原因：盘点差异）”逐个处理后再完成盘点：'.implode('、', $blocked)]);
            }

            $adjustmentId = null;
            if ($adjustmentRows) {
                $adjustment = $this->adjustments->save([
                    'adjustment_date' => now()->toDateString(),
                    'reason' => StocktakeFreezeGuard::EXEMPT_ADJUSTMENT_REASON,
                    'remark' => "盘点单 {$stocktake->stocktake_no} 差异",
                    'items' => $adjustmentRows,
                ], null, $operatorId);
                $this->adjustments->submit($adjustment->id, $operatorId);
                $this->posting->postAdjustment($adjustment->id, $operatorId);
                $adjustmentId = $adjustment->id;
            }

            $facts = $this->adjustmentFacts($stocktake);
            foreach ($lines as $line) {
                $processed = $facts->get($this->factKey($line), collect());
                if (abs(round((float) $line->difference_qty - $processed->sum('change_qty'), 4)) >= 0.00005) {
                    throw ValidationException::withMessages(['lines' => '盘点差异与实际调整数量不一致，不能完成盘点。']);
                }
                $line->update(['difference_amount' => $processed->reduce(fn ($sum, $fact) => bcadd($sum, (string) $fact->cost_amount, 4), '0')]);
            }
            $diffs = $lines->pluck('difference_qty');
            $adjustmentId ??= $facts->flatten(1)->first()?->adjustment_id;
            $stocktake->update([
                'stocktake_status' => 'completed',
                'adjustment_id' => $adjustmentId,
                'difference_count' => $diffs->filter(fn ($q) => abs((float) $q) >= 0.00005)->count(),
                'gain_qty' => round($diffs->filter(fn ($q) => $q > 0)->sum(), 4),
                'loss_qty' => round(abs($diffs->filter(fn ($q) => $q < 0)->sum()), 4),
                'difference_amount' => $lines->reduce(fn ($sum, $line) => bcadd($sum, (string) $line->difference_amount, 4), '0'),
                'completed_by' => $operatorId,
                'completed_at' => now(),
                'business_version' => (int) $stocktake->business_version + 1,
            ]);
            return $stocktake->fresh();
        });
    }

    public function cancel(int $id, ?int $operatorId): InventoryStocktake
    {
        return $this->transaction(function () use ($id, $operatorId): InventoryStocktake {
            $stocktake = $this->lockCounting($id);
            $stocktake->update(['stocktake_status' => 'cancelled', 'cancelled_by' => $operatorId, 'cancelled_at' => now(),
                'business_version' => (int) $stocktake->business_version + 1]);
            return $stocktake->fresh();
        });
    }

    private function lockCounting(int $id): InventoryStocktake
    {
        $warehouseId = InventoryStocktake::query()->whereKey($id)->value('warehouse_id');
        if ($warehouseId) Warehouse::query()->whereKey($warehouseId)->lockForUpdate()->firstOrFail();
        $stocktake = InventoryStocktake::query()->lockForUpdate()->findOrFail($id);
        if ($stocktake->stocktake_status !== 'counting') {
            throw ValidationException::withMessages(['status' => '只有盘点中的单据可以操作。']);
        }
        return $stocktake;
    }

    private function factKey(object $row): string
    {
        return $row->item_id.':'.$row->warehouse_id.':'.$row->location_id.':'.$row->batch_no;
    }

    private function adjustmentFacts(InventoryStocktake $stocktake): \Illuminate\Support\Collection
    {
        $transactionIds = DB::table('erp_inventory_posting_logs')->where('source_type', 'inventory_stocktake')
            ->where('source_id', $stocktake->id)->where('transaction_type', 'stocktake_difference')
            ->where('posting_status', 'posted')->lock('FOR UPDATE NOWAIT')->pluck('transaction_id')->unique();
        if ($transactionIds->isEmpty()) return collect();
        return DB::table('erp_inventory_transaction_items as fact')
            ->join('erp_inventory_transactions as transaction', 'transaction.id', '=', 'fact.transaction_id')
            ->whereIn('transaction.id', $transactionIds)->where('transaction.posting_status', 'posted')
            ->where('transaction.source_type', 'inventory_adjustment')->where('transaction.transaction_type', 'manual_adjustment')
            ->lock('FOR UPDATE NOWAIT')->get(['fact.*', 'transaction.source_id as adjustment_id'])
            ->groupBy(fn ($row) => $this->factKey($row));
    }

    private function transaction(callable $callback): InventoryStocktake
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction($callback, 5);
            } catch (QueryException $exception) {
                if ((int) ($exception->errorInfo[1] ?? 0) !== 3572) throw $exception;
                if ($attempt >= 5) throw ValidationException::withMessages(['stock' => '库存正在变动，本次盘点操作未生效，请稍后重试。']);
                usleep(50000);
            }
        }
    }
}
