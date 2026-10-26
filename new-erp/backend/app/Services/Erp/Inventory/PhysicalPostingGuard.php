<?php

namespace App\Services\Erp\Inventory;

use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventoryTransaction;
use App\Models\Erp\Item;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 实物管理物料（板材等逐张管理）的过账守卫：数量变化必须绑定已落库的正式实物动作。
 * 各业务的实物校验集中在这里，过账内核只调用 assertChange / assertAuthoritativeTotal。
 */
class PhysicalPostingGuard
{
    /** 实物总成本口径只允许来自包装用料与开料批次；返回 [包装实物总成本, 开料实物总成本]。 */
    public function assertAuthoritativeTotal(InventoryTransaction $transaction, array $line, Item $item): array
    {
        $packingPhysicalTotal = ($line['cost_source_type'] ?? null) === 'shipment_packing_physical_total';
        $cuttingPhysicalTotal = ($line['cost_source_type'] ?? null) === 'mfg_cutting_physical_total';
        if ($packingPhysicalTotal && ($transaction->source_type !== 'shipment_packing_material'
            || $transaction->transaction_type !== 'shipment_packing_material_outbound'
            || ($line['source_type'] ?? null) !== 'shipment_packing_operation'
            || $item->materialManagementMode() !== 'physical')) throw ValidationException::withMessages(['cost_amount' => '包装实物总成本仅能来自正式包装用料及实物身份。']);
        if ($cuttingPhysicalTotal && ($transaction->source_type !== 'mfg_cutting_batch'
            || $transaction->transaction_type !== 'mfg_cutting_issue_outbound'
            || ($line['physical_operation'] ?? null) !== 'mfg_cutting_issue'
            || $item->materialManagementMode() !== 'physical')) throw ValidationException::withMessages(['cost_amount' => '开料实物总成本必须来自正式开料批次及逐张来源。']);
        return [$packingPhysicalTotal, $cuttingPhysicalTotal];
    }

