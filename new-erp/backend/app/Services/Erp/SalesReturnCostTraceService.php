<?php

namespace App\Services\Erp;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** 按原过账消费顺序还原退货成本来源，不以批次或当前设备位置猜测历史来源。 */
class SalesReturnCostTraceService
{
    public function quantityPool(object $source, ?string $total = null, array $knownCosts = []): array
    {
        $total ??= bcsub('0', (string) $source->cost_amount, 4);
        $quantity = bcsub('0', (string) $source->change_qty, 8);
        $line = DB::table('erp_sales_shipment_lines')->where('id', $source->source_item_id)->lockForUpdate()->first(['serial_snapshot']);
        $snapshot = json_decode($line?->serial_snapshot ?: '{}', true);
        $ids = array_map('intval', $snapshot['inventory_serial_ids'] ?? []); sort($ids);
        if (count($ids) !== count(array_unique($ids)) || bccomp($quantity, (string) count($ids), 8) < 0) $this->fail();
        $knownCosts = $knownCosts ?: ($snapshot['actual_material_costs'] ?? []);
        $share = bccomp($quantity, '0', 8) > 0 ? bcdiv($total, $quantity, 4) : '0.0000';
        $knownTotal = '0.0000';
        foreach ($ids as $index => $id) $knownTotal = bcadd($knownTotal, (string) ($knownCosts[$id] ??
            (bccomp($quantity, (string) count($ids), 8) === 0 && $index === count($ids) - 1
                ? bcsub($total, bcmul($share, (string) (count($ids) - 1), 4), 4) : $share)), 4);
        $cost = bcsub($total, $knownTotal, 4); $quantity = bcsub($quantity, (string) count($ids), 8);
        if (bccomp($cost, '0', 4) < 0 || (bccomp($quantity, '0', 8) === 0 && bccomp($cost, '0', 4) !== 0)) $this->fail();
        return ['cost' => $cost, 'quantity' => $quantity];
    }

    public function postedSegments(array $returnItemIds): array
    {
        if ($returnItemIds === []) return [];
        $allocations = DB::table('erp_sales_return_cost_allocations')->whereIn('sales_return_item_id', $returnItemIds)->orderBy('id')->lockForUpdate()->get();
        $sources = DB::table('erp_inventory_transaction_items as movement')->join('erp_inventory_transactions as transaction', 'transaction.id', '=', 'movement.transaction_id')
            ->whereIn('movement.id', $allocations->pluck('outbound_transaction_item_id'))->where('movement.source_type', 'sales_shipment')
            ->where('transaction.transaction_type', 'sales_shipment_outbound')->where('transaction.posting_status', 'posted')
            ->where('movement.change_qty', '<', 0)->orderBy('movement.id')->lockForUpdate()->get(['movement.*'])->keyBy('id');
        $inbounds = DB::table('erp_inventory_transaction_items as movement')
            ->join('erp_inventory_transactions as transaction', 'transaction.id', '=', 'movement.transaction_id')
            ->join('erp_sales_return_receipt_items as receipt_item', 'receipt_item.id', '=', 'movement.source_item_id')
            ->where('movement.source_type', 'sales_return_receipt')->where('movement.change_qty', '>', 0)
            ->whereColumn('movement.source_id', 'receipt_item.receipt_id')
            ->where('transaction.transaction_type', 'sales_return_inbound')->where('transaction.posting_status', 'posted')
            ->whereIn('receipt_item.sales_return_item_id', $returnItemIds)->orderBy('movement.id')->lockForUpdate()
            ->get(['movement.*', 'receipt_item.sales_return_item_id']);
        $identities = DB::table('erp_sales_return_serial_identities')->whereIn('sales_return_item_id', $returnItemIds)
            ->whereNotNull('inventory_transaction_item_id')->orderBy('id')->lockForUpdate()->get();
        $byInbound = $identities->keyBy('inventory_transaction_item_id');
        if ($byInbound->count() !== $identities->count()) $this->fail();
        $serialCounts = $identities->countBy('cost_allocation_id');
        $remaining = [];
        foreach ($allocations as $allocation) {
            $remaining[$allocation->id] = bcsub((string) $allocation->posted_base_qty, (string) ($serialCounts[$allocation->id] ?? 0), 8);
            if (bccomp($remaining[$allocation->id], '0', 8) < 0) $this->fail();
        }
        $byReturnItem = $allocations->groupBy('sales_return_item_id'); $byId = $allocations->keyBy('id'); $segments = [];
        foreach ($inbounds as $inbound) {
            $identity = $byInbound->get($inbound->id);
            if ($identity) {
                $allocation = $byId->get($identity->cost_allocation_id);
                if (! $allocation || (int) $allocation->outbound_transaction_item_id !== (int) $identity->outbound_transaction_item_id
                    || (int) $identity->sales_return_receipt_item_id !== (int) $inbound->source_item_id
                    || bccomp((string) $inbound->change_qty, '1', 8) !== 0) $this->fail();
            } else {
                $allocation = $byReturnItem->get($inbound->sales_return_item_id, collect())->first(fn ($row) => bccomp($remaining[$row->id], '0', 8) > 0);
                // 正式入口逐个 allocation 写一条流水；跨来源合并的旧流水不能无凭据拆成本。
                if (! $allocation || bccomp((string) $inbound->change_qty, $remaining[$allocation->id], 8) > 0) $this->fail();
                $remaining[$allocation->id] = bcsub($remaining[$allocation->id], (string) $inbound->change_qty, 8);
            }
            $source = $sources->get($allocation->outbound_transaction_item_id);
            if (! $source || (int) $source->item_id !== (int) $inbound->item_id) $this->fail();
            $segments[] = ['movement' => $inbound, 'source' => $source, 'quantity' => (string) $inbound->change_qty,
                'serial_id' => $identity ? (int) $identity->inventory_serial_id : null];
        }
        foreach ($remaining as $quantity) if (bccomp($quantity, '0', 8) !== 0) $this->fail();
        if ($identities->whereNotIn('inventory_transaction_item_id', $inbounds->pluck('id'))->isNotEmpty()) $this->fail();
        return $segments;
    }

    private function fail(): never
    {
        throw ValidationException::withMessages(['inventory_cost_amount' => '销售退货入库流水、设备身份与原成本分配数量不一致，不能自动归集成本。']);
    }
}
