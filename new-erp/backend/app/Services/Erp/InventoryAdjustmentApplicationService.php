<?php

namespace App\Services\Erp;

use App\Models\Erp\ApprovalTask;
use App\Models\Erp\InventoryAdjustment;
use App\Models\Erp\InventoryAdjustmentItem;
use App\Models\Erp\InventoryAdjustmentSerial;
use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventorySerial;
use App\Models\Erp\InventoryStocktake;
use App\Models\Erp\Item;
use App\Services\Erp\ApprovalIntegrations\InventoryAdjustmentApprovalIntegration;
use App\Services\Erp\Inventory\StocktakeFreezeGuard;
use App\Services\Erp\Inventory\WarehouseInventoryPosting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryAdjustmentApplicationService
{
    public const REASONS = [
        'stocktaking_difference' => '盘点差异',
        'quality_recheck' => '质检复核修正',
        'warehouse_correction' => '仓储录入修正',
        'system_correction' => '系统数据修正',
        'other' => '其他',
    ];

    public static function reasonLabel(?string $reason): ?string
    {
        return self::REASONS[$reason] ?? $reason;
    }

    public function __construct(private readonly MaterialPhysicalService $physicalMaterials) {}

    public function save(array $payload, ?int $id = null, ?int $operatorId = null, bool $allowCostEdits = true): InventoryAdjustment
    {
        return DB::transaction(function () use ($payload, $id, $operatorId, $allowCostEdits): InventoryAdjustment {
            $adjustment = $id
                ? InventoryAdjustment::query()->lockForUpdate()->findOrFail($id)
                : null;
            if ($adjustment && $adjustment->adjustment_status !== 'draft') {
                throw ValidationException::withMessages(['status' => '只有草稿调整单可以编辑。']);
            }
            if (! $allowCostEdits) $this->preservePhysicalCosts($payload, $adjustment);

            if (!$adjustment) {
                $adjustment = InventoryAdjustment::create([
                    // 单号只由服务端编号规则生成，不接受客户端传入，避免重复或伪造单号。
                    'adjustment_no' => app(DocumentNumberService::class)->next('inventory_adjustment', 'IA'),
                    'adjustment_status' => 'draft',
                    'created_by' => $operatorId,
                    'adjustment_date' => $payload['adjustment_date'] ?? now()->toDateString(),
                    'reason' => $payload['reason'],
                    'remark' => $payload['remark'] ?? null,
                ]);
            } else {
                $adjustment->update([
                    'adjustment_date' => $payload['adjustment_date'] ?? $adjustment->adjustment_date,
                    'reason' => $payload['reason'],
                    'remark' => $payload['remark'] ?? null,
                ]);
                InventoryAdjustmentItem::query()->where('adjustment_id', $adjustment->id)->delete();
            }

            foreach ($payload['items'] as $line) {
                $item = Item::query()->findOrFail($line['item_id']);
                $balance = InventoryBalance::query()
                    ->where('item_id', $line['item_id'])
                    ->where('warehouse_id', $line['warehouse_id'])
                    ->where('location_id', $line['location_id'])
                    ->where('batch_no', $line['batch_no'])
                    ->lockForUpdate()
                    ->first();
                if (!$balance) {
                    throw ValidationException::withMessages(['items' => '库存调整必须基于真实库存余额行，请先选择已有 Item + 仓库 + 库位 + 批次。']);
                }
                if ((float) $balance->quantity_on_hand + (float) $line['change_qty'] < 0
                    || (float) $balance->quantity_available + (float) $line['change_qty'] < 0) {
                    throw ValidationException::withMessages(['stock' => '调整后不能出现负库存，请重新输入数量。']);
                }
                $serialEntries = $this->validatedSerialEntries($item, $balance, $line, $adjustment->id);
                $adjustmentItem = InventoryAdjustmentItem::create([
                    'adjustment_id' => $adjustment->id,
                    'item_id' => $line['item_id'],
                    'warehouse_id' => $line['warehouse_id'],
                    'location_id' => $line['location_id'],
                    'batch_no' => $line['batch_no'],
                    'unit_id' => $line['unit_id'] ?? null,
                    'change_qty' => $line['change_qty'],
                    'remark' => $line['remark'] ?? null,
                ]);
                foreach ($serialEntries as $entry) {
                    InventoryAdjustmentSerial::create([
                        'adjustment_item_id' => $adjustmentItem->id,
                        'inventory_serial_id' => $entry['inventory_serial_id'],
                        'serial_no' => $entry['serial_no'],
                        'direction' => $entry['direction'],
                        'number_source' => $entry['number_source'],
                    ]);
                }
                $this->physicalMaterials->replaceAdjustmentEntries(
                    $adjustmentItem,
                    $item,
                    $balance,
                    $line['physical_entries'] ?? [],
                );
            }

            return $adjustment->fresh(['items.item.unit', 'items.warehouse', 'items.location', 'items.serials', 'items.physicalEntries']);
        }, 5);
    }

    private function preservePhysicalCosts(array &$payload, ?InventoryAdjustment $adjustment): void
    {
        $keyOf = function (array $line, array $dimensions): string {
            $clean = [];
            foreach (['length_mm', 'width_mm', 'thickness_mm', 'nominal_thickness_mm'] as $key) {
                if (isset($dimensions[$key]) && $dimensions[$key] !== '') $clean[$key] = bcadd((string) $dimensions[$key], '0', 2);
            }
            return json_encode([(int) $line['item_id'], (int) $line['warehouse_id'], (int) $line['location_id'],
                (string) $line['batch_no'], $clean], JSON_THROW_ON_ERROR);
        };
        $stored = []; $entryKeys = []; $usedIds = [];
        if ($adjustment) {
            foreach ($adjustment->items()->orderBy('id')->lockForUpdate()->get() as $line) {
                if ((float) $line->change_qty <= 0) continue;
                foreach ($line->physicalEntries()->orderBy('id')->lockForUpdate()->get() as $entry) {
                    $key = $keyOf($line->toArray(), $entry->dimensions ?? []);
                    $stored[$key][(int) $entry->id] = (string) $entry->total_cost;
                    $entryKeys[(int) $entry->id] = $key;
                }
            }
        }
        foreach ($payload['items'] as &$line) {
            if ((float) $line['change_qty'] <= 0) continue;
            foreach ($line['physical_entries'] ?? [] as $index => $entry) {
                $key = $keyOf($line, $entry['dimensions'] ?? []);
                $costs = $stored[$key] ?? [];
                // Hidden costs may be reused only for the same source and dimensions, never moved by a client array index.
                $entryId = (int) ($entry['id'] ?? 0);
                if ($entryId && (($entryKeys[$entryId] ?? null) !== $key || isset($usedIds[$entryId]))) {
                    throw ValidationException::withMessages(['physical_entries' => '盘盈实物明细与原调整单不一致，请刷新后重新核对。']);
                }
                if (! $entryId && ($costs === [] || count(array_unique($costs)) !== 1)) {
                    throw ValidationException::withMessages(['physical_entries' => '新增或变更盘盈实物需要查看库存金额与成本权限；请由有权限的人员核对成本。']);
                }
                $entryId = $entryId ?: array_key_first($costs);
                $line['physical_entries'][$index]['total_cost'] = $stored[$key][$entryId];
                $usedIds[$entryId] = true;
                unset($stored[$key][$entryId]);
            }
        }
        unset($line);
    }

    public function submit(int $id, object $initiator, bool $canPost = false): InventoryAdjustment
    {
        return DB::transaction(function () use ($id, $initiator, $canPost): InventoryAdjustment {
            if (! (int) ($initiator->legacy_id ?? 0)) {
                throw ValidationException::withMessages(['initiator' => '库存调整提交缺少发起人，不能启动审批。']);
            }
            $operatorId = (int) $initiator->legacy_id;
            $this->assertNoActiveApproval($id);
            $adjustment = $this->prepareSubmission($id, $operatorId);
            $task = app(InventoryAdjustmentApprovalIntegration::class)->submitted($adjustment, $initiator);
            // 只有引擎明确 BYPASS 且提交人具备过账权限才合并办理；任何过账失败都回滚本次提交。
            // 过账服务反向依赖本服务，需在用例执行时解析，避免构造器循环依赖。
            if (! $task && $canPost) app(WarehouseInventoryPosting::class)->postAdjustment($id, $operatorId);

            $result = $adjustment->fresh(['items.serials', 'items.physicalEntries']);
            $this->attachApprovalState([$result]);
            return $result;
        }, 5);
    }

    public function postStocktakeDifference(InventoryStocktake $stocktake, array $items, ?int $operatorId): InventoryAdjustment
    {
        return DB::transaction(function () use ($stocktake, $items, $operatorId): InventoryAdjustment {
            $stocktake = InventoryStocktake::query()->lockForUpdate()->findOrFail($stocktake->id);
            if ($stocktake->stocktake_status !== 'counting') {
                throw ValidationException::withMessages(['stocktake' => '只有进行中的盘点可以登记差异。']);
            }
            // 完成盘点本身已是正式库存动作，只对本事务新建的派生单直接过账，不给已有手工调整免审批入口。
            $adjustment = $this->save([
                'adjustment_date' => now()->toDateString(),
                'reason' => StocktakeFreezeGuard::EXEMPT_ADJUSTMENT_REASON,
                'remark' => "盘点单 {$stocktake->stocktake_no} 差异",
                'items' => $items,
            ], null, $operatorId);
            $this->prepareSubmission($adjustment->id, $operatorId);
            app(WarehouseInventoryPosting::class)->postAdjustment($adjustment->id, $operatorId);
            return $adjustment->fresh(['items.serials', 'items.physicalEntries']);
        }, 5);
    }

    private function prepareSubmission(int $id, ?int $operatorId): InventoryAdjustment
    {
        $adjustment = InventoryAdjustment::query()->with(['items.item', 'items.serials', 'items.physicalEntries'])->lockForUpdate()->findOrFail($id);
        if ($adjustment->adjustment_status !== 'draft') {
            throw ValidationException::withMessages(['status' => '只有草稿调整单可以提交。']);
        }
        if (! $adjustment->items()->exists()) {
            throw ValidationException::withMessages(['items' => '调整单没有明细，不能提交。']);
        }
        foreach ($adjustment->items as $line) {
            $balance = InventoryBalance::query()
                ->where('item_id', $line->item_id)
                ->where('warehouse_id', $line->warehouse_id)
                ->where('location_id', $line->location_id)
                ->where('batch_no', $line->batch_no)
                ->lockForUpdate()->firstOrFail();
            $this->validateStoredLine($line, $balance, $adjustment->id);
        }
        $adjustment->update(['adjustment_status' => 'submitted', 'submitted_by' => $operatorId, 'submitted_at' => now()]);
        return $adjustment;
    }

    public function cancel(int $id, ?int $operatorId = null, ?ApprovalTask $completionTask = null): InventoryAdjustment
    {
        return DB::transaction(function () use ($id, $operatorId, $completionTask): InventoryAdjustment {
            $this->assertNoActiveApproval($id, $completionTask);
            $adjustment = InventoryAdjustment::query()->lockForUpdate()->findOrFail($id);
            $this->assertNoActiveApproval($id, $completionTask);
            if ($adjustment->adjustment_status !== 'submitted') {
                throw ValidationException::withMessages(['status' => '只有已提交、尚未过账的调整单可以取消。']);
            }
            $adjustment->update(['adjustment_status' => 'cancelled', 'cancelled_by' => $operatorId, 'cancelled_at' => now()]);
            return $adjustment->fresh('items');
        }, 5);
    }

    public function attachApprovalState($adjustments): void
    {
        $ids = collect($adjustments)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $tasks = $ids ? $this->activeApprovalQuery($ids)->select(['id', 'business_id'])->orderByDesc('id')->get()->unique('business_id')->keyBy('business_id') : collect();
        foreach ($adjustments as $adjustment) {
            $task = $tasks->get((int) $adjustment->id);
            $adjustment->setAttribute('approval_pending', $task !== null);
            $adjustment->setAttribute('approval_task_id', $task?->id);
        }
    }

    public function assertNoActiveApproval(int $adjustmentId, ?ApprovalTask $completionTask = null): void
    {
        // 先按审批运行时的任务→单据顺序锁定；取得单据锁后还须再次当前读，防止漏掉并发提交新建的任务。
        $tasks = $this->activeApprovalQuery([$adjustmentId])->select('id')->orderBy('id')->lockForUpdate()->get();
        foreach ($tasks as $task) {
            if (! $completionTask || (int) $task->id !== (int) $completionTask->id) {
                throw ValidationException::withMessages(['approval' => '该调整单正在审批，请在审批中心处理，不能直接过账或取消。']);
            }
        }
    }

    private function activeApprovalQuery(array $adjustmentIds): Builder
    {
        return ApprovalTask::query()->where('business_object_code', 'INVENTORY_ADJUSTMENT')->whereIn('business_id', $adjustmentIds)
            ->where(function (Builder $query): void {
                // 当前运行时使用 PENDING；同时保护兼容状态及尚未释放的活动业务键，失败动作也不能被绕过。
                $query->whereIn(DB::raw('LOWER(task_status)'), ['pending', 'in_progress'])->orWhereNotNull('active_business_key');
            });
    }

    public function deleteDraft(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $adjustment = InventoryAdjustment::query()->lockForUpdate()->findOrFail($id);
            if ($adjustment->adjustment_status !== 'draft' || $adjustment->submitted_at !== null
                || $adjustment->posted_at !== null || $adjustment->cancelled_at !== null) {
                throw ValidationException::withMessages(['status' => '只有从未提交、未过账且未取消的库存调整草稿可以删除。']);
            }
            if (DB::table('erp_inventory_transactions')->where('source_type', 'inventory_adjustment')->where('source_id', $id)->exists()) {
                throw ValidationException::withMessages(['adjustment' => '该调整单已产生库存流水，不能删除。']);
            }
            $adjustment->delete();
        }, 5);
    }

    public function validateStoredLine(InventoryAdjustmentItem $line, InventoryBalance $balance, int $adjustmentId): array
    {
        $line->loadMissing(['item', 'serials']);

        $serials = $this->validatedSerialEntries($line->item, $balance, [
            'change_qty' => (float) $line->change_qty,
            'serial_entries' => $line->serials->map(fn (InventoryAdjustmentSerial $serial) => [
                'serial_no' => $serial->serial_no,
                'source' => $serial->number_source,
            ])->all(),
        ], $adjustmentId);
        $this->physicalMaterials->assertAdjustmentReady($line, $balance);
        return $serials;
    }

    private function validatedSerialEntries(Item $item, InventoryBalance $balance, array $line, ?int $ignoreAdjustmentId = null): array
    {
        $quantity = abs((float) ($line['change_qty'] ?? 0));
        $direction = (float) ($line['change_qty'] ?? 0) > 0 ? 'increase' : 'decrease';
        $mode = $item->serialTrackingMode();
        $rawEntries = collect($line['serial_entries'] ?? [])->map(function ($entry): array {
            return [
                'serial_no' => trim((string) ($entry['serial_no'] ?? '')),
                'number_source' => (string) ($entry['source'] ?? 'manual'),
            ];
        })->filter(fn (array $entry) => $entry['serial_no'] !== '')->values();

        if ($rawEntries->count() !== $rawEntries->pluck('serial_no')->unique()->count()) {
            throw ValidationException::withMessages(['serial_entries' => '设备编号或序列号存在重复，请逐件核对。']);
        }
        if ($mode === 'none' && $rawEntries->isNotEmpty()) {
            throw ValidationException::withMessages(['serial_entries' => "物料 {$item->item_code} 未启用单件编号，不允许携带序列号调整。"]);
        }

        $roundedQuantity = (int) round($quantity);
        if (($mode === 'required' || $rawEntries->isNotEmpty()) && abs($quantity - $roundedQuantity) > 0.00000001) {
            throw ValidationException::withMessages(['serial_entries' => "物料 {$item->item_code} 涉及单件编号时，调整数量必须为整数。"]);
        }
        if ($mode === 'required' && $rawEntries->count() !== $roundedQuantity) {
            throw ValidationException::withMessages(['serial_entries' => "物料 {$item->item_code} 必须逐件编号，调整 {$roundedQuantity} 件必须录入或选择 {$roundedQuantity} 个唯一编号。"]);
        }
        if ($mode === 'optional' && $rawEntries->count() > $roundedQuantity) {
            throw ValidationException::withMessages(['serial_entries' => '序列号数量不能超过调整数量。']);
        }
        if ($rawEntries->isEmpty()) {
            if ($mode === 'optional' && $direction === 'decrease') {
                $availableSerialCount = InventorySerial::query()->where('inventory_balance_id', $balance->id)->where('serial_status', 'available')->count();
                $unnumberedAvailable = max(0, (float) $balance->quantity_available - $availableSerialCount);
                if ($quantity > $unnumberedAvailable + 0.00000001) {
                    throw ValidationException::withMessages(['serial_entries' => '当前库存中的无编号可用数量不足，请选择需要减少的具体设备编号。']);
                }
            }
            return [];
        }

        $numbers = $rawEntries->pluck('serial_no');
        $reservedByAdjustment = InventoryAdjustmentSerial::query()->whereIn('serial_no', $numbers)
            ->whereHas('item.adjustment', function ($query) use ($ignoreAdjustmentId): void {
                $query->whereIn('adjustment_status', ['draft', 'submitted']);
                if ($ignoreAdjustmentId) $query->where('id', '<>', $ignoreAdjustmentId);
            })->exists();
        if ($reservedByAdjustment) {
            throw ValidationException::withMessages(['serial_entries' => '设备编号已被其他未完成调整单占用，请刷新编号列表后重新选择。']);
        }

        if ($direction === 'increase') {
            if (InventorySerial::query()->whereIn('serial_no', $numbers)->exists()) {
                throw ValidationException::withMessages(['serial_entries' => '新增编号已存在于序列号档案中，不能建立一物多码或重复实物。']);
            }
            return $rawEntries->map(fn (array $entry) => $entry + [
                'inventory_serial_id' => null,
                'direction' => 'increase',
            ])->all();
        }

        $serials = InventorySerial::query()->whereIn('serial_no', $numbers)->lockForUpdate()->get()->keyBy('serial_no');
        if ($serials->count() !== $numbers->count()) {
            throw ValidationException::withMessages(['serial_entries' => '减少调整中存在未建档的设备编号。']);
        }
        foreach ($rawEntries as $entry) {
            $serial = $serials->get($entry['serial_no']);
            if ((int) $serial->inventory_balance_id !== (int) $balance->id
                || (int) $serial->item_id !== (int) $item->id
                || (int) $serial->warehouse_id !== (int) $balance->warehouse_id
                || (int) $serial->location_id !== (int) $balance->location_id
                || (string) $serial->batch_no !== (string) $balance->batch_no
                || $serial->serial_status !== 'available') {
                throw ValidationException::withMessages(['serial_entries' => "设备编号 {$serial->serial_no} 不属于当前库存对象或不是可用状态。"]);
            }
        }

        if ($mode === 'optional') {
            $unnumberedDecrease = $quantity - $rawEntries->count();
            $availableSerialCount = InventorySerial::query()->where('inventory_balance_id', $balance->id)->where('serial_status', 'available')->count();
            $unnumberedAvailable = max(0, (float) $balance->quantity_available - $availableSerialCount);
            if ($unnumberedDecrease > $unnumberedAvailable + 0.00000001) {
                throw ValidationException::withMessages(['serial_entries' => '未选择编号的减少数量超过当前无编号可用库存，请补选具体设备编号。']);
            }
        }

        return $rawEntries->map(function (array $entry) use ($serials): array {
            return $entry + [
                'inventory_serial_id' => $serials->get($entry['serial_no'])->id,
                'direction' => 'decrease',
            ];
        })->all();
    }
}
