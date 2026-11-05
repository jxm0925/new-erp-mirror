<?php

namespace App\Services\Erp;

use App\Services\Erp\Inventory\PurchaseInventoryPosting;
use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{PurchaseReceipt, PurchaseReturn, SalesOrder, SalesReturn, SalesReturnReceipt, SalesShipment};
use App\Models\Erp\{InventoryStocktake, MfgMaterialDelivery, MfgMaterialReturn, MfgReceipt, MfgWorkOrder, MfgWorkOrderOperation};
use App\Services\Erp\Inventory\InventoryStocktakeService;
use App\Services\Erp\Manufacturing\{MaterialDeliveryService, MaterialReturnService, ProductionReviewers, ReceiptService, TaskService};
use Illuminate\Support\Facades\Validator;
use App\Models\Erp\{InventoryTransfer, MfgCuttingBatch, MfgCuttingRun, MfgCuttingRemnantReceipt};
use App\Services\Erp\Inventory\InventoryTransferService;
use App\Services\Erp\Manufacturing\{CuttingService, SharedCuttingService, CuttingRunService, CuttingRemnantReceiptService};

/** A closed registry of warehouse use cases, not an arbitrary URL or service dispatcher. */
final class WarehouseActionService
{
    public const PERMISSIONS = [
        'cutting_batch.request' => ['production.task.execute', 'production.work_order.edit'],
        'cutting_run.finish' => ['production.task.execute', 'production.work_order.edit'],
        'cutting_remnant.confirm' => 'inventory.inbound.confirm', 'cutting_remnant.reject' => 'inventory.inbound.confirm',
        'cutting_remnant.scrap' => 'inventory.inbound.confirm',
        'cutting_remnant.cancel' => ['production.task.execute', 'production.work_order.edit'],
        'inventory_transfer.create' => 'inventory.transfer.create', 'inventory_transfer.update' => 'inventory.transfer.create',
        'inventory_transfer.post' => 'inventory.transfer.post', 'inventory_transfer.confirm' => 'inventory.inbound.confirm',
        'inventory_transfer.exception' => ['inventory.inbound.confirm', 'inventory.transfer.post'],
        'inventory_transfer.cancel' => ['inventory.transfer.create', 'inventory.transfer.post'], 'inventory_transfer.delete' => 'inventory.transfer.delete',
        'material_physical_transfer.dispatch' => 'inventory.physical.manage', 'material_physical_transfer.confirm' => 'inventory.inbound.confirm',
        'material_physical_transfer.exception' => ['inventory.inbound.confirm', 'inventory.physical.manage'],
        'mfg_receipt.request' => 'production.receipt.execute',
        'mfg_task.pass' => 'production.task.execute', 'mfg_task.prepare' => 'production.task.execute',
        'mfg_receipt.confirm' => 'inventory.inbound.confirm', 'mfg_receipt.reject' => 'inventory.inbound.confirm',
        'mfg_receipt.cancel' => ['production.receipt.execute', 'production.task.execute'],
        'purchase.post' => 'inventory.post.execute', 'purchase.allocate' => 'inventory.post.repair',
        'sales_return.receive' => 'sales_return.receive', 'sales_return.post' => 'sales_return.post',
        'sales_shipment.post' => 'sales_order.shipment.post', 'sales_shipment.dispatch' => 'sales_order.shipment.dispatch',
        'purchase_return.post' => 'purchase_return.post',
        'material_delivery.prepare' => 'production.material_delivery.manage',
        'material_delivery.dispatch' => 'production.material_delivery.manage', 'material_delivery.repick' => 'production.material_delivery.manage',
        'material_delivery.cancel' => 'production.material_delivery.manage', 'material_delivery.redeliver' => 'production.material_delivery.manage',
        'material_delivery.recall' => 'production.material_delivery.manage',
        'material_return.request' => ['production.material_return.request', 'production.material_return.approve', 'inventory.inbound.confirm'],
        'material_return.plan_review' => ['production.task.execute', 'production.scrap.approve'],
        'material_return.approve' => 'inventory.inbound.confirm', 'material_return.reject' => 'inventory.inbound.confirm',
        'material_return.dispose' => 'production.material_return.approve',
        'material_return.cancel' => ['production.material_return.request', 'production.material_return.approve', 'inventory.inbound.confirm'],
        'stocktake.counts' => 'inventory.stocktake.create', 'stocktake.complete' => 'inventory.stocktake.complete',
    ];

