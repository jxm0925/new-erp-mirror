<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesOrderCommand;
use App\Models\Erp\SalesOrderFulfillment;
use App\Models\Erp\SalesOrderLine;
use App\Models\Erp\InventoryReservation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class SalesOrderInventoryLockService
{
    public function __construct(
        private readonly InventoryAvailabilityService $availability,
        private readonly InventoryReservationService $reservations,
    ) {}

    public function lock(int $orderId, array $payload, object $user, array $permissions): array
    {
        $this->permission($permissions, 'sales_order.inventory_lock');
        return $this->command($orderId, $payload, $user, function () use ($orderId, $payload, $user): array {
            $order = SalesOrder::query()->with('lines')->lockForUpdate()->find($orderId);
            if (! $order) $this->fail('sales_order_not_found', '销售订单不存在。', 404);
            if ((int) $order->business_version !== (int) $payload['expected_version']) {
                $this->fail('version_conflict', '销售订单版本已变化，请刷新后重试。', 409, [
                    'current_version' => (int) $order->business_version,
                ]);
            }
            if ($order->order_status !== 'confirmed') {
                $this->fail('sales_order_not_confirmed', '销售订单正式确认后才能锁库存。');
            }
            if ($order->order_status === 'cancelled') {
                $this->fail('sales_order_cancelled', '已取消销售订单不能锁库存。');
            }

            $created = 0;
            foreach ($order->lines as $line) {
                if (! $this->isPhysical($line)) continue;
                $alreadyAllocated = $this->allocatedSalesQty($line);
                $remaining = max(0, (float) $line->order_qty - (float) $line->cancelled_qty - $alreadyAllocated);
                if ($remaining <= 0.00000001) continue;

                $analysis = $this->availability->analyzeSalesOrderLine($line, $remaining, true);
                $inventorySalesQty = (float) $analysis['suggested_inventory_qty'];
                if ($inventorySalesQty <= 0.00000001) continue;
                $factor = $this->factor($line);
                $allocations = $this->availability->allocateBaseQuantity(
                    $analysis,
                    round($inventorySalesQty * $factor, 8),
                );
                $remainingSales = $inventorySalesQty;
                foreach ($allocations as $index => $allocation) {
                    $salesQty = $index === array_key_last($allocations)
                        ? $remainingSales
                        : round((float) $allocation['base_qty'] / $factor, 8);
                    $remainingSales = max(0, $remainingSales - $salesQty);
                    SalesOrderFulfillment::create([
                        'sales_order_id' => $order->id,
                        'sales_order_line_id' => $line->id,
                        'fulfillment_type' => 'inventory',
                        'fulfillment_qty' => (float) $allocation['base_qty'],
                        'sales_qty' => $salesQty,
                        'sales_unit_id' => $line->unit_id,
                        'sales_unit_code_snapshot' => $line->unit_code_snapshot,
                        'sales_unit_name_snapshot' => $line->unit_name_snapshot,
                        'fulfillment_factor_snapshot' => $factor,
                        'item_base_qty' => (float) $allocation['base_qty'],
                        'item_id' => $line->item_id,
                        'base_unit_id' => $line->item_base_unit_id,
                        'base_unit_name_snapshot' => $line->item_base_unit_name_snapshot,
                        'inventory_balance_id' => $allocation['inventory_balance_id'],
                        'warehouse_id' => $allocation['warehouse_id'],
                        'location_id' => $allocation['location_id'],
                        'batch_no' => $allocation['batch_no'],
                        'reservation_status' => 'pending',
                        'production_requirement_status' => 'not_required',
                        'demand_status' => 'confirmed',
                        'match_snapshot' => [
                            'source' => 'sales_order_inventory_lock',
                            'snapshot_locked' => true,
                            'client_command_id' => $payload['client_command_id'],
                            'inventory_allocation' => $allocation,
                            'planning' => [
                                'calculated_at' => $analysis['calculated_at'],
                                'available_base_qty' => $analysis['available_base_qty'],
                                'available_sales_qty' => $analysis['available_sales_qty'],
                                'locked_sales_qty' => $salesQty,
                                'pending_production_qty' => max(0, $remaining - $inventorySalesQty),
                            ],
                        ],
                    ]);
                    $created++;
                }
            }

            $this->reservations->reserveForSalesOrder($order->fresh('fulfillments'));
            $projection = $this->projection($order->fresh(['lines', 'fulfillments']));
            $status = $projection['totals']['locked_inventory_qty'] <= 0.00000001
                ? 'unavailable'
                : ($projection['totals']['pending_production_qty'] > 0.00000001 ? 'partial' : 'locked');
            $changed = $created > 0 || ! $order->inventory_locked_at;
            if ($changed) {
                $order->forceFill([
                    'inventory_lock_status' => $status,
                    'inventory_locked_at' => now(),
                    'inventory_locked_by_legacy_id' => $this->userId($user),
                    'business_version' => (int) $order->business_version + 1,
                ])->save();
            }

            DB::table('erp_sales_order_logs')->insert([
                'sales_order_id' => $order->id,
                'action' => 'inventory_lock',
                'before_status' => null,
                'after_status' => $status,
                'payload' => json_encode([
                    'client_command_id' => $payload['client_command_id'],
                    'created_fulfillment_count' => $created,
                    'totals' => $projection['totals'],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'operator' => $user->nickname ?? $user->username ?? 'system',
                'content' => $created > 0 ? '销售订单已按当前可用库存完成正式锁定。' : '当前没有新增可锁定库存，未重复占用。',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $result = $this->projection($order->fresh(['lines', 'fulfillments']));
            $result['created_fulfillment_count'] = $created;
            return $result;
        });
    }

    public function projection(SalesOrder $order): array
    {
        $order->loadMissing(['lines', 'fulfillments', 'productionRequirements']);
        $rows = $order->lines->map(function (SalesOrderLine $line): array {
            $effective = max(0, (float) $line->order_qty - (float) $line->cancelled_qty);
            $factor = max(0.00000001, (float) $line->fulfillment_factor_snapshot);
            $activeReservations = InventoryReservation::query()
                ->where('source_type', InventoryReservation::SOURCE_SALES_ORDER)
                ->where('source_order_id', $line->sales_order_id)
                ->where('source_order_line_id', $line->id)
                ->whereIn('reservation_status', ['active', 'partially_released', 'converted_to_shipment'])
                ->get();
            $inventory = $activeReservations->sum(fn ($row) => (float) $row->reserved_qty / $factor);
            $replenished = (float) $line->production_replenished_qty;
            $shipped = (float) $line->shipped_qty;
            $activeDemandQty = $line->productionRequirements
                ->where('is_active', true)
                ->whereNotIn('requirement_status', ['cancelled', 'superseded', 'closed', 'completed'])
                ->sum(fn ($row) => (float) ($row->remaining_qty ?? $row->production_qty ?? 0));
            $pendingProduction = ! $this->isPhysical($line) ? 0.0 : ($activeDemandQty > 0.00000001
                ? $activeDemandQty
                : max(0, $effective - $shipped - $inventory));
            return [
                'sales_order_line_id' => (int) $line->id,
                'line_no' => (int) $line->line_no,
                'order_qty' => $effective,
                'locked_inventory_qty' => round($inventory, 8),
                'pending_production_qty' => round($pendingProduction, 8),
                'production_replenished_qty' => $replenished,
                'shipped_qty' => round($shipped, 8),
                'pending_fulfillment_qty' => round(max(0, $effective - $shipped), 8),
            ];
        })->values();
        $totals = [];
        foreach (['order_qty', 'locked_inventory_qty', 'pending_production_qty', 'production_replenished_qty', 'shipped_qty', 'pending_fulfillment_qty'] as $key) {
            $totals[$key] = round((float) $rows->sum($key), 8);
        }
        return [
            'sales_order_id' => (int) $order->id,
            'sales_order_no' => $order->sales_order_no,
            'inventory_lock_status' => $order->inventory_lock_status ?: 'pending',
            'inventory_locked_at' => optional($order->inventory_locked_at)->toISOString(),
            'business_version' => (int) $order->business_version,
            'totals' => $totals,
            'lines' => $rows->all(),
        ];
    }

    private function command(int $orderId, array $payload, object $user, callable $action): array
    {
        $commandId = trim((string) ($payload['client_command_id'] ?? ''));
        if ($commandId === '') $this->fail('client_command_id_required', '锁库存必须提供 client_command_id。');
        $hashPayload = $payload;
        ksort($hashPayload);
        $hash = hash('sha256', json_encode($hashPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        try {
            return DB::transaction(function () use ($orderId, $commandId, $hash, $user, $action): array {
                $existing = SalesOrderCommand::query()->where('client_command_id', $commandId)->lockForUpdate()->first();
                if ($existing) return $this->replay($existing, $orderId, $hash);
                $ledger = SalesOrderCommand::create([
                    'client_command_id' => $commandId,
                    'command_type' => 'lock_inventory',
                    'sales_order_id' => $orderId,
                    'request_hash' => $hash,
                    'status' => 'processing',
                    'initiated_by_legacy_id' => $this->userId($user),
                    'processing_started_at' => now(),
                ]);
                $result = $action();
                $ledger->update(['response_snapshot' => $result, 'status' => 'succeeded', 'processing_finished_at' => now()]);
                return $result;
            }, 5);
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) throw $e;
            $existing = SalesOrderCommand::query()->where('client_command_id', $commandId)->first();
            if ($existing) return $this->replay($existing, $orderId, $hash);
            throw $e;
        }
    }

    private function replay(SalesOrderCommand $command, int $orderId, string $hash): array
    {
        if ($command->command_type !== 'lock_inventory' || (int) $command->sales_order_id !== $orderId || $command->request_hash !== $hash) {
            $this->fail('command_conflict', '该 client_command_id 已用于不同的销售订单请求。', 409);
        }
        if ($command->status !== 'succeeded' || ! is_array($command->response_snapshot)) {
            $this->fail('command_processing', '相同锁库存命令正在处理中，请稍后重试。', 409);
        }
        return $command->response_snapshot;
    }

    private function allocatedSalesQty(SalesOrderLine $line): float
    {
        return (float) SalesOrderFulfillment::query()->where('sales_order_line_id', $line->id)
            ->where('demand_status', 'confirmed')
            ->sum(DB::raw('COALESCE(sales_qty, 0)'));
    }

    private function factor(SalesOrderLine $line): float
    {
        $factor = (float) $line->fulfillment_factor_snapshot;
        if ($factor <= 0) $this->fail('fulfillment_factor_missing', "第 {$line->line_no} 行缺少有效履约换算因子。");
        return $factor;
    }

    private function isPhysical(SalesOrderLine $line): bool
    {
        return ! in_array($line->line_type, ['service', 'no_delivery', 'fee', 'auxiliary'], true);
    }

    private function permission(array $permissions, string $code): void
    {
        if (! in_array($code, $permissions, true)) $this->fail('permission_denied', '当前用户没有锁库存按钮权限。', 403, ['permission' => $code]);
    }

    private function userId(object $user): int { return (int) ($user->legacy_id ?? $user->id ?? 0); }
    private function fail(string $code, string $message, int $status = 422, array $details = []): never
    {
        throw new WorkOrderDomainException($code, $message, $status, $details);
    }
}
