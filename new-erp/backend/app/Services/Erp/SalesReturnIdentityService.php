<?php

namespace App\Services\Erp;

use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventorySerial;
use App\Models\Erp\InventorySerialEvent;
use App\Models\Erp\InventoryTransactionItem;
use App\Models\Erp\SalesReturnCostAllocation;
use App\Models\Erp\SalesReturnItem;
use App\Models\Erp\SalesReturnReceiptItem;
use App\Models\Erp\SalesReturnSerialIdentity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesReturnIdentityService
{
    private const STATES = [
        'restock' => 'sales_return_restock_pending',
        'pending' => 'sales_return_pending',
        'scrap' => 'sales_return_scrapped',
        'rejected' => 'sales_return_rejected',
    ];

    public function requiresIdentities(SalesReturnItem $line): bool
    {
        $line->loadMissing('item');
        if ($line->item?->materialManagementMode() === 'physical') return false;
        if ($line->item?->serialTrackingMode() === 'required') return true;
        // Turning tracking off later must not erase the identity of an already shipped unit.
        return DB::table('erp_sales_shipment_lines as shipment_line')
            ->join('erp_inventory_transaction_items as outbound', function ($join): void {
                $join->on('outbound.source_item_id', '=', 'shipment_line.id')->where('outbound.source_type', 'sales_shipment');
            })
            ->join('erp_inventory_transactions as transaction', 'transaction.id', '=', 'outbound.transaction_id')
            ->where('shipment_line.sales_order_line_id', $line->sales_order_line_id)->where('shipment_line.item_id', $line->item_id)
            ->where('transaction.transaction_type', 'sales_shipment_outbound')->where('transaction.posting_status', 'posted')
            ->whereRaw("JSON_LENGTH(JSON_EXTRACT(shipment_line.serial_snapshot, '$.inventory_serial_ids')) > 0")->exists();
    }

    /** Caller must authorize this return's order scope before exposing any candidates. */
    public function candidates(int $returnId, int $returnItemId, array $filters = []): LengthAwarePaginator
    {
        $line = SalesReturnItem::with('salesReturn')->where('sales_return_id', $returnId)->findOrFail($returnItemId);
        $query = $this->candidateQuery($line);
        if (array_key_exists('ids', $filters)) $query->whereIn('serial.id', $filters['ids']);
        if (!in_array($line->salesReturn->return_status, ['pending_receipt', 'partial_received'], true)
            || (float) $line->requested_base_qty <= (float) $line->received_base_qty) $query->whereRaw('1=0');
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) $query->where('serial.serial_no', 'like', '%'.$keyword.'%');
        if ($batch = trim((string) ($filters['batch_no'] ?? ''))) $query->where('shipment_line.batch_no', $batch);
        return $query->select([
            'serial.id', 'serial.serial_no', 'serial.serial_status', 'shipment_line.batch_no', 'shipment.shipment_no',
            'shipment_line.id as sales_shipment_line_id', 'outbound.id as outbound_transaction_item_id',
        ])->orderBy('serial.id')->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 10))), ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)));
    }

    /** Caller authorizes the receipt's return/order; cost fields never enter this projection. */
    public function receiptIdentities(int $receiptId, array $filters = []): LengthAwarePaginator
    {
        $query = DB::table('erp_sales_return_serial_identities as identity')
            ->join('erp_sales_return_receipt_items as receipt_line', 'receipt_line.id', '=', 'identity.sales_return_receipt_item_id')
            ->join('erp_sales_shipment_lines as shipment_line', 'shipment_line.id', '=', 'identity.sales_shipment_line_id')
            ->join('erp_sales_shipments as shipment', 'shipment.id', '=', 'shipment_line.shipment_id')
            ->where('receipt_line.receipt_id', $receiptId);
        if (!empty($filters['line_id'])) $query->where('identity.sales_return_receipt_item_id', (int) $filters['line_id']);
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) $query->where('identity.serial_no_snapshot', 'like', '%'.$keyword.'%');
        return $query->select(['identity.id as identity_id', 'identity.inventory_serial_id as id', 'identity.sales_return_receipt_item_id as receipt_item_id',
            'identity.serial_no_snapshot as serial_no', 'identity.source_batch_no as batch_no', 'identity.disposition', 'shipment.shipment_no',
            'identity.sales_shipment_line_id', 'identity.outbound_transaction_item_id', 'identity.inventory_transaction_item_id', 'identity.received_at', 'identity.posted_at'])
            ->orderBy('identity.id')->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 10))), ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)));
    }

    private function candidateQuery(SalesReturnItem $line): Builder
    {
        return DB::table('erp_inventory_serials as serial')
            ->join('erp_inventory_serial_events as event', function ($join): void {
                $join->on('event.inventory_serial_id', '=', 'serial.id')->where('event.event_type', 'sales_shipment_outbound');
            })
            ->join('erp_sales_shipment_lines as shipment_line', function ($join): void {
                $join->on('shipment_line.id', '=', DB::raw("CAST(JSON_UNQUOTE(JSON_EXTRACT(event.event_payload, '$.shipment_line_id')) AS UNSIGNED)"))
                    ->on('shipment_line.shipment_id', '=', 'event.document_id');
            })
            ->join('erp_sales_shipments as shipment', 'shipment.id', '=', 'shipment_line.shipment_id')
            ->join('erp_inventory_transaction_items as outbound', function ($join): void {
                $join->on('outbound.source_item_id', '=', 'shipment_line.id')->where('outbound.source_type', 'sales_shipment');
            })
            ->join('erp_inventory_transactions as transaction', 'transaction.id', '=', 'outbound.transaction_id')
            ->where('serial.item_id', $line->item_id)->where('shipment_line.item_id', $line->item_id)->where('outbound.item_id', $line->item_id)
            ->where('shipment_line.sales_order_line_id', $line->sales_order_line_id)
            ->where('serial.serial_status', 'shipped')->where('event.document_type', 'sales_shipment')
            ->where('transaction.transaction_type', 'sales_shipment_outbound')->where('transaction.posting_status', 'posted')->where('outbound.change_qty', '<', 0)
            ->where('transaction.source_type', 'sales_shipment')->whereColumn('outbound.source_id', 'shipment.id')->whereColumn('transaction.source_id', 'shipment.id')
            ->whereColumn('serial.batch_no', 'shipment_line.batch_no')->whereColumn('outbound.batch_no', 'shipment_line.batch_no')
            ->whereRaw("JSON_CONTAINS(JSON_EXTRACT(shipment_line.serial_snapshot, '$.inventory_serial_ids'), CAST(serial.id AS JSON))")
            // The newest sale is authoritative: an old order cannot reclaim a serial that
            // has already returned, been sold again, and now belongs to another shipment.
            ->whereRaw("event.id = (SELECT MAX(newer.id) FROM erp_inventory_serial_events newer WHERE newer.inventory_serial_id = serial.id AND newer.event_type = 'sales_shipment_outbound')")
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('erp_sales_return_serial_identities as identity')
                    ->whereColumn('identity.inventory_serial_id', 'serial.id')->whereColumn('identity.outbound_transaction_item_id', 'outbound.id');
            });
    }

    /** Called inside the receipt transaction before quantities are accumulated. */
    public function recordReceipt(SalesReturnItem $returnLine, SalesReturnReceiptItem $receiptLine, array $row, ?int $operatorId): void
    {
        $dispositions = $row['serial_dispositions'] ?? [];
        if (!$this->requiresIdentities($returnLine)) {
            if (collect($dispositions)->flatten()->isNotEmpty()) $this->fail('当前退货物料没有可绑定的销售序列事实，不允许携带序列号。');
            return;
        }
        if (!is_array($dispositions) || array_diff(array_keys($dispositions), array_keys(self::STATES))) $this->fail('序列号处理去向无效。');
        $selected = [];
        foreach (self::STATES as $disposition => $status) {
            $ids = $dispositions[$disposition] ?? [];
            if (!is_array($ids) || !array_is_list($ids)) $this->fail('每种处理去向必须传入序列号列表。');
            $quantity = (string) $receiptLine->{$disposition.'_base_qty'};
            if (bccomp($quantity, (string) count($ids), 8) !== 0) $this->fail('序列商品每种处理数量必须与所选序列号数量一致，不允许只填写数量。');
            foreach ($ids as $id) {
                if (!is_scalar($id) || !ctype_digit((string) $id) || (int) $id <= 0 || isset($selected[(int) $id])) $this->fail('序列号无效或重复，同一序列不能选择多个处理去向。');
                $selected[(int) $id] = $disposition;
            }
        }
        if (!$selected || bccomp((string) count($selected), (string) $receiptLine->received_base_qty, 8) !== 0) $this->fail('本次实收数量必须等于全部处理序列号数量。');
        $serialIds = array_keys($selected); sort($serialIds);
        $serials = InventorySerial::whereIn('id', $serialIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $facts = $this->candidateQuery($returnLine)->whereIn('serial.id', $serialIds)->select([
            'serial.id', 'serial.serial_no', 'shipment_line.batch_no', 'shipment_line.serial_snapshot',
            'shipment_line.id as shipment_line_id', 'shipment_line.shipment_id', 'outbound.id as outbound_id',
            'outbound.change_qty', 'outbound.unit_cost', 'outbound.cost_amount',
        ])->get();
        if ($facts->count() !== count($selected) || $facts->pluck('id')->unique()->count() !== count($selected)) $this->fail('所选序列号不属于当前订单原发货，或已退回、已被其他业务处理，请重新核对。');
        $batches = $facts->pluck('batch_no')->unique()->values();
        if ($batches->count() !== 1 || (string) $receiptLine->batch_no !== (string) $batches->first()) $this->fail('同一退货行本次只能接收同一个原发货批次，批次必须与所选序列号一致；不同批次请分次收货。');
        $allocations = $this->bindOriginalCosts($returnLine, $facts);
        foreach ($facts as $fact) {
            $serial = $serials[$fact->id];
            $disposition = $selected[$fact->id];
            $identity = SalesReturnSerialIdentity::create([
                'sales_return_receipt_item_id' => $receiptLine->id, 'sales_return_item_id' => $returnLine->id,
                'inventory_serial_id' => $serial->id, 'sales_shipment_line_id' => $fact->shipment_line_id,
                'outbound_transaction_item_id' => $fact->outbound_id, 'cost_allocation_id' => $allocations[$fact->outbound_id]->id,
                'serial_no_snapshot' => $serial->serial_no, 'source_batch_no' => $fact->batch_no, 'disposition' => $disposition,
                'unit_cost_snapshot' => $this->absolute((string) $fact->unit_cost, 8),
                'cost_amount_snapshot' => $this->serialCost($fact), 'received_by' => $operatorId, 'received_at' => now(),
            ]);
            $serial->update(['serial_status' => self::STATES[$disposition], 'inventory_balance_id' => null]);
            $this->event($serial, $receiptLine, $identity, 'sales_return_received', 'shipped', self::STATES[$disposition], $operatorId);
        }
    }

    private function bindOriginalCosts(SalesReturnItem $line, Collection $facts): Collection
    {
        // Initial return creation reserves quantities FIFO before the returned serial is known.
        // Only its still-unbound reservation may move to the actual serial's original outbound;
        // already received identities and posted quantities must never change their source cost.
        $outboundIds = DB::table('erp_sales_shipment_lines as shipment_line')
            ->join('erp_inventory_transaction_items as outbound', function ($join): void { $join->on('outbound.source_item_id', '=', 'shipment_line.id')->where('outbound.source_type', 'sales_shipment'); })
            ->where('shipment_line.sales_order_line_id', $line->sales_order_line_id)->pluck('outbound.id');
        $outbounds = InventoryTransactionItem::whereIn('id', $outboundIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $all = SalesReturnCostAllocation::whereIn('outbound_transaction_item_id', $outboundIds)->orderBy('id')->lockForUpdate()->get();
        $own = $all->where('sales_return_item_id', $line->id)->values();
        $bound = SalesReturnSerialIdentity::whereIn('cost_allocation_id', $all->pluck('id'))->selectRaw('cost_allocation_id, COUNT(*) as aggregate')->groupBy('cost_allocation_id')->pluck('aggregate', 'cost_allocation_id');
        $needed = $facts->groupBy('outbound_id')->map(fn ($rows) => $rows->count());
        $byOutbound = $own->keyBy('outbound_transaction_item_id');
        if ($byOutbound->count() !== $own->count()) $this->fail('原发货成本分配重复，请先核对成本事实。');
        $free = [];
        foreach ($own as $allocation) $free[$allocation->id] = max(0, (float) $allocation->allocated_base_qty - max((float) $allocation->posted_base_qty, (int) ($bound[$allocation->id] ?? 0)));
        $deficits = [];
        foreach ($needed as $outboundId => $count) {
            $allocation = $byOutbound->get($outboundId);
            $used = $allocation ? min($count, $free[$allocation->id]) : 0;
            if ($allocation) $free[$allocation->id] -= $used;
            $deficits[$outboundId] = $count - $used;
        }
        $missing = array_sum($deficits);
        if ($missing > array_sum($free) + 0.00000001) $this->fail('当前退货单没有足够未接收的原出库成本预留，不能重复接收序列号。');
        $statuses = DB::table('erp_sales_return_items as item')->join('erp_sales_returns as document', 'document.id', '=', 'item.sales_return_id')
            ->whereIn('item.id', $all->pluck('sales_return_item_id'))->pluck('document.return_status', 'item.id');
        foreach ($deficits as $outboundId => $count) {
            if ($count <= 0) continue;
            $outbound = $outbounds->get($outboundId);
            if (!$outbound) $this->fail('原出库成本事实不存在。');
            $reserved = $all->where('outbound_transaction_item_id', $outboundId)->sum(function ($allocation) use ($statuses, $bound) {
                if (in_array($statuses[$allocation->sales_return_item_id] ?? null, ['cancelled', 'closed'], true)) return max((float) $allocation->posted_base_qty, (int) ($bound[$allocation->id] ?? 0));
                return (float) $allocation->allocated_base_qty;
            });
            if ($reserved + $count > abs((float) $outbound->change_qty) + 0.00000001) $this->fail('所选序列号对应的原发货可退成本额度已被其他退货单占用，请重新核对。');
        }
        foreach ($own as $allocation) {
            if ($missing <= 0) break;
            $release = min($missing, $free[$allocation->id]);
            if ($release <= 0) continue;
            $quantity = bcsub((string) $allocation->allocated_base_qty, (string) $release, 8);
            $allocation->update(['allocated_base_qty' => $quantity, 'cost_amount_snapshot' => $this->roundedProduct($quantity, (string) $allocation->unit_cost_snapshot),
                'allocation_status' => bccomp($quantity, (string) $allocation->posted_base_qty, 8) <= 0 ? 'posted' : 'reserved']);
            $missing -= $release;
        }
        foreach ($deficits as $outboundId => $count) {
            if ($count <= 0) continue;
            $fact = $facts->firstWhere('outbound_id', $outboundId);
            $allocation = $byOutbound->get($outboundId);
            if (!$allocation) {
                $allocation = SalesReturnCostAllocation::create(['sales_return_item_id' => $line->id, 'sales_shipment_id' => $fact->shipment_id,
                    'sales_shipment_line_id' => $fact->shipment_line_id, 'outbound_transaction_item_id' => $outboundId,
                    'allocated_base_qty' => 0, 'posted_base_qty' => 0, 'unit_cost_snapshot' => $this->absolute((string) $fact->unit_cost, 8), 'cost_amount_snapshot' => 0, 'allocation_status' => 'reserved']);
                $byOutbound->put($outboundId, $allocation);
            }
            $quantity = bcadd((string) $allocation->allocated_base_qty, (string) $count, 8);
            $allocation->update(['allocated_base_qty' => $quantity, 'cost_amount_snapshot' => $this->roundedProduct($quantity, (string) $allocation->unit_cost_snapshot), 'allocation_status' => 'reserved']);
        }
        return $byOutbound;
    }

    /** Returns null for ordinary quantity lines; serialized rows consume their exact source. */
    public function consumeRestock(SalesReturnReceiptItem $line): ?array
    {
        $identities = SalesReturnSerialIdentity::where('sales_return_receipt_item_id', $line->id)->orderBy('id')->lockForUpdate()->get();
        if ($identities->isEmpty()) {
            if ($this->requiresIdentities($line->salesReturnItem)) $this->fail('序列商品退货缺少已确认的逐件身份，不允许只按数量入库。');
            return null;
        }
        foreach (self::STATES as $disposition => $state) {
            if (bccomp((string) $identities->where('disposition', $disposition)->count(), (string) $line->{$disposition.'_base_qty'}, 8) !== 0) $this->fail('退货到货的序列处理数量与原确认事实不一致。');
        }
        $restocks = $identities->where('disposition', 'restock');
        $serials = InventorySerial::whereIn('id', $restocks->pluck('inventory_serial_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $allocations = SalesReturnCostAllocation::whereIn('id', $restocks->pluck('cost_allocation_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $segments = [];
        foreach ($restocks as $identity) {
            $serial = $serials->get($identity->inventory_serial_id);
            $allocation = $allocations->get($identity->cost_allocation_id);
            if ($identity->inventory_transaction_item_id || !$serial || $serial->serial_status !== self::STATES['restock']
                || (int) $serial->item_id !== (int) $line->item_id || $identity->source_batch_no !== $line->batch_no
                || !$allocation || (int) $allocation->sales_return_item_id !== (int) $line->sales_return_item_id
                || (int) $allocation->outbound_transaction_item_id !== (int) $identity->outbound_transaction_item_id
                || (int) $allocation->sales_shipment_line_id !== (int) $identity->sales_shipment_line_id) $this->fail('退货序列身份、原批次或成本来源已变化，不能入库。');
            $posted = bcadd((string) $allocation->posted_base_qty, '1', 8);
            if (bccomp($posted, (string) $allocation->allocated_base_qty, 8) > 0) $this->fail('所选序列号没有足额的原发货成本分配。');
            $allocation->update(['posted_base_qty' => $posted, 'allocation_status' => bccomp($posted, (string) $allocation->allocated_base_qty, 8) === 0 ? 'posted' : 'reserved']);
            $segments[] = ['base_qty' => 1, 'unit_cost' => $identity->cost_amount_snapshot, 'cost_amount' => $identity->cost_amount_snapshot,
                'shipment_line_id' => $identity->sales_shipment_line_id, 'return_serial_identity_id' => $identity->id,
                'outbound_transaction_item_id' => $identity->outbound_transaction_item_id];
        }
        return $segments;
    }

    public function completeRestock(int $identityId, InventoryTransactionItem $transactionItem, ?int $operatorId): void
    {
        $identity = SalesReturnSerialIdentity::with('receiptItem.receipt')->lockForUpdate()->findOrFail($identityId);
        $line = $identity->receiptItem;
        $serial = InventorySerial::lockForUpdate()->findOrFail($identity->inventory_serial_id);
        if ($identity->inventory_transaction_item_id || $identity->disposition !== 'restock' || $serial->serial_status !== self::STATES['restock']
            || $transactionItem->source_type !== 'sales_return_receipt' || (int) $transactionItem->source_item_id !== (int) $line->id
            || (int) $transactionItem->item_id !== (int) $line->item_id || $transactionItem->batch_no !== $identity->source_batch_no
            || bccomp((string) $transactionItem->change_qty, '1', 8) !== 0 || bccomp((string) $transactionItem->cost_amount, (string) $identity->cost_amount_snapshot, 4) !== 0) $this->fail('退货序列号与正式入库事实不一致。');
        $balance = InventoryBalance::where('item_id', $line->item_id)->where('warehouse_id', $line->warehouse_id)
            ->where('location_id', $line->location_id)->where('batch_no', $line->batch_no)->lockForUpdate()->firstOrFail();
        $serial->update(['inventory_balance_id' => $balance->id, 'warehouse_id' => $line->warehouse_id, 'location_id' => $line->location_id,
            'batch_no' => $line->batch_no, 'serial_status' => 'available', 'outbound_at' => null, 'reserved_at' => null, 'posted_at' => now()]);
        $identity->update(['inventory_transaction_item_id' => $transactionItem->id, 'posted_at' => now()]);
        $this->event($serial, $line, $identity, 'sales_return_inbound', self::STATES['restock'], 'available', $operatorId);
    }

    private function serialCost(object $fact): string
    {
        $ids = array_values(array_unique(array_map('intval', json_decode($fact->serial_snapshot ?: '{}', true)['inventory_serial_ids'] ?? [])));
        $quantity = abs((float) $fact->change_qty);
        if ($quantity <= 0 || abs($quantity - round($quantity)) > 0.00000001 || count($ids) !== (int) $quantity) $this->fail('原发货逐件序列与出库数量不完整，无法确定该序列的原成本。');
        sort($ids);
        $index = array_search((int) $fact->id, $ids, true);
        if ($index === false) $this->fail('序列号不在原发货快照中。');
        // Deterministic partition of the frozen outbound total avoids manufacturing or
        // losing the final 0.0001 when serials from one shipment return in separate receipts.
        $total = $this->absolute((string) $fact->cost_amount, 4);
        $share = bcdiv($total, (string) count($ids), 4);
        return $index === count($ids) - 1 ? bcsub($total, bcmul($share, (string) (count($ids) - 1), 4), 4) : $share;
    }

    private function event(InventorySerial $serial, SalesReturnReceiptItem $line, SalesReturnSerialIdentity $identity, string $type, string $from, string $to, ?int $operatorId): void
    {
        $receipt = $line->receipt;
        InventorySerialEvent::create(['inventory_serial_id' => $serial->id, 'event_type' => $type, 'document_type' => 'sales_return_receipt',
            'document_id' => $receipt->id, 'document_no' => $receipt->receipt_no, 'from_status' => $from, 'to_status' => $to,
            'warehouse_id' => $line->warehouse_id, 'location_id' => $line->location_id, 'batch_no' => $identity->source_batch_no,
            'operator_id' => $operatorId, 'occurred_at' => now(), 'event_payload' => ['return_serial_identity_id' => $identity->id,
                'receipt_item_id' => $line->id, 'disposition' => $identity->disposition, 'shipment_line_id' => $identity->sales_shipment_line_id,
                'outbound_transaction_item_id' => $identity->outbound_transaction_item_id, 'inventory_transaction_item_id' => $identity->inventory_transaction_item_id]]);
    }

    private function absolute(string $value, int $scale): string { return bccomp($value, '0', $scale) < 0 ? bcsub('0', $value, $scale) : bcadd($value, '0', $scale); }
    private function roundedProduct(string $qty, string $unitCost): string { return bcadd(bcmul($qty, $unitCost, 8), '0.00005', 4); }
    private function fail(string $message): never { throw ValidationException::withMessages(['serial_dispositions' => $message]); }
}
