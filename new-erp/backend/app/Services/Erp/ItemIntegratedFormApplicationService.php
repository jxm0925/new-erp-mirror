<?php

namespace App\Services\Erp;

use App\Models\Erp\Item;
use App\Models\Erp\Location;
use App\Models\Erp\Warehouse;
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

    public function save(?Item $item, array $itemPayload, array $policyPayload, bool $activate, ?int $operatorLegacyId, ?string $managementContext = null): Item
    {
        return DB::transaction(function () use ($item, $itemPayload, $policyPayload, $activate, $operatorLegacyId, $managementContext) {
            $locationIds = $itemPayload['storage_location_ids'] ?? null;
            $expectedIds = $itemPayload['expected_storage_location_ids'] ?? null;
            unset($itemPayload['storage_location_ids'], $itemPayload['expected_storage_location_ids']);
            $previousScope = $item?->managementScope();
            $itemPayload['is_stock_item'] = (bool) $policyPayload['is_stock_managed'];
            $itemPayload['serial_tracking_mode'] = $policyPayload['serial_tracking_mode'];
            $itemPayload['is_serial_managed'] = $policyPayload['serial_tracking_mode'] !== 'none';
            $itemPayload['production_execution_mode'] = $policyPayload['production_execution_mode'] ?? 'unit';
            // 生成时机已停用：序列号在工单下达或到货时生成。
            $itemPayload['serial_generation_stage'] = 'before_finished_goods_posting';
            $itemPayload['serial_generation_routing_operation_id'] = null;
            if ($policyPayload['serial_tracking_mode'] === 'none') $itemPayload['serial_number_prefix'] = null;
            if ($activate) $itemPayload['status'] = 'enabled';

            $saved = $item
                ? $this->masterData->update('items', $item, $itemPayload, $operatorLegacyId, $managementContext)
                : $this->masterData->create('items', Item::class, $itemPayload, $operatorLegacyId);

            if ($locationIds !== null || ($item && $previousScope !== $saved->managementScope())) {
                $this->syncStorageLocations($saved, $locationIds, $expectedIds);
            }

            if ($activate) {
                $this->materialPolicy->activate($saved, $policyPayload, $operatorLegacyId);
            } else {
                $this->materialPolicy->saveDraft($saved, $policyPayload, $operatorLegacyId);
            }

            $result = $saved->fresh([
                'category', 'unit.standardUnit', 'activeMaterialPolicy', 'storageLocations.warehouse:id,warehouse_code,warehouse_name',
                'materialPolicies' => fn ($query) => $query->latest('version_no')->limit(5),
            ]);
            return app(ItemManagementScopeService::class)->exposeCategoryScope($result);
        }, 5);
    }

    private function syncStorageLocations(Item $item, ?array $requested, ?array $expected): void
    {
        $current = DB::table('erp_item_storage_locations')->where('item_id', $item->id)
            ->orderBy('location_id')->lockForUpdate()->pluck('location_id')->map(fn ($id) => (int) $id)->all();
        $wanted = $requested === null ? $current : array_map('intval', $requested);
        if ($expected !== null) {
            $expected = array_map('intval', $expected);
            sort($expected, SORT_NUMERIC);
            abort_if($expected !== $current, 409, '存放库位已被其他人修改，请刷新后重新核对。');
        }
        $affected = array_values(array_unique(array_merge($current, $wanted)));
        if (!$affected) return;
        $locations = Location::whereKey($affected)->orderBy('id')->sharedLock()->get()->keyBy('id');
        // 与盘点完成的仓库排他锁串行，确保新绑定要么进入本次盘点，要么在结单后生效。
        $warehouses = Warehouse::whereKey($locations->pluck('warehouse_id')->unique()->values())->orderBy('id')->sharedLock()->get()->keyBy('id');
        foreach ($wanted as $id) {
            $location = $locations->get($id);
            $warehouse = $location ? $warehouses->get($location->warehouse_id) : null;
            abort_unless($warehouse && $warehouse->managementScope() === $item->managementScope(), 422, '请为物料选择同一管理范围内的存放库位。');
            if (!in_array($id, $current, true)) {
                abort_unless(in_array($location->status, ['enabled', 'active'], true) && in_array($warehouse->status, ['enabled', 'active'], true),
                    422, '新增存放位置必须是已启用的仓库和库位。');
            }
        }
        $item->storageLocations()->sync($wanted);
    }
}
