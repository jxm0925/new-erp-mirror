<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\ProductionDemand;
use App\Models\Erp\WorkOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ProductionWorkOrderQueryService
{
    private const ACTIVE_WORK_ORDER_STATUSES = ['DRAFT', 'WAIT_RELEASE', 'RELEASED', 'IN_PROGRESS', 'COMPLETED'];

    public function __construct(
        private readonly ProductionDataScopeResolver $scopeResolver,
        private readonly ErpUserProjectionService $userProjections,
    ) {}

    public function demands(array $filters, object $user, array $permissions, bool $superAdmin = false): LengthAwarePaginator
    {
        $this->assertPermission($permissions, 'production.demand.view', $superAdmin);
        $query = ProductionDemand::query()
            ->select('erp_sales_order_production_requirements.*')
            ->with([
                'order:id,sales_order_no,sales_user_legacy_id,created_by_legacy_id,customer_snapshot,customer_name,contact_name,contact_phone,order_date,required_delivery_date,total_amount,currency,order_status,production_confirm_status',
                'line:id,line_no,product_id,sku_id,item_id,unit_id,unit_name_snapshot,product_snapshot,sku_snapshot,item_snapshot,product_name,sku_name,item_name',
                'line.item:id,item_name,spec,unit_id',
            ])
            ->withCount('workOrders')
            ->orderByDesc('id');
        $this->scopeResolver->applyDemandScope(
            $query,
            $this->scopeResolver->resolve($user, 'production.demand.view', $permissions, $superAdmin),
        );
        $this->applyDemandFilters($query, $filters);
        $paginator = $query->paginate($this->perPage($filters), ['*'], 'page', $this->page($filters));
        $this->attachDemandUserProjections($paginator->getCollection());
        return $paginator;
    }

    public function demand(int $id, array $filters, object $user, array $permissions, bool $superAdmin = false): ProductionDemand
    {
        $this->assertPermission($permissions, 'production.demand.view', $superAdmin);
        $demand = ProductionDemand::query()->with([
            'order:id,sales_order_no,sales_user_legacy_id,created_by_legacy_id,customer_snapshot,customer_name,contact_name,contact_phone,order_date,required_delivery_date,total_amount,currency,order_status,production_confirm_status',
            'line:id,line_no,product_id,sku_id,item_id,unit_id,unit_name_snapshot,product_snapshot,sku_snapshot,item_snapshot,product_name,sku_name,item_name,item_base_required_qty',
            'line.item:id,item_name,spec,unit_id',
        ])->find($id);
        if (! $demand) throw new WorkOrderDomainException('not_found', 'Production demand not found.', 404);
        $this->assertDemandVisible($demand, $user, $permissions, $superAdmin);
        $children = WorkOrder::query()->where('production_demand_id', $demand->id)
            ->select(['id', 'work_order_no', 'production_demand_id', 'target_unit_name_snapshot', 'target_qty', 'planned_date', 'production_batch', 'responsible_user_legacy_id', 'production_location_name', 'status', 'business_version', 'created_at', 'updated_at'])
            ->orderByDesc('id');
        $this->scopeResolver->applyWorkOrderScope(
            $children,
            $this->scopeResolver->resolve($user, 'production.work_order.view', $permissions, $superAdmin),
        );
        $children = $children->paginate($this->perPage($filters), ['*'], 'work_order_page', $this->page($filters));
        $this->attachWorkOrderUserProjections($children->getCollection());
        $demand->setRelation('workOrders', $children->getCollection());
        $demand->setAttribute('work_orders_pagination', collect($children->toArray())->only(['current_page', 'last_page', 'per_page', 'total', 'from', 'to'])->all());
        $this->attachDemandUserProjections(collect([$demand]));
        return $demand;
    }

    public function workOrders(array $filters, object $user, array $permissions, bool $superAdmin = false): LengthAwarePaginator
    {
        $this->assertPermission($permissions, 'production.work_order.view', $superAdmin);
        $query = WorkOrder::query()
            ->select('erp_work_orders.*')
            ->with([
                'outputItem:id,item_code,item_name,spec,unit_id',
                'routing:id,routing_no,routing_name,version',
                'targetOperation:id,operation_no,operation_name',
                'targetRoutingOperation.operation:id,operation_no,operation_name',
                'demand:id,requirement_no,sales_order_id,sales_order_line_id,production_qty,remaining_qty',
                'demand.order:id,sales_order_no,customer_snapshot,sales_user_legacy_id,created_by_legacy_id',
                'demand.line:id,product_snapshot,sku_snapshot,item_snapshot,product_name,sku_name,item_name,unit_name_snapshot',
                'demand.line.item:id,item_name,spec,unit_id',
            ])->withCount('materialRequirements')->orderByDesc('id');
        $this->scopeResolver->applyWorkOrderScope(
            $query,
            $this->scopeResolver->resolve($user, 'production.work_order.view', $permissions, $superAdmin),
        );
        $this->applyWorkOrderFilters($query, $filters);
        $paginator = $query->paginate($this->perPage($filters), ['*'], 'page', $this->page($filters));
        $this->attachWorkOrderUserProjections($paginator->getCollection());
        return $paginator;
    }

    public function workOrder(int $id, object $user, array $permissions, bool $superAdmin = false): WorkOrder
    {
        $this->assertPermission($permissions, 'production.work_order.view', $superAdmin);
        $workOrder = WorkOrder::query()->with([
            'outputItem:id,item_code,item_name,spec,unit_id',
            'routing:id,routing_no,routing_name,version',
            'targetOperation:id,operation_no,operation_name',
            'targetRoutingOperation.operation:id,operation_no,operation_name',
            'demand:id,requirement_no,sales_order_id,sales_order_line_id,production_qty,allocated_qty,consumed_qty,closed_qty,remaining_qty',
            'demand.order:id,sales_order_no,customer_snapshot,sales_user_legacy_id,created_by_legacy_id',
            'demand.line:id,product_snapshot,sku_snapshot,item_snapshot,product_name,sku_name,item_name,unit_name_snapshot',
            'demand.line.item:id,item_name,spec,unit_id',
            'statusLogs:id,work_order_id,before_status,after_status,reason,operator_name,occurred_at',
            'releaseGateChecks:id,work_order_id,work_order_version,check_key,status,reason_code,message,evidence,evaluated_at',
        ])->withCount('materialRequirements')->find($id);
        if (! $workOrder) throw new WorkOrderDomainException('not_found', 'Work order not found.', 404);
        $this->assertWorkOrderVisible($workOrder, $user, $permissions, $superAdmin);
        $workOrder->setAttribute('field_audit_summary', $this->fieldAuditSummary($workOrder));
        $this->attachWorkOrderUserProjections(collect([$workOrder]));
        return $workOrder;
    }

    private function applyDemandFilters(Builder $query, array $filters): void
    {
        if ($this->value($filters, 'status')) $query->where('requirement_status', $this->value($filters, 'status'));
        if ($this->value($filters, 'sales_order_id')) $query->where('sales_order_id', (int) $this->value($filters, 'sales_order_id'));
        if ($this->value($filters, 'sales_order_no')) $query->whereHas('order', fn ($q) => $q->where('sales_order_no', 'like', '%'.$this->value($filters, 'sales_order_no').'%'));
        if ($this->value($filters, 'customer')) $query->whereHas('order', fn ($q) => $q->where('customer_name', 'like', '%'.$this->value($filters, 'customer').'%')->orWhereRaw("JSON_SEARCH(customer_snapshot, 'one', ?) IS NOT NULL", [$this->value($filters, 'customer')]));
        if ($this->value($filters, 'product')) $query->whereHas('line', fn ($q) => $q->where('product_name', 'like', '%'.$this->value($filters, 'product').'%')->orWhere('sku_name', 'like', '%'.$this->value($filters, 'product').'%'));
        if ($this->value($filters, 'responsible_user_legacy_id')) $query->whereHas('workOrders', fn ($q) => $q->where('responsible_user_legacy_id', (int) $this->value($filters, 'responsible_user_legacy_id')));
        if ($this->value($filters, 'date_from')) $query->whereDate('required_delivery_date', '>=', $this->value($filters, 'date_from'));
        if ($this->value($filters, 'date_to')) $query->whereDate('required_delivery_date', '<=', $this->value($filters, 'date_to'));
        if ($this->value($filters, 'delivery_date_from')) $query->whereHas('order', fn ($q) => $q->whereDate('required_delivery_date', '>=', $this->value($filters, 'delivery_date_from')));
        if ($this->value($filters, 'delivery_date_to')) $query->whereHas('order', fn ($q) => $q->whereDate('required_delivery_date', '<=', $this->value($filters, 'delivery_date_to')));
        if ($this->value($filters, 'quantity_min') !== null) $query->where('production_qty', '>=', (float) $this->value($filters, 'quantity_min'));
        if ($this->value($filters, 'quantity_max') !== null) $query->where('production_qty', '<=', (float) $this->value($filters, 'quantity_max'));
        $this->applyDemandKeyword($query, $this->value($filters, 'keyword'));
    }

    private function applyWorkOrderFilters(Builder $query, array $filters): void
    {
        if ($this->value($filters, 'status')) $query->where('status', $this->value($filters, 'status'));
        if ($this->value($filters, 'production_demand_id')) $query->where('production_demand_id', (int) $this->value($filters, 'production_demand_id'));
        if ($this->value($filters, 'source_type')) $query->where('source_type', $this->value($filters, 'source_type'));
        if ($this->value($filters, 'production_location_name')) $query->where('production_location_name', 'like', '%'.$this->value($filters, 'production_location_name').'%');
        if ($this->value($filters, 'responsible_user_legacy_id')) $query->where('responsible_user_legacy_id', (int) $this->value($filters, 'responsible_user_legacy_id'));
        if ($this->value($filters, 'customer')) $query->whereHas('demand.order', fn ($q) => $q->where('customer_name', 'like', '%'.$this->value($filters, 'customer').'%')->orWhereRaw("JSON_SEARCH(customer_snapshot, 'one', ?) IS NOT NULL", [$this->value($filters, 'customer')]));
        if ($this->value($filters, 'product')) $query->whereHas('demand.line', fn ($q) => $q->where('product_name', 'like', '%'.$this->value($filters, 'product').'%')->orWhere('sku_name', 'like', '%'.$this->value($filters, 'product').'%'));
        if ($this->value($filters, 'date_from')) $query->whereDate('planned_date', '>=', $this->value($filters, 'date_from'));
        if ($this->value($filters, 'date_to')) $query->whereDate('planned_date', '<=', $this->value($filters, 'date_to'));
        if ($this->value($filters, 'delivery_date_from')) $query->whereHas('demand.order', fn ($q) => $q->whereDate('required_delivery_date', '>=', $this->value($filters, 'delivery_date_from')));
        if ($this->value($filters, 'delivery_date_to')) $query->whereHas('demand.order', fn ($q) => $q->whereDate('required_delivery_date', '<=', $this->value($filters, 'delivery_date_to')));
        if ($this->value($filters, 'quantity_min') !== null) $query->where('target_qty', '>=', (float) $this->value($filters, 'quantity_min'));
        if ($this->value($filters, 'quantity_max') !== null) $query->where('target_qty', '<=', (float) $this->value($filters, 'quantity_max'));
        $keyword = $this->value($filters, 'keyword');
        if ($keyword) $query->where(function (Builder $q) use ($keyword): void {
            $like = '%'.$keyword.'%';
            $q->where('work_order_no', 'like', $like)
                ->orWhere('source_no_snapshot', 'like', $like)
                ->orWhere('source_title_snapshot', 'like', $like)
                ->orWhereHas('demand', fn ($d) => $d->where('requirement_no', 'like', $like))
                ->orWhereHas('demand.order', fn ($o) => $o->where('sales_order_no', 'like', $like)->orWhereRaw("JSON_SEARCH(customer_snapshot, 'one', ?) IS NOT NULL", [$keyword]))
                ->orWhereHas('demand.line', fn ($l) => $l->where('product_name', 'like', $like)->orWhere('sku_name', 'like', $like));
        });
    }

    private function applyDemandKeyword(Builder $query, ?string $keyword): void
    {
        if (! $keyword) return;
        $like = '%'.$keyword.'%';
        $query->where(function (Builder $q) use ($like, $keyword): void {
            $q->where('requirement_no', 'like', $like)
                ->orWhereHas('order', fn ($o) => $o->where('sales_order_no', 'like', $like)->orWhereRaw("JSON_SEARCH(customer_snapshot, 'one', ?) IS NOT NULL", [$keyword]))
                ->orWhereHas('line', fn ($l) => $l->where('product_name', 'like', $like)->orWhere('sku_name', 'like', $like));
        });
    }

    private function assertDemandVisible(ProductionDemand $demand, object $user, array $permissions, bool $superAdmin): void
    {
        $scope = $this->scopeResolver->resolve($user, 'production.demand.view', $permissions, $superAdmin);
        if (! $this->scopeResolver->demandVisible($demand, $scope)) {
            throw new WorkOrderDomainException('data_scope_denied', '当前生产需求不在用户的生产数据范围内。', 403);
        }
    }

    private function assertWorkOrderVisible(WorkOrder $workOrder, object $user, array $permissions, bool $superAdmin): void
    {
        $scope = $this->scopeResolver->resolve($user, 'production.work_order.view', $permissions, $superAdmin);
        if (! $this->scopeResolver->workOrderVisible($workOrder, $scope)) {
            throw new WorkOrderDomainException('data_scope_denied', '当前工单不在用户的生产数据范围内。', 403);
        }
    }

    private function assertPermission(array $permissions, string $required, bool $superAdmin): void
    {
        if (! in_array($required, $permissions, true)) throw new WorkOrderDomainException('permission_denied', '当前用户没有访问该生产资源的权限。', 403, ['permission' => $required]);
    }

    private function attachWorkOrderUserProjections(Collection $workOrders): void
    {
        $map = $this->userProjections->many($workOrders->pluck('responsible_user_legacy_id')->filter()->all());
        $workOrders->each(function (WorkOrder $workOrder) use ($map): void {
            $workOrder->setAttribute('responsible_user_projection', $map[(int) $workOrder->responsible_user_legacy_id] ?? null);
        });
    }

    private function attachDemandUserProjections(Collection $demands): void
    {
        $demandIds = $demands->pluck('id')->map(fn ($id) => (int) $id)->filter()->all();
        if ($demandIds === []) return;
        $assignments = DB::table('erp_work_orders')
            ->whereIn('production_demand_id', $demandIds)
            ->whereIn('status', self::ACTIVE_WORK_ORDER_STATUSES)
            ->whereNotNull('responsible_user_legacy_id')
            ->get(['production_demand_id', 'responsible_user_legacy_id'])
            ->groupBy('production_demand_id');
        $responsibleIds = $assignments->flatten()->pluck('responsible_user_legacy_id')->all();
        $salesOwnerIds = $demands->map(fn (ProductionDemand $demand) => $demand->order?->sales_user_legacy_id)->filter()->all();
        $map = $this->userProjections->many(array_merge($responsibleIds, $salesOwnerIds));

        $demands->each(function (ProductionDemand $demand) use ($assignments, $map): void {
            $ids = $assignments->get($demand->id, collect())
                ->pluck('responsible_user_legacy_id')->map(fn ($id) => (int) $id)->unique()->values();
            $productionResponsible = $ids->count() === 1
                ? ($map[$ids->first()] ?? null)
                : ($ids->count() > 1 ? ['user_id' => null, 'display_name' => '多人负责', 'department_name' => null, 'status' => 'mixed'] : null);
            $salesOwnerId = (int) ($demand->order?->sales_user_legacy_id ?? 0);
            $demand->setAttribute('production_responsible_projection', $productionResponsible);
            $demand->setAttribute('sales_owner_projection', $map[$salesOwnerId] ?? null);
        });
    }

    private function fieldAuditSummary(WorkOrder $workOrder): ?array
    {
        $row = DB::table('erp_operation_logs')
            ->where('module', 'work_order')->where('target_type', 'work_order')->where('target_id', $workOrder->id)
            ->orderByDesc('id')->first(['action', 'old_snapshot', 'new_snapshot', 'reason', 'operator_name', 'created_at']);
        if (! $row) return null;
        $allowed = ['target_qty', 'planned_date', 'production_batch', 'production_location_name', 'business_version'];
        $snapshot = function ($value) use ($allowed): array {
            $decoded = is_array($value) ? $value : (json_decode((string) $value, true) ?: []);
            $safe = collect($decoded)->only($allowed)->all();
            if (array_key_exists('responsible_user_legacy_id', $decoded)) {
                $safe['responsible_user'] = $this->userProjections->one($decoded['responsible_user_legacy_id']);
            }
            return $safe;
        };
        return [
            'action' => $row->action,
            'old' => $snapshot($row->old_snapshot),
            'new' => $snapshot($row->new_snapshot),
            'reason' => $row->reason,
            'operator_name' => $row->operator_name,
            'occurred_at' => $row->created_at,
        ];
    }

    private function value(array $filters, string $key): mixed
    {
        $value = $filters[$key] ?? null;
        return is_string($value) ? trim($value) : $value;
    }

    private function perPage(array $filters): int { return max(1, min(100, (int) ($filters['per_page'] ?? 20))); }
    private function page(array $filters): int { return max(1, (int) ($filters['page'] ?? 1)); }
}
