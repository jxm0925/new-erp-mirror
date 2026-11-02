<?php

namespace App\Services\Erp;

use App\Services\Erp\Inventory\PurchaseInventoryPosting;
use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{PurchaseReceipt, PurchaseReturn, SalesOrder, SalesReturn, SalesReturnReceipt, SalesShipment};
use App\Models\Erp\{InventoryStocktake, MfgMaterialDelivery, MfgMaterialReturn, MfgWorkOrderOperation};
use App\Services\Erp\Inventory\InventoryStocktakeService;
use App\Services\Erp\Manufacturing\{MaterialDeliveryService, MaterialReturnService};
use Illuminate\Support\Facades\Validator;

/** A closed registry of warehouse use cases, not an arbitrary URL or service dispatcher. */
final class WarehouseActionService
{
    public const PERMISSIONS = [
        'purchase.post' => 'inventory.post.execute', 'purchase.allocate' => 'inventory.post.repair',
        'sales_return.receive' => 'sales_return.receive', 'sales_return.post' => 'sales_return.post',
        'sales_shipment.post' => 'sales_order.shipment.post', 'sales_shipment.dispatch' => 'sales_order.shipment.dispatch',
        'purchase_return.post' => 'purchase_return.post',
        'material_delivery.prepare' => 'production.material_delivery.manage',
        'material_delivery.dispatch' => 'production.material_delivery.manage', 'material_delivery.repick' => 'production.material_delivery.manage',
        'material_delivery.cancel' => 'production.material_delivery.manage', 'material_delivery.redeliver' => 'production.material_delivery.manage',
        'material_delivery.recall' => 'production.material_delivery.manage',
        'material_return.approve' => 'production.material_return.approve', 'material_return.reject' => 'production.material_return.approve',
        'material_return.dispose' => 'production.material_return.approve',
        'material_return.cancel' => ['production.material_return.request', 'production.material_return.approve'],
        'stocktake.counts' => 'inventory.stocktake.create', 'stocktake.complete' => 'inventory.stocktake.complete',
    ];

    public function authorize(string $action, int $id, array $payload, object $user, array $permissions, bool $super): void
    {
        $permission = self::PERMISSIONS[$action] ?? null;
        if (! $permission || ! array_intersect((array) $permission, $permissions)) $this->fail('permission_denied', '没有执行当前仓库操作的权限。', 403);
        if ($id < 1) $this->fail('warehouse_target_invalid', '业务单据不存在。', 404);
        if (str_starts_with($action, 'purchase.')) { PurchaseReceipt::findOrFail($id); return; }
        if ($action === 'purchase_return.post') { PurchaseReturn::findOrFail($id); return; }
        if (str_starts_with($action, 'material_delivery.')) {
            if ($action === 'material_delivery.prepare') MfgWorkOrderOperation::query()->where('work_order_id', (int) ($payload['work_order_id'] ?? 0))->findOrFail($id);
            else MfgMaterialDelivery::findOrFail($id);
            return;
        }
        if (str_starts_with($action, 'material_return.')) {
            $return = MfgMaterialReturn::findOrFail($id);
            if (! in_array('production.material_return.approve', $permissions, true)
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
        $rules = match ($action) {
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
                'remark' => 'nullable|string|max:500', 'dispatch' => 'nullable|boolean', 'expected_source_revision' => 'nullable|string|regex:/^[a-f0-9]{64}$/'],
            'material_delivery.dispatch' => ['expected_source_revision' => 'nullable|string|regex:/^[a-f0-9]{64}$/'],
            'material_delivery.repick', 'material_return.cancel', 'stocktake.complete' => [],
            'material_delivery.cancel', 'material_delivery.redeliver' => ['remark' => 'nullable|string|max:500'],
            'material_delivery.recall' => ['reason' => 'required|string|max:300'],
            'material_return.approve' => ['location_id' => 'nullable|integer|min:1', 'remark' => 'nullable|string|max:500'],
            'material_return.reject' => ['remark' => 'required|string|max:500'],
            'material_return.dispose' => ['lines' => 'required|array|min:1|max:200', 'lines.*.line_id' => 'required|integer|min:1',
                'lines.*.action' => 'required|in:release,scrap,supplier_return', 'lines.*.qty' => 'nullable|numeric|min:0|max:1000000',
                'remark' => 'nullable|string|max:500'],
            'stocktake.counts' => InventoryStocktakeService::COUNT_RULES,
            default => throw new WorkOrderDomainException('warehouse_action_invalid', '不支持的仓库操作。', 422),
        };
        return Validator::make($payload, $rules)->validate();
    }

    public function execute(string $action, int $id, array $payload, string $commandId, object $user, array $permissions, bool $super): mixed
    {
        $actor = (int) ($user->legacy_id ?? $user->id ?? 0); $name = (string) ($user->nickname ?? $user->username ?? $user->name ?? '');
        $command = $payload + ['client_command_id' => $commandId];
        if (str_starts_with($action, 'material_delivery.')) {
            $deliveries = app(MaterialDeliveryService::class);
            $delivery = match ($action) {
                'material_delivery.prepare' => $deliveries->prepare((int) $payload['work_order_id'], $id,
                    isset($payload['units']) ? (int) $payload['units'] : null, $payload['remark'] ?? null, $user, $payload['expected_source_revision'] ?? null),
                'material_delivery.dispatch' => $deliveries->dispatch($id, $user, $payload['expected_source_revision'] ?? null),
                'material_delivery.repick' => $deliveries->repick($id, $user),
                'material_delivery.cancel' => $deliveries->cancel($id, $payload['remark'] ?? null, $user),
                'material_delivery.redeliver' => $deliveries->redeliver($id, $payload['remark'] ?? null, $user),
                'material_delivery.recall' => $deliveries->recall($id, $payload['reason'], $user),
            };
            if ($action === 'material_delivery.prepare' && ! empty($payload['dispatch']))
                $delivery = $deliveries->dispatch((int) $delivery->id, $user, $payload['expected_source_revision'] ?? null);
            return $deliveries->row($delivery);
        }
        if (str_starts_with($action, 'material_return.')) {
            $returns = app(MaterialReturnService::class);
            $return = match ($action) {
                'material_return.approve' => $returns->approve($id, isset($payload['location_id']) ? (int) $payload['location_id'] : null, $payload['remark'] ?? null, $user),
                'material_return.reject' => $returns->reject($id, $payload['remark'], $user),
                'material_return.dispose' => $returns->dispose($id, $payload['lines'], $payload['remark'] ?? null, $user),
                'material_return.cancel' => $returns->cancel($id, $user, in_array('production.material_return.approve', $permissions, true)),
            };
            return $returns->row($return, in_array('inventory.amount.view', $permissions, true));
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
