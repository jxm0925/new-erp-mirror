<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\AssemblyComponentDemand;
use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventoryLocationBalance;
use App\Models\Erp\WorkOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Allocations are ownership facts; the existing inventory balances remain the only stock ledger. */
final class AssemblyProductionInventoryService
{
    public function __construct(private readonly InventoryAvailabilityService $availability) {}

    public function reserve(AssemblyComponentDemand $demand, array $allocation, object $user, ?int $receiptId = null): int
    {
        $balance = InventoryBalance::query()->whereKey($allocation['inventory_balance_id'])->lockForUpdate()->firstOrFail();
        $qty = (string) $allocation['base_qty'];
        if ((int) $balance->item_id !== (int) $demand->item_id || (int) $balance->unit_id !== (int) $demand->base_unit_id
            || bccomp($qty, '0', 8) <= 0 || bccomp($qty, bcadd($qty, '0', 4), 8) !== 0
            || bccomp($this->availability->availableForOutboundDecimal($balance), $qty, 8) < 0) {
            $this->fail('assembly_stock_changed', '自产部件库存归属与可保留数量已变化，请重新计算。');
        }
        $this->changeLock($balance, $qty);
        $requirementId = DB::table('erp_work_order_material_requirements')->where('work_order_id', $demand->parent_work_order_id)
            ->where('bom_item_id', $demand->bom_item_id)->value('id');
        return DB::table('erp_assembly_inventory_reservations')->insertGetId([
            'component_demand_id' => $demand->id, 'work_order_id' => $demand->parent_work_order_id,
            'inventory_balance_id' => $balance->id, 'material_requirement_id' => $requirementId,
            'finished_goods_receipt_id' => $receiptId, 'reserved_base_qty' => $qty, 'consumed_base_qty' => 0,
            'status' => 'ACTIVE', 'source_snapshot' => json_encode([
                'item_id' => $balance->item_id, 'warehouse_id' => $balance->warehouse_id,
                'location_id' => $balance->location_id, 'batch_no' => $balance->batch_no,
                'source' => $receiptId ? 'linked_child_finished_goods_receipt' : 'available_component_inventory',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_by_legacy_id' => (int) ($user->legacy_id ?? $user->id ?? 0), 'reserved_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function bindPublished(WorkOrder $wo): void
    {
        if (! Schema::hasTable('erp_assembly_inventory_reservations')) return;
        $demands = AssemblyComponentDemand::where('parent_work_order_id', $wo->id)->where('status', 'PREPARED')->orderBy('id')->lockForUpdate()->get();
        foreach ($demands as $demand) {
            $rows = DB::table('erp_work_order_material_requirements')->where('work_order_id', $wo->id)->where('bom_item_id', $demand->bom_item_id)->get();
            if ($rows->count() !== 1 || (int) $rows[0]->component_item_id !== (int) $demand->item_id
                || (int) $rows[0]->base_unit_id !== (int) $demand->base_unit_id
                || bccomp((string) $rows[0]->base_required_qty, (string) $demand->required_base_qty, 8) !== 0) {
                $this->fail('assembly_material_changed', '工单正式部件需求与已准备的自产计划不一致，禁止发布。');
            }
            DB::table('erp_assembly_inventory_reservations')->where('component_demand_id', $demand->id)->where('status', 'ACTIVE')
                ->update(['material_requirement_id' => $rows[0]->id, 'updated_at' => now()]);
        }
    }

    public function ownedQuantity(int $balanceId, int $requirementId): string
    {
        if (! Schema::hasTable('erp_assembly_inventory_reservations')) return '0.00000000';
        return (string) DB::table('erp_assembly_inventory_reservations')->where('inventory_balance_id', $balanceId)
            ->where('material_requirement_id', $requirementId)->where('status', 'ACTIVE')
            ->selectRaw('COALESCE(SUM(reserved_base_qty - consumed_base_qty), 0) as qty')->value('qty');
    }

    public function totalOwnedQuantity(int $requirementId): string
    {
        if (! Schema::hasTable('erp_assembly_inventory_reservations')) return '0.00000000';
        return (string) DB::table('erp_assembly_inventory_reservations')->where('material_requirement_id', $requirementId)->where('status', 'ACTIVE')
            ->selectRaw('COALESCE(SUM(reserved_base_qty - consumed_base_qty), 0) as qty')->value('qty');
    }

    /** Called immediately before ordinary picking posting, inside that same command transaction. */
    public function consumePicking(object $task): void
    {
        if (! Schema::hasTable('erp_assembly_inventory_reservations')) return;
        foreach ($task->lines->where('actual_pick_qty', '>', 0)->sortBy('id') as $line) {
            $remaining = (string) $line->actual_pick_qty;
            $reservations = DB::table('erp_assembly_inventory_reservations')->where('work_order_id', $task->work_order_id)
                ->where('material_requirement_id', $line->material_requirement_id)->where('inventory_balance_id', $line->inventory_balance_id)
                ->where('status', 'ACTIVE')->orderBy('id')->lockForUpdate()->get();
            foreach ($reservations as $reservation) {
                if (bccomp($remaining, '0', 8) <= 0) break;
                $left = bcsub((string) $reservation->reserved_base_qty, (string) $reservation->consumed_base_qty, 8);
                $consume = bccomp($left, $remaining, 8) < 0 ? $left : $remaining;
                $balance = InventoryBalance::whereKey($reservation->inventory_balance_id)->lockForUpdate()->firstOrFail();
                $this->changeLock($balance, bcsub('0', $consume, 8));
                $total = bcadd((string) $reservation->consumed_base_qty, $consume, 8);
                DB::table('erp_assembly_inventory_reservations')->where('id', $reservation->id)->update([
                    'consumed_base_qty' => $total, 'status' => bccomp($total, (string) $reservation->reserved_base_qty, 8) === 0 ? 'CONSUMED' : 'ACTIVE', 'updated_at' => now(),
                ]);
                $remaining = bcsub($remaining, $consume, 8);
            }
        }
    }

    public function reserveChildReceipt(WorkOrder $child, object $output, int $receiptId, array $posting, float $quantity, object $user): array
    {
        $existing = DB::table('erp_assembly_inventory_reservations')->where('finished_goods_receipt_id', $receiptId)->lockForUpdate()->first();
        if ($existing) return ['reservation_id' => null, 'assembly_reservation_id' => (int) $existing->id, 'internal_issue_task_id' => null];
        $demand = AssemblyComponentDemand::whereKey($child->assembly_component_demand_id)->lockForUpdate()->firstOrFail();
        if ($demand->status !== 'PREPARED' || (int) $demand->child_work_order_id !== (int) $child->id
            || (int) $demand->item_id !== (int) $output->output_item_id) $this->fail('assembly_receipt_owner_invalid', '子工单入库产出与父工单部件需求不一致。');
        $owned = (string) DB::table('erp_assembly_inventory_reservations')->where('component_demand_id', $demand->id)
            ->whereIn('status', ['ACTIVE', 'CONSUMED'])->sum('reserved_base_qty');
        $left = bcsub((string) $demand->required_base_qty, $owned, 8);
        $formal = DB::table('erp_work_order_material_requirements')->where('work_order_id', $demand->parent_work_order_id)
            ->where('bom_item_id', $demand->bom_item_id)->lockForUpdate()->first();
        if ($formal) {
            $left = bcsub(bcsub((string) $formal->required_qty, (string) $formal->picked_qty, 8), $this->totalOwnedQuantity((int) $formal->id), 8);
        }
        if (bccomp($left, '0', 8) <= 0) return ['reservation_id' => null, 'assembly_reservation_id' => null, 'internal_issue_task_id' => null];
        $qty = bccomp($left, (string) $quantity, 8) < 0 ? $left : bcadd((string) $quantity, '0', 8);
        $balance = InventoryBalance::where('item_id', $output->output_item_id)->where('warehouse_id', $posting['warehouse_id'])
            ->where('location_id', $posting['location_id'])->where('batch_no', $posting['batch_no'])->lockForUpdate()->firstOrFail();
        $id = $this->reserve($demand, ['inventory_balance_id' => $balance->id, 'base_qty' => $qty], $user, $receiptId);
        return ['reservation_id' => null, 'assembly_reservation_id' => $id, 'internal_issue_task_id' => null];
    }

    public function releasePlan(int $planId): void
    {
        $ids = AssemblyComponentDemand::where('assembly_plan_id', $planId)->pluck('id');
        $reservations = DB::table('erp_assembly_inventory_reservations')->whereIn('component_demand_id', $ids)->where('status', 'ACTIVE')->orderBy('inventory_balance_id')->lockForUpdate()->get();
        foreach ($reservations as $row) {
            $balance = InventoryBalance::whereKey($row->inventory_balance_id)->lockForUpdate()->firstOrFail();
            $this->changeLock($balance, bcsub('0', bcsub((string) $row->reserved_base_qty, (string) $row->consumed_base_qty, 8), 8));
            DB::table('erp_assembly_inventory_reservations')->where('id', $row->id)->update(['status' => 'RELEASED', 'released_at' => now(), 'updated_at' => now()]);
        }
    }

    private function changeLock(InventoryBalance $balance, string $delta): void
    {
        $locked = bcadd((string) $balance->quantity_locked, $delta, 8);
        if (bccomp($locked, '0', 8) < 0 || bccomp($locked, (string) $balance->quantity_on_hand, 8) > 0) $this->fail('assembly_stock_lock_invalid', '部件库存锁定量与在库量不一致。');
        $balance->quantity_locked = $locked;
        $available = bcsub(bcsub(bcsub((string) $balance->quantity_on_hand, $locked, 8), (string) $balance->quantity_defective, 8), (string) $balance->quantity_pending, 8);
        $balance->quantity_available = bccomp($available, '0', 8) > 0 ? $available : '0.00000000';
        $balance->save();
        $location = InventoryLocationBalance::where('item_id', $balance->item_id)->where('warehouse_id', $balance->warehouse_id)->where('location_id', $balance->location_id)->lockForUpdate()->firstOrFail();
        $locationLocked = bcadd((string) $location->quantity_locked, $delta, 8);
        if (bccomp($locationLocked, '0', 8) < 0) $this->fail('assembly_location_lock_invalid', '部件库位锁定量不一致。');
        $location->quantity_locked = $locationLocked;
        $available = bcsub(bcsub(bcsub((string) $location->quantity_on_hand, $locationLocked, 8), (string) $location->quantity_defective, 8), (string) $location->quantity_pending, 8);
        $location->quantity_available = bccomp($available, '0', 8) > 0 ? $available : '0.00000000';
        $location->save();
    }

    private function fail(string $code, string $message): never { throw new WorkOrderDomainException($code, $message, 409); }
}
