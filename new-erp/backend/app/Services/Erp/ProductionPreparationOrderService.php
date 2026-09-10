<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\ProductionPreparationOrder;
use App\Models\Erp\ProductionPreparationOrderLine;
use App\Models\Erp\WorkOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class ProductionPreparationOrderService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ProductionDataScopeResolver $scopeResolver,
    ) {}

    public function syncFromPublishedWorkOrder(WorkOrder $workOrder, object $operator): ProductionPreparationOrder
    {
        if ($workOrder->status !== WorkOrderApplicationService::RELEASED || ! $workOrder->production_master_order_id) {
            throw new WorkOrderDomainException('preparation_source_invalid', '订单备料单只能从已发布且已归属主生产工单的生产工单生成。', 422);
        }
        $requirements = $workOrder->materialRequirements()->lockForUpdate()->get();
        if ($requirements->isEmpty()) {
            throw new WorkOrderDomainException('formal_material_requirement_missing', '生产工单尚未形成冻结的正式物料需求，不能生成订单备料单。', 422);
        }

        $masterId = (int) $workOrder->production_master_order_id;
        $order = ProductionPreparationOrder::query()
            ->where('active_production_master_order_id', $masterId)
            ->lockForUpdate()
            ->first();
        if (! $order) {
            $order = ProductionPreparationOrder::create([
                'preparation_order_no' => $this->numbers->next('production_preparation_order', 'PB'),
                'production_master_order_id' => $masterId,
                'active_production_master_order_id' => $masterId,
                'status' => 'WAIT_PREPARE',
                'business_version' => 1,
                'organization_code' => $workOrder->organization_code,
                'created_by_legacy_id' => $this->userId($operator),
                'updated_by_legacy_id' => $this->userId($operator),
            ]);
        }

        $inserted = false;
        foreach ($requirements as $requirement) {
            $line = ProductionPreparationOrderLine::query()->where('material_requirement_id', $requirement->id)->first();
            if ($line) {
                if ((int) $line->preparation_order_id !== (int) $order->id || (int) $line->work_order_id !== (int) $workOrder->id) {
                    throw new WorkOrderDomainException('preparation_line_conflict', '正式物料需求已被其他订单备料单引用。', 409);
                }
                continue;
            }
            ProductionPreparationOrderLine::create([
                'preparation_order_id' => $order->id,
                'work_order_id' => $workOrder->id,
                'material_requirement_id' => $requirement->id,
                'component_item_id' => $requirement->component_item_id,
                'required_base_qty' => $requirement->base_required_qty,
                'status' => 'WAIT_PREPARE',
                'business_version' => 1,
            ]);
            $inserted = true;
        }
        if ($inserted && $order->wasRecentlyCreated === false) {
            $order->update([
                'business_version' => (int) $order->business_version + 1,
                'updated_by_legacy_id' => $this->userId($operator),
            ]);
        }
        return $order->fresh($this->relations());
    }

    public function paginate(array $filters, object $user, array $permissions, bool $superAdmin = false): LengthAwarePaginator
    {
        $this->permission($permissions, $superAdmin);
        $scope = $this->scopeResolver->resolve($user, 'production.work_order.view', $permissions, $superAdmin);
        $query = ProductionPreparationOrder::query()->with(['masterOrder', 'lines.workOrder', 'lines.componentItem'])->orderByDesc('id');
        $query->whereHas('lines.workOrder', fn (Builder $workOrders) => $this->scopeResolver->applyWorkOrderScope($workOrders, $scope));
        if (! empty($filters['status'])) $query->where('status', $filters['status']);
        if (! empty($filters['production_master_order_id'])) $query->where('production_master_order_id', (int) $filters['production_master_order_id']);
        if (! empty($filters['keyword'])) {
            $like = '%'.trim((string) $filters['keyword']).'%';
            $query->where(fn (Builder $q) => $q->where('preparation_order_no', 'like', $like)
                ->orWhereHas('masterOrder', fn (Builder $master) => $master->where('master_order_no', 'like', $like)));
        }
        return $query->paginate(min(50, max(1, (int) ($filters['per_page'] ?? 20))), ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)));
    }

    public function show(int $id, object $user, array $permissions, bool $superAdmin = false): ProductionPreparationOrder
    {
        $this->permission($permissions, $superAdmin);
        $order = ProductionPreparationOrder::query()->with($this->relations())->find($id);
        if (! $order) throw new WorkOrderDomainException('preparation_order_not_found', '订单备料单不存在。', 404);
        $scope = $this->scopeResolver->resolve($user, 'production.work_order.view', $permissions, $superAdmin);
        $visible = WorkOrder::query()->whereIn('id', $order->lines->pluck('work_order_id'));
        $this->scopeResolver->applyWorkOrderScope($visible, $scope);
        if (! $visible->exists()) throw new WorkOrderDomainException('data_scope_denied', '订单备料单不在当前数据范围内。', 403);
        return $order;
    }

    private function relations(): array { return ['masterOrder', 'lines.workOrder', 'lines.materialRequirement', 'lines.componentItem']; }
    private function userId(object $user): ?int { return isset($user->legacy_id) ? (int) $user->legacy_id : (isset($user->id) ? (int) $user->id : null); }
    private function permission(array $permissions, bool $superAdmin): void
    {
        if (! $superAdmin && ! in_array('production.work_order.view', $permissions, true)) {
            throw new WorkOrderDomainException('permission_denied', '当前用户没有查看订单备料单的权限。', 403);
        }
    }
}
