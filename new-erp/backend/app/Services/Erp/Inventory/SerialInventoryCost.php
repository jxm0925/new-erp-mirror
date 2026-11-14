<?php

namespace App\Services\Erp\Inventory;

use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventorySerial;
use App\Models\Erp\InventorySerialEvent;
use App\Services\Erp\CuttingDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** 已有逐件成本的编号按身份出库；其余库存只分摊扣除已知编号后的剩余金额。 */
final class SerialInventoryCost
{
    public static function outbound(InventoryBalance $balance, string $qty, array $selected, array &$shares): string
    {
        $remainingQty = (string) $balance->quantity_on_hand; $remainingCost = (string) $balance->inventory_value;
        $shares = []; $known = [];
        $resident = InventorySerial::query()->where('inventory_balance_id', $balance->id)
            ->whereIn('serial_status', ['available', ...InventoryStocktakeEntryService::LOCKED_SERIAL_STATUSES])->orderBy('id')->lockForUpdate()->pluck('id');
        $ids = array_values(array_unique(array_map('intval', $selected))); sort($ids);
        if (array_diff($ids, $resident->all()) || bccomp((string) count($ids), $qty, 8) > 0
            || bccomp(bcsub($qty, (string) count($ids), 8), bcsub($remainingQty, (string) $resident->count(), 8), 8) > 0)
            self::fail('所选编号、数量或库存位置已变化，请重新选择。');
        $events = InventorySerialEvent::query()->whereIntegerInRaw('inventory_serial_id', $resident)
            ->whereIn('event_type', ['production_receipt_in', 'inventory_posted', 'sales_return_inbound', 'production_material_returned'])
            ->orderBy('id')->lockForUpdate()->get()->keyBy('inventory_serial_id');
        $returned = DB::table('erp_sales_return_serial_identities')->whereIntegerInRaw('id', $events->where('event_type', 'sales_return_inbound')
            ->map(fn ($event) => $event->event_payload['return_serial_identity_id'] ?? 0))->whereNotNull('inventory_transaction_item_id')
            ->orderBy('id')->lockForUpdate()->get(['id', 'inventory_serial_id', 'cost_amount_snapshot'])->keyBy('id');
        $materials = DB::table('erp_mfg_material_return_lines')->whereIntegerInRaw('return_id', $events->where('event_type', 'production_material_returned')->pluck('document_id'))
            ->whereNotNull('inventory_serial_id')->orderBy('id')->lockForUpdate()->get(['return_id', 'inventory_serial_id', 'cost_amount'])
            ->keyBy(fn ($row) => $row->return_id.':'.$row->inventory_serial_id);
        foreach ($events as $id => $event) {
            $payload = $event->event_payload ?? [];
            $cost = $payload['actual_material_cost'] ?? null;
            if ($event->event_type === 'sales_return_inbound') {
                $identity = $returned->get($payload['return_serial_identity_id'] ?? 0);
                $cost = $identity && (int) $identity->inventory_serial_id === (int) $id ? $identity->cost_amount_snapshot : null;
                if ($cost === null) self::fail('退回设备缺少对应的原出库成本。');
            } elseif ($event->event_type === 'production_material_returned') {
                $cost = $materials->get($event->document_id.':'.$id)?->cost_amount;
                if ($cost === null) self::fail('生产退回的编号缺少原退料成本。');
            }
            if ($cost === null) continue;
            if (bccomp((string) $cost, '0', 4) < 0) self::fail('编号记录的库存成本为负。');
            $known[$id] = (string) $cost;
            $remainingCost = bcsub($remainingCost, (string) $cost, 4); $remainingQty = bcsub($remainingQty, '1', 8);
        }
        if (bccomp($remainingQty, '0', 8) < 0 || bccomp($remainingCost, '0', 4) < 0
            || (bccomp($remainingQty, '0', 8) === 0 && bccomp($remainingCost, '0', 4) !== 0))
            self::fail('该批库存的逐台成本与批次余额不一致，请核对原入库及领用记录。');
        $knownTake = '0'; $knownCount = 0;
        foreach ($ids as $id) if (array_key_exists($id, $known)) {
            $shares[$id] = $known[$id]; $knownTake = bcadd($knownTake, $known[$id], 4); ++$knownCount;
        }
        $unknownQty = bcsub($qty, (string) $knownCount, 8);
        $unknownTake = bccomp($unknownQty, '0', 8) > 0 ? CuttingDecimal::share($remainingCost, $remainingQty, $unknownQty) : '0.0000';
        $left = $unknownTake;
        foreach ($ids as $id) if (! array_key_exists($id, $shares)) {
            $shares[$id] = CuttingDecimal::share($left, $unknownQty, '1');
            $left = bcsub($left, $shares[$id], 4); $unknownQty = bcsub($unknownQty, '1', 8);
        }
        return bcadd($knownTake, $unknownTake, 4);
    }

    private static function fail(string $message): never { throw ValidationException::withMessages(['stock' => $message]); }
}
