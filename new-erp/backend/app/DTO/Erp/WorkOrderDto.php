<?php

namespace App\DTO\Erp;

use App\Models\Erp\WorkOrder;
use App\Services\Erp\ErpUserProjectionService;

final class WorkOrderDto
{
    public static function fromModel(WorkOrder $workOrder, array $permissions = [], bool $superAdmin = false): array
    {
        $demand = $workOrder->relationLoaded('demand') ? $workOrder->demand : null;
        $line = $demand?->relationLoaded('line') ? $demand->line : null;
        $order = $demand?->relationLoaded('order') ? $demand->order : null;
        $log = $workOrder->relationLoaded('statusLogs') ? $workOrder->statusLogs->sortByDesc('occurred_at')->first() : null;
        $gateChecks = $workOrder->relationLoaded('releaseGateChecks')
            ? $workOrder->releaseGateChecks->sortByDesc('work_order_version')
            : collect();
        $latestGateVersion = $gateChecks->max('work_order_version');
        $latestGateChecks = $latestGateVersion === null
            ? collect()
            : $gateChecks->where('work_order_version', $latestGateVersion)->values();
        $materialCount = array_key_exists('material_requirements_count', $workOrder->getAttributes())
            ? (int) $workOrder->getAttribute('material_requirements_count')
            : ($workOrder->relationLoaded('materialRequirements') ? $workOrder->materialRequirements->count() : null);

        return [
            'id' => (int) $workOrder->id,
            'work_order_no' => $workOrder->work_order_no,
            'production_demand_id' => $workOrder->production_demand_id ? (int) $workOrder->production_demand_id : null,
            'source_type' => $workOrder->source_type ?: 'sales_order',
            'source_type_label' => self::sourceTypeLabel($workOrder->source_type ?: 'sales_order'),
            'source' => [
                'demand_id' => $demand?->id ? (int) $demand->id : null,
                'demand_no' => $demand?->requirement_no ?? $demand?->demand_no ?? null,
                'sales_order_no' => $order?->sales_order_no ?? null,
                'customer' => self::snapshotLabel($order?->customer_snapshot),
                'type' => $workOrder->source_type ?: 'sales_order',
                'type_label' => self::sourceTypeLabel($workOrder->source_type ?: 'sales_order'),
                'id' => $workOrder->source_id ? (int) $workOrder->source_id : null,
                'no' => $workOrder->source_no_snapshot ?: ($order?->sales_order_no ?? null),
                'title' => $workOrder->source_title_snapshot,
            ],
            'product' => [
                'name' => self::snapshotLabel($line?->product_snapshot) ?: ($line?->product_name ?? null),
                'sku' => self::snapshotLabel($line?->sku_snapshot) ?: ($line?->sku_name ?? null),
                'specification' => data_get($line?->item_snapshot, 'spec') ?: ($line?->item?->spec ?? null),
                'item_id' => $workOrder->output_item_id ? (int) $workOrder->output_item_id : ($demand?->item_id ? (int) $demand->item_id : null),
                'item_code' => $workOrder->outputItem?->item_code,
                'item_name' => $workOrder->outputItem?->item_name ?: ($line?->item_name ?? null),
            ],
            'routing' => [
                'id' => $workOrder->production_routing_id ? (int) $workOrder->production_routing_id : null,
                'no' => $workOrder->routing?->routing_no,
                'name' => $workOrder->routing?->routing_name,
                'version' => $workOrder->routing_version_snapshot,
                'snapshot' => $workOrder->routing_snapshot,
                'target_operation_id' => $workOrder->target_operation_id ? (int) $workOrder->target_operation_id : null,
                'target_operation_name' => $workOrder->targetOperation?->operation_name,
                'target_routing_operation_id' => $workOrder->target_routing_operation_id ? (int) $workOrder->target_routing_operation_id : null,
                'target_routing_operation' => $workOrder->targetRoutingOperation ? [
                    'id' => (int) $workOrder->targetRoutingOperation->id,
                    'sequence' => (int) $workOrder->targetRoutingOperation->sequence,
                    'operation_id' => (int) $workOrder->targetRoutingOperation->operation_id,
                    'operation_code' => $workOrder->targetRoutingOperation->operation?->operation_no,
                    'operation_name' => $workOrder->targetRoutingOperation->operation?->operation_name,
                ] : collect($workOrder->routing_snapshot['operations'] ?? [])->firstWhere('routing_operation_id', (int) $workOrder->target_routing_operation_id),
            ],
            'quantity' => [
                'target_qty' => (float) $workOrder->target_qty,
                'target_base_qty' => (float) $workOrder->target_base_qty,
                'unit_name' => $workOrder->target_unit_name_snapshot,
                'base_unit_name' => $workOrder->base_unit_name_snapshot,
                'demand_remaining_qty' => $demand ? (float) ($demand->remaining_qty ?? 0) : null,
            ],
            'plan' => [
                'planned_date' => optional($workOrder->planned_date)->format('Y-m-d'),
                'production_batch' => $workOrder->production_batch,
                'production_location_name' => $workOrder->production_location_name,
            ],
            'responsible_user' => $workOrder->getAttribute('responsible_user_projection')
                ?: app(ErpUserProjectionService::class)->one($workOrder->responsible_user_legacy_id),
            'target_qty' => (float) $workOrder->target_qty,
            'planned_date' => optional($workOrder->planned_date)->format('Y-m-d'),
            'production_batch' => $workOrder->production_batch,
            'production_location_name' => $workOrder->production_location_name,
            'status' => $workOrder->status,
            'business_version' => (int) $workOrder->business_version,
            'release' => [
                'gate_status' => $workOrder->release_gate_status,
                'gate_checked_at' => optional($workOrder->release_gate_checked_at)->toISOString(),
                'released_at' => optional($workOrder->released_at)->toISOString(),
                'released_by_legacy_id' => $workOrder->released_by_legacy_id ? (int) $workOrder->released_by_legacy_id : null,
                'reason' => $workOrder->release_reason,
                'bom' => is_array($workOrder->bom_snapshot) ? $workOrder->bom_snapshot : null,
                'material_requirement_count' => $materialCount,
                'gate_summary' => $latestGateChecks->isEmpty() ? null : [
                    'evaluated_work_order_version' => (int) $latestGateVersion,
                    'total' => $latestGateChecks->count(),
                    'passed' => $latestGateChecks->where('status', 'passed')->count(),
                    'blockers' => $latestGateChecks->where('status', '!=', 'passed')->map(fn ($check): array => [
                        'key' => $check->check_key,
                        'reason_code' => $check->reason_code,
                        'message' => $check->message,
                    ])->values()->all(),
                ],
            ],
            'status_log_summary' => $log ? [
                'before_status' => $log->before_status,
                'after_status' => $log->after_status,
                'reason' => $log->reason,
                'occurred_at' => optional($log->occurred_at)->toISOString(),
                'operator_name' => $log->operator_name,
            ] : null,
            'field_audit_summary' => $workOrder->field_audit_summary ?? null,
            'actions' => [
                'view' => self::allowed($permissions, 'production.work_order.view'),
                'edit' => $workOrder->status === 'DRAFT' && self::allowed($permissions, 'production.work_order.edit'),
                'submit' => $workOrder->status === 'DRAFT' && self::allowed($permissions, 'production.work_order.submit'),
                'return_draft' => $workOrder->status === 'WAIT_RELEASE' && self::allowed($permissions, 'production.work_order.edit'),
                'rematch_routing' => ($workOrder->source_type ?: 'sales_order') === 'sales_order'
                    && in_array((string) $workOrder->status, ['DRAFT', 'WAIT_RELEASE'], true)
                    && ! $workOrder->production_routing_id
                    && empty($workOrder->routing_snapshot)
                    && self::allowed($permissions, 'production.work_order.edit'),
                'publish' => $workOrder->status === 'WAIT_RELEASE'
                    && $workOrder->release_gate_status === 'passed'
                    && self::allowed($permissions, 'production.work_order.publish'),
                'view_release_gate' => self::allowed($permissions, 'production.work_order.gate.view'),
                'view_materials' => self::allowed($permissions, 'production.material.view'),
                'cancel' => in_array((string) $workOrder->status, ['DRAFT', 'WAIT_RELEASE'], true) && self::allowed($permissions, 'production.work_order.cancel'),
            ],
        ];
    }

    private static function allowed(array $permissions, string $permission): bool
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

    private static function sourceTypeLabel(string $type): string
    {
        return ['sales_order' => '销售订单', 'production_plan' => '生产计划', 'trial' => '试制', 'stock_prebuild' => '备货'][$type] ?? '其他';
    }
}
