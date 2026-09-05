<?php

namespace App\Services\Erp;

use App\Models\Erp\Item;
use App\Models\Erp\ItemMaterialPolicy;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class MaterialPolicyApplicationService
{
    private const SNAPSHOT_KEYS = [
        'template_code', 'is_stock_managed', 'inventory_management_mode',
        'requires_custodian', 'is_returnable', 'requires_capitalization',
        'serial_tracking_mode', 'production_execution_mode', 'serial_generation_stage',
        'serial_generation_routing_operation_id', 'post_purchase_action',
        'consumption_confirmation_mode', 'future_route', 'future_bearer_type',
    ];

    public function saveDraft(Item $item, array $attributes, ?int $operatorLegacyId): ItemMaterialPolicy
    {
        return DB::transaction(function () use ($item, $attributes, $operatorLegacyId) {
            $lockedItem = Item::query()->lockForUpdate()->findOrFail($item->id);
            $this->assertConsistent($attributes, $lockedItem);

            $draft = ItemMaterialPolicy::query()
                ->where('item_id', $lockedItem->id)->where('status', 'draft')
                ->lockForUpdate()->latest('id')->first();

            $payload = $this->payload($attributes, $operatorLegacyId);
            if ($draft) {
                $draft->update($payload);
                return $draft->fresh();
            }

            return ItemMaterialPolicy::create($payload + [
                'item_id' => $lockedItem->id,
                'version_no' => 0,
                'status' => 'draft',
            ]);
        });
    }

    public function activate(Item $item, array $attributes, ?int $operatorLegacyId): ItemMaterialPolicy
    {
        return DB::transaction(function () use ($item, $attributes, $operatorLegacyId) {
            $lockedItem = Item::query()->lockForUpdate()->findOrFail($item->id);
            $this->assertConsistent($attributes, $lockedItem);

            $current = ItemMaterialPolicy::query()
                ->where('item_id', $lockedItem->id)->where('status', 'active')
                ->lockForUpdate()->latest('effective_at')->first();

            abort_if($current && blank($attributes['change_reason'] ?? null), 422, '已存在生效策略，启用新版本必须填写变更原因。');
            $now = now();
            if ($current) $current->update(['status' => 'historical', 'expired_at' => $now]);

            ItemMaterialPolicy::query()->where('item_id', $lockedItem->id)->where('status', 'draft')->lockForUpdate()
                ->update(['status' => 'historical', 'expired_at' => $now]);

            $policy = ItemMaterialPolicy::create($this->payload($attributes, $operatorLegacyId) + [
                'item_id' => $lockedItem->id,
                'version_no' => $this->nextVersion($lockedItem->id),
                'status' => 'active',
                'enabled_by_legacy_id' => $operatorLegacyId,
                'effective_at' => $now,
            ]);

            $this->syncOperationalItemAttributes($lockedItem, $policy);
            return $policy->fresh();
        });
    }

    public function current(Item $item): array
    {
        return [
            'active' => $item->activeMaterialPolicy()->first(),
            'draft' => $item->materialPolicies()->where('status', 'draft')->first(),
        ];
    }

    private function payload(array $attributes, ?int $operatorLegacyId): array
    {
        $parameters = Arr::only($attributes, self::SNAPSHOT_KEYS);
        return $parameters + [
            'parameter_snapshot' => $parameters,
            'change_reason' => $attributes['change_reason'] ?? null,
            'remark' => $attributes['remark'] ?? null,
            'created_by_legacy_id' => $operatorLegacyId,
        ];
    }

    private function nextVersion(int $itemId): int
    {
        return (int) ItemMaterialPolicy::query()->where('item_id', $itemId)->max('version_no') + 1;
    }

    private function syncOperationalItemAttributes(Item $item, ItemMaterialPolicy $policy): void
    {
        if ($policy->serial_tracking_mode === 'none') {
            $hasSerialHistory = DB::table('erp_inventory_serials')->where('item_id', $item->id)->exists();
            abort_if($hasSerialHistory, 422, '该 Item 已存在单件追溯档案，策略不能改为无需逐件编号。');
        }

        $item->update([
            'is_stock_item' => $policy->is_stock_managed,
            'is_serial_managed' => $policy->serial_tracking_mode !== 'none',
            'serial_tracking_mode' => $policy->serial_tracking_mode,
            'production_execution_mode' => $policy->production_execution_mode ?: 'unit',
            'serial_generation_stage' => $policy->serial_generation_stage ?: 'before_finished_goods_posting',
            'serial_generation_routing_operation_id' => $policy->serial_generation_routing_operation_id,
        ]);
    }

    private function assertConsistent(array &$attributes, Item $item): void
    {
        $stockManaged = (bool) $attributes['is_stock_managed'];
        $route = (string) $attributes['future_route'];
        $action = (string) $attributes['post_purchase_action'];
        $executionMode = (string) ($attributes['production_execution_mode'] ?? 'unit');
        $serialStage = (string) ($attributes['serial_generation_stage'] ?? 'before_finished_goods_posting');

        abort_unless(in_array($executionMode, ['unit', 'quantity'], true), 422, '生产执行模式只能选择逐件生产或按数量生产。');
        abort_unless(in_array($serialStage, ['production_unit_created', 'routing_operation_completed', 'before_finished_goods_posting'], true), 422, '设备编号生成节点无效。');
        if ($serialStage === 'routing_operation_completed') {
            $routingOperationId = (int) ($attributes['serial_generation_routing_operation_id'] ?? 0);
            $belongsToItem = $routingOperationId > 0 && DB::table('erp_production_routing_operations as ro')
                ->join('erp_production_routings as r', 'r.id', '=', 'ro.routing_id')
                ->where('ro.id', $routingOperationId)
                ->where('r.output_item_id', $item->id)
                ->exists();
            abort_unless($belongsToItem, 422, '指定的设备编号生成工序不属于该产出物料的工艺路线。');
        } else {
            $attributes['serial_generation_routing_operation_id'] = null;
        }

        abort_if($stockManaged && !in_array($route, ['inventory', 'expense'], true), 422, '库存管理物资当前只能选择库存或库存后领用费用意图。');
        abort_if(!$stockManaged && $route === 'inventory', 422, '非库存管理物资不能选择库存处理意图。');
        abort_if($stockManaged && !in_array($action, ['inventory_receipt', 'issue_confirmation'], true), 422, '库存管理物资的采购后处理必须进入库存或领用确认。');
        abort_if($route === 'direct_expense' && $stockManaged, 422, '直接非库存处理不能启用库存管理。');
        abort_if($route === 'direct_expense' && $action !== 'expense_confirmation', 422, '直接非库存处理的采购后处理必须为费用确认。');
        abort_if($route === 'direct_expense' && (string) $attributes['consumption_confirmation_mode'] !== 'none', 422, '直接非库存处理不应进入库存领用确认。');
        abort_if($route === 'asset' && $stockManaged, 422, '固定资产待验收不能启用库存管理。');
        abort_if($route === 'asset' && $action !== 'asset_acceptance', 422, '固定资产待验收的采购后处理必须为资产验收。');
        abort_if((bool) $attributes['is_returnable'] && !(bool) $attributes['requires_custodian'], 422, '可归还物资必须同时启用责任人管理。');
        abort_if((bool) $attributes['requires_capitalization'] && $route !== 'asset', 422, '需要资产化的物资必须选择资产处理意图。');
    }
}