    public function authorize(string $action, int $id, array $payload, object $user, array $permissions, bool $super): void
    {
        $permission = self::PERMISSIONS[$action] ?? null;
        if (! $permission || ! array_intersect((array) $permission, $permissions)) $this->fail('permission_denied', '没有执行当前仓库操作的权限。', 403);
        if ($id < 1) $this->fail('warehouse_target_invalid', '业务单据不存在。', 404);
        if ($action === 'cutting_batch.request') { app(CuttingRemnantReceiptService::class)->assertRequestActor(MfgCuttingBatch::findOrFail($id), $user); return; }
        if ($action === 'cutting_run.finish') { MfgCuttingRun::findOrFail($id); return; }
        if (str_starts_with($action, 'cutting_remnant.')) {
            $receipt = MfgCuttingRemnantReceipt::findOrFail($id);
            if ($action === 'cutting_remnant.cancel' && (int) $receipt->requested_by_legacy_id !== (int) ($user->legacy_id ?? $user->id ?? 0))
                $this->fail('permission_denied', '只能撤回自己提交的余料申请。', 403);
            if ($action === 'cutting_remnant.scrap' && ! in_array('production.scrap.approve', $permissions, true))
                $this->fail('permission_denied', '余料报废还需要生产报废审批权限。', 403);
            return;
        }
        if (str_starts_with($action, 'inventory_transfer.')) {
            // 删除后的同命令重放仍需读回原结果；首执行由领域服务锁单校验范围。
            if ($action === 'inventory_transfer.delete') return;
            if ($action === 'inventory_transfer.create') { InventoryTransferService::assertWarehouseScope($id, $user); return; }
            $transfer = InventoryTransfer::findOrFail($id);
            if ($action === 'inventory_transfer.exception') app(InventoryTransferService::class)->assertExceptionScope((int) $transfer->source_warehouse_id, (int) $transfer->target_warehouse_id, $user, 'inventory.transfer.post');
            else InventoryTransferService::assertWarehouseScope((int) ($action === 'inventory_transfer.confirm' ? $transfer->target_warehouse_id : $transfer->source_warehouse_id), $user);
            return;
        }
        if (str_starts_with($action, 'material_physical_transfer.')) {
            if ($action === 'material_physical_transfer.dispatch') { \Illuminate\Support\Facades\DB::table('erp_material_physicals')->where('id', $id)->firstOrFail(); return; }
            $transfer = app(PhysicalInventoryApplicationService::class)->transferDetail($id, $user);
            if ($action === 'material_physical_transfer.exception') app(InventoryTransferService::class)->assertExceptionScope((int) $transfer['source_warehouse_id'], (int) $transfer['target_warehouse_id'], $user, 'inventory.physical.manage');
            else InventoryTransferService::assertWarehouseScope((int) $transfer['target_warehouse_id'], $user);
            return;
        }
        if (str_starts_with($action, 'mfg_task.')) { MfgWorkOrderOperation::findOrFail($id); return; }
        if ($action === 'mfg_receipt.request') { MfgWorkOrder::findOrFail($id); return; }
        if (str_starts_with($action, 'mfg_receipt.')) {
            $receipt = MfgReceipt::findOrFail($id);
            if ($action === 'mfg_receipt.cancel' && (int) $receipt->operator_legacy_id !== (int) ($user->legacy_id ?? $user->id ?? 0))
                $this->fail('permission_denied', '只能撤回自己提交的入库申请。', 403);
            return;
        }
        if (str_starts_with($action, 'purchase.')) { PurchaseReceipt::findOrFail($id); return; }
        if ($action === 'purchase_return.post') { PurchaseReturn::findOrFail($id); return; }
        if (str_starts_with($action, 'material_delivery.')) {
            if ($action === 'material_delivery.prepare') MfgWorkOrderOperation::query()->where('work_order_id', (int) ($payload['work_order_id'] ?? 0))->findOrFail($id);
            else MfgMaterialDelivery::findOrFail($id);
            return;
        }
        if (str_starts_with($action, 'material_return.')) {
            if ($action === 'material_return.request') {
                MfgWorkOrderOperation::query()->where('work_order_id', (int) ($payload['work_order_id'] ?? 0))->findOrFail($id);
                return;
            }
            $return = MfgMaterialReturn::findOrFail($id);
            if ($action === 'material_return.plan_review') {
                $wo = MfgWorkOrder::findOrFail($return->work_order_id);
                if (! $super && ! in_array('production.scrap.approve', $permissions, true)
                    && ! in_array((int) ($user->legacy_id ?? $user->id ?? 0), ProductionReviewers::for($wo), true))
                    $this->fail('permission_denied', '只有本工单生产负责人可以审核处置方案。', 403);
                return;
            }
            if (! $super && ! array_intersect(['production.material_return.approve', 'inventory.inbound.confirm'], $permissions)
                && (int) $return->requested_by_legacy_id !== (int) ($user->legacy_id ?? $user->id ?? 0)) {
                $this->fail('permission_denied', '只能查看或撤回自己提交的退料申请。', 403);
            }
            return;
        }
        if (str_starts_with($action, 'stocktake.')) { InventoryStocktake::findOrFail($id); return; }
        if (str_starts_with($action, 'sales_')) {
            $orders = SalesOrder::query()->select('id'); app(SalesOrderVisibilityService::class)->apply($orders, $user);
            if (str_starts_with($action, 'sales_shipment.')) SalesShipment::whereIn('sales_order_id', $orders)->findOrFail($id);
            elseif ($action === 'sales_return.post') SalesReturnReceipt::whereHas('salesReturn', fn ($q) => $q->whereIn('sales_order_id', $orders))->findOrFail($id);
            else SalesReturn::whereIn('sales_order_id', $orders)->findOrFail($id);
            return;
        }
        $this->fail('warehouse_action_invalid', '不支持的仓库操作。', 422);
    }

