<?php

namespace App\Services\Erp;

use App\Models\Erp\Item;
use Illuminate\Support\Facades\DB;

/**
 * Owns the single-screen Item + material-policy write boundary.
 * Both records are saved in one transaction so an Item never exists with a
 * partially saved configuration from the integrated maintenance screen.
 */
class ItemIntegratedFormApplicationService
{
    public function __construct(
        private readonly MasterDataApplicationService $masterData,
        private readonly MaterialPolicyApplicationService $materialPolicy,
    ) {
    }

    public function save(?Item $item, array $itemPayload, array $policyPayload, bool $activate, ?int $operatorLegacyId): Item
    {
        return DB::transaction(function () use ($item, $itemPayload, $policyPayload, $activate, $operatorLegacyId) {
            $itemPayload['is_stock_item'] = (bool) $policyPayload['is_stock_managed'];
            $itemPayload['serial_tracking_mode'] = $policyPayload['serial_tracking_mode'];
            $itemPayload['is_serial_managed'] = $policyPayload['serial_tracking_mode'] !== 'none';
            $itemPayload['production_execution_mode'] = $policyPayload['production_execution_mode'] ?? 'unit';
            $itemPayload['serial_generation_stage'] = $policyPayload['serial_generation_stage'] ?? 'before_finished_goods_posting';
            $itemPayload['serial_generation_routing_operation_id'] = $policyPayload['serial_generation_routing_operation_id'] ?? null;
            if ($policyPayload['serial_tracking_mode'] === 'none') $itemPayload['serial_number_prefix'] = null;
            if ($activate) $itemPayload['status'] = 'enabled';

            $saved = $item
                ? $this->masterData->update('items', $item, $itemPayload, $operatorLegacyId)
                : $this->masterData->create('items', Item::class, $itemPayload, $operatorLegacyId);

            if ($activate) {
                $this->materialPolicy->activate($saved, $policyPayload, $operatorLegacyId);
            } else {
                $this->materialPolicy->saveDraft($saved, $policyPayload, $operatorLegacyId);
            }

            return $saved->fresh([
                'category', 'unit.standardUnit', 'activeMaterialPolicy',
                'materialPolicies' => fn ($query) => $query->latest('version_no')->limit(5),
            ]);
        });
    }
}
