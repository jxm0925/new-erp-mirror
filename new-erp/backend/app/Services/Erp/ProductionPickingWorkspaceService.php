<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventorySerial;
use App\Models\Erp\ItemCategory;
use App\Models\Erp\Warehouse;
use App\Models\Erp\WorkOrder;
use App\Models\Erp\WorkOrderMaterialRequirement;
use Illuminate\Support\Facades\DB;

final class ProductionPickingWorkspaceService
{
    public function __construct(
        private readonly ProductionMaterialExecutionService $execution,
        private readonly ProductionDataScopeResolver $scope,
        private readonly ProductionPickingStockService $stock,
    ) {}

    public function targets(array $filters, object $user, array $permissions, bool $superAdmin)
    {
        $demands = $this->execution->preparationDemandQuery($filters, $user, $permissions, $superAdmin)->reorder();
        $fields = ['work_order_id', 'work_order_no', 'work_order_version', 'production_batch_no', 'output_item_name',
            'output_item_code', 'production_unit_no', 'production_target_type', 'production_target_id', 'target_routing_operation_id', 'target_operation_name'];
        return DB::query()->fromSub($demands, 'demands')->select($fields)->selectRaw('COUNT(*) as material_count')
            ->groupBy($fields)->orderByDesc('work_order_id')->orderBy('production_target_id')
            ->paginate($this->pageSize($filters));
    }

