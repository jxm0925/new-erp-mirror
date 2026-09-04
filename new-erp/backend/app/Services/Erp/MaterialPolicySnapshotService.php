<?php

namespace App\Services\Erp;

use App\Models\Erp\Item;
use App\Models\Erp\ItemMaterialPolicy;

class MaterialPolicySnapshotService
{
    /**
     * Creates a durable purchase-line fact. The fallback keeps pre-policy Items
     * usable without re-introducing any name/category based business rule.
     */
    public function fromItem(Item $item): array
    {
        $policy = $item->relationLoaded('activeMaterialPolicy')
            ? $item->activeMaterialPolicy
            : $item->activeMaterialPolicy()->first();

        if ($policy instanceof ItemMaterialPolicy) {
            return [
                'material_policy_id_snapshot' => $policy->id,
                'material_policy_version_snapshot' => $policy->version_no,
                'material_policy_snapshot' => [
                    'source' => 'item_active_policy',
                    'policy_id' => $policy->id,
                    'version_no' => $policy->version_no,
                    'template_code' => $policy->template_code,
                    'is_stock_managed' => (bool) $policy->is_stock_managed,
                    'inventory_management_mode' => $policy->inventory_management_mode,
                    'requires_custodian' => (bool) $policy->requires_custodian,
                    'is_returnable' => (bool) $policy->is_returnable,
                    'requires_capitalization' => (bool) $policy->requires_capitalization,
                    'serial_tracking_mode' => $policy->serial_tracking_mode,
                    'post_purchase_action' => $policy->post_purchase_action,
                    'consumption_confirmation_mode' => $policy->consumption_confirmation_mode,
                    'future_route' => $policy->future_route,
                    'future_bearer_type' => $policy->future_bearer_type,
                    'parameter_snapshot' => $policy->parameter_snapshot ?: [],
                    'effective_at' => optional($policy->effective_at)?->toDateTimeString(),
                ],
            ];
        }

        return [
            'material_policy_id_snapshot' => null,
            'material_policy_version_snapshot' => null,
            'material_policy_snapshot' => [
                'source' => 'legacy_item_default',
                'is_stock_managed' => (bool) $item->is_stock_item,
                'inventory_management_mode' => (bool) $item->is_stock_item ? 'quantity' : 'none',
                'requires_custodian' => false,
                'is_returnable' => false,
                'requires_capitalization' => false,
                'serial_tracking_mode' => $item->serial_tracking_mode ?: ((bool) $item->is_serial_managed ? 'serial_required' : 'none'),
                'post_purchase_action' => (bool) $item->is_stock_item ? 'inventory_receipt' : 'future_route',
                'consumption_confirmation_mode' => 'not_required',
                'future_route' => (bool) $item->is_stock_item ? 'inventory' : 'expense',
                'future_bearer_type' => (bool) $item->is_stock_item ? 'inventory' : 'company',
            ],
        ];
    }

    public function summary(?array $snapshot): array
    {
        $snapshot = $snapshot ?: [];

        return [
            'is_stock_managed' => (bool) ($snapshot['is_stock_managed'] ?? false),
            'post_purchase_action' => $snapshot['post_purchase_action'] ?? 'future_route',
            'future_route' => $snapshot['future_route'] ?? 'expense',
            'requires_custodian' => (bool) ($snapshot['requires_custodian'] ?? false),
            'requires_capitalization' => (bool) ($snapshot['requires_capitalization'] ?? false),
            'serial_tracking_mode' => $snapshot['serial_tracking_mode'] ?? 'none',
        ];
    }
}
