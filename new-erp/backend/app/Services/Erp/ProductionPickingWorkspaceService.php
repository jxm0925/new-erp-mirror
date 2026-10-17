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
        $memberIds = $filters['target_material_requirement_ids'] ?? [$demand->id];
        if (! in_array((int) $demand->id, array_map('intval', $memberIds), true)) $this->fail('cutting_member_invalid', '组合库存选择必须包含当前配料目标。');
        $memberRequirements = []; $memberMetadata = []; $memberError = null;
        foreach ($memberIds as $memberId) {
            [$memberTarget, $member] = (int) $memberId === (int) $demand->id ? [$demand, $requirement] : $this->demand((int) $memberId, $user, $permissions, $superAdmin);
            if ((int) $memberTarget->work_order_id !== (int) $demand->work_order_id || $memberTarget->target_type !== $demand->target_type
                || (int) $memberTarget->target_id !== (int) $demand->target_id || (int) $member->component_item_id !== (int) $requirement->component_item_id
                || (int) $member->base_unit_id !== (int) $requirement->base_unit_id || (int) $member->configuration_id !== (int) $requirement->configuration_id)
                $this->fail('cutting_member_invalid', '组合选择只能引用同工单、目标、原料、库存单位和配置的需求。');
            $memberRequirements[] = $member;
            $totalLength = FrozenCuttingStockPolicy::isTotalLength($member->cutting_requirement_snapshot ?? []);
            $remainingLength = null;
            try {
                $remainingFacts = app(ProductionCuttingAllocationService::class)->unitKind($member) ? app(ProductionCuttingAllocationService::class)->remaining($member, $memberTarget) : null;
                $remaining = $remainingFacts['target_piece_qty'] ?? null;
                $remainingLength = $remainingFacts['target_total_length_mm'] ?? null;
            }
            catch (WorkOrderDomainException $e) { $remaining = null; $memberError = $e->getMessage(); }
            $memberMetadata[] = ['target_material_requirement_id' => (int) $memberTarget->id, 'material_requirement_id' => (int) $member->id,
                'bom_item_id' => (int) $member->bom_item_id, 'remaining_cut_piece_qty' => $remaining,
                'cutting_quantity_basis' => $totalLength ? 'total_length' : 'dimensions_pieces', 'remaining_cut_total_length_mm' => $remainingLength,
                'dimensions' => $this->stock->geometry($member, $member->componentItem?->cuttingMode() ?? 'none')];
        }
        $mixed = count($memberRequirements) > 1;
        $query = InventoryBalance::with(['item.category', 'location', 'warehouse', 'unit'])
            ->where('item_id', $demand->component_item_id)->where('warehouse_id', $filters['warehouse_id']);
        if ($requirement->base_unit_id) $query->where('unit_id', $requirement->base_unit_id);
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
        $page->getCollection()->transform(function ($balance) use ($requirement, $demand, $mixed, $memberMetadata, $memberError, $memberRequirements) {
            $eligible = $mixed ? collect($memberRequirements)->contains(fn ($member) => $this->stock->eligible($balance, $member)) : $this->stock->eligible($balance, $requirement);
            $balance->setAttribute('picking_available_qty', ! $memberError && $eligible ? $this->stock->available($balance, null, $mixed ? [] : [(int) $requirement->id], $requirement, $mixed) : 0);
            $balance->setAttribute('serial_tracking_mode', $balance->item?->serialTrackingMode() ?? 'none');
            $allocation = app(ProductionCuttingAllocationService::class);
            $balance->setAttribute('cutting_unit_kind', $allocation->unitKind($requirement));
            $balance->setAttribute('cutting_capacity_piece_qty', $mixed ? null : $this->stock->minimumPieceCapacity($balance, $requirement));
            $balance->setAttribute('cutting_requirement_dimensions', $this->stock->geometry($requirement, $balance->item?->cuttingMode() ?? 'none'));
            $balance->setAttribute('cutting_member_requirements', $memberMetadata);
            foreach ($this->stock->sourceMetadata($balance, $requirement, $mixed) as $field => $value) $balance->setAttribute($field, $value);
            $perSource = $balance->getAttribute('cutting_stock_qty_per_source');
            $balance->setAttribute('picking_available_source_count', $perSource ? (int) bcdiv((string) $balance->getAttribute('picking_available_qty'), $perSource, 0) : null);
            $balance->setAttribute('suggested_cutting_stock_qty', null);
            $balance->setAttribute('cutting_quantity_basis', FrozenCuttingStockPolicy::isTotalLength($requirement->cutting_requirement_snapshot ?? []) ? 'total_length' : 'dimensions_pieces');
            if ($memberError) $balance->setAttribute('cutting_allocation_error', $memberError);
            if ($allocation->unitKind($requirement)) {
                try {
                    $remaining = $allocation->remaining($requirement, $demand);
                    $balance->setAttribute('remaining_cut_piece_qty', $remaining['target_piece_qty']);
                    $balance->setAttribute('remaining_cut_total_length_mm', $remaining['target_total_length_mm']);
                }
                catch (WorkOrderDomainException $e) { $balance->setAttribute('picking_available_qty', 0); $balance->setAttribute('cutting_allocation_error', $e->getMessage()); }
            }
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

    private function cuttingContext(array $filters, object $user, array $permissions, bool $superAdmin): array
    {
        [$target, $owner] = $this->demand((int) $filters['target_material_requirement_id'], $user, $permissions, $superAdmin);
        $ids = array_map('intval', $filters['target_material_requirement_ids'] ?? [$target->id]);
        if (! in_array((int) $target->id, $ids, true)) $this->fail('cutting_member_invalid', '所选规格必须包含当前配料需求。');
        $members = [];
        foreach ($ids as $id) {
            [$member, $requirement] = $this->demand($id, $user, $permissions, $superAdmin);
            if ((int) $member->work_order_id !== (int) $target->work_order_id || $member->target_type !== $target->target_type
                || (int) $member->target_id !== (int) $target->target_id || (int) $requirement->component_item_id !== (int) $owner->component_item_id
                || (int) $requirement->base_unit_id !== (int) $owner->base_unit_id || (int) $requirement->configuration_id !== (int) $owner->configuration_id)
                $this->fail('cutting_member_invalid', '逐板计划只能选择同一目标、原料、配置和库存单位的需求。');
            $members[$id] = ['target' => $member, 'requirement' => $requirement];
        }
        $balance = InventoryBalance::with(['item', 'warehouse', 'location', 'unit'])->findOrFail((int) $filters['inventory_balance_id']);
        $eligible = false;
        foreach ($members as $member) if ($this->stock->eligible($balance, $member['requirement'])) { $eligible = true; break; }
        if (app(ProductionCuttingAllocationService::class)->unitKind($owner) !== 'sheet' || ! $eligible)
            $this->fail('physical_source_invalid', '请选择与当前板材需求相符的实际仓库库存。');
        return [$target, $owner, $balance, $members];
    }

    public function warehousePhysicals(array $filters, object $user, array $permissions, bool $superAdmin)
    {
        [, , $balance, $members] = $this->cuttingContext($filters, $user, $permissions, $superAdmin);
        $matching = null;
        foreach ($members as $member) {
            $candidate = $this->stock->physicalQuery($balance, $member['requirement'])->select('physical.id');
            $matching = $matching ? $matching->union($candidate) : $candidate;
        }
        $query = $this->stock->physicalQuery($balance)->whereIn('physical.id', $matching)
            ->whereNotIn('physical.id', app(ProductionCuttingAllocationService::class)->reservedPhysicalIds());
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) $query->where('physical.physical_no', 'like', '%'.$keyword.'%');
        $page = $query->orderBy('physical.id')->paginate(min(20, $this->pageSize($filters)), ['physical.id', 'physical.physical_no', 'physical.business_version', 'physical.item_id', 'physical.shape', 'physical.dimensions']);
        $page->getCollection()->transform(function ($row) use ($balance, $members) {
            $row->dimensions = ['shape' => $row->shape] + json_decode($row->dimensions ?: '{}', true, 512, JSON_THROW_ON_ERROR);
            $row->inventory_balance_id = (int) $balance->id;
            $row->area_mm2 = FrozenCuttingStockPolicy::area($row->dimensions);
            $row->capacity_basis = $row->shape === 'IRREGULAR' ? 'area' : 'rectangle';
            $row->capacity_piece_qty = count($members) === 1 ? CuttingStockAllocationPolicy::capacity($this->stock->geometry(reset($members)['requirement'], 'sheet'), $row->dimensions) : null;
            return $row;
        });
        return ['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]];
    }

    public function cuttingPreview(array $payload, object $user, array $permissions, bool $superAdmin): array
    {
        if (! in_array('production.material_picking.create', $permissions, true)) $this->fail('forbidden', '没有创建生产配料的权限。', 403);
        [$target, $owner, $balance, $members] = $this->cuttingContext($payload, $user, $permissions, $superAdmin);
        $definitions = []; $seen = [];
        foreach ($payload['cutting_members'] as $row) {
            $id = (int) $row['target_material_requirement_id'];
            if (! isset($members[$id]) || isset($seen[$id])) $this->fail('cutting_member_invalid', '预览件数与所选需求不一致。');
            $seen[$id] = true; $definitions[] = $members[$id] + ['planned_cut_piece_qty' => (int) $row['planned_cut_piece_qty']];
        }
        if (! $definitions) $this->fail('cutting_member_invalid', '本库存来源至少需要一条实际分配的需求。');
        $target = $definitions[0]['target']; $owner = $definitions[0]['requirement'];
        $members = array_intersect_key($members, $seen);
        $physicalIds = array_map('intval', $payload['physical_material_ids']); $quantity = '0.00000000';
        $stockKind = app(ProductionCuttingAllocationService::class)->stockUnitKind($owner);
        $selected = $this->stock->physicalQuery($balance)->whereIn('physical.id', $physicalIds)->get(['physical.id', 'physical.shape', 'physical.dimensions']);
        if (count($physicalIds) !== count(array_unique($physicalIds)) || $selected->count() !== count($physicalIds)) $this->fail('physical_source_invalid', '所选实物已变化，请保留方案并刷新来源。');
        foreach ($selected as $physical) $quantity = bcadd($quantity, CuttingStockQuantityPolicy::sourceQuantity($stockKind, ['shape' => $physical->shape] + json_decode($physical->dimensions ?: '{}', true, 512, JSON_THROW_ON_ERROR)), 8);
        $requirementIds = array_map(fn ($member) => (int) $member['requirement']->id, $members);
        if (bccomp($quantity, $this->stock->availableDecimal($balance, null, count($members) > 1 ? [] : $requirementIds, $owner, count($members) > 1), 8) > 0) $this->fail('inventory_changed', '当前可用库存不足，请保留方案并重新选择来源。');
        $service = app(ProductionCuttingAllocationService::class);
        $allocation = $service->plan($owner, $target, $balance, $quantity, null, true, $physicalIds, $definitions, null, $payload['cutting_source_plan'] ?? null, true);
        return ['data' => ['cutting_source_plan' => $service->sourcePlan($allocation), 'allocation' => $allocation,
            'owner_target_material_requirement_id' => (int) $target->id, 'owner_material_requirement_id' => (int) $owner->id]];
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
        $allocation = $line->serial_snapshot['cutting_allocation'] ?? null;
        $mixed = count($allocation['members'] ?? []) > 1;
        $query = $this->stock->physicalQuery($balance, $mixed ? null : $requirement, 'p', 'h');
        if ($allocation) $query->whereIn('p.id', array_column($allocation['sources'], 'physical_material_id'));
        if ($task->status !== 'PICKING') $query->whereRaw('1=0');
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) $query->where('p.physical_no', 'like', '%'.$keyword.'%');
        // Identity and real dimensions are returned directly. A client cannot request an
        // unrelated balance or silently replace its selected pieces with the first N rows.
        $page = $query->select('p.id', 'p.physical_no', 'p.shape', 'p.dimensions', 'p.status', 'p.business_version', 'p.item_id')
            ->orderBy('p.id')->paginate($this->pageSize($filters));
        $geometry = $this->stock->geometry($requirement, $balance->item?->cuttingMode() ?? 'none');
        $page->getCollection()->transform(function ($row) use ($geometry, $allocation, $mixed) {
            $row->dimensions = ['shape' => $row->shape] + json_decode($row->dimensions ?: '{}', true);
            $row->capacity_piece_qty = $mixed ? null : CuttingStockAllocationPolicy::capacity($geometry, $row->dimensions);
            $source = collect($allocation['sources'] ?? [])->firstWhere('physical_material_id', (int) $row->id);
            $row->covered_piece_qty = $source['covered_piece_qty'] ?? null;
            $row->cutting_members = $source['members'] ?? [];
            return $row;
        });
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

    private function pageSize(array $filters): int { return min(100, max(1, (int) ($filters['per_page'] ?? 10))); }
    private function fail(string $code, string $message, int $status = 422): never { throw new WorkOrderDomainException($code, $message, $status); }
}
