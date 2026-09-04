<?php

namespace App\Services\Erp;

use App\Models\Erp\InventoryAdjustment;
use App\Models\Erp\InventoryAdjustmentItem;
use App\Models\Erp\InventoryAdjustmentSerial;
use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventorySerial;
use App\Models\Erp\Item;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryAdjustmentApplicationService
{
    public function save(array $payload, ?int $id = null): InventoryAdjustment
    {
        return DB::transaction(function () use ($payload, $id): InventoryAdjustment {
            $adjustment = $id
                ? InventoryAdjustment::query()->lockForUpdate()->findOrFail($id)
                : null;
            if ($adjustment && $adjustment->adjustment_status !== 'draft') {
                throw ValidationException::withMessages(['status' => '只有草稿调整单可以编辑。']);
            }

            if (!$adjustment) {
                $adjustment = InventoryAdjustment::create([
                    'adjustment_no' => $payload['adjustment_no'] ?? $this->nextNo('ADJ'),
                    'adjustment_status' => 'draft',
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
            }

            return $adjustment->fresh(['items.item.unit', 'items.warehouse', 'items.location', 'items.serials']);
        }, 5);
    }

    public function submit(int $id): InventoryAdjustment
    {
        return DB::transaction(function () use ($id): InventoryAdjustment {
            $adjustment = InventoryAdjustment::query()->with(['items.item', 'items.serials'])->lockForUpdate()->findOrFail($id);
            if ($adjustment->adjustment_status !== 'draft') {
                throw ValidationException::withMessages(['status' => '只有草稿调整单可以提交。']);
            }
            if (!$adjustment->items()->exists()) {
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
            $adjustment->update(['adjustment_status' => 'submitted', 'submitted_at' => now()]);
            return $adjustment->fresh(['items.serials']);
        }, 5);
    }

    public function cancel(int $id): InventoryAdjustment
    {
        return DB::transaction(function () use ($id): InventoryAdjustment {
            $adjustment = InventoryAdjustment::query()->lockForUpdate()->findOrFail($id);
            if ($adjustment->adjustment_status !== 'submitted') {
                throw ValidationException::withMessages(['status' => '只有已提交、尚未过账的调整单可以取消。']);
            }
            $adjustment->update(['adjustment_status' => 'cancelled', 'cancelled_at' => now()]);
            return $adjustment->fresh('items');
        }, 5);
    }

    public function deleteDraft(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $adjustment = InventoryAdjustment::query()->lockForUpdate()->findOrFail($id);
            if ($adjustment->adjustment_status !== 'draft') {
                throw ValidationException::withMessages(['status' => '只有草稿调整单可以删除。']);
            }
            $adjustment->delete();
        }, 5);
    }

    private function nextNo(string $prefix): string
    {
        return $prefix.now()->format('YmdHis').random_int(100, 999);
    }

    public function validateStoredLine(InventoryAdjustmentItem $line, InventoryBalance $balance, int $adjustmentId): array
    {
        $line->loadMissing(['item', 'serials']);

        return $this->validatedSerialEntries($line->item, $balance, [
            'change_qty' => (float) $line->change_qty,
            'serial_entries' => $line->serials->map(fn (InventoryAdjustmentSerial $serial) => [
                'serial_no' => $serial->serial_no,
                'source' => $serial->number_source,
            ])->all(),
        ], $adjustmentId);
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