    public function sources(array $filters, object $user, array $permissions, bool $superAdmin)
    {
        [$demand, $requirement] = $this->demand((int) $filters['target_material_requirement_id'], $user, $permissions, $superAdmin);
        $query = InventoryBalance::with(['item.category', 'location', 'warehouse', 'unit'])
            ->where('item_id', $demand->component_item_id)->where('warehouse_id', $filters['warehouse_id']);
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') $query->where(fn ($q) => $q->where('batch_no', 'like', '%'.$keyword.'%')
            ->orWhereHas('location', fn ($loc) => $loc->where('location_code', 'like', '%'.$keyword.'%')->orWhere('location_name', 'like', '%'.$keyword.'%'))
            ->orWhereHas('item', fn ($item) => $item->where('item_code', 'like', '%'.$keyword.'%')->orWhere('item_name', 'like', '%'.$keyword.'%')->orWhere('spec', 'like', '%'.$keyword.'%')));
        if (! empty($filters['location_id'])) $query->where('location_id', $filters['location_id']);
        if (! empty($filters['category_id'])) $query->whereHas('item', fn ($item) => $item->where('category_id', $filters['category_id']));
        if (! empty($filters['ids'])) $query->whereIn('id', $filters['ids']);
        // Configuration filtering happens before pagination, so matching stock is not hidden on an empty page.
        $query->where(function ($q) use ($requirement) {
            if (! $requirement->configuration_id) $q->whereNull('material_lot_id')->orWhereIn('material_lot_id', DB::table('erp_material_lots')->whereNull('configuration_id')->select('id'));
            else $q->whereIn('material_lot_id', DB::table('erp_material_lots')->where('configuration_id', $requirement->configuration_id)->select('id'));
        });
        $page = $query->orderBy('location_id')->orderBy('id')->paginate($this->pageSize($filters));
        $page->getCollection()->transform(function ($balance) use ($requirement) {
            $balance->setAttribute('picking_available_qty', $this->stock->eligible($balance, $requirement) ? $this->stock->available($balance) : 0);
            $balance->setAttribute('serial_tracking_mode', $balance->item?->serialTrackingMode() ?? 'none');
            return $balance;
        });
        return $page;
    }

    public function options(array $filters, object $user, array $permissions, bool $superAdmin)
    {
        $allowed = ['production.material_picking.create', 'production.material_picking.assign', 'production.material_picking.pick', 'production.material_delivery.create', 'production.material_delivery.dispatch'];
        if (! array_intersect($allowed, $permissions)) $this->fail('forbidden', '没有生产配料或配送操作权限。', 403);
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($filters['kind'] === 'warehouses') {
            $query = Warehouse::whereIn('status', ['enabled', 'active'])->select('id', 'warehouse_code', 'warehouse_name');
            if ($keyword !== '') $query->where(fn ($q) => $q->where('warehouse_code', 'like', '%'.$keyword.'%')->orWhere('warehouse_name', 'like', '%'.$keyword.'%'));
        } elseif ($filters['kind'] === 'people') {
            $query = DB::table('erp_legacy_admin_users')->where('status', 'normal')->select('legacy_id as id', 'username', 'nickname');
            if ($keyword !== '') $query->where(fn ($q) => $q->where('username', 'like', '%'.$keyword.'%')->orWhere('nickname', 'like', '%'.$keyword.'%'));
            if (! empty($filters['department_id'])) $query->whereIn('legacy_id', DB::table('erp_department_users')->where('department_legacy_id', $filters['department_id'])->select('user_legacy_id'));
        } elseif ($filters['kind'] === 'departments') {
            $query = DB::table('erp_departments')->where('status', 'normal')->select('legacy_id as id', 'name', 'parent_legacy_id');
            if ($keyword !== '') $query->where('name', 'like', '%'.$keyword.'%');
        } elseif ($filters['kind'] === 'locations') {
            if (empty($filters['target_material_requirement_id']) || empty($filters['warehouse_id'])) $this->fail('validation_error', '选择库位必须指定工序物料需求和仓库。');
            [$demand] = $this->demand((int) $filters['target_material_requirement_id'], $user, $permissions, $superAdmin);
            $query = DB::table('erp_locations')->where('warehouse_id', $filters['warehouse_id'])->whereIn('status', ['enabled', 'active'])
                ->whereIn('id', DB::table('erp_inventory_balances')->where('warehouse_id', $filters['warehouse_id'])->where('item_id', $demand->component_item_id)->select('location_id'))
                ->select('id', 'location_name', 'location_code');
            if ($keyword !== '') $query->where(fn ($q) => $q->where('location_name', 'like', '%'.$keyword.'%')->orWhere('location_code', 'like', '%'.$keyword.'%'));
        } else {
            // Category choices are scoped to the real demand, not an unrelated all-material selector.
            [, $requirement] = $this->demand((int) $filters['target_material_requirement_id'], $user, $permissions, $superAdmin);
            $query = ItemCategory::whereKey($requirement->componentItem?->category_id)->select('id', 'category_name', 'parent_id');
        }
        return $query->orderBy('id')->paginate($this->pageSize($filters));
    }

    public function serials(array $filters, object $user, array $permissions, bool $superAdmin)
    {
        $task = $this->execution->showPickingTask((int) $filters['picking_task_id'], $user, $permissions, $superAdmin);
        $line = $task->lines->firstWhere('id', (int) $filters['picking_task_line_id']);
        if (! $line) $this->fail('picking_line_invalid', '该库存来源不属于当前配料单。');
        $query = InventorySerial::query();
        if ($task->status === 'PICKING') $query->where('inventory_balance_id', $line->inventory_balance_id)->where('serial_status', 'available');
        elseif (! empty($filters['source_delivery_id'])) {
            $source = $this->execution->showDelivery((int) $filters['source_delivery_id'], $user, $permissions, $superAdmin);
            if ((int) $source->picking_task_id !== (int) $task->id) $this->fail('delivery_source_invalid', '原配送单不属于当前配料单。');
            $sourceLine = $source->lines->firstWhere('picking_task_line_id', $line->id);
            $query->whereIn('id', $sourceLine?->available_redelivery_serial_ids ?? [])->where('serial_status', 'production_rejected');
        } else $query->whereIn('id', $line->available_delivery_serial_ids ?? [])->where('serial_status', 'production_in_transit');
        if (trim((string) ($filters['keyword'] ?? '')) !== '') $query->where('serial_no', 'like', '%'.trim($filters['keyword']).'%');
        return $query->select('id', 'serial_no', 'serial_status')->orderBy('id')->paginate($this->pageSize($filters));
    }

    public function physicals(array $filters, object $user, array $permissions, bool $superAdmin)
    {
        $task = $this->execution->showPickingTask((int) $filters['picking_task_id'], $user, $permissions, $superAdmin);
        $line = $task->lines->firstWhere('id', (int) $filters['picking_task_line_id']);
        if (! $line) $this->fail('picking_line_invalid', '该库存来源不属于当前配料单。');
        $balance = InventoryBalance::with(['item', 'warehouse', 'location'])->findOrFail($line->inventory_balance_id);
        $requirement = WorkOrderMaterialRequirement::findOrFail($line->material_requirement_id);
        if ($balance->item?->materialManagementMode() !== 'physical' || ! $this->stock->eligible($balance, $requirement))
            $this->fail('physical_source_invalid', '当前来源不是符合需求的实物库存。');
        $query = DB::table('erp_material_physicals as p')->join('erp_material_holdings as h', 'h.id', '=', 'p.current_holding_id')
            ->where('p.item_id', $line->component_item_id)->where('h.inventory_balance_id', $balance->id)
            ->where('h.position_type', 'WAREHOUSE')->where('h.status', 'ACTIVE')->where('p.status', 'AVAILABLE');
        if ($task->status !== 'PICKING') $query->whereRaw('1=0');
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) $query->where('p.physical_no', 'like', '%'.$keyword.'%');
        // Identity and real dimensions are returned directly. A client cannot request an
        // unrelated balance or silently replace its selected pieces with the first N rows.
        $page = $query->select('p.id', 'p.physical_no', 'p.dimensions', 'p.status', 'p.business_version', 'p.item_id')
            ->orderBy('p.id')->paginate($this->pageSize($filters));
        $page->getCollection()->transform(function ($row) { $row->dimensions = json_decode($row->dimensions ?: '{}', true); return $row; });
        return $page;
    }

    public function receiptSerials(array $filters, object $user, array $permissions, bool $superAdmin)
    {
        $delivery = $this->execution->showDelivery((int) $filters['delivery_id'], $user, $permissions, $superAdmin);
        $line = $delivery->lines->firstWhere('id', (int) $filters['delivery_line_id']);
        if (! $line) $this->fail('delivery_line_invalid', '该序列来源不属于当前配送单。');
        $used = $delivery->receipts->flatMap(fn ($receipt) => $receipt->lines)->where('delivery_line_id', $line->id)
            ->flatMap(fn ($receiptLine) => array_merge($receiptLine->accepted_serial_snapshot['inventory_serial_ids'] ?? [], $receiptLine->rejected_serial_snapshot['inventory_serial_ids'] ?? []))->all();
        $ids = array_diff($line->serial_snapshot['inventory_serial_ids'] ?? [], $used);
        $query = InventorySerial::whereIn('id', $ids)->select('id', 'serial_no', 'serial_status');
        if ($delivery->status !== 'DELIVERED') $query->whereRaw('1=0');
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) $query->where('serial_no', 'like', '%'.$keyword.'%');
        return $query->orderBy('id')->paginate($this->pageSize($filters));
    }

    public function events(string $type, int $id, array $filters, object $user, array $permissions, bool $superAdmin)
    {
        $type === 'picking_task' ? $this->execution->showPickingTask($id, $user, $permissions, $superAdmin)
            : $this->execution->showDelivery($id, $user, $permissions, $superAdmin);
        return DB::table('erp_production_material_events')->where('aggregate_type', $type)->where('aggregate_id', $id)
            ->orderByDesc('id')->paginate($this->pageSize($filters));
    }

    private function demand(int $id, object $user, array $permissions, bool $superAdmin): array
    {
        if (! in_array('production.material_picking.view', $permissions, true)) $this->fail('forbidden', '没有查看生产配料的权限。', 403);
        $demand = DB::table('erp_production_target_material_requirements')->where('id', $id)->first();
        $workOrder = $demand ? WorkOrder::find($demand->work_order_id) : null;
        if (! $workOrder) $this->fail('not_found', '物料需求不存在。', 404);
        if (! $this->scope->workOrderVisible($workOrder, $this->scope->resolve($user, 'production.material_picking.view', $permissions, $superAdmin)))
            $this->fail('data_scope_denied', '当前用户不在该工单的数据范围内。', 403);
        return [$demand, WorkOrderMaterialRequirement::with('componentItem')->findOrFail($demand->material_requirement_id)];
    }

    private function pageSize(array $filters): int { return min(100, max(1, (int) ($filters['per_page'] ?? 20))); }
    private function fail(string $code, string $message, int $status = 422): never { throw new WorkOrderDomainException($code, $message, $status); }
}
