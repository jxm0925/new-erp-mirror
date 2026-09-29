<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{PurchaseReceipt, PurchaseReturn, SalesOrder, SalesReturn, SalesReturnReceipt, SalesShipment, WorkOrder};
use Illuminate\Support\Facades\Validator;

/** A closed registry of warehouse use cases, not an arbitrary URL or service dispatcher. */
final class WarehouseActionService
{
    public const PERMISSIONS = [
        'purchase.post' => 'inventory.post.execute', 'purchase.allocate' => 'inventory.post.repair',
        'output.warehouse' => 'production.output.warehouse', 'remnant.warehouse' => 'production.cutting.warehouse',
        'cutting.warehouse' => 'production.cutting.warehouse', 'production_return.receive' => 'production.material_return.receive',
        'picking.create' => 'production.material_picking.create', 'picking.assign' => 'production.material_picking.assign',
        'picking.start' => 'production.material_picking.pick', 'picking.confirm' => 'production.material_picking.pick',
        'picking.cancel' => 'production.material_picking.cancel', 'delivery.create' => 'production.material_delivery.create',
        'delivery.dispatch' => 'production.material_delivery.dispatch', 'delivery.deliver' => 'production.material_delivery.confirm',
        'delivery.receive' => 'production.material_receipt.confirm', 'delivery.cancel' => 'production.material_delivery.cancel',
        'sales_return.receive' => 'sales_return.receive', 'sales_return.post' => 'sales_return.post',
        'sales_shipment.post' => 'sales_order.shipment.post', 'sales_shipment.dispatch' => 'sales_order.shipment.dispatch',
        'purchase_return.post' => 'purchase_return.post',
    ];

    public function authorize(string $action, int $id, array $payload, object $user, array $permissions, bool $super): void
    {
        $permission = self::PERMISSIONS[$action] ?? null;
        if (! $permission || ! in_array($permission, $permissions, true)) $this->fail('permission_denied', '没有执行当前仓库操作的权限。', 403);
        if ($id < 1) $this->fail('warehouse_target_invalid', '业务单据不存在。', 404);
        if (str_starts_with($action, 'purchase.')) { PurchaseReceipt::findOrFail($id); return; }
        if ($action === 'purchase_return.post') { PurchaseReturn::findOrFail($id); return; }
        if (str_starts_with($action, 'sales_')) {
            $orders = SalesOrder::query()->select('id'); app(SalesOrderVisibilityService::class)->apply($orders, $user);
            if (str_starts_with($action, 'sales_shipment.')) SalesShipment::whereIn('sales_order_id', $orders)->findOrFail($id);
            elseif ($action === 'sales_return.post') SalesReturnReceipt::whereHas('salesReturn', fn ($q) => $q->whereIn('sales_order_id', $orders))->findOrFail($id);
            else SalesReturn::whereIn('sales_order_id', $orders)->findOrFail($id);
            return;
        }
        if ($action === 'output.warehouse' || $action === 'production_return.receive') {
            app(ProductionExecutionInboxService::class)->assertVisible($action === 'output.warehouse' ? 'outputs' : 'material_returns', $id, $user, $permissions, $super);
            return;
        }
        if ($action === 'remnant.warehouse') { app(CuttingCommandService::class)->order($id, $user, $permissions, $super, $permission); return; }
        if ($action === 'cutting.warehouse') {
            $route = \Illuminate\Support\Facades\DB::table('erp_cutting_result_routes')->where('id', $id)->first();
            if (! $route) $this->fail('not_found', '产出分流记录不存在。', 404);
            app(CuttingCommandService::class)->assertResultVisible($route->result_id, $user, $permissions, $super, $permission); return;
        }
        if ($action === 'picking.create') {
            $wo = WorkOrder::findOrFail($id); $scopes = app(ProductionDataScopeResolver::class);
            if (! $scopes->workOrderVisible($wo, $scopes->resolve($user, 'production.material_picking.view', $permissions, $super))) $this->fail('data_scope_denied', '工单不在当前数据范围内。', 403);
            return;
        }
        $execution = app(ProductionMaterialExecutionService::class);
        if (str_starts_with($action, 'picking.') || $action === 'delivery.create') $execution->showPickingTask($id, $user, $permissions, $super);
        else $execution->showDelivery($id, $user, $permissions, $super);
    }

