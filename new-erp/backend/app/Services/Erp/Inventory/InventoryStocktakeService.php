<?php

namespace App\Services\Erp\Inventory;

use App\Domain\Finance\Money;
use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventoryBatch;
use App\Models\Erp\InventoryPostingLog;
use App\Models\Erp\InventoryTransactionItem;
use App\Models\Erp\InventoryStocktake;
use App\Models\Erp\InventoryStocktakeLine;
use App\Models\Erp\Item;
use App\Models\Erp\Location;
use App\Models\Erp\Warehouse;
use App\Services\Erp\DocumentNumberService;
use App\Services\Erp\InventoryAdjustmentApplicationService;
use App\Services\Erp\InventoryAmountVisibilityService;
use App\Services\Erp\WarehouseManagementScopeService;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 库存盘点：开盘（快照账面、冻结范围）→ 录入实盘数 → 完成（差异生成“盘点差异”调整单并过账）/ 取消。
 * 盘点本身不直接写库存，差异统一复用手工调整的校验与过账，冻结由 StocktakeFreezeGuard 在过账内核入口执行。
 */
class InventoryStocktakeService
{
    public const STATUSES = ['counting' => '盘点中', 'completed' => '已完成', 'cancelled' => '已取消'];
    public const CREATE_RULES = [
        'warehouse_id' => 'required|integer|exists:erp_warehouses,id', 'scope' => 'sometimes|required|in:location,warehouse',
        'location_id' => 'nullable|integer|exists:erp_locations,id', 'stocktake_date' => 'nullable|date', 'remark' => 'nullable|string|max:500',
    ];
    public const ADD_LINE_RULES = [
        'expected_version' => 'required|integer|min:1', 'item_id' => 'required|integer|min:1',
        'location_id' => 'required|integer|min:1', 'batch_no' => 'required|string|max:80',
        'remark' => 'nullable|string|max:500', 'unit_cost' => ['nullable', 'regex:/^\d{1,10}(?:\.\d{1,8})?$/', 'numeric', 'gt:0'],
    ];
    public const COUNT_RULES = [
        'lines' => 'required|array|min:1', 'lines.*.id' => 'required_without:lines.*.source_key|nullable|integer|min:1|distinct',
        'lines.*.source_key' => ['nullable', 'string', 'max:100', 'distinct', 'regex:/^(balance:\d+|binding:\d+:\d+)$/'], 'expected_version' => 'required|integer|min:1',
        'lines.*.counted_qty' => 'nullable|numeric|min:0', 'lines.*.remark' => 'nullable|string|max:500',
        'lines.*.unit_cost' => ['nullable', 'regex:/^\d{1,10}(?:\.\d{1,8})?$/', 'numeric', 'gt:0'],
        'lines.*.mark_all_present' => 'nullable|boolean', 'lines.*.entries' => 'nullable|array|max:500',
        'lines.*.entries.*.id' => 'required|integer', 'lines.*.entries.*.counted_present' => 'nullable|boolean',
        'lines.*.delete_entry_ids' => 'nullable|array|max:500', 'lines.*.delete_entry_ids.*' => 'integer',
        'lines.*.unnumbered_counted_qty' => 'nullable|numeric|min:0', 'lines.*.new_entries' => 'nullable|array|max:500',
        'lines.*.new_entries.*.serial_no' => 'nullable|string|max:120', 'lines.*.new_entries.*.physical_label' => 'nullable|string|max:120',
        'lines.*.new_entries.*.dimensions' => 'nullable|array',
        'lines.*.new_entries.*.dimensions.length_mm' => ['nullable', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'],
        'lines.*.new_entries.*.dimensions.width_mm' => ['nullable', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'],
        'lines.*.new_entries.*.dimensions.thickness_mm' => ['nullable', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'],
        'lines.*.new_entries.*.dimensions.nominal_thickness_mm' => ['nullable', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'],
        'lines.*.new_entries.*.total_cost' => ['nullable', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/'],
    ];

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

            $stocktake = InventoryStocktake::create([
                'stocktake_no' => $this->numbers->next('inventory_stocktake', 'PD'),
                'warehouse_id' => $warehouse->id,
                'location_id' => $locationId,
                'stocktake_status' => 'counting',
                'stocktake_date' => $data['stocktake_date'] ?? now()->toDateString(),
                'remark' => $data['remark'] ?? null,
                'line_count' => 0,
                'created_by' => $operatorId,
            ]);
            $this->snapshotScopeLines($stocktake, $warehouse);
            $result = $stocktake->fresh();
            $result->line_count = $this->logicalLineCount($result);
            return $result;
        });
    }

    private function snapshotScopeLines(InventoryStocktake $stocktake, Warehouse $warehouse): void
    {
        $locationId = $stocktake->location_id;
        // 开盘只冻结真实在账数量；绑定无库存的物料由查询实时呈现，首次录入时才保存盘点身份。
        $balances = InventoryBalance::query()->where('warehouse_id', $warehouse->id)
            ->when($locationId, fn ($query) => $query->where('location_id', $locationId))
            ->where('quantity_on_hand', '<>', 0)
            ->orderBy('location_id')->orderBy('item_id')->orderBy('batch_no')->lock('FOR UPDATE NOWAIT')->get();
        $items = Item::query()->whereIn('id', $balances->pluck('item_id')->unique())->orderBy('id')->lock('FOR UPDATE NOWAIT')->get()->keyBy('id');
        $written = 0;
        foreach ($balances as $balance) {
            $item = $items->get($balance->item_id);
            $line = $stocktake->lines()->create([
                'inventory_balance_id' => $balance->id, 'item_id' => $balance->item_id,
                'warehouse_id' => $balance->warehouse_id, 'location_id' => $balance->location_id,
                'batch_no' => $balance->batch_no, 'unit_id' => $balance->unit_id, 'book_qty' => $balance->quantity_on_hand,
                'unit_cost' => $item && bccomp((string) $balance->quantity_on_hand, '0', 4) === 0
                    ? $this->discoveredUnitCost($item, $balance, $this->entries->countMode($item), null) : ($balance->average_unit_cost ?? 0),
                'count_mode' => $item ? $this->entries->countMode($item) : 'quantity',
            ]);
            if ($line->count_mode !== 'quantity') $written += $this->entries->snapshot($line, $balance, $written);
        }
        $stocktake->update(['line_count' => $balances->count()]);
    }

    /** 尚未保存的来源不能重建开盘时的逐件状态和成本，开盘后已过账的批次必须先核对历史。 */
    private function assertMissingSnapshotUnchanged(InventoryStocktake $stocktake, InventoryBalance $balance): void
    {
        $error = $this->sourceErrors($stocktake, collect([$balance]))[$this->factKey($balance)] ?? null;
        if ($error) throw ValidationException::withMessages(['lines' => $error]);
    }

    private function sourceErrors(InventoryStocktake $stocktake, \Illuminate\Support\Collection $balances): array
    {
        if ($balances->isEmpty()) return [];
        $errors = [];
        $message = fn ($balance) => '批次 '.$balance->batch_no.' 在开盘后已有过账或无法确认开盘账面，请先核对历史流水。';
        foreach ($balances as $balance) {
            if (! $stocktake->created_at || ($balance->last_transaction_at && $balance->last_transaction_at->greaterThanOrEqualTo($stocktake->created_at))) {
                $errors[$this->factKey($balance)] = $message($balance);
            }
        }
        $remaining = $balances->reject(fn ($balance) => isset($errors[$this->factKey($balance)]));
        if ($remaining->isEmpty()) return $errors;
        $changed = InventoryTransactionItem::query()->where('warehouse_id', $stocktake->warehouse_id)
            ->where(function ($pairs) use ($remaining) {
                foreach ($remaining as $balance) $pairs->orWhere(fn ($q) => $q->where('item_id', $balance->item_id)
                    ->where('location_id', $balance->location_id)->where('batch_no', $balance->batch_no));
            })
            ->whereHas('transaction', fn ($q) => $q->where('posting_status', 'posted'))
            ->where(fn ($q) => $q->where('created_at', '>=', $stocktake->created_at)->orWhereHas('transaction', fn ($transaction) => $transaction
                ->where(fn ($time) => $time->whereNull('posted_at')->orWhere('posted_at', '>=', $stocktake->created_at))))
            ->distinct()->get(['item_id', 'warehouse_id', 'location_id', 'batch_no']);
        foreach ($changed as $balance) $errors[$this->factKey($balance)] = $message($balance);
        return $errors;
    }

    /** 当前摆放关系按物料、库位精确关联，不能把两组 ID 分别筛选后拼成不存在的组合。 */
    private function boundSources(InventoryStocktake $stocktake): \Illuminate\Database\Query\Builder
    {
        return DB::table('erp_item_storage_locations as binding')
            ->join('erp_locations as location', 'location.id', '=', 'binding.location_id')
            ->join('erp_warehouses as warehouse', 'warehouse.id', '=', 'location.warehouse_id')
            ->join('erp_items as item', 'item.id', '=', 'binding.item_id')
            ->where('location.warehouse_id', $stocktake->warehouse_id)
            ->when($stocktake->location_id, fn ($q) => $q->where('location.id', $stocktake->location_id))
            ->whereIn('location.status', ['enabled', 'active'])->whereIn('item.status', ['enabled', 'active'])
            ->where('item.is_stock_item', true)->where('item.item_type', '<>', 'service')
            ->whereColumn('item.management_scope', 'warehouse.management_scope')
            ->where(fn ($q) => $q->whereNull('item.manufacturing_strategy')->orWhere('item.manufacturing_strategy', '<>', 'phantom'));
    }

    /** 已保存行优先；盘点中才联查当前绑定和真实余额，历史单只读冻结事实。此查询没有写入副作用。 */
    private function scopeRows(InventoryStocktake $stocktake): \Illuminate\Database\Query\Builder
    {
        $fields = ['id', 'stocktake_id', 'inventory_balance_id', 'item_id', 'warehouse_id', 'location_id', 'batch_no', 'unit_id',
            'count_mode', 'book_qty', 'unnumbered_book_qty', 'unnumbered_counted_qty', 'counted_qty', 'difference_qty', 'unit_cost',
            'difference_amount', 'counted_by', 'counted_at', 'remark', 'created_at', 'updated_at'];
        $saved = DB::table('erp_inventory_stocktake_lines as line')->where('line.stocktake_id', $stocktake->id)
            ->select(array_map(fn ($field) => 'line.'.$field, $fields))->selectRaw("CONCAT('balance:', line.inventory_balance_id) AS source_key, 1 AS is_snapshot");
        if ($stocktake->stocktake_status !== 'counting') return $saved;
        $mode = "CASE WHEN item.material_management_mode = 'physical' THEN 'physical' WHEN COALESCE(NULLIF(item.serial_tracking_mode, ''), CASE WHEN item.is_serial_managed = 1 THEN 'required' ELSE 'none' END) IN ('required', 'optional') THEN 'serial' ELSE 'quantity' END";
        $tail = 'NULL AS unnumbered_book_qty, NULL AS unnumbered_counted_qty, NULL AS counted_qty, NULL AS difference_qty, 0 AS unit_cost,
            NULL AS difference_amount, NULL AS counted_by, NULL AS counted_at, NULL AS remark, NULL AS created_at, NULL AS updated_at';
        $balances = DB::table('erp_inventory_balances as balance')->join('erp_items as item', 'item.id', '=', 'balance.item_id')
            ->where('balance.warehouse_id', $stocktake->warehouse_id)
            ->when($stocktake->location_id, fn ($q) => $q->where('balance.location_id', $stocktake->location_id))
            ->where(fn ($q) => $q->where('balance.quantity_on_hand', '<>', 0)->orWhereExists($this->boundSources($stocktake)->selectRaw('1')
                ->whereColumn('binding.item_id', 'balance.item_id')->whereColumn('binding.location_id', 'balance.location_id')))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('erp_inventory_stocktake_lines as existing')
                ->where('existing.stocktake_id', $stocktake->id)->whereColumn('existing.inventory_balance_id', 'balance.id'))
            ->selectRaw("NULL AS id, ? AS stocktake_id, balance.id AS inventory_balance_id, balance.item_id, balance.warehouse_id, balance.location_id,
                balance.batch_no, balance.unit_id, {$mode} AS count_mode, balance.quantity_on_hand AS book_qty, {$tail},
                CONCAT('balance:', balance.id) AS source_key, 0 AS is_snapshot", [$stocktake->id]);
        $bindings = $this->boundSources($stocktake)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('erp_inventory_balances as existing')
                ->where('existing.warehouse_id', $stocktake->warehouse_id)->whereColumn('existing.item_id', 'binding.item_id')->whereColumn('existing.location_id', 'binding.location_id'))
            ->selectRaw("NULL AS id, ? AS stocktake_id, NULL AS inventory_balance_id, binding.item_id, location.warehouse_id, binding.location_id,
                CONCAT(?, '-L', binding.location_id, '-I', binding.item_id) AS batch_no, item.unit_id, {$mode} AS count_mode, 0 AS book_qty, {$tail},
                CONCAT('binding:', binding.item_id, ':', binding.location_id) AS source_key, 0 AS is_snapshot", [$stocktake->id, $stocktake->stocktake_no]);
        return $saved->unionAll($balances)->unionAll($bindings);
    }

    private function sourceLineQuery(InventoryStocktake $stocktake): \Illuminate\Database\Eloquent\Builder
    {
        return InventoryStocktakeLine::query()->fromSub($this->scopeRows($stocktake), 'erp_inventory_stocktake_lines');
    }

    /** 开单前只读查看当前库位物料，不建立盘点单、零余额或预览批次。 */
    public function previewLocation(int $warehouseId, int $locationId, array $filters): LengthAwarePaginator
    {
        if (! Warehouse::query()->whereKey($warehouseId)->whereIn('status', ['enabled', 'active'])->exists()) {
            throw ValidationException::withMessages(['warehouse_id' => '仓库不存在或已停用。']);
        }
        if (! Location::query()->whereKey($locationId)->where('warehouse_id', $warehouseId)->whereIn('status', ['enabled', 'active'])->exists()) {
            throw ValidationException::withMessages(['location_id' => '库位已停用或不属于所选仓库。']);
        }
        $scope = new InventoryStocktake(['id' => 0, 'warehouse_id' => $warehouseId, 'location_id' => $locationId, 'stocktake_status' => 'counting']);
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        return $this->sourceLineQuery($scope)->with([InventoryStocktakeLine::ITEM_RELATION, 'unit:id,unit_name'])
            ->select(['id', 'source_key', 'inventory_balance_id', 'item_id', 'warehouse_id', 'location_id', 'unit_id', 'book_qty'])
            ->selectRaw("CASE WHEN inventory_balance_id IS NULL THEN '' ELSE batch_no END AS batch_no")
            ->when($keyword !== '', fn ($q) => $q->where(fn ($search) => $search->where('batch_no', 'like', '%'.$keyword.'%')
                ->orWhereHas('item', fn ($item) => $item->where('item_code', 'like', '%'.$keyword.'%')->orWhere('item_name', 'like', '%'.$keyword.'%')->orWhere('spec', 'like', '%'.$keyword.'%'))))
            ->orderBy('item_id')->orderBy('batch_no')->orderBy('source_key')
            ->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 10))), ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)));
    }

    public function paginateLines(InventoryStocktake $stocktake, array $filters, int $perPage = 10): LengthAwarePaginator
    {
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        $paginator = $this->sourceLineQuery($stocktake)->with([InventoryStocktakeLine::ITEM_RELATION, 'location:id,location_code,location_name', 'unit:id,unit_name'])
            ->when($filters['line_id'] ?? null, fn ($q, $id) => $q->where('id', $id))
            ->when($filters['source_key'] ?? null, fn ($q, $key) => $q->where('source_key', $key))
            ->when($filters['location_id'] ?? null, fn ($q, $id) => $q->where('location_id', $id))
            ->when($keyword !== '', fn ($q) => $q->where(fn ($search) => $search->where('batch_no', 'like', '%'.$keyword.'%')
                ->orWhereHas('item', fn ($item) => $item->where('item_code', 'like', '%'.$keyword.'%')->orWhere('item_name', 'like', '%'.$keyword.'%')->orWhere('spec', 'like', '%'.$keyword.'%'))))
            ->when(($filters['line_status'] ?? null) === 'uncounted', fn ($q) => $q->whereNull('counted_qty'))
            ->when(($filters['line_status'] ?? null) === 'diff', fn ($q) => $q->withDifferences())
            ->orderBy('location_id')->orderBy('item_id')->orderBy('batch_no')->orderBy('source_key')
            ->paginate(min(100, max(1, $perPage)), ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)));
        $stats = $this->entries->stats($paginator->getCollection()->filter(fn ($line) => $line->id && $line->count_mode !== 'quantity')->pluck('id')->all());
        $balances = InventoryBalance::query()->whereIn('id', $paginator->getCollection()->pluck('inventory_balance_id')->filter()->unique())->get()->keyBy('id');
        $virtual = $paginator->getCollection()->filter(fn ($line) => ! $line->id);
        $items = Item::query()->whereIn('id', $virtual->pluck('item_id')->unique())->get()->keyBy('id');
        $errors = $this->sourceErrors($stocktake, $balances->only($virtual->pluck('inventory_balance_id')->filter()->all()));
        $sourceStats = $this->entries->previewStatsFor($virtual->where('count_mode', '<>', 'quantity')->reject(fn ($line) => isset($errors[$this->factKey($line)])));
        foreach ($paginator->getCollection() as $line) {
            $balance = $balances->get($line->inventory_balance_id);
            if (! $line->id) $this->prepareSourceLine($line, $items->get($line->item_id), $balance, $errors[$this->factKey($line)] ?? null, $sourceStats[$line->source_key] ?? null);
            $line->item?->setAttribute('cutting_mode', $line->item->cuttingMode());
            $line->setAttribute('entry_stats', $line->count_mode === 'quantity' ? null : ($line->entry_stats ?? $stats[$line->id] ?? $this->entries->emptyStats()));
            $line->setAttribute('unit_cost_editable', ! $line->source_error && $this->canEditUnitCost($line, $balance));
        }
        return $paginator;
    }

    private function prepareSourceLine(InventoryStocktakeLine $line, ?Item $item, ?InventoryBalance $balance, ?string $error, ?array $preview): void
    {
        if (! $item || ($line->inventory_balance_id && ! $balance)) $error = '物料或库存来源已变化，请刷新后核对。';
        if ($error) {
            $line->book_qty = null;
            $line->unit_cost = null;
            $line->setAttribute('source_error', $error);
            return;
        }
        $balance ??= new InventoryBalance(['item_id' => $line->item_id, 'warehouse_id' => $line->warehouse_id, 'location_id' => $line->location_id,
                'batch_no' => $line->batch_no, 'quantity_on_hand' => 0, 'average_unit_cost' => 0]);
        $line->unit_cost = bccomp((string) $line->book_qty, '0', 4) === 0
            ? $this->discoveredUnitCost($item, $balance, $line->count_mode, null) : ($balance->average_unit_cost ?? 0);
        if ($line->count_mode !== 'quantity') {
            $line->setAttribute('entry_stats', $preview['stats']);
            $line->unnumbered_book_qty = $preview['unnumbered_book_qty'];
        }
    }

    public function sourceEntries(InventoryStocktake $stocktake, string $sourceKey, array $filters): LengthAwarePaginator
    {
        $line = $this->sourceLineQuery($stocktake)->where('source_key', $sourceKey)->first();
        if (! $line) throw ValidationException::withMessages(['source_key' => '盘点物料来源已变化或不在本单范围内，请刷新后重试。']);
        if ($line->id) return $this->entries->paginateStored($line, $filters);
        if ($line->count_mode === 'quantity') throw ValidationException::withMessages(['source_key' => '该物料按数量盘点，无逐件明细。']);
        $balance = $line->inventory_balance_id ? InventoryBalance::query()->findOrFail($line->inventory_balance_id) : new InventoryBalance;
        if ($balance->id) $this->assertMissingSnapshotUnchanged($stocktake, $balance);
        return $this->entries->paginateSource($line, $balance, $filters);
    }

    public function logicalLineCount(InventoryStocktake $stocktake): int
    {
        return $stocktake->stocktake_status === 'counting' ? $this->sourceLineQuery($stocktake)->count() : (int) $stocktake->line_count;
    }

    /** 来源键只指向服务端联查结果；实际录入才持锁建行，不能通过客户端自造物料、库位或开盘数量。 */
    private function recordSourceLine(InventoryStocktake $stocktake, string $sourceKey, ?int $operatorId): InventoryStocktakeLine
    {
        $source = $this->sourceLineQuery($stocktake)->where('source_key', $sourceKey)->first();
        if (! $source) throw ValidationException::withMessages(['source_key' => '盘点物料来源已变化或不在本单范围内，请刷新后重试。']);
        if ($source->id) return $stocktake->lines()->whereKey($source->id)->lockForUpdate()->firstOrFail();
        $item = Item::query()->whereKey($source->item_id)->lock('FOR UPDATE NOWAIT')->firstOrFail();
        $bound = $this->boundSources($stocktake)->where('binding.item_id', $source->item_id)->where('binding.location_id', $source->location_id)
            ->lock('FOR UPDATE NOWAIT')->exists();
        if ($source->inventory_balance_id) {
            $balance = InventoryBalance::query()->whereKey($source->inventory_balance_id)->where('warehouse_id', $stocktake->warehouse_id)
                ->when($stocktake->location_id, fn ($q) => $q->where('location_id', $stocktake->location_id))->lock('FOR UPDATE NOWAIT')->firstOrFail();
            if (! $bound && bccomp((string) $balance->quantity_on_hand, '0', 4) === 0) {
                throw ValidationException::withMessages(['source_key' => '该物料已无本库位绑定及实际库存，请刷新后核对。']);
            }
            $this->assertMissingSnapshotUnchanged($stocktake, $balance);
        } else {
            if (! $bound || InventoryBalance::query()->where('warehouse_id', $stocktake->warehouse_id)->where('item_id', $source->item_id)
                ->where('location_id', $source->location_id)->lock('FOR UPDATE NOWAIT')->exists()) {
                throw ValidationException::withMessages(['source_key' => '该库位的物料或批次来源已变化，请刷新后核对。']);
            }
            $balance = InventoryBalance::create(['item_id' => $item->id, 'warehouse_id' => $stocktake->warehouse_id,
                'location_id' => $source->location_id, 'batch_no' => $source->batch_no, 'unit_id' => $item->unit_id])->fresh();
        }
        $line = $stocktake->lines()->create([
            'inventory_balance_id' => $balance->id, 'item_id' => $balance->item_id, 'warehouse_id' => $balance->warehouse_id,
            'location_id' => $balance->location_id, 'batch_no' => $balance->batch_no, 'unit_id' => $balance->unit_id,
            'book_qty' => $balance->quantity_on_hand, 'count_mode' => $this->entries->countMode($item),
            'unit_cost' => bccomp((string) $balance->quantity_on_hand, '0', 4) === 0
                ? $this->discoveredUnitCost($item, $balance, $this->entries->countMode($item), null) : ($balance->average_unit_cost ?? 0),
        ]);
        if ($line->count_mode !== 'quantity') $this->entries->snapshot($line, $balance,
            DB::table('erp_inventory_stocktake_entries')->where('stocktake_id', $stocktake->id)->count());
        InventoryPostingLog::create(['source_type' => 'inventory_stocktake', 'source_id' => $stocktake->id, 'source_no' => $stocktake->stocktake_no,
            'transaction_type' => 'stocktake_line_recorded', 'posting_status' => 'counting', 'message' => '首次录入盘点物料明细 '.$line->id,
            'posted_by' => $operatorId, 'posted_at' => now()]);
        return $line;
    }

    public function addLine(int $id, array $data, object $actor): array
    {
        return $this->transaction(function () use ($id, $data, $actor): array {
            $stocktake = $this->lockCounting($id);
            InventoryTransferService::assertWarehouseScope((int) $stocktake->warehouse_id, $actor);
            if ((int) $stocktake->business_version !== (int) $data['expected_version']) {
                throw ValidationException::withMessages(['expected_version' => '盘点单已被修改，请刷新后核对明细。']);
            }
            $locationId = (int) $data['location_id'];
            if (($stocktake->location_id && (int) $stocktake->location_id !== $locationId)
                || ! Location::query()->whereKey($locationId)->where('warehouse_id', $stocktake->warehouse_id)
                    ->whereIn('status', ['enabled', 'active'])->lockForUpdate()->first()) {
                throw ValidationException::withMessages(['location_id' => '请选择本次盘点范围内的启用库位。']);
            }
            $item = Item::query()->whereKey($data['item_id'])->whereIn('status', ['enabled', 'active'])->where('is_stock_item', true)
                ->lock('FOR UPDATE NOWAIT')->first();
            if (! $item || $item->isPhantom() || $item->item_type === 'service') {
                throw ValidationException::withMessages(['item_id' => '请选择启用的库存物料。']);
            }
            app(WarehouseManagementScopeService::class)->assertWarehouseItem($item, (int) $stocktake->warehouse_id);
            $batchNo = trim($data['batch_no']);
            if ($batchNo === '') throw ValidationException::withMessages(['batch_no' => '请填写实际发现的批次。']);
            $locator = ['item_id' => $item->id, 'warehouse_id' => $stocktake->warehouse_id, 'location_id' => $locationId, 'batch_no' => $batchNo];
            $balance = InventoryBalance::query()->where($locator)->lock('FOR UPDATE NOWAIT')->first();
            if ($balance && ($stocktake->lines()->where('inventory_balance_id', $balance->id)->exists() || bccomp((string) $balance->quantity_on_hand, '0', 4) !== 0)) {
                throw ValidationException::withMessages(['batch_no' => '该库位和批次已有盘点明细或非零库存，请核对既有明细。']);
            }
            $lotId = InventoryBatch::query()->where('item_id', $item->id)->where('batch_no', $batchNo)->value('material_lot_id');
            // 零余额仅承载批次身份，数量和金额仍在完成盘点时经正式流水写入。
            $balance ??= InventoryBalance::create($locator + ['unit_id' => $item->unit_id, 'material_lot_id' => $lotId])->fresh();
            if ($lotId && (int) $balance->material_lot_id !== (int) $lotId) {
                throw ValidationException::withMessages(['batch_no' => '库存余额与已有材料批次不一致，请先核对批次来源。']);
            }
            $mode = $this->entries->countMode($item);
            $unitCost = $this->discoveredUnitCost($item, $balance, $mode, $data['unit_cost'] ?? null);
            $line = $stocktake->lines()->create(['batch_no' => $balance->batch_no] + $locator + ['inventory_balance_id' => $balance->id, 'unit_id' => $item->unit_id,
                'book_qty' => 0, 'unit_cost' => $unitCost, 'count_mode' => $mode, 'remark' => $data['remark'] ?? null]);
            if ($mode !== 'quantity') $this->entries->snapshot($line, $balance, 0);
            $stocktake->update(['line_count' => $stocktake->line_count + 1, 'business_version' => $stocktake->business_version + 1]);
            InventoryPostingLog::create(['source_type' => 'inventory_stocktake', 'source_id' => $id, 'source_no' => $stocktake->stocktake_no,
                'transaction_type' => 'stocktake_line_added', 'posting_status' => 'counting', 'message' => "补录账外盘点行 {$line->id}（{$item->item_code}，批次 {$batchNo}）",
                'posted_by' => $actor->legacy_id ?? $actor->id, 'posted_at' => now(), 'remark' => $line->remark]);
            return ['line_id' => $line->id, 'business_version' => $stocktake->business_version];
        });
    }

    private function discoveredUnitCost(Item $item, InventoryBalance $balance, string $mode, mixed $provided): string
    {
        if ($provided !== null && ! app(InventoryAmountVisibilityService::class)->canView()) {
            throw ValidationException::withMessages(['unit_cost' => '填写盘盈单价需要库存金额权限。']);
        }
        if ($mode === 'physical') {
            if ($provided !== null) throw ValidationException::withMessages(['unit_cost' => '实物成本请在逐件盘点时填写。']);
            return '0';
        }
        if (! $balance->exists) return bccomp((string) $item->standard_cost, '0', 8) > 0 ? (string) $item->standard_cost : '0';
        if ($provided !== null && $this->hasFrozenCost($balance)) {
            throw ValidationException::withMessages(['unit_cost' => '已有库存或历史入库成本的批次按账面计价，不能修改盘盈单价。']);
        }
        if ($provided !== null) return (string) $provided;
        if (bccomp((string) ($balance->average_unit_cost ?? 0), '0', 8) > 0) return (string) $balance->average_unit_cost;
        $fact = $this->historicalCost($balance);
        if ($fact && bccomp((string) $fact->cost_amount, '0', 4) >= 0) return bcdiv((string) $fact->cost_amount, (string) $fact->change_qty, 8);
        if ($balance->material_lot_id) return '0';
        if (bccomp((string) $item->standard_cost, '0', 8) > 0) return (string) $item->standard_cost;
        return '0';
    }

    private function historicalCost(InventoryBalance $balance): ?InventoryTransactionItem
    {
        return InventoryTransactionItem::query()->where('item_id', $balance->item_id)->where('batch_no', $balance->batch_no)
            ->where('material_lot_id', $balance->material_lot_id)->where('change_qty', '>', 0)
            ->whereHas('transaction', fn ($query) => $query->where('posting_status', 'posted'))
            ->orderByRaw('(warehouse_id = ? AND location_id = ?) DESC', [$balance->warehouse_id, $balance->location_id])->latest('id')->first();
    }

    private function hasFrozenCost(InventoryBalance $balance): bool
    {
        return $balance->material_lot_id || bccomp((string) $balance->quantity_on_hand, '0', 4) !== 0
            || bccomp((string) ($balance->average_unit_cost ?? 0), '0', 8) > 0 || $this->historicalCost($balance) !== null;
    }

    public function canEditUnitCost(InventoryStocktakeLine $line, ?InventoryBalance $balance = null): bool
    {
        if ($line->count_mode === 'physical' || bccomp((string) $line->book_qty, '0', 4) !== 0) return false;
        if (! $line->inventory_balance_id && ! $line->id) return true;
        $balance ??= InventoryBalance::query()->find($line->inventory_balance_id);
        return $balance && ! $this->hasFrozenCost($balance);
    }

    /** 历史免费入库仍属有效成本；首次账外盘盈必须有核定单价，不能把缺失成本当作免费。 */
    public function assertGainCost(InventoryStocktakeLine $line, InventoryBalance $balance): void
    {
        if ($line->count_mode === 'physical') return;
        if ($balance->material_lot_id && bccomp((string) $balance->quantity_on_hand, '0', 4) === 0 && ! $this->historicalCost($balance)) {
            throw ValidationException::withMessages(['unit_cost' => '该材料批次没有可追溯的入库金额，不能确定盘盈成本。']);
        }
        if (bccomp((string) $line->unit_cost, '0', 8) <= 0 && ! $this->hasFrozenCost($balance)) {
            throw ValidationException::withMessages(['unit_cost' => '批次 '.$line->batch_no.' 尚未核定盘盈单价，请由有库存金额权限的人员填写后完成。']);
        }
    }

    /** 录入实盘数：counted_qty 传 null 表示清空，可多次保存。 */
    public function saveCounts(int $id, array $lines, ?int $operatorId, int $expectedVersion): InventoryStocktake
    {
        return $this->transaction(function () use ($id, $lines, $operatorId, $expectedVersion): InventoryStocktake {
            $stocktake = $this->lockCounting($id);
            if ((int) $stocktake->business_version !== $expectedVersion) throw ValidationException::withMessages(['expected_version' => '盘点单已被其他人修改，请重新打开后核对实盘数。']);
            $rows = InventoryStocktakeLine::query()->where('stocktake_id', $stocktake->id)->whereIn('id', array_column($lines, 'id'))->lockForUpdate()->get()->keyBy('id');
            $seen = [];
            foreach ($lines as $input) {
                if (empty($input['id']) && empty($input['source_key'])) {
                    throw ValidationException::withMessages(['lines' => '请指定本单盘点明细或有效物料来源后再保存。']);
                }
                if (empty($input['id']) && ! empty($input['source_key'])
                    && ! collect(['counted_qty', 'unit_cost', 'unnumbered_counted_qty', 'mark_all_present'])->contains(fn ($key) => isset($input[$key]) && $input[$key] !== '')
                    && empty($input['entries']) && empty($input['new_entries']) && trim((string) ($input['remark'] ?? '')) === '') continue;
                $line = ! empty($input['id']) ? $rows->get((int) $input['id'])
                    : (! empty($input['source_key']) ? $this->recordSourceLine($stocktake, $input['source_key'], $operatorId) : null);
                if (! $line) throw ValidationException::withMessages(['lines' => '盘点明细不属于当前盘点单。']);
                $this->assertLineScope($stocktake, $line);
                if ((! empty($input['source_key']) && ! empty($input['id']) && $input['source_key'] !== 'balance:'.$line->inventory_balance_id) || isset($seen[$line->id])) {
                    throw ValidationException::withMessages(['lines' => '同一盘点来源不能重复录入，或来源与盘点行不一致。']);
                }
                $seen[$line->id] = true;
                if (array_key_exists('unit_cost', $input)) {
                    if (! app(InventoryAmountVisibilityService::class)->canView()) throw ValidationException::withMessages(['unit_cost' => '填写盘盈单价需要库存金额权限。']);
                    if (! $this->canEditUnitCost($line)) throw ValidationException::withMessages(['unit_cost' => '此行按已有库存或逐件实物成本计价，不能修改盘盈单价。']);
                    $line->unit_cost = $input['unit_cost'] ?? '0';
                    $line->save();
                }
                if ($line->count_mode !== 'quantity') {
                    foreach ($input['entries'] ?? [] as $index => $entry) {
                        if ((int) $entry['id'] >= 0) continue;
                        $identity = $line->count_mode === 'serial' ? 'inventory_serial_id' : 'material_physical_id';
                        $entryId = $line->entries()->where($identity, -(int) $entry['id'])->value('id');
                        if (! $entryId) throw ValidationException::withMessages(['entries' => '逐件来源已变化或不属于该盘点物料，请刷新后核对。']);
                        $input['entries'][$index]['id'] = $entryId;
                    }
                    if (array_key_exists('remark', $input)) $line->update(['remark' => $input['remark']]);
                    if (array_intersect(['mark_all_present', 'entries', 'delete_entry_ids', 'new_entries', 'unnumbered_counted_qty'], array_keys($input))) {
                        $this->entries->saveLine($line, $input, $operatorId);
                    } elseif ($line->count_mode === 'serial' && $line->counted_qty !== null) {
                        $line->update(['difference_amount' => round((float) $line->difference_qty * (float) $line->unit_cost, 4)]);
                    }
                    continue;
                }
                $counted = array_key_exists('counted_qty', $input)
                    ? ($input['counted_qty'] !== null && $input['counted_qty'] !== '' ? round((float) $input['counted_qty'], 4) : null)
                    : ($line->counted_qty === null ? null : (float) $line->counted_qty);
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
            $stocktake->line_count = $stocktake->lines()->count();
            $stocktake->save();
            $stocktake->increment('business_version');
            $result = $stocktake->fresh();
            $result->line_count = $this->logicalLineCount($result);
            return $result;
        });
    }

    /** 仓库排他锁与正式绑定维护的共享锁互斥；再用当前读检查来源，避免 RR 旧读视图漏掉开盘后的绑定。 */
    private function pendingSourcesForCompletion(InventoryStocktake $stocktake, \Illuminate\Support\Collection $lines): int
    {
        $boundPairs = $this->boundSources($stocktake)->lock('FOR UPDATE NOWAIT')->get(['binding.item_id', 'binding.location_id'])
            ->mapWithKeys(fn ($row) => [$row->item_id.':'.$row->location_id => true]);
        $recorded = $lines->pluck('inventory_balance_id')->flip();
        $balancePairs = [];
        $pending = 0;
        $balances = InventoryBalance::query()->where('warehouse_id', $stocktake->warehouse_id)
            ->when($stocktake->location_id, fn ($q) => $q->where('location_id', $stocktake->location_id))
            ->orderBy('location_id')->orderBy('item_id')->orderBy('batch_no')->lock('FOR UPDATE NOWAIT')
            ->get(['id', 'item_id', 'location_id', 'quantity_on_hand']);
        foreach ($balances as $balance) {
            $pair = $balance->item_id.':'.$balance->location_id;
            $balancePairs[$pair] = true;
            if (! $recorded->has($balance->id) && ($boundPairs->has($pair) || bccomp((string) $balance->quantity_on_hand, '0', 4) !== 0)) $pending++;
        }
        foreach ($boundPairs as $pair => $_) if (! isset($balancePairs[$pair])) $pending++;
        return $pending;
    }

    public function summary(InventoryStocktake $stocktake): array
    {
        $lines = InventoryStocktakeLine::query()->where('stocktake_id', $stocktake->id);
        $summary = (array) (clone $lines)->toBase()->selectRaw(
            'COUNT(*) AS total_lines, SUM(counted_qty IS NOT NULL) AS counted_lines, SUM(counted_qty IS NULL) AS uncounted_lines,
            SUM(GREATEST(difference_qty, 0)) AS gain_qty, SUM(GREATEST(-difference_qty, 0)) AS loss_qty, SUM(difference_amount) AS difference_amount')->first();
        $summary = array_map(fn ($value) => (float) ($value ?? 0), $summary);
        if ($stocktake->stocktake_status === 'completed') {
            return array_merge($summary, ['diff_lines' => (int) $stocktake->difference_count, 'gain_qty' => $stocktake->gain_qty,
                'loss_qty' => $stocktake->loss_qty, 'difference_amount' => $stocktake->difference_amount]);
        }
        $summary['total_lines'] = $this->logicalLineCount($stocktake);
        $summary['uncounted_lines'] = $summary['total_lines'] - $summary['counted_lines'];
        $summary['diff_lines'] = (clone $lines)->withDifferences()->count();
        // 混合盘盈盘亏不能只合计净差，补回逐件行被抵消的数量。
        $tracked = $lines->where('count_mode', '<>', 'quantity')->withDifferences()
            ->with(['item.unit', 'entries' => fn ($entries) => $entries->changed()])->get();
        foreach ($tracked as $line) {
            $gain = $this->entries->entryQuantity($line->item, $line->count_mode, $line->entries->where('is_new', true))
                + max(0, (float) $line->unnumbered_counted_qty - (float) $line->unnumbered_book_qty);
            $offset = max(0, $gain - max(0, (float) $line->difference_qty));
            $summary['gain_qty'] += $offset;
            $summary['loss_qty'] += $offset;
        }
        return $summary;
    }

    /** 保留开盘差异，仅对尚未处理的数量过账；手工与自动处理均按正式流水核对。 */
    public function complete(int $id, ?int $operatorId): InventoryStocktake
    {
        return $this->transaction(function () use ($id, $operatorId): InventoryStocktake {
            $stocktake = $this->lockCounting($id);
            $lines = InventoryStocktakeLine::query()->where('stocktake_id', $stocktake->id)->lockForUpdate()->get();
            foreach ($lines as $line) $this->assertLineScope($stocktake, $line);
            $uncounted = $lines->whereNull('counted_qty')->count() + $this->pendingSourcesForCompletion($stocktake, $lines);
            if ($uncounted > 0) throw ValidationException::withMessages(['lines' => "还有 {$uncounted} 行未录入实盘数。"]);

            // 适配器可能已预锁物料/余额，不持范围锁等待它们，失败后整笔盘点回滚重试。
            $items = Item::query()->whereIn('id', $lines->pluck('item_id')->unique())->orderBy('id')
                ->lock('FOR UPDATE NOWAIT')->get()->keyBy('id');
            $lineKeys = $lines->map(fn ($line) => $this->factKey($line))->all();
            $facts = $this->adjustmentFacts($stocktake)->only($lineKeys);
            $adjustmentRows = [];
            $blocked = [];
            foreach ($lines as $line) {
                $balance = InventoryBalance::query()->whereKey($line->inventory_balance_id)->lock('FOR UPDATE NOWAIT')->firstOrFail();
                if ($this->factKey($balance) !== $this->factKey($line)) {
                    throw ValidationException::withMessages(['lines' => '盘点库存来源与本单冻结范围不一致，请核对后重试。']);
                }
                $processed = $facts->get($this->factKey($line), collect());
                if (abs(round((float) $balance->quantity_on_hand - (float) $line->book_qty - $processed->sum('change_qty'), 4)) >= 0.00005) {
                    throw ValidationException::withMessages(['lines' => '盘点账面变化与已关联的差异调整流水不一致，请核对后重试。']);
                }
                $difference = round((float) $line->counted_qty - (float) $line->book_qty, 4);
                $line->update(['difference_qty' => $difference, 'difference_amount' => round($difference * (float) $line->unit_cost, 4)]);
                $remaining = round((float) $line->counted_qty - (float) $balance->quantity_on_hand, 4);
                $item = $items->get($line->item_id);
                if ($line->count_mode !== 'quantity') {
                    $rows = $this->entries->adjustmentLines($line, $item, $difference);
                    if (collect($rows)->contains(fn ($row) => $row['change_qty'] > 0)) $this->assertGainCost($line, $balance);
                    if ($processed->isNotEmpty()) {
                        $this->entries->assertPostedEntries($line, $rows, $processed);
                    } else {
                        array_push($adjustmentRows, ...$rows);
                    }
                    continue;
                }
                if (abs($remaining) < 0.00005) continue;
                if ($remaining > 0) $this->assertGainCost($line, $balance);
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

            $facts = $this->adjustmentFacts($stocktake)->only($lineKeys);
            foreach ($lines as $line) {
                $processed = $facts->get($this->factKey($line), collect());
                if (abs(round((float) $line->difference_qty - $processed->sum('change_qty'), 4)) >= 0.00005) {
                    throw ValidationException::withMessages(['lines' => '盘点差异与实际调整数量不一致，不能完成盘点。']);
                }
                $line->update(['difference_amount' => $processed->reduce(fn ($sum, $fact) => bcadd($sum, (string) $fact->cost_amount, 4), '0')]);
            }
            $adjustmentId ??= $facts->flatten(1)->first()?->adjustment_id;
            $posted = $facts->flatten(1);
            $stocktake->update([
                'stocktake_status' => 'completed',
                'adjustment_id' => $adjustmentId,
                'difference_count' => $lines->filter(fn ($line) => $facts->has($this->factKey($line)))->count(),
                'gain_qty' => round($posted->where('change_qty', '>', 0)->sum('change_qty'), 4),
                'loss_qty' => round(abs($posted->where('change_qty', '<', 0)->sum('change_qty')), 4),
                'difference_amount' => Money::settle($lines->reduce(fn ($sum, $line) => bcadd($sum, (string) $line->difference_amount, 4), '0')),
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
        if ((int) $stocktake->warehouse_id !== (int) $warehouseId) {
            throw ValidationException::withMessages(['warehouse_id' => '盘点仓库已变化，请刷新后核对盘点范围。']);
        }
        if ($stocktake->stocktake_status !== 'counting') {
            throw ValidationException::withMessages(['status' => '只有盘点中的单据可以操作。']);
        }
        return $stocktake;
    }

    private function assertLineScope(InventoryStocktake $stocktake, InventoryStocktakeLine $line): void
    {
        if ((int) $line->warehouse_id !== (int) $stocktake->warehouse_id
            || ($stocktake->location_id && (int) $line->location_id !== (int) $stocktake->location_id)) {
            throw ValidationException::withMessages(['lines' => '盘点明细不在本单冻结范围内，请刷新后核对。']);
        }
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

    private function transaction(callable $callback): mixed
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
