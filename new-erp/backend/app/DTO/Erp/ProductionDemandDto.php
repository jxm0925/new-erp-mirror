<?php

namespace App\DTO\Erp;

use App\Models\Erp\ProductionDemand;
use App\Services\Erp\ErpUserProjectionService;

final class ProductionDemandDto
{
    public static function fromModel(ProductionDemand $demand, array $permissions = [], bool $superAdmin = false): array
    {
        $line = $demand->relationLoaded('line') ? $demand->line : null;
        $order = $demand->relationLoaded('order') ? $demand->order : null;
        $workOrders = $demand->relationLoaded('workOrders') ? $demand->workOrders : collect();
        $allocated = (float) ($demand->allocated_qty ?? 0);
        $productionQty = (float) ($demand->production_qty ?? 0);
        $consumed = (float) ($demand->consumed_qty ?? 0);
        $closed = (float) ($demand->closed_qty ?? 0);
        $remaining = (float) ($demand->remaining_qty ?? max(0, $productionQty - $consumed - $closed - $allocated));
        $createGate = self::createWorkOrderGate($demand, $remaining, $permissions, $superAdmin);

        return [
            'id' => (int) $demand->id,
            'demand_no' => $demand->requirement_no ?? $demand->demand_no ?? null,
            'sales_order' => [
                'id' => $order?->id ? (int) $order->id : null,
                'order_no' => $order?->sales_order_no ?? null,
                'order_date' => optional($order?->order_date)->format('Y-m-d'),
                'customer_name' => $order?->customer_name ?? self::snapshotLabel($order?->customer_snapshot),
                'contact_name' => $order?->contact_name ?? null,
                'contact_phone' => $order?->contact_phone ?? null,
                'total_amount' => $order?->total_amount === null ? null : (float) $order->total_amount,
                'currency' => $order?->currency ?? null,
                'order_status' => $order?->order_status ?? null,
                'production_confirm_status' => $order?->production_confirm_status ?? null,
            ],
            'customer' => self::snapshotLabel($order?->customer_snapshot) ?: ($order?->customer_name ?? null),
            'sales_order_line' => [
                'line_no' => $line?->line_no === null ? null : (int) $line->line_no,
            ],
            'product' => [
                'name' => self::snapshotLabel($line?->product_snapshot) ?: ($line?->product_name ?? null),
                'sku' => self::snapshotLabel($line?->sku_snapshot) ?: ($line?->sku_name ?? null),
                'item_name' => $line?->item_name ?: self::snapshotLabel($line?->item_snapshot) ?: $line?->item?->item_name,
                'specification' => data_get($line?->item_snapshot, 'spec') ?: ($line?->item?->spec ?? null),
            ],
            'quantity' => [
                'production_qty' => $productionQty,
                'allocated_qty' => $allocated,
                'consumed_qty' => $consumed,
                'closed_qty' => $closed,
                'remaining_qty' => $remaining,
                'unit_name' => $line?->unit_name_snapshot ?? $demand->base_unit_name_snapshot ?? null,
            ],
            'status' => (string) ($demand->requirement_status ?? 'unknown'),
            'readiness' => [
                'is_active' => (bool) $demand->is_active,
                'has_work_order_allocation' => (bool) $demand->is_ready_for_work_order,
                'bom_match_status' => $demand->bom_match_status,
                'bom_version' => $demand->bom_version,
            ],
            'create_work_order_gate' => $createGate,
            'business_version' => (int) ($demand->business_version ?: 1),
            'required_delivery_date' => optional($demand->required_delivery_date)->format('Y-m-d'),
            'sales_owner' => $demand->getAttribute('sales_owner_projection'),
            'production_responsible_user' => $demand->getAttribute('production_responsible_projection'),
            'work_order_count' => (int) ($demand->work_orders_count ?? $workOrders->count()),
            'work_orders_pagination' => $demand->work_orders_pagination ?? null,
            'work_orders' => $workOrders->map(function ($workOrder) use ($permissions, $superAdmin): array {
                return [
                    'id' => (int) $workOrder->id,
                    'work_order_no' => $workOrder->work_order_no,
                    'target_qty' => (float) $workOrder->target_qty,
                    'unit_name' => $workOrder->target_unit_name_snapshot,
                    'planned_date' => optional($workOrder->planned_date)->format('Y-m-d'),
                    'production_batch' => $workOrder->production_batch,
                    'production_location_name' => $workOrder->production_location_name,
                    'responsible_user' => $workOrder->getAttribute('responsible_user_projection')
                        ?: app(ErpUserProjectionService::class)->one($workOrder->responsible_user_legacy_id),
                    'status' => $workOrder->status,
                    'business_version' => (int) $workOrder->business_version,
                    'actions' => self::workOrderActions($workOrder->status, $permissions, $superAdmin),
                ];
            })->values()->all(),
            'actions' => [
                'view' => self::allowed($permissions, 'production.demand.view', $superAdmin),
                'create_work_order' => $createGate['allowed'],
            ],
        ];
    }

    private static function createWorkOrderGate(ProductionDemand $demand, float $remaining, array $permissions, bool $superAdmin): array
    {
        $blockers = [];
        if (! $demand->is_active) $blockers[] = ['code' => 'demand_inactive', 'message' => '生产需求已关闭。'];
        if (! in_array((string) $demand->requirement_status, ['ready', 'confirmed'], true)) {
            $blockers[] = ['code' => 'invalid_status', 'message' => '当前需求状态不允许创建工单。'];
        }
        if ($remaining <= 0) $blockers[] = ['code' => 'no_remaining_quantity', 'message' => '没有剩余可拆数量。'];
        if ($demand->bom_match_status !== null && $demand->bom_match_status !== 'matched') {
            $blockers[] = ['code' => 'bom_not_matched', 'message' => 'BOM 尚未满足创建门禁。'];
        }
        if (! self::allowed($permissions, 'production.work_order.create', $superAdmin)) {
            $blockers[] = ['code' => 'permission_denied', 'message' => '当前用户没有创建工单权限。'];
        }

        return [
            'allowed' => $blockers === [],
            'remaining_qty' => $remaining,
            'demand_version' => (int) ($demand->business_version ?: 1),
            'blockers' => $blockers,
        ];
    }

    private static function workOrderActions(string $status, array $permissions, bool $superAdmin): array
    {
        return [
            'view' => self::allowed($permissions, 'production.work_order.view', $superAdmin),
            'edit' => $status === 'DRAFT' && self::allowed($permissions, 'production.work_order.edit', $superAdmin),
            'submit' => $status === 'DRAFT' && self::allowed($permissions, 'production.work_order.submit', $superAdmin),
            'cancel' => in_array($status, ['DRAFT', 'WAIT_RELEASE'], true) && self::allowed($permissions, 'production.work_order.cancel', $superAdmin),
        ];
    }

    private static function allowed(array $permissions, string $permission, bool $superAdmin): bool
    {
        return in_array($permission, $permissions, true);
    }

    private static function snapshotLabel(mixed $snapshot): ?string
    {
        if (is_string($snapshot)) return $snapshot;
        if (! is_array($snapshot)) return null;
        foreach (['name', 'product_name', 'sku_name', 'code', 'sku_code', 'customer_name', 'short_name'] as $key) {
            if (isset($snapshot[$key]) && is_scalar($snapshot[$key])) return (string) $snapshot[$key];
        }
        return null;
    }
}
