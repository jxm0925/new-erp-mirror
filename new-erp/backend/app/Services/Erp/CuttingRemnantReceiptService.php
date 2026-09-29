<?php

namespace App\Services\Erp;

use App\Models\Erp\{InventoryBalance, Item, Location, Warehouse};
use Illuminate\Support\Facades\DB;

final class CuttingRemnantReceiptService
{
    public const PENDING_SQL = "r.status = 'CONFIRMED' AND b.status = 'CONFIRMED' AND h.status = 'ACTIVE' AND h.quantity = 1 AND h.inventory_balance_id IS NULL AND line.id IS NULL AND (r.physical_material_id IS NULL OR (p.status = 'AVAILABLE' AND p.current_holding_id = h.id))";

    public function __construct(private readonly CuttingCommandService $commands,
        private readonly DocumentNumberService $numbers, private readonly InventoryService $inventory,
        private readonly MaterialPhysicalService $physicals) {}

    /** Unscoped row shape; callers must restrict to visible cutting orders before reading. */
    public function rowsQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('erp_cutting_results as r')
            ->join('erp_cutting_settlement_batches as b', 'b.id', '=', 'r.settlement_batch_id')
            ->join('erp_items as i', 'i.id', '=', 'b.input_item_id')
            ->leftJoin('erp_units as u', 'u.id', '=', 'i.unit_id')
            ->leftJoin('erp_material_lots as lot', 'lot.id', '=', 'r.material_lot_id')
            ->leftJoin('erp_material_physicals as p', 'p.id', '=', 'r.physical_material_id')
            ->leftJoin('erp_material_holdings as h', function ($join): void {
                $join->on('h.material_lot_id', '=', 'r.material_lot_id')->on('h.position_id', '=', 'r.id')->where('h.position_type', 'REMNANT_WIP');
            })
            ->leftJoin('erp_cutting_remnant_receipt_lines as line', 'line.result_id', '=', 'r.id')
            ->leftJoin('erp_cutting_remnant_receipts as receipt', 'receipt.id', '=', 'line.receipt_id')
            ->where('r.result_type', 'usable_remnant');
    }

    public function paginate(int $orderId, array $filters, object $user, array $permissions, bool $super = false): array
    {
        $order = $this->commands->order($orderId, $user, $permissions, $super, 'production.cutting.view');
        $q = $this->rowsQuery()->where('b.cutting_order_id', $orderId);
        $pending = self::PENDING_SQL;
        $state = "CASE WHEN receipt.status = 'POSTED' THEN 'POSTED' WHEN {$pending} THEN 'PENDING' ELSE 'UNAVAILABLE' END";
        if (($filters['status'] ?? '') !== '') $q->whereRaw("({$state}) = ?", [$filters['status']]);
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) $q->where(function ($search) use ($keyword): void {
            foreach (['p.physical_no', 'lot.lot_no', 'i.item_code', 'i.item_name', 'i.spec', 'b.batch_no'] as $field) $search->orWhere($field, 'like', '%'.$keyword.'%');
        });
        $page = $q->select('r.id', 'r.business_version', 'r.measurements', 'r.physical_material_id', 'r.material_lot_id',
            'p.physical_no', 'p.dimensions', 'p.business_version as physical_version', 'lot.lot_no', 'lot.cut_length_mm',
            'h.id as source_holding_id', 'h.business_version as holding_version', 'line.posted_qty',
            'i.id as item_id', 'i.item_code', 'i.item_name', 'i.spec', 'i.unit_id', 'u.unit_name',
            'b.batch_no as source_batch_no', 'receipt.id as receipt_id', 'receipt.receipt_no')
            ->selectRaw("({$state}) as status, COALESCE(line.posted_cost, h.total_cost) as total_cost")->orderBy('r.id')
            ->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 10))), ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)));
        $rows = [];
        foreach ($page->items() as $row) {
            $data = (array) $row;
            $data['remnant_no'] = $row->physical_no ?: $row->lot_no;
            $data['dimensions'] = json_decode($row->dimensions ?: $row->measurements ?: '{}', true);
            $data['quantity'] = null;
            try { $data['quantity'] = $row->posted_qty ?? $this->stockQuantity(Item::findOrFail($row->item_id), (bool) $row->physical_material_id); }
            catch (\App\Exceptions\Erp\WorkOrderDomainException $e) { $data['unavailable_reason'] = $e->getMessage(); }
            $data['receivable'] = $row->status === 'PENDING' && $data['quantity'] !== null;
            $rows[] = $data;
        }
        $sourceOrders = \App\Models\Erp\WorkOrder::query()->where(function ($q) use ($orderId): void {
            $q->whereIn('id', DB::table('erp_cutting_plan_allocations')->where('cutting_order_id', $orderId)->select('work_order_id'))
                ->orWhereIn('id', DB::table('erp_production_cutting_operations')->where('cutting_order_id', $orderId)->select('work_order_id'));
        })->orderBy('id')->paginate(5, ['id', 'work_order_no'], 'source_page', max(1, (int) ($filters['source_page'] ?? 1)));
        return ['data' => $rows, 'order_no' => $order->cutting_order_no, 'source_work_orders' => $sourceOrders->toArray(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]];
    }

    public function show(int $orderId, int $receiptId, object $user, array $permissions, bool $super = false): array
    {
        $this->commands->order($orderId, $user, $permissions, $super, 'production.cutting.view');
        $receipt = DB::table('erp_cutting_remnant_receipts')->where('id', $receiptId)->where('cutting_order_id', $orderId)->first();
        if (! $receipt) $this->commands->fail('remnant_receipt_missing', '余料入库单不存在。', 404);
        $data = (array) $receipt;
        $data['header_snapshot'] = json_decode($receipt->header_snapshot, true);
        $data['lines'] = DB::table('erp_cutting_remnant_receipt_lines')->where('receipt_id', $receiptId)->orderBy('id')->limit(100)->get()
            ->map(fn ($line) => array_merge((array) $line, ['line_snapshot' => json_decode($line->line_snapshot, true)]))->all();
        return $data;
    }

    public function commandResult(int $orderId, string $commandId, object $user, array $permissions, bool $super = false): array
    {
        $this->commands->order($orderId, $user, $permissions, $super, 'production.cutting.warehouse');
        $row = DB::table('erp_cutting_commands')->where('client_command_id', $commandId)->where('command_type', 'warehouse_cutting_remnants')
            ->where('actor_legacy_id', $this->commands->actor($user))->first();
        if (! $row) return ['status' => 'NOT_FOUND'];
        $result = json_decode($row->response ?: '{}', true);
        if ((int) ($result['cutting_order_id'] ?? 0) !== $orderId) $this->commands->fail('command_scope_mismatch', '请求不属于当前下料单。', 409);
        return ['status' => $row->status, 'result' => $result];
    }

    public function post(int $orderId, array $payload, object $user, array $permissions, bool $super = false): array
    {
        $c = $this->commands;
        $c->order($orderId, $user, $permissions, $super, 'production.cutting.warehouse');
        return $c->run('warehouse_cutting_remnants', $orderId, $payload, $user, function () use ($orderId, $payload, $user, $permissions, $super, $c): array {
            // Closing production does not consume remaining remnant facts. Lock the order before
            // batches/results, matching cutting issue/correction; never reopen completed production.
            $order = $c->order($orderId, $user, $permissions, $super, 'production.cutting.warehouse', true);
            if ($order->status === 'CANCELLED') $c->fail('cutting_order_cancelled', '已取消的下料单不能办理余料入库。', 409);
            $warehouse = Warehouse::whereKey($payload['warehouse_id'])->where('status', 'enabled')->lockForUpdate()->first();
            $location = Location::whereKey($payload['location_id'])->where('status', 'enabled')->lockForUpdate()->first();
            if (! $warehouse || ! $location || (int) $location->warehouse_id !== (int) $warehouse->id) $c->fail('location_invalid', '请选择启用仓库及其所属的启用库位。');
            $requested = collect($payload['lines'] ?? [])->sortBy('result_id')->values();
            if ($requested->isEmpty() || $requested->count() > 100 || $requested->pluck('result_id')->unique()->count() !== $requested->count()) $c->fail('remnant_selection_invalid', '请选择1至100条不重复的余料。');
            $entries = []; $total = '0.0000';
            foreach ($requested as $line) {
                $result = DB::table('erp_cutting_results')->where('id', $line['result_id'])->lockForUpdate()->first();
                $batch = $result ? DB::table('erp_cutting_settlement_batches')->where('id', $result->settlement_batch_id)->lockForUpdate()->first() : null;
                if (! $result || ! $batch || (int) $batch->cutting_order_id !== $orderId) $c->fail('remnant_scope_invalid', '选中余料不属于当前下料单。', 409);
                $c->version($result, $line);
                if ($result->result_type !== 'usable_remnant' || $result->status !== 'CONFIRMED' || $batch->status !== 'CONFIRMED') $c->fail('remnant_not_confirmed', '只有已确认的可用余料可以入库。', 409);
                $physical = $result->physical_material_id ? DB::table('erp_material_physicals')->where('id', $result->physical_material_id)->lockForUpdate()->first() : null;
                $holding = DB::table('erp_material_holdings')->where('material_lot_id', $result->material_lot_id)->where('position_type', 'REMNANT_WIP')->where('position_id', $result->id)->lockForUpdate()->first();
                if (! $holding || $holding->status !== 'ACTIVE' || $holding->inventory_balance_id !== null || bccomp((string) $holding->quantity, '1', 8) !== 0
                    || DB::table('erp_cutting_remnant_receipt_lines')->where('result_id', $result->id)->exists()) $c->fail('remnant_unavailable', '余料已领用、已入库或已发生变更，请刷新。', 409, ['result_id' => $result->id]);
                $c->version($holding, ['expected_version' => $line['holding_version'] ?? null]);
                if (bccomp((string) $holding->total_cost, (string) $result->total_cost, 4) !== 0 || bccomp((string) $holding->total_cost, '0', 4) < 0) $c->fail('remnant_cost_changed', '余料金额与已确认的核算事实不一致。', 409);
                if ($result->physical_material_id && (! $physical || $physical->status !== 'AVAILABLE' || (int) $physical->current_holding_id !== (int) $holding->id
                    || (int) $physical->material_lot_id !== (int) $holding->material_lot_id || bccomp((string) $physical->total_cost, (string) $holding->total_cost, 4) !== 0)) $c->fail('remnant_physical_changed', '余料实物位置或材料金额已变化。', 409);
                if ($physical) {
                    $c->version($physical, ['expected_version' => $line['physical_version'] ?? null]);
                    $this->physicals->assertPhysicalNotInOpenDocument((int) $physical->id);
                }
                $lot = DB::table('erp_material_lots')->where('id', $holding->material_lot_id)->lockForUpdate()->first();
                if (! $lot || $lot->material_form !== 'REMNANT' || (int) $lot->item_id !== (int) $batch->input_item_id) $c->fail('remnant_lot_invalid', '余料材料批次与来源物料不一致。', 409);
                $item = Item::whereKey($lot->item_id)->lockForUpdate()->firstOrFail();
                if (! $item->is_stock_item || $item->status !== 'enabled') $c->fail('remnant_item_invalid', '余料物料已停用或不管理库存。');
                $quantity = $this->stockQuantity($item, $physical !== null);
                $batchNo = $physical?->physical_no ?: $lot->lot_no;
                // Do not merge a remnant into another lineage, even if its on-hand balance is zero.
                $existingBatch = DB::table('erp_inventory_batches')->where('item_id', $item->id)->where('batch_no', $batchNo)->lockForUpdate()->first();
                if ($existingBatch && (int) $existingBatch->material_lot_id !== (int) $lot->id) $c->fail('remnant_batch_conflict', '余料批次编号已经属于其他来源。', 409);
                $dimensions = json_decode($physical?->dimensions ?: $result->measurements ?: '{}', true);
                $snapshot = ['remnant_no' => $batchNo, 'item_code' => $item->item_code, 'item_name' => $item->item_name,
                    'dimensions' => $dimensions, 'unit_name' => $item->unit?->unit_name, 'source_batch_no' => $batch->batch_no,
                    'result_version' => $result->business_version, 'holding_version' => $holding->business_version,
                    'physical_version' => $physical?->business_version];
                $entries[] = compact('result', 'batch', 'physical', 'holding', 'lot', 'item', 'quantity', 'batchNo', 'snapshot');
                $total = bcadd($total, (string) $holding->total_cost, 4);
            }
            $linkedWorkOrder = DB::table('erp_production_cutting_operations')->where('cutting_order_id', $orderId)->value('work_order_id');
            $workOrderIds = DB::table('erp_cutting_plan_allocations')->where('cutting_order_id', $orderId)->pluck('work_order_id')->push($linkedWorkOrder)->filter()->unique();
            $header = ['cutting_order_no' => $order->cutting_order_no, 'work_order_nos' => DB::table('erp_work_orders')->whereIn('id', $workOrderIds)->pluck('work_order_no')->all(),
                'warehouse_name' => $warehouse->warehouse_name, 'location_name' => $location->location_name,
                'operator_name' => $user->nickname ?? $user->username ?? (string) $c->actor($user)];
            $id = DB::table('erp_cutting_remnant_receipts')->insertGetId(['receipt_no' => $this->numbers->next('cutting_remnant_receipt', 'RWR'),
                'cutting_order_id' => $orderId, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'status' => 'POSTING',
                'piece_count' => count($entries), 'posted_cost' => $total, 'posted_by_legacy_id' => $c->actor($user),
                'header_snapshot' => json_encode($header, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'remark' => trim($payload['remark'] ?? ''), 'created_at' => now(), 'updated_at' => now()]);
            foreach ($entries as &$entry) {
                $entry['line_id'] = DB::table('erp_cutting_remnant_receipt_lines')->insertGetId(['receipt_id' => $id,
                    'result_id' => $entry['result']->id, 'source_holding_id' => $entry['holding']->id, 'physical_material_id' => $entry['physical']?->id,
                    'material_lot_id' => $entry['lot']->id, 'item_id' => $entry['item']->id, 'unit_id' => $entry['item']->unit_id,
                    'batch_no' => $entry['batchNo'], 'posted_qty' => $entry['quantity'], 'posted_cost' => $entry['holding']->total_cost,
                    'line_snapshot' => json_encode($entry['snapshot'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            }
            unset($entry);
            $receipt = DB::table('erp_cutting_remnant_receipts')->where('id', $id)->first();
            $lines = DB::table('erp_cutting_remnant_receipt_lines')->where('receipt_id', $id)->orderBy('id')->get();
            $transaction = $this->inventory->postCuttingRemnantReceipt($receipt, $lines, $user);
            foreach ($entries as $entry) {
                $balance = InventoryBalance::where('item_id', $entry['item']->id)->where('warehouse_id', $warehouse->id)->where('location_id', $location->id)->where('batch_no', $entry['batchNo'])->lockForUpdate()->firstOrFail();
                $target = $this->physicals->warehouseHolding($balance, 'REMNANT', (int) $entry['lot']->id);
                if ($entry['physical']) $this->physicals->movePhysical($entry['physical'], $entry['holding'], (int) $target->id, 'RECEIPT', 'AVAILABLE', (int) $transaction->id, $c->actor($user));
                else DB::table('erp_material_movements')->insert(['movement_no' => $this->numbers->next('material_movement', 'MM'), 'source_holding_id' => $entry['holding']->id,
                    'target_holding_id' => $target->id, 'action' => 'RECEIPT', 'quantity' => 1, 'total_cost' => $entry['holding']->total_cost,
                    'inventory_transaction_id' => $transaction->id, 'operator_legacy_id' => $c->actor($user), 'created_at' => now(), 'updated_at' => now()]);
                DB::table('erp_material_holdings')->where('id', $entry['holding']->id)->update(['status' => 'CONSUMED', 'quantity' => 0, 'total_cost' => 0,
                    'business_version' => $entry['holding']->business_version + 1, 'updated_at' => now()]);
                DB::table('erp_cutting_remnant_receipt_lines')->where('id', $entry['line_id'])->update(['inventory_balance_id' => $balance->id, 'warehouse_holding_id' => $target->id, 'updated_at' => now()]);
            }
            DB::table('erp_cutting_remnant_receipts')->where('id', $id)->update(['status' => 'POSTED', 'inventory_transaction_id' => $transaction->id, 'posted_at' => now(), 'updated_at' => now()]);
            $response = ['receipt_id' => $id, 'receipt_no' => $receipt->receipt_no, 'cutting_order_id' => $orderId, 'status' => 'POSTED', 'piece_count' => count($entries), 'posted_cost' => $total, 'inventory_transaction_id' => $transaction->id];
            $c->event('cutting_remnant_receipt', $id, 'post', $user, null, $response + ['header' => $header, 'lines' => $lines->all()]);
            return $response;
        });
    }

    private function stockQuantity(Item $item, bool $physical): string
    {
        $unit = app(UnitConversionDomainService::class)->canonicalUnit($item->unit);
        // Existing sheet/length cutting consumes pieces. Neither a measured bounding rectangle
        // nor a standard full-bar length proves mass/area/linear stock units for a remnant.
        if (! $unit || ! in_array($unit->unit_type, ['quantity', 'count'], true) || ($physical ? $item->materialManagementMode() !== 'physical' : $item->cuttingMode() !== 'length')) {
            $this->commands->fail('remnant_unit_conversion_missing', '该余料缺少已确认的库存单位换算，不能将一块余料直接按一个基本单位入库。');
        }
        return '1.00000000';
    }
}
