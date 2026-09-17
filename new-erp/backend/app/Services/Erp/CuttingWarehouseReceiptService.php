<?php

namespace App\Services\Erp;

use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventoryLocationBalance;
use App\Models\Erp\Item;
use App\Models\Erp\Location;
use App\Models\Erp\Warehouse;
use Illuminate\Support\Facades\DB;

/** A cutting warehouse route becomes stock only through this formal receipt command. */
final class CuttingWarehouseReceiptService
{
    public function __construct(
        private readonly CuttingCommandService $commands,
        private readonly DocumentNumberService $numbers,
        private readonly InventoryService $inventory,
        private readonly InventoryAvailabilityService $availability,
    ) {}

    public function post(int $routeId, array $payload, object $user, array $permissions, bool $super = false): array
    {
        $permission = 'production.cutting.warehouse';
        $this->authorize($routeId, $user, $permissions, $super, $permission);
        return $this->commands->run('warehouse_cutting_route', $routeId, $payload, $user, function () use ($routeId, $payload, $user, $permissions, $super, $permission): array {
            $route = DB::table('erp_cutting_result_routes')->where('id', $routeId)->lockForUpdate()->first();
            $result = $route ? DB::table('erp_cutting_results')->where('id', $route->result_id)->lockForUpdate()->first() : null;
            $batch = $result ? DB::table('erp_cutting_settlement_batches')->where('id', $result->settlement_batch_id)->lockForUpdate()->first() : null;
            if (! $route || ! $result || ! $batch) $this->commands->fail('cutting_route_missing', '产出去向不存在。', 404);
            $this->commands->order((int) $batch->cutting_order_id, $user, $permissions, $super, $permission, true);
            $this->commands->version($route, $payload);
            if ($route->route_type !== 'WAREHOUSE' || $route->target_material_requirement_id) {
                $this->commands->fail('cutting_route_not_warehouse', '只有正式入库备货去向可以办理入库。', 409);
            }
            if (! in_array($route->status, ['WAIT_WAREHOUSE', 'PART_WAREHOUSED'], true)) {
                $this->commands->fail('cutting_route_not_receivable', '当前产出去向不处于待入库状态。', 409);
            }
            $quantity = CuttingDecimal::value($payload['quantity'] ?? null);
            $remaining = bcsub((string) $route->quantity, (string) $route->warehoused_qty, 8);
            if (bccomp($quantity, $remaining, 8) > 0) {
                $this->commands->fail('warehouse_quantity_exceeded', '本次入库数量超过该去向尚未入库的数量。');
            }
            $source = DB::table('erp_material_holdings')->where('id', $route->holding_id)->lockForUpdate()->first();
            if (! $source || $source->position_type !== 'WAIT_WAREHOUSE' || (int) $source->position_id !== (int) $route->id
                || $source->status !== 'ACTIVE' || bccomp((string) $source->quantity, $quantity, 8) < 0) {
                $this->commands->fail('warehouse_holding_invalid', '该去向没有足量、有效且唯一的待入库持有份额。', 409);
            }
            [$warehouse, $location, $batchNo] = $this->locator($payload);
            $item = Item::query()->find($result->item_id);
            if (! $item || ! $item->is_stock_item) $this->commands->fail('warehouse_item_invalid', '该下料产出不是可入库物料。', 409);
            $this->assertBatchIdentity((int) $item->id, $batchNo, (int) $source->material_lot_id);

            [$segments, $cost] = $this->allocationSegments((int) $route->id, $quantity);
            if (bccomp((string) $source->total_cost, $cost, 4) < 0) {
                $this->commands->fail('warehouse_cost_exceeded', '待入库持有份额金额不足，禁止掩盖成本差额。', 409);
            }
            $receiptNo = $this->numbers->next('cutting_warehouse_receipt', 'CWR');
            $now = now();
            $receiptId = DB::table('erp_cutting_warehouse_receipts')->insertGetId([
                'receipt_no' => $receiptNo, 'route_id' => $route->id, 'cutting_order_id' => $batch->cutting_order_id,
                'result_id' => $result->id, 'source_holding_id' => $source->id,
                'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'batch_no' => $batchNo,
                'posted_qty' => $quantity, 'posted_cost' => $cost, 'status' => 'POSTING',
                'posted_by_legacy_id' => $this->commands->actor($user), 'created_at' => $now, 'updated_at' => $now,
            ]);
            $receipt = DB::table('erp_cutting_warehouse_receipts')->where('id', $receiptId)->first();
            $transaction = $this->inventory->postCuttingReceipt($receipt, [
                'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id,
                'batch_no' => $batchNo, 'unit_id' => $item->unit_id, 'change_qty' => $quantity,
                'unit_cost' => bcdiv($cost, $quantity, 8), 'cost_amount' => $cost,
                'material_lot_id' => $source->material_lot_id, 'remark' => '下料产出入库 '.$receiptNo,
            ], $user);
            $balance = InventoryBalance::query()->where('item_id', $item->id)->where('warehouse_id', $warehouse->id)
                ->where('location_id', $location->id)->where('batch_no', $batchNo)->lockForUpdate()->first();
            if (! $balance || (int) $balance->material_lot_id !== (int) $source->material_lot_id) {
                $this->commands->fail('cutting_receipt_balance_missing', '下料入库后未形成匹配来源批次的库存余额。', 409);
            }
            $warehouseHoldingId = DB::table('erp_material_holdings')->where('inventory_balance_id', $balance->id)->value('id');
            if (! $warehouseHoldingId) {
                $warehouseHoldingId = DB::table('erp_material_holdings')->insertGetId([
                    'material_lot_id' => $source->material_lot_id, 'position_type' => 'WAREHOUSE',
                    'position_id' => $warehouse->id, 'inventory_balance_id' => $balance->id,
                    'quantity' => null, 'total_cost' => null, 'status' => 'ACTIVE', 'business_version' => 1,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            $reservationIds = [];
            foreach ($segments as $segment) {
                $receiptAllocationId = DB::table('erp_cutting_warehouse_receipt_allocations')->insertGetId([
                    'receipt_id' => $receiptId, 'output_allocation_id' => $segment['allocation']->id,
                    'quantity' => $segment['quantity'], 'total_cost' => $segment['cost'],
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                if ($segment['allocation']->disposition !== 'PUBLIC_UNALLOCATED') {
                    $reservationIds[] = $this->reserve($receiptAllocationId, $segment['allocation'], $result, $balance, $segment['quantity'], $user, $now);
                }
            }

            $leftQty = bcsub((string) $source->quantity, $quantity, 8);
            $leftCost = bcsub((string) $source->total_cost, $cost, 4);
            DB::table('erp_material_holdings')->where('id', $source->id)->update([
                'quantity' => $leftQty, 'total_cost' => $leftCost,
                'status' => bccomp($leftQty, '0', 8) === 0 ? 'CONSUMED' : 'ACTIVE',
                'business_version' => (int) $source->business_version + 1, 'updated_at' => $now,
            ]);
            $warehousedQty = bcadd((string) $route->warehoused_qty, $quantity, 8);
            $warehousedCost = bcadd((string) $route->warehoused_cost, $cost, 4);
            $routeStatus = bccomp($warehousedQty, (string) $route->quantity, 8) >= 0 ? 'WAREHOUSED' : 'PART_WAREHOUSED';
            DB::table('erp_cutting_result_routes')->where('id', $route->id)->update([
                'warehoused_qty' => $warehousedQty, 'warehoused_cost' => $warehousedCost,
                'status' => $routeStatus, 'business_version' => (int) $route->business_version + 1, 'updated_at' => $now,
            ]);
            DB::table('erp_cutting_warehouse_receipts')->where('id', $receiptId)->update([
                'warehouse_holding_id' => $warehouseHoldingId, 'inventory_balance_id' => $balance->id,
                'inventory_transaction_id' => $transaction->id, 'status' => 'POSTED', 'posted_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('erp_material_movements')->insert([
                'movement_no' => $this->numbers->next('material_movement', 'MM'), 'route_id' => $route->id,
                'source_holding_id' => $source->id, 'target_holding_id' => $warehouseHoldingId,
                'action' => 'RECEIPT', 'quantity' => $quantity, 'total_cost' => $cost,
                'inventory_transaction_id' => $transaction->id, 'operator_legacy_id' => $this->commands->actor($user),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $response = ['receipt_id' => $receiptId, 'receipt_no' => $receiptNo, 'status' => 'POSTED',
                'inventory_transaction_id' => (int) $transaction->id, 'inventory_balance_id' => (int) $balance->id,
                'posted_qty' => $quantity, 'posted_cost' => $cost, 'route_id' => (int) $route->id,
                'route_status' => $routeStatus, 'route_designated_qty' => (string) $route->quantity,
                'route_warehoused_qty' => $warehousedQty, 'route_business_version' => (int) $route->business_version + 1,
                'reservation_ids' => $reservationIds];
            $this->commands->event('cutting_warehouse_receipt', $receiptId, 'post', $user, null, $response);
            return $response;
        });
    }

    private function allocationSegments(int $routeId, string $quantity): array
    {
        $allocations = DB::table('erp_cutting_output_allocations')->where('route_id', $routeId)
            ->where('status', 'EFFECTIVE')->orderBy('id')->lockForUpdate()->get();
        $remaining = $quantity; $segments = []; $totalCost = '0.0000';
        foreach ($allocations as $allocation) {
            if (bccomp($remaining, '0', 8) === 0) break;
            $posted = DB::table('erp_cutting_warehouse_receipt_allocations as receipt_allocation')
                ->join('erp_cutting_warehouse_receipts as receipt', 'receipt.id', '=', 'receipt_allocation.receipt_id')
                ->where('receipt_allocation.output_allocation_id', $allocation->id)->where('receipt.status', 'POSTED')
                ->selectRaw('COALESCE(SUM(receipt_allocation.quantity),0) AS quantity, COALESCE(SUM(receipt_allocation.total_cost),0) AS total_cost')->first();
            $availableQty = bcsub((string) $allocation->quantity, (string) $posted->quantity, 8);
            $availableCost = bcsub((string) $allocation->total_cost, (string) $posted->total_cost, 4);
            if (bccomp($availableQty, '0', 8) <= 0) continue;
            $take = bccomp($remaining, $availableQty, 8) >= 0 ? $availableQty : $remaining;
            $cost = bccomp($take, $availableQty, 8) === 0 ? $availableCost : CuttingDecimal::share($availableCost, $availableQty, $take);
            $segments[] = ['allocation' => $allocation, 'quantity' => $take, 'cost' => $cost];
            $remaining = bcsub($remaining, $take, 8); $totalCost = bcadd($totalCost, $cost, 4);
        }
        if (bccomp($remaining, '0', 8) !== 0) {
            $this->commands->fail('warehouse_allocation_missing', '正式入库数量缺少对应的核算归属。', 409);
        }
        return [$segments, $totalCost];
    }

    private function reserve(int $receiptAllocationId, object $allocation, object $result, InventoryBalance $balance, string $quantity, object $user, $now): int
    {
        $plan = $allocation->plan_id ? DB::table('erp_cutting_plan_allocations')->where('id', $allocation->plan_id)->first() : null;
        if ($allocation->disposition === 'PLAN' && ! $plan) {
            $this->commands->fail('warehouse_plan_missing', '专用入库归属的正式计划不存在。', 409);
        }
        $scope = $allocation->disposition === 'PLAN' ? 'PLAN' : 'RESTRICTED_CONFIGURATION';
        $id = DB::table('erp_cutting_inventory_reservations')->insertGetId([
            'reservation_no' => $this->numbers->next('cutting_inventory_reservation', 'CIR'),
            'receipt_allocation_id' => $receiptAllocationId, 'plan_id' => $plan?->id,
            'configuration_id' => $result->configuration_id,
            'target_material_requirement_id' => $plan?->target_material_requirement_id,
            'inventory_balance_id' => $balance->id, 'item_id' => $balance->item_id,
            'reservation_scope' => $scope, 'reserved_qty' => $quantity, 'issued_qty' => 0,
            'status' => 'ACTIVE', 'created_by_legacy_id' => $this->commands->actor($user),
            'reserved_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->changeLock($balance, (float) $quantity);
        return $id;
    }

    private function changeLock(InventoryBalance $balance, float $quantity): void
    {
        $locked = (float) $balance->quantity_locked + $quantity;
        if ($locked > (float) $balance->quantity_on_hand + 0.00000001) {
            $this->commands->fail('cutting_inventory_lock_invalid', '专用下料库存锁定量超过真实在库量。', 409);
        }
        $balance->quantity_locked = $locked;
        $balance->quantity_available = $this->availability->calculate((float) $balance->quantity_on_hand, $locked,
            (float) $balance->quantity_defective, (float) $balance->quantity_pending);
        $balance->save();
        $location = InventoryLocationBalance::query()->where('item_id', $balance->item_id)
            ->where('warehouse_id', $balance->warehouse_id)->where('location_id', $balance->location_id)->lockForUpdate()->firstOrFail();
        $location->quantity_locked = (float) $location->quantity_locked + $quantity;
        $location->quantity_available = $this->availability->calculate((float) $location->quantity_on_hand,
            (float) $location->quantity_locked, (float) $location->quantity_defective, (float) $location->quantity_pending);
        $location->save();
    }

    private function locator(array $payload): array
    {
        $warehouse = Warehouse::query()->whereKey((int) ($payload['warehouse_id'] ?? 0))->where('status', 'enabled')->first();
        $location = Location::query()->whereKey((int) ($payload['location_id'] ?? 0))->where('status', 'enabled')->first();
        $batchNo = trim((string) ($payload['batch_no'] ?? ''));
        if (! $warehouse) $this->commands->fail('warehouse_invalid', '入库仓库不存在或已停用。');
        if (! $location || (int) $location->warehouse_id !== (int) $warehouse->id) {
            $this->commands->fail('location_invalid', '入库库位不属于所选仓库或已停用。');
        }
        if ($batchNo === '' || strlen($batchNo) > 80) $this->commands->fail('batch_no_invalid', '入库批次号不能为空且不能超过80字节。');
        return [$warehouse, $location, $batchNo];
    }

    private function assertBatchIdentity(int $itemId, string $batchNo, int $materialLotId): void
    {
        $batch = DB::table('erp_inventory_batches')->where('item_id', $itemId)->where('batch_no', $batchNo)->lockForUpdate()->first();
        if ($batch && $batch->material_lot_id && (int) $batch->material_lot_id !== $materialLotId) {
            $this->commands->fail('warehouse_batch_identity_conflict', '该库存批次号已属于另一材料批次，不能混入本次下料产出。', 409);
        }
        $balances = InventoryBalance::query()->where('item_id', $itemId)->where('batch_no', $batchNo)
            ->where('quantity_on_hand', '>', 0)->lockForUpdate()->get();
        foreach ($balances as $balance) {
            if (! $balance->material_lot_id || (int) $balance->material_lot_id !== $materialLotId) {
                $this->commands->fail('warehouse_batch_identity_conflict', '该库存批次号已有无法证明同源的库存，禁止合并入库。', 409);
            }
        }
    }

    private function authorize(int $routeId, object $user, array $permissions, bool $super, string $permission): void
    {
        $this->commands->permission($permissions, $permission);
        $row = DB::table('erp_cutting_result_routes as route')->join('erp_cutting_results as result', 'result.id', '=', 'route.result_id')
            ->join('erp_cutting_settlement_batches as batch', 'batch.id', '=', 'result.settlement_batch_id')
            ->where('route.id', $routeId)->select('batch.cutting_order_id')->first();
        if (! $row) $this->commands->fail('cutting_route_missing', '产出去向不存在。', 404);
        $this->commands->order((int) $row->cutting_order_id, $user, $permissions, $super, $permission);
    }
}