    public function validate(string $action, array $payload): array
    {
        $confirmationRemark = in_array($action, ['mfg_receipt.confirm', 'cutting_remnant.confirm', 'inventory_transfer.confirm', 'material_physical_transfer.confirm'], true)
            ? ['remark' => 'nullable|string|max:500'] : [];
        if (str_starts_with($action, 'inventory_transfer.')) return Validator::make($payload, InventoryTransferService::rules(substr($action, strlen('inventory_transfer.'))) + $confirmationRemark)->validate();
        if (str_starts_with($action, 'material_physical_transfer.')) return Validator::make($payload, PhysicalInventoryApplicationService::transferRules(substr($action, strlen('material_physical_transfer.'))) + $confirmationRemark)->validate();
        $rules = match ($action) {
            'cutting_batch.request' => CuttingRemnantReceiptService::requestRules(),
            'cutting_remnant.confirm' => CuttingRemnantReceiptService::confirmRules(),
            'cutting_remnant.scrap' => CuttingRemnantReceiptService::confirmRules(true),
            'cutting_remnant.reject' => ['expected_version' => 'required|integer|min:1', 'reason' => 'required|string|max:500'],
            'cutting_remnant.cancel' => ['expected_version' => 'required|integer|min:1'],
            'cutting_run.finish' => ['expected_version' => 'required|integer|min:1', 'lines' => 'required|array|min:1|max:1000',
                'lines.*.line_key' => 'required|string|max:160|distinct', 'lines.*.actual_pieces' => 'required|integer|min:0', 'remark' => 'nullable|string|max:500'],
            'mfg_task.pass', 'mfg_task.prepare' => ['unit_ids' => 'required|array|min:1|max:1000', 'unit_ids.*' => 'required|integer|min:1|distinct',
                'lane' => 'nullable|in:normal,rework', 'remark' => 'nullable|string|max:500', 'cutting_run_id' => 'nullable|integer|min:1',
                'completion_action' => 'nullable|in:store,handoff', 'completion_location_id' => 'nullable|integer|min:1',
                'handoff_operation_id' => 'nullable|integer|min:1'] + ProductionMediaService::rules(),
            'mfg_receipt.request' => ['unit_ids' => 'required|array|min:1|max:1000', 'unit_ids.*' => 'required|integer|min:1|distinct',
                'warehouse_id' => 'required|integer|min:1', 'location_id' => 'required|integer|min:1', 'remark' => 'nullable|string|max:500'],
            'mfg_receipt.confirm' => ['expected_version' => 'required|integer|min:1', 'warehouse_id' => 'nullable|integer|min:1',
                'location_id' => 'required|integer|min:1', 'serial_nos' => 'nullable|array|max:1000',
                'serial_nos.*' => 'required|string|max:120|distinct'],
            'mfg_receipt.reject' => ['expected_version' => 'required|integer|min:1', 'reason' => 'required|string|max:500'],
            'mfg_receipt.cancel' => ['expected_version' => 'required|integer|min:1'],
            'purchase.post', 'sales_return.post', 'purchase_return.post' => [],
            'sales_shipment.post' => ['expected_version' => 'required|integer|min:1', 'staging_location_id' => 'required|integer|min:1'],
            'sales_shipment.dispatch' => ['expected_version' => 'required|integer|min:1', 'package_ids' => 'required|array|min:1|max:100',
                'package_ids.*' => 'required|integer|min:1|distinct', 'delivery_mode' => 'required|in:dispatch,pickup',
                'carrier_name' => 'nullable|string|max:120', 'tracking_no' => 'nullable|string|max:120'],
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
            'sales_return.receive' => ['receipt_date' => 'nullable|date', 'remark' => 'nullable|string|max:2000', 'items' => 'required|array|min:1|max:100',
                'items.*.serial_dispositions' => 'nullable|array:restock,pending,scrap,rejected',
                'items.*.serial_dispositions.*' => 'array|max:1000', 'items.*.serial_dispositions.*.*' => 'integer|min:1|distinct',
                'items.*.sales_return_item_id' => 'required|integer|min:1|distinct', 'items.*.received_base_qty' => 'required|numeric|gt:0',
                'items.*.restock_base_qty' => 'required|numeric|min:0', 'items.*.pending_base_qty' => 'required|numeric|min:0',
                'items.*.scrap_base_qty' => 'required|numeric|min:0', 'items.*.rejected_base_qty' => 'required|numeric|min:0',
                'items.*.warehouse_id' => 'nullable|integer|min:1', 'items.*.location_id' => 'nullable|integer|min:1',
                'items.*.batch_no' => 'nullable|string|max:80', 'items.*.inspection_remark' => 'nullable|string|max:1000'],
            'material_delivery.prepare' => ['work_order_id' => 'required|integer|min:1', 'units' => 'nullable|integer|min:1|max:100000',
                'unit_ids' => 'nullable|array|min:1|max:1000', 'unit_ids.*' => 'integer|min:1|distinct',
                'remark' => 'nullable|string|max:500', 'dispatch' => 'nullable|boolean', 'expected_source_revision' => 'nullable|string|regex:/^[a-f0-9]{64}$/'] + MaterialDeliveryService::selectionRules(),
            'material_delivery.dispatch' => ['expected_source_revision' => 'nullable|string|regex:/^[a-f0-9]{64}$/'],
            'material_delivery.repick', 'stocktake.complete' => [],
            'material_delivery.cancel', 'material_delivery.redeliver' => ['remark' => 'nullable|string|max:500'],
            'material_delivery.recall' => ['reason' => 'required|string|max:300', 'confirm_inbound' => 'nullable|boolean',
                'location_id' => 'nullable|integer|min:1', 'serial_nos' => 'sometimes|array|max:1000', 'serial_nos.*' => 'required|string|max:120|distinct'],
            'material_return.request' => ['work_order_id' => 'required|integer|min:1', 'return_type' => 'required|in:surplus,defective,scrapped,cancellation',
                'disposition_id' => 'required_if:return_type,cancellation|nullable|integer|min:1',
                'reason' => 'required|string|max:500', 'lines' => 'required|array|min:1|max:50', 'lines.*.material_id' => 'required|integer|min:1',
                'lines.*.qty' => 'nullable|numeric|min:0|max:1000000', 'lines.*.serial_ids' => 'nullable|array|max:1000',
                'lines.*.serial_ids.*' => 'integer|min:1|distinct', 'direct' => 'nullable|boolean', 'location_id' => 'nullable|integer|min:1',
                'serial_nos' => 'sometimes|array|max:1000', 'serial_nos.*' => 'required|string|max:120|distinct',
                'disposition_plan' => 'nullable|array', 'disposition_plan.action' => 'required_with:disposition_plan|in:rework,scrap,release',
                'disposition_plan.start_sequence' => 'nullable|integer|min:1', 'disposition_plan.photos' => 'nullable|array|max:9',
                'disposition_plan.photos.*' => 'string|max:1000'] + ProductionMediaService::rules('disposition_plan.media'),
            'material_return.plan_review' => ['expected_version' => 'required|integer|min:1', 'approve' => 'required|boolean', 'remark' => 'nullable|string|max:500'],
            'material_return.approve' => ['expected_version' => 'required|integer|min:1', 'location_id' => 'required|integer|min:1',
                'serial_nos' => 'present|array|max:1000', 'serial_nos.*' => 'required|string|max:120|distinct', 'remark' => 'nullable|string|max:500'],
            'material_return.reject' => ['expected_version' => 'required|integer|min:1', 'remark' => 'required|string|max:500'],
            'material_return.cancel' => ['expected_version' => 'required|integer|min:1'],
            'material_return.dispose' => ['expected_version' => 'required|integer|min:1', 'lines' => 'required|array|min:1|max:200', 'lines.*.line_id' => 'required|integer|min:1',
                'lines.*.action' => 'required|in:release,scrap,supplier_return', 'lines.*.qty' => 'nullable|numeric|min:0|max:1000000',
                'remark' => 'nullable|string|max:500'],
            'stocktake.counts' => InventoryStocktakeService::COUNT_RULES,
            default => throw new WorkOrderDomainException('warehouse_action_invalid', '不支持的仓库操作。', 422),
        };
        return Validator::make($payload, $rules + $confirmationRemark)->validate();
    }

