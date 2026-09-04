<?php

namespace App\Services\Erp;

use App\Models\Erp\{Item, Sku, SkuItemRelation, SkuItemRelationLog};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SkuItemDefaultRelationService
{
    public function setPrimary(
        int $skuId,
        int $itemId,
        ?string $reason,
        ?string $remark,
        ?int $operatorId,
        string $operatorName,
        float $factor = 1.0,
    ): SkuItemRelation {
        $reason = $reason ?: '首次设置';
        $this->ensureReasonRemark($reason, $remark);
        if ($factor <= 0) throw ValidationException::withMessages(['factor' => '履约因子必须大于 0。']);

        return DB::transaction(function () use ($skuId, $itemId, $factor, $reason, $remark, $operatorId, $operatorName) {
            $sku = Sku::with('salesUnit')->lockForUpdate()->findOrFail($skuId);
            $this->ensurePhysical($sku);
            $item = Item::with('unit')->whereKey($itemId)->where('status', 'enabled')->first();
            if (!$item || !$item->unit || $item->unit->status !== 'enabled') {
                throw ValidationException::withMessages(['item_id' => '默认履约 Item 及其库存基本单位必须处于启用状态。']);
            }

            $active = SkuItemRelation::where('sku_id', $sku->id)->where('status', 'active')->where('is_primary', true)->lockForUpdate()->get();
            if ($active->count() > 1) throw ValidationException::withMessages(['sku_id' => '该 SKU 存在多个有效默认 Item，请先在完整性检查中修复。']);
            $old = $active->first();
            if ($old && (int) $old->item_id === $item->id && abs((float) $old->qty - $factor) < 0.00000001) {
                throw ValidationException::withMessages(['item_id' => '新默认 Item 和履约因子与当前关系相同，无需保存。']);
            }

            if ($old) {
                $old->update([
                    'status' => 'inactive', 'is_primary' => false, 'expired_at' => now(),
                    'operator_name' => $operatorName, 'change_reason' => $reason,
                ]);
            }
            $relation = SkuItemRelation::create([
                'sku_id' => $sku->id, 'item_id' => $item->id, 'unit_id' => $item->unit_id,
                'relation_type' => 'primary', 'qty' => $factor, 'is_primary' => true, 'is_bundle_item' => false,
                'status' => 'active', 'remark' => $remark, 'effective_at' => now(),
                'operator_name' => $operatorName, 'change_reason' => $reason,
            ]);
            $this->log($sku->id, $relation->id, $old?->item_id, $item->id, $old ? 'change_primary' : 'set_primary', $reason, $remark, $operatorId, $operatorName);
            return $relation;
        });
    }

    public function resolveDuplicate(int $skuId, int $keepRelationId, string $reason, ?string $remark, ?int $operatorId, string $operatorName): void
    {
        $this->ensureReasonRemark($reason, $remark);
        DB::transaction(function () use ($skuId, $keepRelationId, $reason, $remark, $operatorId, $operatorName) {
            $sku = Sku::lockForUpdate()->findOrFail($skuId);
            $this->ensurePhysical($sku);
            $relations = SkuItemRelation::where('sku_id', $sku->id)->where('status', 'active')->where('is_primary', true)->lockForUpdate()->get();
            if (!$relations->contains('id', $keepRelationId)) throw ValidationException::withMessages(['keep_relation_id' => '保留关系不是该 SKU 的有效默认关系。']);
            foreach ($relations->where('id', '!=', $keepRelationId) as $relation) {
                $relation->update(['status' => 'inactive', 'is_primary' => false, 'expired_at' => now(), 'operator_name' => $operatorName, 'change_reason' => $reason]);
                $this->log($sku->id, $relation->id, $relation->item_id, null, 'resolve_duplicate', $reason, $remark, $operatorId, $operatorName);
            }
        });
    }

    public function removeWrongBindings(int $skuId, string $reason, ?string $remark, ?int $operatorId, string $operatorName): void
    {
        $this->ensureReasonRemark($reason, $remark);
        DB::transaction(function () use ($skuId, $reason, $remark, $operatorId, $operatorName) {
            $sku = Sku::lockForUpdate()->findOrFail($skuId);
            if ($sku->line_type === 'physical') throw ValidationException::withMessages(['sku_id' => '实物 SKU 不允许解除默认 Item，请更换为可用的默认履约 Item。']);
            $relations = SkuItemRelation::where('sku_id', $sku->id)->where('status', 'active')->where('is_primary', true)->lockForUpdate()->get();
            foreach ($relations as $relation) {
                $relation->update(['status' => 'inactive', 'is_primary' => false, 'expired_at' => now(), 'operator_name' => $operatorName, 'change_reason' => $reason]);
                $this->log($sku->id, $relation->id, $relation->item_id, null, 'remove_wrong_binding', $reason, $remark, $operatorId, $operatorName);
            }
        });
    }

    private function ensurePhysical(Sku $sku): void
    {
        if ($sku->line_type !== 'physical') throw ValidationException::withMessages(['sku_id' => '服务或无需发货 SKU 无需设置默认履约 Item。']);
    }

    private function ensureReasonRemark(string $reason, ?string $remark): void
    {
        if ($reason === '其他' && blank($remark)) throw ValidationException::withMessages(['remark' => '变更原因选择“其他”时必须填写备注。']);
    }

    private function log(int $skuId, ?int $relationId, ?int $oldItemId, ?int $newItemId, string $action, string $reason, ?string $remark, ?int $operatorId, string $operatorName): void
    {
        SkuItemRelationLog::create([
            'sku_id' => $skuId, 'relation_id' => $relationId, 'old_item_id' => $oldItemId, 'new_item_id' => $newItemId,
            'action' => $action, 'change_reason' => $reason, 'remark' => $remark,
            'operator_id' => $operatorId, 'operator_name' => $operatorName,
        ]);
    }
}