    public function validate(string $action, array $payload): array
    {
        $version = ['expected_version' => 'required|integer|min:1'];
        $locator = ['warehouse_id' => 'required|integer|min:1', 'location_id' => 'required|integer|min:1'];
        $lines = ['lines' => 'required|array|min:1|max:100'];
        $serials = ['lines.*.serial_ids' => 'nullable|array|max:1000', 'lines.*.serial_ids.*' => 'integer|min:1|distinct'];
        $rules = match ($action) {
            'purchase.post', 'sales_return.post', 'sales_shipment.post', 'sales_shipment.dispatch', 'purchase_return.post' => [],
            'purchase.allocate' => [
                'items' => 'required|array|min:1|max:100', 'items.*.receipt_item_id' => 'required|integer|min:1|distinct',
                'items.*.expected_revision' => 'required|string|size:64',
                'items.*.allocations' => 'required|array|min:1|max:100', 'items.*.allocations.*.warehouse_id' => 'required|integer|min:1',
                'items.*.allocations.*.location_id' => 'required|integer|min:1', 'items.*.allocations.*.base_qty' => 'required|numeric|gt:0',
                'items.*.allocations.*.serial_nos' => 'nullable|array|max:1000', 'items.*.allocations.*.serial_nos.*' => 'string|max:100',
                'items.*.allocations.*.physical_entries' => 'nullable|array|max:500',
                'items.*.allocations.*.physical_entries.*.dimensions' => 'required|array',
                'items.*.allocations.*.physical_entries.*.dimensions.length_mm' => 'required|numeric|gt:0',
                'items.*.allocations.*.physical_entries.*.dimensions.width_mm' => 'required|numeric|gt:0',
                'items.*.allocations.*.physical_entries.*.dimensions.thickness_mm' => 'required|numeric|gt:0',
                'items.*.allocations.*.physical_entries.*.dimensions.nominal_thickness_mm' => 'nullable|numeric|gt:0',
            ],
            'output.warehouse' => $version + $locator + ['batch_no' => 'required|string|max:80', 'posted_base_qty' => 'required|numeric|gt:0'],
            'cutting.warehouse' => $version + $locator + ['batch_no' => 'required|string|max:80', 'quantity' => 'required|numeric|gt:0'],
            'remnant.warehouse' => $locator + $lines + ['remark' => 'nullable|string|max:1000', 'lines.*.result_id' => 'required|integer|min:1|distinct',
                'lines.*.expected_version' => 'required|integer|min:1', 'lines.*.holding_version' => 'required|integer|min:1', 'lines.*.physical_version' => 'nullable|integer|min:1'],
            'production_return.receive', 'picking.start', 'delivery.deliver' => $version,
            'picking.assign' => $version + ['assigned_picker_legacy_id' => 'required|integer|min:1'],
            'picking.cancel', 'delivery.cancel' => $version + ['reason' => 'required|string|max:500'],
            'picking.create' => $version + $lines + $serials + ['warehouse_id' => 'required|integer|min:1', 'planned_delivery_at' => 'nullable|date', 'remark' => 'nullable|string|max:2000',
                'lines.*.target_material_requirement_id' => 'required|integer|min:1', 'lines.*.inventory_balance_id' => 'required|integer|min:1', 'lines.*.planned_pick_qty' => 'required|numeric|gt:0'],
            'picking.confirm' => $version + $lines + $serials + ['reason' => 'nullable|string|max:500', 'lines.*.picking_task_line_id' => 'required|integer|min:1|distinct',
                'lines.*.actual_pick_qty' => 'required|numeric|min:0', 'lines.*.physical_material_ids' => 'nullable|array|max:1000', 'lines.*.physical_material_ids.*' => 'integer|min:1|distinct'],
            'delivery.create' => $version + $lines + $serials + ['delivery_user_legacy_id' => 'required|integer|min:1', 'delivery_type' => 'nullable|in:standard,redelivery,supplement,internal_issue',
                'source_delivery_id' => 'nullable|integer|min:1', 'remark' => 'nullable|string|max:2000', 'lines.*.picking_task_line_id' => 'required|integer|min:1|distinct', 'lines.*.delivery_qty' => 'required|numeric|gt:0'],
            'delivery.dispatch' => $version + ['delivery_user_legacy_id' => 'nullable|integer|min:1'],
            'delivery.receive' => $version + $lines + ['remark' => 'nullable|string|max:2000', 'lines.*.delivery_line_id' => 'required|integer|min:1|distinct',
                'lines.*.accepted_qty' => 'required|numeric|min:0', 'lines.*.rejected_qty' => 'required|numeric|min:0', 'lines.*.reject_reason' => 'nullable|string|max:500',
                'lines.*.accepted_serial_ids' => 'nullable|array|max:1000', 'lines.*.accepted_serial_ids.*' => 'integer|min:1|distinct',
                'lines.*.rejected_serial_ids' => 'nullable|array|max:1000', 'lines.*.rejected_serial_ids.*' => 'integer|min:1|distinct',
                'lines.*.rejected_serial_reasons' => 'nullable|array|max:1000', 'lines.*.rejected_serial_reasons.*' => 'string|max:500'],
            'sales_return.receive' => ['receipt_date' => 'nullable|date', 'remark' => 'nullable|string|max:2000', 'items' => 'required|array|min:1|max:100',
                'items.*.serial_dispositions' => 'nullable|array:restock,pending,scrap,rejected',
                'items.*.serial_dispositions.*' => 'array|max:1000', 'items.*.serial_dispositions.*.*' => 'integer|min:1|distinct',
                'items.*.sales_return_item_id' => 'required|integer|min:1|distinct', 'items.*.received_base_qty' => 'required|numeric|gt:0',
                'items.*.restock_base_qty' => 'required|numeric|min:0', 'items.*.pending_base_qty' => 'required|numeric|min:0',
                'items.*.scrap_base_qty' => 'required|numeric|min:0', 'items.*.rejected_base_qty' => 'required|numeric|min:0',
                'items.*.warehouse_id' => 'nullable|integer|min:1', 'items.*.location_id' => 'nullable|integer|min:1',
                'items.*.batch_no' => 'nullable|string|max:80', 'items.*.inspection_remark' => 'nullable|string|max:1000'],
            default => throw new WorkOrderDomainException('warehouse_action_invalid', '不支持的仓库操作。', 422),
        };
        return Validator::make($payload, $rules)->validate();
    }