    public function execute(string $action, int $id, array $payload, string $commandId, object $user, array $permissions, bool $super): mixed
    {
        $actor = (int) ($user->legacy_id ?? $user->id ?? 0); $name = (string) ($user->nickname ?? $user->username ?? $user->name ?? '');
        $command = $payload + ['client_command_id' => $commandId];
        if ($action === 'cutting_batch.request') {
            $batch = MfgCuttingBatch::findOrFail($id);
            $service = app($batch->batch_scope === 'shared' ? SharedCuttingService::class : CuttingService::class);
            return $service->settle($id, $command, $user, in_array('inventory.amount.view', $permissions, true));
        }
        if ($action === 'cutting_run.finish') return app(CuttingRunService::class)->finish($id, $command, $user);
        if (str_starts_with($action, 'cutting_remnant.')) {
            $service = app(CuttingRemnantReceiptService::class);
            $receipt = match ($action) {
                'cutting_remnant.confirm' => $service->confirm($id, $command, $user),
                'cutting_remnant.reject' => $service->reject($id, $command, $user),
                'cutting_remnant.cancel' => $service->cancel($id, $command, $user),
                'cutting_remnant.scrap' => $service->scrap($id, $command, $user),
            };
            return $service->present($receipt, in_array('inventory.amount.view', $permissions, true), $user);
        }
        if (str_starts_with($action, 'inventory_transfer.')) {
            $service = app(InventoryTransferService::class);
            if ($action === 'inventory_transfer.delete') return $service->deleteDraft($id, $command, $user);
            if ($action === 'inventory_transfer.create' && (int) $payload['source_warehouse_id'] !== $id)
                $this->fail('warehouse_target_invalid', '调出仓库与命令目标不一致。', 422);
            $transfer = match ($action) {
                'inventory_transfer.create' => $service->save($command, null, $user),
                'inventory_transfer.update' => $service->save($command, $id, $user),
                'inventory_transfer.post' => $service->post($id, $command, $user),
                'inventory_transfer.confirm' => $service->confirm($id, $command, $user),
                'inventory_transfer.exception' => $service->recordException($id, $command, $user),
                'inventory_transfer.cancel' => $service->cancel($id, $command, $user),
            };
            return $service->present($transfer);
        }
        if (str_starts_with($action, 'material_physical_transfer.')) {
            $service = app(PhysicalInventoryApplicationService::class);
            return match ($action) {
                'material_physical_transfer.dispatch' => $service->transfer($id, $command, $user),
                'material_physical_transfer.confirm' => $service->confirmTransfer($id, $command, $user),
                'material_physical_transfer.exception' => $service->recordTransferException($id, $command, $user),
            };
        }
        if (str_starts_with($action, 'mfg_task.')) {
            $tasks = app(TaskService::class);
            return $action === 'mfg_task.pass' ? $tasks->pass($id, $payload['unit_ids'], $user, TaskService::lane($payload['lane'] ?? null), $payload)
                : $tasks->prepare($id, $payload['unit_ids'], $payload['remark'] ?? null, $user,
                    isset($payload['cutting_run_id']) ? (int) $payload['cutting_run_id'] : null, $payload);
        }
        if (str_starts_with($action, 'mfg_receipt.')) {
            $receipts = app(ReceiptService::class);
            $receipt = match ($action) {
                'mfg_receipt.request' => $receipts->receive($id, $payload['unit_ids'], (int) $payload['warehouse_id'], (int) $payload['location_id'], $payload['remark'] ?? null, $user),
                'mfg_receipt.confirm' => $receipts->confirm($id, $payload, $user),
                'mfg_receipt.reject' => $receipts->reject($id, $payload, $user),
                'mfg_receipt.cancel' => $receipts->cancel($id, $payload, $user),
            };
            return $receipts->present($receipt, in_array('inventory.amount.view', $permissions, true), $user);
        }
        if (str_starts_with($action, 'material_delivery.')) {
            $deliveries = app(MaterialDeliveryService::class);
            $delivery = match ($action) {
                'material_delivery.prepare' => $deliveries->prepare((int) $payload['work_order_id'], $id,
                    isset($payload['units']) ? (int) $payload['units'] : null, $payload['remark'] ?? null, $user,
                    $payload['expected_source_revision'] ?? null, $payload['selections'] ?? [], $payload['unit_ids'] ?? null),
                'material_delivery.dispatch' => $deliveries->dispatch($id, $user, $payload['expected_source_revision'] ?? null),
                'material_delivery.repick' => $deliveries->repick($id, $user),
                'material_delivery.cancel' => $deliveries->cancel($id, $payload['remark'] ?? null, $user),
                'material_delivery.redeliver' => $deliveries->redeliver($id, $payload['remark'] ?? null, $user),
                'material_delivery.recall' => $deliveries->recall($id, $payload['reason'], $user, $payload),
            };
            if ($action === 'material_delivery.prepare' && ! empty($payload['dispatch']))
                $delivery = $deliveries->dispatch((int) $delivery->id, $user, $payload['expected_source_revision'] ?? null);
            return $deliveries->row($delivery);
        }
        if (str_starts_with($action, 'material_return.')) {
            $returns = app(MaterialReturnService::class);
            $return = match ($action) {
                'material_return.request' => $returns->request((int) $payload['work_order_id'], $id, $payload, $user),
                'material_return.plan_review' => $returns->reviewPlan($id, $payload, $user, $super || in_array('production.scrap.approve', $permissions, true)),
                'material_return.approve' => $returns->approve($id, $payload, $user),
                'material_return.reject' => $returns->reject($id, $payload, $user),
                'material_return.dispose' => $returns->dispose($id, $payload, $user),
                'material_return.cancel' => $returns->cancel($id, $payload, $user),
            };
            return $returns->row($return, true);
        }
        if (str_starts_with($action, 'stocktake.')) {
            $stocktakes = app(InventoryStocktakeService::class);
            $stocktake = $action === 'stocktake.counts' ? $stocktakes->saveCounts($id, $payload['lines'], $actor, (int) $payload['expected_version'])
                : $stocktakes->complete($id, $actor);
            return $stocktake->only(['id', 'stocktake_no', 'stocktake_status', 'business_version', 'completed_at']);
        }
        return match ($action) {
            'purchase.post' => app(PurchaseInventoryPosting::class)->postPurchaseReceipt($id, $actor ?: null),
            'purchase.allocate' => ['receipt_id' => app(PurchaseReceiptPostingRepairApplicationService::class)->repairSelectedLines($id, $payload['items'], $name)->id],
            'sales_return.receive' => app(SalesReturnApplicationService::class)->receive($payload + ['sales_return_id' => $id], $actor, $name),
            'sales_return.post' => app(SalesReturnApplicationService::class)->postReceipt($id, $actor, $name),
            'sales_shipment.post' => app(SalesShipmentApplicationService::class)->postOutbound(SalesShipment::findOrFail($id), $name, $command, $user),
            'sales_shipment.dispatch' => app(SalesShipmentApplicationService::class)->dispatch(SalesShipment::findOrFail($id), $name, $command, $user),
            'purchase_return.post' => app(PurchaseReturnApplicationService::class)->post($id, $actor, $name),
        };
    }

    private function fail(string $code, string $message, int $status): never { throw new WorkOrderDomainException($code, $message, $status); }
}
