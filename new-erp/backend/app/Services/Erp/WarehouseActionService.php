<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{PurchaseReceipt, PurchaseReturn, SalesOrder, SalesReturn, SalesReturnReceipt, SalesShipment};
use Illuminate\Support\Facades\Validator;

/** A closed registry of warehouse use cases, not an arbitrary URL or service dispatcher. */
final class WarehouseActionService
{
    public const PERMISSIONS = [
        'purchase.post' => 'inventory.post.execute', 'purchase.allocate' => 'inventory.post.repair',
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
        $this->fail('warehouse_action_invalid', '不支持的仓库操作。', 422);
    }

    public function validate(string $action, array $payload): array
    {
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
        $actor = (int) ($user->legacy_id ?? $user->id ?? 0); $name = (string) ($user->nickname ?? $user->username ?? $user->name ?? '');
        $command = $payload + ['client_command_id' => $commandId];
        return match ($action) {
            'purchase.post' => app(InventoryService::class)->postPurchaseReceipt($id, $actor ?: null),
            'purchase.allocate' => ['receipt_id' => app(PurchaseReceiptPostingRepairApplicationService::class)->repairSelectedLines($id, $payload['items'], $name)->id],
            'sales_return.receive' => app(SalesReturnApplicationService::class)->receive($payload + ['sales_return_id' => $id], $actor, $name),
            'sales_return.post' => app(SalesReturnApplicationService::class)->postReceipt($id, $actor, $name),
            'sales_shipment.post' => app(SalesShipmentApplicationService::class)->postOutbound(SalesShipment::findOrFail($id), $name),
            'sales_shipment.dispatch' => app(SalesShipmentApplicationService::class)->dispatch(SalesShipment::findOrFail($id), $name),
            'purchase_return.post' => app(PurchaseReturnApplicationService::class)->post($id, $actor, $name),
        };
    }

    private function fail(string $code, string $message, int $status): never { throw new WorkOrderDomainException($code, $message, $status); }
}
