<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{Item, ItemCategory, PurchaseRequest, SalesOrder, WorkOrder};
use Illuminate\Support\Facades\DB;

/** Warehouse may request purchasing; it receives no purchasing approval or order privileges. */
final class ProductionMaterialProcurementApplicationService
{
    public function __construct(private readonly ProductionDataScopeResolver $scope, private readonly ProductionMaterialCommandService $commands,
        private readonly PurchaseRequestCreationApplicationService $requests, private readonly PurchaseWorkflowApplicationService $workflow) {}

    public function options(string $kind, array $filters, object $user, array $permissions, bool $admin)
    {
        $this->permission($permissions);
        if ($kind === 'categories') return app(ItemManagementScopeService::class)->applyScope(ItemCategory::query(), 'factory')->select('id', 'category_name', 'parent_id')->orderBy('sort_order')->orderBy('id')
            ->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 20))));
        if ($kind === 'orders') {
            $query = $this->orders($user, $permissions, $admin)->select('id', 'sales_order_no', 'customer_name');
            if (! empty($filters['keyword'])) $query->where(fn ($q) => $q->where('sales_order_no', 'like', '%'.$filters['keyword'].'%')->orWhere('customer_name', 'like', '%'.$filters['keyword'].'%'));
        } else {
            $query = app(ItemManagementScopeService::class)->applyScope(Item::query(), 'factory')->where('status', 'enabled')->where('is_purchase_item', true)
                ->with(['unit.standardUnit'])
                ->select('id', 'item_code', 'item_name', 'spec', 'model', 'category_id', 'unit_id');
            if (! empty($filters['category_id'])) $query->where('category_id', (int) $filters['category_id']);
            if (! empty($filters['keyword'])) $query->where(fn ($q) => $q->where('item_code', 'like', '%'.$filters['keyword'].'%')->orWhere('item_name', 'like', '%'.$filters['keyword'].'%')->orWhere('spec', 'like', '%'.$filters['keyword'].'%')->orWhere('model', 'like', '%'.$filters['keyword'].'%'));
        }
        $page = $query->orderByDesc('id')->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 20))));
        if ($kind === 'items') $page->getCollection()->transform(function ($item) {
            $unit = app(UnitConversionDomainService::class)->canonicalUnit($item->unit);
            return [...$item->only(['id', 'item_code', 'item_name', 'spec', 'model', 'category_id']),
                'unit_name' => $unit?->unit_name, 'unit_id' => $unit?->id, 'decimal_places' => $unit?->decimal_places];
        });
        return $page;
    }

    public function create(array $payload, object $user, array $permissions, bool $admin): array
    {
        $this->permission($permissions);
        return $this->commands->run('material_procurement_create', $payload, $user, function () use ($payload, $user, $permissions, $admin) {
            $order = null;
            if (! empty($payload['sales_order_id'])) {
                $order = $this->orders($user, $permissions, $admin)->lockForUpdate()->find($payload['sales_order_id']);
                if (! $order) throw new WorkOrderDomainException('source_order_invalid', '订单不存在或不在当前生产数据范围内。', 403);
            }
            $rows = collect($payload['items']);
            if ($rows->pluck('item_id')->unique()->count() !== $rows->count()) throw new WorkOrderDomainException('validation_error', '同一物料请合并数量后提交。', 422);
            $sources = [];
            $demandIds = $rows->pluck('target_material_requirement_id')->filter()->sort()->values();
            $demands = DB::table('erp_production_target_material_requirements')->whereIn('id', $demandIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $items = [];
            foreach ($rows as $line) {
                $item = Item::with('unit.standardUnit')->lockForUpdate()->findOrFail($line['item_id']);
                app(ItemManagementScopeService::class)->assertProductionAllowed($item, 'items');
                if ($item->status !== 'enabled' || ! $item->is_purchase_item) throw new WorkOrderDomainException('item_not_purchasable', '请选择已启用且允许采购的物料。', 422);
                $unit = app(UnitConversionDomainService::class)->canonicalUnit($item->unit);
                if (! $unit) throw new WorkOrderDomainException('unit_missing', '物料缺少库存单位，请先维护单位。', 422);
                $qty = (string) $line['request_qty'];
                $source = null;
                if (! empty($line['target_material_requirement_id'])) {
                    $source = $demands->get($line['target_material_requirement_id']);
                    $wo = $source ? WorkOrder::find($source->work_order_id) : null;
                    if (! $source || ! $wo || (int) $source->component_item_id !== (int) $item->id
                        || ! $this->scope->workOrderVisible($wo, $this->scope->resolve($user, 'production.material_requirement.view', $permissions, $admin)))
                        throw new WorkOrderDomainException('preparation_demand_invalid', '采购物料与所选生产需求不一致。', 403);
                    if ($order && ! $this->workOrdersForOrder($order->id, $user, $permissions, $admin)->whereKey($wo->id)->exists())
                        throw new WorkOrderDomainException('source_order_mismatch', '所选需求不属于当前来源订单。', 422);
                    $pending = DB::table('erp_material_procurement_sources as source')->join('erp_purchase_requests as request', 'request.id', '=', 'source.request_id')
                        ->join('erp_purchase_request_items as line', 'line.id', '=', 'source.request_item_id')
                        ->where('source.target_material_requirement_id', $source->id)->whereNull('request.deleted_at')
                        ->whereNotIn('request.request_status', ['cancelled', 'closed'])->sum('line.request_qty');
                    $allocated = DB::table('erp_material_picking_task_lines as line')->join('erp_material_picking_tasks as task', 'task.id', '=', 'line.task_id')
                        ->where('line.production_target_type', $source->target_type)->where('line.production_target_id', $source->target_id)
                        ->where('line.material_supply_rule_snapshot_id', $source->material_supply_rule_snapshot_id)->where('task.status', '<>', 'CANCELLED')
                        ->sum(DB::raw("CASE WHEN task.status IN ('WAIT_PICK','PICKING') THEN line.planned_pick_qty ELSE GREATEST(line.actual_pick_qty-line.received_qty,0) END"));
                    $remaining = max(0, (float) $source->required_base_qty - max(0, (float) $source->satisfied_base_qty - (float) $source->returned_base_qty) - (float) $pending - (float) $allocated);
                    if ((float) $qty > $remaining + 0.00000001) throw new WorkOrderDomainException('procurement_quantity_exceeded', '采购需求数量超过尚未申购的生产缺料数量。', 422);
                }
                // This form explicitly enters stock units, avoiding an implicit purchase conversion.
                $items[] = ['item_id' => $item->id, 'request_qty' => $qty, 'purchase_unit_id' => $unit->id,
                    'expected_date' => $payload['expected_date'] ?? null, 'warehouse_id' => $payload['warehouse_id'] ?? null,
                    'remark' => $line['remark'] ?? null];
                $sources[] = ['demand' => $source, 'item' => $item];
            }
            $operator = $user->nickname ?? $user->username;
            $request = $this->requests->create(['request_no' => app(DocumentNumberService::class)->next('purchase_request', 'PRQ'),
                'request_date' => now()->toDateString(), 'source_type' => 'production_material', 'source_id' => $order?->id,
                'source_no' => $order?->sales_order_no, 'requester' => $operator, 'remark' => $payload['remark'] ?? null, 'data_source' => 'manual'], $items);
            foreach ($request->items->values() as $index => $line) {
                $source = $sources[$index];
                DB::table('erp_material_procurement_sources')->insert(['request_id' => $request->id, 'request_item_id' => $line->id,
                    'component_item_id' => $source['item']->id,
                    'sales_order_id' => $order?->id, 'work_order_id' => $source['demand']?->work_order_id,
                    'target_material_requirement_id' => $source['demand']?->id, 'requested_base_qty' => $line->request_qty,
                    'source_snapshot' => json_encode(['sales_order_no' => $order?->sales_order_no, 'item_code' => $source['item']->item_code,
                        'item_name' => $source['item']->item_name, 'spec' => $source['item']->spec, 'purpose' => $payload['remark'] ?? null], JSON_UNESCAPED_UNICODE),
                    'created_by_legacy_id' => (int) ($user->legacy_id ?? $user->id), 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->workflow->confirmRequest($request->id, $operator);
            return $this->result($request->id, $user);
        }, fn ($id) => $this->result($id, $user));
    }

    private function result(int $id, object $user): array
    {
        if (! DB::table('erp_material_procurement_sources')->where('request_id', $id)->where('created_by_legacy_id', (int) ($user->legacy_id ?? $user->id))->exists())
            throw new WorkOrderDomainException('permission_denied', '不能查看其他人的申购提交结果。', 403);
        $request = PurchaseRequest::withTrashed()->findOrFail($id);
        return $request->only(['id', 'request_no', 'request_status', 'requester', 'source_no']);
    }

    private function orders(object $user, array $permissions, bool $admin)
    {
        $wo = $this->visibleWorkOrders($user, $permissions, $admin);
        $masterIds = (clone $wo)->whereNotNull('production_master_order_id')->select('production_master_order_id');
        $sourceIds = (clone $wo)->where('source_type', 'sales_order')->select('source_id');
        return SalesOrder::query()->where(fn ($q) => $q->whereIn('id', $sourceIds)->orWhereIn('id', DB::table('erp_production_master_orders')->whereIn('id', $masterIds)->select('sales_order_id')));
    }

    private function visibleWorkOrders(object $user, array $permissions, bool $admin)
    {
        $query = WorkOrder::query();
        $this->scope->applyWorkOrderScope($query, $this->scope->resolve($user, 'production.material_requirement.view', $permissions, $admin));
        return $query;
    }

    private function workOrdersForOrder(int $id, object $user, array $permissions, bool $admin)
    {
        return $this->visibleWorkOrders($user, $permissions, $admin)->where(fn ($q) => $q->where(fn ($s) => $s->where('source_type', 'sales_order')->where('source_id', $id))
            ->orWhereIn('production_master_order_id', DB::table('erp_production_master_orders')->where('sales_order_id', $id)->select('id')));
    }

    private function permission(array $permissions): void
    {
        if (! in_array('production.material_procurement.create', $permissions, true)) throw new WorkOrderDomainException('permission_denied', '当前用户没有提交配料采购需求的权限。', 403);
    }
}