    public function execute(string $action, int $id, array $payload, string $commandId, object $user, array $permissions, bool $super): mixed
    {
        $context = [$user, $permissions, $super];
        $actor = (int) ($user->legacy_id ?? $user->id ?? 0); $name = (string) ($user->nickname ?? $user->username ?? $user->name ?? '');
        $command = $payload + ['client_command_id' => $commandId];
        return match ($action) {
            'purchase.post' => app(InventoryService::class)->postPurchaseReceipt($id),
            'purchase.allocate' => ['receipt_id' => app(PurchaseReceiptPostingRepairApplicationService::class)->repairSelectedLines($id, $payload['items'], $name)->id],
            'output.warehouse' => app(ProductionOutputService::class)->warehouse($id, $command, $user, $permissions),
            'remnant.warehouse' => app(CuttingRemnantReceiptService::class)->post($id, $command, ...$context),
            'cutting.warehouse' => app(CuttingWarehouseReceiptService::class)->post($id, $command, ...$context),
            'production_return.receive' => app(ProductionMaterialReturnService::class)->receive($id, $command, $user, $permissions),
            'picking.create' => app(ProductionMaterialExecutionService::class)->createPickingTask($command + ['work_order_id' => $id], ...$context),
            'picking.assign' => app(ProductionMaterialExecutionService::class)->assignPickingTask($id, $command, ...$context),
            'picking.start' => app(ProductionMaterialExecutionService::class)->startPickingTask($id, $command, ...$context),
            'picking.confirm' => $this->confirmPicking($id, $command, ...$context),
            'picking.cancel' => app(ProductionMaterialExecutionService::class)->cancelPickingTask($id, $command, ...$context),
            'delivery.create' => app(ProductionMaterialExecutionService::class)->createDelivery($command + ['picking_task_id' => $id], ...$context),
            'delivery.dispatch' => app(ProductionMaterialExecutionService::class)->dispatchDelivery($id, $command, ...$context),
            'delivery.deliver' => app(ProductionMaterialExecutionService::class)->deliverDelivery($id, $command, ...$context),
            'delivery.receive' => app(ProductionMaterialExecutionService::class)->receiveDelivery($id, $command, ...$context),
            'delivery.cancel' => app(ProductionMaterialExecutionService::class)->cancelDelivery($id, $command, ...$context),
            'sales_return.receive' => app(SalesReturnApplicationService::class)->receive($payload + ['sales_return_id' => $id], $actor, $name),
            'sales_return.post' => app(SalesReturnApplicationService::class)->postReceipt($id, $actor, $name),
            'sales_shipment.post' => app(SalesShipmentApplicationService::class)->postOutbound(SalesShipment::findOrFail($id), $name),
            'sales_shipment.dispatch' => app(SalesShipmentApplicationService::class)->dispatch(SalesShipment::findOrFail($id), $name),
            'purchase_return.post' => app(PurchaseReturnApplicationService::class)->post($id, $actor, $name),
        };
    }

    private function confirmPicking(int $id, array $command, object $user, array $permissions, bool $super): mixed
    {
        $service = app(ProductionMaterialExecutionService::class);
        $task = $service->showPickingTask($id, $user, $permissions, $super);
        if (count($command['lines']) !== $task->lines->count()) $this->fail('picking_lines_incomplete', '请逐页核对全部实拣明细，未拣出的物料填写0。', 422);
        foreach ($command['lines'] as $row) {
            $line = $task->lines->firstWhere('id', $row['picking_task_line_id']);
            if ($line && $line->componentItem?->materialManagementMode() === 'physical' && (float) $row['actual_pick_qty'] > 0 && empty($row['physical_material_ids']))
                $this->fail('physical_selection_required', '请逐张选择本次实际拣出的实物。', 422);
        }
        return $service->confirmPickingTask($id, $command, $user, $permissions, $super);
    }

    private function fail(string $code, string $message, int $status): never { throw new WorkOrderDomainException($code, $message, $status); }
}
