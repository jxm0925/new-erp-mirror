<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventoryLocationBalance;
use App\Models\Erp\InventorySerial;
use App\Models\Erp\Item;
use App\Models\Erp\ProductionOutputRecord;
use App\Models\Erp\ProductionQuantityOperation;
use App\Models\Erp\ProductionUnitOperation;
use App\Models\Erp\WorkOrder;
use Illuminate\Support\Facades\DB;

/**
 * Owns the two terminal destinations of a stock-prebuild order.  Reserved
 * output must never briefly appear as common available stock: the receipt,
 * ownership fact and inventory lock are written in the same outer transaction.
 */
final class ProductionStockPrebuildService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly InventoryAvailabilityService $availability,
    ) {}

    public function routeApprovedDirectOutput(WorkOrder $workOrder, ProductionOutputRecord $output, object $user): ?int
    {
        if (! $this->isReserved($workOrder) || $this->requiresWarehouse($workOrder, $output)) return null;
        $existing = DB::table('erp_production_operation_handovers')->where('output_record_id', $output->id)->lockForUpdate()->first();
        if ($existing) return (int) $existing->id;

        $target = $this->targetContext($workOrder, (int) $output->output_item_id);
        $now = now();
        $id = DB::table('erp_production_operation_handovers')->insertGetId([
            'handover_no' => $this->numbers->next('production_handover', 'PHO'),
            'work_order_id' => $workOrder->id,
            'source_target_type' => $output->source_target_type,
            'source_target_id' => $output->source_target_id,
            'target_target_type' => $target['type'],
            'target_target_id' => $target['id'],
            'target_material_requirement_id' => $target['requirement_id'],
            'output_record_id' => $output->id,
            'status' => 'WAIT_RECEIVE',
            'handed_over_by_legacy_id' => $this->userId($user),
            'handed_over_at' => $now,
            'identity_snapshot' => json_encode([
                'output_no' => $output->output_no,
                'source_stock_prebuild_work_order_id' => (int) $workOrder->id,
                'reserved_for_work_order_id' => (int) $workOrder->reserved_for_work_order_id,
                'reserved_for_production_unit_id' => $workOrder->reserved_for_production_unit_id ? (int) $workOrder->reserved_for_production_unit_id : null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'business_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $output->update(['status' => 'HANDED_OVER', 'business_version' => (int) $output->business_version + 1]);
        return $id;
    }

    public function reserveWarehouseOutput(
        WorkOrder $workOrder,
        ProductionOutputRecord $output,
        int $finishedGoodsReceiptId,
        array $posting,
        float $quantity,
        object $user,
    ): array {
        if ($workOrder->assembly_component_demand_id) {
            return app(AssemblyProductionInventoryService::class)->reserveChildReceipt($workOrder, $output, $finishedGoodsReceiptId, $posting, $quantity, $user);
        }
        if (! $this->isReserved($workOrder)) return ['reservation_id' => null, 'internal_issue_task_id' => null];
        $existing = DB::table('erp_production_inventory_reservations')
            ->where('finished_goods_receipt_id', $finishedGoodsReceiptId)->lockForUpdate()->first();
        if ($existing) {
            $issueId = DB::table('erp_production_internal_issue_lines')->where('production_inventory_reservation_id', $existing->id)
                ->join('erp_production_internal_issue_tasks', 'erp_production_internal_issue_tasks.id', '=', 'erp_production_internal_issue_lines.issue_task_id')
                ->value('erp_production_internal_issue_tasks.id');
            return ['reservation_id' => (int) $existing->id, 'internal_issue_task_id' => $issueId ? (int) $issueId : null];
        }

        $target = $this->targetContext($workOrder, (int) $output->output_item_id);
        $balance = InventoryBalance::query()->where('item_id', $output->output_item_id)
            ->where('warehouse_id', (int) $posting['warehouse_id'])->where('location_id', (int) $posting['location_id'])
            ->where('batch_no', (string) $posting['batch_no'])->lockForUpdate()->first();
        if (! $balance || (float) $balance->quantity_on_hand + 0.00000001 < $quantity) {
            $this->fail('reserved_output_balance_missing', '指定工单备货入库后未找到足量库存，无法建立保留归属。', 409);
        }

        $this->changeLockedQuantity($balance, $quantity);
        $now = now();
        $reservationId = DB::table('erp_production_inventory_reservations')->insertGetId([
            'reservation_no' => $this->numbers->next('production_inventory_reservation', 'PIR'),
            'source_work_order_id' => $workOrder->id,
            'source_output_record_id' => $output->id,
            'finished_goods_receipt_id' => $finishedGoodsReceiptId,
            'inventory_balance_id' => $balance->id,
            'target_work_order_id' => $workOrder->reserved_for_work_order_id,
            'target_production_unit_id' => $workOrder->reserved_for_production_unit_id,
            'target_routing_operation_id' => $workOrder->reserved_for_target_operation_id,
            'target_type' => $target['type'],
            'target_id' => $target['id'],
            'target_material_requirement_id' => $target['requirement_id'],
            'item_id' => $output->output_item_id,
            'reserved_base_qty' => $quantity,
            'issued_base_qty' => 0,
            'status' => 'ACTIVE',
            'created_by_legacy_id' => $this->userId($user),
            'reserved_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $issueId = DB::table('erp_production_internal_issue_tasks')->insertGetId([
            'issue_no' => $this->numbers->next('production_internal_issue', 'PII'),
            'work_order_id' => $workOrder->reserved_for_work_order_id,
            'target_task_id' => $target['task_id'],
            'target_type' => $target['type'],
            'target_id' => $target['id'],
            'source_type' => 'continuation_reserved',
            'status' => 'WAIT_ISSUE',
            'business_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('erp_production_internal_issue_lines')->insert([
            'issue_task_id' => $issueId,
            'output_record_id' => $output->id,
            'production_inventory_reservation_id' => $reservationId,
            'target_material_requirement_id' => $target['requirement_id'],
            'item_id' => $output->output_item_id,
            'inventory_balance_id' => $balance->id,
            'warehouse_id' => $balance->warehouse_id,
            'location_id' => $balance->location_id,
            'batch_no' => $balance->batch_no,
            'serial_id' => $output->serial_id,
            'serial_no_snapshot' => $output->serial_no_snapshot,
            'issue_base_qty' => $quantity,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return ['reservation_id' => $reservationId, 'internal_issue_task_id' => $issueId];
    }

    public function releaseForIssue(object $issue, iterable $lines): void
    {
        foreach ($lines as $line) {
            if (! $line->production_inventory_reservation_id) continue;
            $reservation = DB::table('erp_production_inventory_reservations')->where('id', $line->production_inventory_reservation_id)
                ->lockForUpdate()->first();
            if (! $reservation || $reservation->status !== 'ACTIVE'
                || (int) $reservation->target_work_order_id !== (int) $issue->work_order_id
                || $reservation->target_type !== $issue->target_type
                || (int) $reservation->target_id !== (int) $issue->target_id) {
                $this->fail('continuation_reservation_invalid', '内部领用与指定工单库存归属不匹配，禁止交出。', 409);
            }
            $remaining = (float) $reservation->reserved_base_qty - (float) $reservation->issued_base_qty;
            if ($remaining + 0.00000001 < (float) $line->issue_base_qty) {
                $this->fail('continuation_reservation_exceeded', '本次领用超过指定工单剩余保留量。', 409);
            }
            $balance = InventoryBalance::query()->whereKey($reservation->inventory_balance_id)->lockForUpdate()->firstOrFail();
            $this->changeLockedQuantity($balance, -(float) $line->issue_base_qty);
            $issued = (float) $reservation->issued_base_qty + (float) $line->issue_base_qty;
            DB::table('erp_production_inventory_reservations')->where('id', $reservation->id)->update([
                'issued_base_qty' => $issued,
                'status' => $issued + 0.00000001 >= (float) $reservation->reserved_base_qty ? 'CONSUMED' : 'ACTIVE',
                'consumed_at' => $issued + 0.00000001 >= (float) $reservation->reserved_base_qty ? now() : null,
                'updated_at' => now(),
            ]);
            $serial = InventorySerial::query()->where('inventory_balance_id', $balance->id)
                ->where('serial_no', $line->serial_no_snapshot)->where('serial_status', 'continuation_reserved')
                ->lockForUpdate()->first();
            if ($serial) {
                $serial->update(['serial_status' => 'production_consumed', 'outbound_at' => now()]);
                DB::table('erp_inventory_serial_events')->insert([
                    'inventory_serial_id' => $serial->id, 'event_type' => 'continuation_reserved_issued',
                    'document_type' => 'production_internal_issue', 'document_id' => $issue->id,
                    'document_no' => $issue->issue_no, 'from_status' => 'continuation_reserved',
                    'to_status' => 'production_consumed', 'warehouse_id' => $serial->warehouse_id,
                    'location_id' => $serial->location_id, 'batch_no' => $serial->batch_no,
                    'event_payload' => json_encode(['production_inventory_reservation_id' => (int) $reservation->id], JSON_UNESCAPED_UNICODE),
                    'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function registerOutputSerial(WorkOrder $workOrder, ProductionOutputRecord $output, array $posting, object $user): ?int
    {
        $item = Item::query()->find($output->output_item_id);
        if (! $item || $item->serialTrackingMode() === 'none') return null;
        if (abs((float) $output->output_base_qty - 1.0) > 0.00000001) {
            $this->fail('production_serial_quantity_invalid', '序列号管理的生产产出必须逐件入库。', 409);
        }
        $balance = InventoryBalance::query()->where('item_id', $output->output_item_id)
            ->where('warehouse_id', (int) $posting['warehouse_id'])->where('location_id', (int) $posting['location_id'])
            ->where('batch_no', (string) $posting['batch_no'])->lockForUpdate()->firstOrFail();
        $productionSerial = $output->serial_id ? DB::table('erp_production_serials')->where('id', $output->serial_id)->lockForUpdate()->first() : null;
        $serialNo = trim((string) ($output->serial_no_snapshot ?: $productionSerial?->serial_no));
        if ($serialNo === '') {
            $prefix = trim((string) ($item->serial_number_prefix ?: $item->item_code)) ?: 'SN';
            $serialNo = $this->numbers->next('production_inventory_serial_'.$item->id, $prefix);
        }
        $serial = InventorySerial::query()->where('serial_no', $serialNo)->lockForUpdate()->first();
        if ($serial && ((int) $serial->item_id !== (int) $item->id || $serial->source_document_type !== 'production_output')) {
            $this->fail('production_serial_conflict', '生产序列号已属于其他实物，禁止重复入库。', 409);
        }
        $status = $this->isReserved($workOrder) ? 'continuation_reserved' : 'available';
        if ($serial && (int) $serial->source_document_id !== (int) $output->id) {
            // A resumed unit keeps its identity. Rebinding is valid only after its previous
            // stock was formally consumed, and that exact source remains in its genealogy.
            $queue = [(int) $output->id]; $seen = []; $originFound = false;
            while ($queue !== []) {
                $child = array_pop($queue); if (isset($seen[$child])) continue; $seen[$child] = true;
                foreach (DB::table('erp_production_output_lineage_links')->where('child_output_record_id', $child)->pluck('parent_output_record_id') as $parent) {
                    if ((int) $parent === (int) $serial->source_document_id) $originFound = true;
                    $queue[] = (int) $parent;
                }
            }
            if ($serial->serial_status !== 'production_consumed' || ! $originFound) $this->fail('production_serial_conflict', '该序列号没有同源续接领用记录，禁止重复入库。', 409);
            $previousOutput = (int) $serial->source_document_id;
            $serial->update(['inventory_balance_id' => $balance->id, 'warehouse_id' => $balance->warehouse_id,
                'location_id' => $balance->location_id, 'batch_no' => $balance->batch_no, 'serial_status' => $status,
                'source_document_id' => $output->id, 'source_document_no' => $output->output_no, 'posted_at' => now()]);
            DB::table('erp_inventory_serial_events')->insert(['inventory_serial_id' => $serial->id, 'event_type' => 'production_continuation_output_posted',
                'document_type' => 'production_output', 'document_id' => $output->id, 'document_no' => $output->output_no,
                'from_status' => 'production_consumed', 'to_status' => $status, 'warehouse_id' => $balance->warehouse_id,
                'location_id' => $balance->location_id, 'batch_no' => $balance->batch_no, 'operator_id' => $this->userId($user),
                'event_payload' => json_encode(['work_order_id' => (int) $workOrder->id, 'previous_output_record_id' => $previousOutput], JSON_THROW_ON_ERROR),
                'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        if (! $serial) {
            $serial = InventorySerial::create([
                'serial_no' => $serialNo,
                'inventory_balance_id' => $balance->id,
                'item_id' => $item->id,
                'warehouse_id' => $balance->warehouse_id,
                'location_id' => $balance->location_id,
                'batch_no' => $balance->batch_no,
                'origin_type' => 'production',
                'number_source' => 'system',
                'source_document_type' => 'production_output',
                'source_document_id' => $output->id,
                'source_document_no' => $output->output_no,
                'serial_status' => $status,
                'received_at' => now(),
                'registered_at' => now(),
                'posted_at' => now(),
            ]);
            DB::table('erp_inventory_serial_events')->insert([
                'inventory_serial_id' => $serial->id,
                'event_type' => 'production_output_posted',
                'document_type' => 'production_output',
                'document_id' => $output->id,
                'document_no' => $output->output_no,
                'from_status' => 'produced',
                'to_status' => $status,
                'warehouse_id' => $balance->warehouse_id,
                'location_id' => $balance->location_id,
                'batch_no' => $balance->batch_no,
                'operator_id' => $this->userId($user),
                'event_payload' => json_encode(['work_order_id' => (int) $workOrder->id], JSON_UNESCAPED_UNICODE),
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $output->update(['inventory_serial_id' => $serial->id, 'serial_no_snapshot' => $serialNo]);
        if ($productionSerial) DB::table('erp_production_serials')->where('id', $productionSerial->id)->update([
            'inventory_serial_id' => $serial->id, 'status' => 'inventory_bound', 'updated_at' => now(),
        ]);
        DB::table('erp_production_output_lineage_links')->where('parent_output_record_id', $output->id)
            ->update(['parent_inventory_serial_id' => $serial->id, 'updated_at' => now()]);
        DB::table('erp_production_output_lineage_links')->where('child_output_record_id', $output->id)
            ->update(['child_inventory_serial_id' => $serial->id, 'updated_at' => now()]);
        return (int) $serial->id;
    }

    public function requiresWarehouse(WorkOrder $workOrder, ProductionOutputRecord $output): bool
    {
        if ($workOrder->stocking_purpose === 'common_inventory') return true;
        return $output->output_mode_snapshot === 'warehouse_required'
            || ($output->output_mode_snapshot === 'warehouse_optional' && $output->disposition === 'warehouse');
    }

    private function targetContext(WorkOrder $source, int $itemId): array
    {
        try {
            return app(StockPrebuildTargetService::class)->resolve((int) $source->reserved_for_work_order_id,
                $source->reserved_for_production_unit_id ? (int) $source->reserved_for_production_unit_id : null,
                (int) $source->reserved_for_target_operation_id, $itemId);
        } catch (WorkOrderDomainException $exception) {
            $this->fail($exception->errorCode, $exception->getMessage(), 409);
        }
    }

    private function changeLockedQuantity(InventoryBalance $balance, float $delta): void
    {
        $locked = (float) $balance->quantity_locked + $delta;
        if ($locked < -0.00000001 || $locked > (float) $balance->quantity_on_hand + 0.00000001) {
            $this->fail('continuation_inventory_lock_invalid', '指定工单库存锁定量与在库量不一致。', 409);
        }
        $balance->quantity_locked = max(0, $locked);
        $balance->quantity_available = $this->availability->calculate((float) $balance->quantity_on_hand,
            (float) $balance->quantity_locked, (float) $balance->quantity_defective, (float) $balance->quantity_pending);
        $balance->save();
        $location = InventoryLocationBalance::query()->where('item_id', $balance->item_id)
            ->where('warehouse_id', $balance->warehouse_id)->where('location_id', $balance->location_id)->lockForUpdate()->firstOrFail();
        $location->quantity_locked = max(0, (float) $location->quantity_locked + $delta);
        $location->quantity_available = $this->availability->calculate((float) $location->quantity_on_hand,
            (float) $location->quantity_locked, (float) $location->quantity_defective, (float) $location->quantity_pending);
        $location->save();
    }

    private function isReserved(WorkOrder $workOrder): bool
    {
        return $workOrder->source_type === 'stock_prebuild' && $workOrder->stocking_purpose === 'reserved_for_work_order';
    }

    private function userId(object $user): int { return (int) ($user->legacy_id ?? $user->id ?? 0); }
    private function fail(string $code, string $message, int $status = 422): never { throw new WorkOrderDomainException($code, $message, $status); }
}