    public function assertChange(
        InventoryTransaction $transaction,
        array $line,
        Item $item,
        InventoryBalance $balance,
    ): void {
        $quantity = (string) $line['change_qty'];
        $operation = $line['physical_operation'] ?? null;

        if (in_array($operation, ['mfg_cutting_issue', 'mfg_cutting_remnant'], true)) {
            $batch = \App\Models\Erp\MfgCuttingBatch::query()->lockForUpdate()->find($transaction->source_id);
            $input = $batch ? collect($batch->input_lines)->firstWhere('source_key', $line['cutting_source_key'] ?? null) : null;
            $physical = $input ? DB::table('erp_material_physicals')->where('id', $input['physical_material_id'])->lockForUpdate()->first() : null;
            $valid = $batch && $batch->status === 'issued' && $input && $physical
                && $transaction->source_type === 'mfg_cutting_batch' && ($line['source_type'] ?? null) === 'mfg_cutting_batch'
                && (int) $line['source_id'] === (int) $batch->id && (int) $input['item_id'] === (int) $item->id
                && (int) $physical->item_id === (int) $item->id && (int) ($line['physical_material_id'] ?? 0) === (int) $physical->id;
            if ($valid && $operation === 'mfg_cutting_issue' && ! $batch->inventory_issue_transaction_id
                && $transaction->transaction_type === 'mfg_cutting_issue_outbound'
                && (int) $input['inventory_balance_id'] === (int) $balance->id && bccomp($quantity, '-1', 8) === 0
                && bccomp((string) $line['cost_amount'], bcsub('0', (string) $physical->total_cost, 4), 4) === 0
                && $this->atBalance((int) $physical->id, (int) $item->id, (int) $balance->id)) return;
            $remnant = $batch ? collect($batch->remnants ?? [])->firstWhere('source_key', $line['cutting_source_key'] ?? null) : null;
            if ($valid && $operation === 'mfg_cutting_remnant' && $batch->inventory_issue_transaction_id && ! $batch->inventory_return_transaction_id
                && $transaction->transaction_type === 'mfg_cutting_remnant_inbound' && $physical->status === 'CUTTING'
                && $remnant && bccomp((string) $remnant['measure_qty'], '0', 8) > 0 && bccomp($quantity, '1', 8) === 0
                && (int) $remnant['warehouse_id'] === (int) $line['warehouse_id'] && (int) $remnant['location_id'] === (int) $line['location_id']
                && bccomp((string) $remnant['cost_amount'], (string) $line['cost_amount'], 4) === 0) return;
        }

        if ($operation === 'purchase_receipt') {
            $whole = bcadd($quantity, '0', 0);
            $valid = $transaction->source_type === 'purchase_receipt'
                && $transaction->transaction_type === 'purchase_receipt_posting'
                && bccomp($quantity, '0', 8) > 0
                && bccomp($quantity, $whole, 8) === 0
                && (int) ($line['physical_entry_count'] ?? -1) === (int) $whole;
            if ($valid) return;
        }

        if (in_array($operation, ['adjustment_increase', 'adjustment_decrease'], true)) {
            $entry = DB::table('erp_inventory_adjustment_item_physicals as entry')
                ->join('erp_inventory_adjustment_items as item_line', 'item_line.id', '=', 'entry.adjustment_item_id')
                ->where('entry.id', (int) ($line['physical_entry_id'] ?? 0))
                ->where('item_line.adjustment_id', $transaction->source_id)
                ->where('item_line.item_id', $item->id)
                ->select('entry.*')
                ->lockForUpdate()
                ->first();
            $expectedDirection = $operation === 'adjustment_increase' ? 'increase' : 'decrease';
            $expectedQty = $operation === 'adjustment_increase' ? '1' : '-1';
            $valid = $transaction->source_type === 'inventory_adjustment'
                && $transaction->transaction_type === 'manual_adjustment'
                && $entry
                && $entry->direction === $expectedDirection
                && !$entry->inventory_transaction_item_id
                && bccomp($quantity, $expectedQty, 8) === 0;
            if ($valid && $expectedDirection === 'increase' && !$entry->physical_material_id) return;
            if ($valid && $expectedDirection === 'decrease'
                && (int) $entry->physical_material_id === (int) ($line['physical_material_id'] ?? 0)
                && $this->atBalance((int) $entry->physical_material_id, (int) $item->id, (int) $balance->id)) return;
        }

        if ($operation === 'purchase_return') {
            $link = DB::table('erp_purchase_return_item_physicals as link')
                ->join('erp_purchase_return_items as item_line', 'item_line.id', '=', 'link.purchase_return_item_id')
                ->where('link.id', (int) ($line['physical_link_id'] ?? 0))
                ->where('item_line.return_id', $transaction->source_id)
                ->where('item_line.item_id', $item->id)
                ->select('link.*')
                ->lockForUpdate()
                ->first();
            if ($transaction->source_type === 'purchase_return'
                && $transaction->transaction_type === 'purchase_return_outbound'
                && $link && !$link->inventory_transaction_item_id
                && (int) $link->physical_material_id === (int) ($line['physical_material_id'] ?? 0)
                && bccomp($quantity, '-1', 8) === 0
                && $this->atBalance((int) $link->physical_material_id, (int) $item->id, (int) $balance->id)) return;
        }

        if (in_array($operation, ['transfer_out', 'transfer_in'], true)) {
            $transfer = DB::table('erp_material_physical_transfers')
                ->where('id', (int) ($line['physical_transfer_id'] ?? 0))
                ->where('physical_material_id', (int) ($line['physical_material_id'] ?? 0))
                ->where('status', 'PENDING')
                ->lockForUpdate()
                ->first();
            $physical = $transfer ? DB::table('erp_material_physicals')->where('id', $transfer->physical_material_id)->lockForUpdate()->first() : null;
            $validBase = $transfer && $physical
                && $transaction->source_type === 'material_physical_transfer'
                && $transaction->transaction_type === 'physical_material_transfer'
                && (int) $transaction->source_id === (int) $transfer->id
                && (int) $physical->item_id === (int) $item->id
                && $physical->status === 'AVAILABLE';
            if ($validBase && $operation === 'transfer_out'
                && bccomp($quantity, '-1', 8) === 0
                && (int) $transfer->source_inventory_balance_id === (int) $balance->id
                && $this->atBalance((int) $physical->id, (int) $item->id, (int) $balance->id)) return;
            if ($validBase && $operation === 'transfer_in'
                && bccomp($quantity, '1', 8) === 0
                && (int) $transfer->target_warehouse_id === (int) $line['warehouse_id']
                && (int) $transfer->target_location_id === (int) $line['location_id']
                && (string) $transfer->target_batch_no === (string) $line['batch_no']) return;
        }

        if ($operation === 'disposal') {
            $disposal = DB::table('erp_material_physical_disposals')
                ->where('id', (int) ($line['physical_disposal_id'] ?? 0))
                ->where('physical_material_id', (int) ($line['physical_material_id'] ?? 0))
                ->where('status', 'PENDING')
                ->lockForUpdate()
                ->first();
            if ($disposal
                && $transaction->source_type === 'material_physical_disposal'
                && $transaction->transaction_type === 'physical_material_disposal'
                && (int) $transaction->source_id === (int) $disposal->id
                && bccomp($quantity, '-1', 8) === 0
                && $this->atBalance((int) $disposal->physical_material_id, (int) $item->id, (int) $balance->id)) return;
        }

        if ($transaction->source_type === 'shipment_packing_material' && $transaction->transaction_type === 'shipment_packing_material_outbound') {
            $anchor = DB::table('erp_shipment_packing_materials')->where('id', $transaction->source_id)
                ->where('operation_id', (int) ($line['source_id'] ?? 0))->lockForUpdate()->first();
            $material = $anchor && ($line['source_type'] ?? null) === 'shipment_packing_operation'
                && (! $anchor->inventory_transaction_id || (int) $anchor->inventory_transaction_id === (int) $transaction->id)
                ? DB::table('erp_shipment_packing_materials')->where('id', (int) ($line['source_item_id'] ?? 0))
                    ->where('operation_id', $anchor->operation_id)->where('inventory_balance_id', $balance->id)->where('item_id', $item->id)->lockForUpdate()->first()
                : null;
            $ids = $material ? (json_decode($material->serial_snapshot ?: '{}', true)['physical_material_ids'] ?? []) : [];
            $valid = $material && ! $material->inventory_transaction_id && count($ids) > 0 && count($ids) === count(array_unique($ids))
                && bccomp((string) $material->base_qty, (string) count($ids), 8) === 0 && bccomp($quantity, bcsub('0', (string) count($ids), 8), 8) === 0;
            $total = '0';
            foreach ($ids as $id) {
                if (! $this->atBalance((int) $id, (int) $item->id, (int) $balance->id)) { $valid = false; break; }
                $total = bcadd($total, (string) DB::table('erp_material_physicals')->where('id', $id)->value('total_cost'), 4);
            }
            if ($valid && bccomp((string) ($line['cost_amount'] ?? '0'), bcsub('0', $total, 4), 4) === 0) return;
        }
        throw ValidationException::withMessages([
            'physical_material_id' => '实物管理物料禁止通过普通数量接口改变库存；必须逐张绑定已落库的正式实物动作。',
        ]);
    }

    public function atBalance(int $physicalId, int $itemId, int $balanceId): bool
    {
        $physical = DB::table('erp_material_physicals')->where('id', $physicalId)->lockForUpdate()->first();
        $holding = $physical ? DB::table('erp_material_holdings')->where('id', $physical->current_holding_id)->first() : null;
        return $physical && $holding && $physical->status === 'AVAILABLE' && $holding->status === 'ACTIVE'
            && $holding->position_type === 'WAREHOUSE' && (int) $physical->item_id === $itemId
            && (int) $holding->inventory_balance_id === $balanceId;
    }
}
