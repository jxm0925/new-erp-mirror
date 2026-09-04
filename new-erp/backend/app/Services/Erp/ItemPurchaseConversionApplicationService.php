<?php

namespace App\Services\Erp;

use App\Models\Erp\{Item, ItemPurchaseConversion, Unit};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ItemPurchaseConversionApplicationService
{
    public function __construct(private readonly UnitConversionDomainService $conversions) {}

    public function save(int $itemId, array $data, ?int $id, ?int $operatorId, string $operatorName): ItemPurchaseConversion
    {
        return DB::transaction(function () use ($itemId, $data, $id, $operatorId, $operatorName) {
            $item = Item::with('unit.standardUnit')->lockForUpdate()->findOrFail($itemId);
            $baseUnit = $this->conversions->canonicalUnit($item->unit);
            $purchaseUnit = Unit::with('standardUnit')->whereKey($data['purchase_unit_id'])
                ->where('status', 'enabled')->where('is_legacy', false)->first();
            if (!$purchaseUnit) {
                throw ValidationException::withMessages(['purchase_unit_id' => '采购单位必须是启用的标准单位。']);
            }
            if (!$baseUnit || $baseUnit->status !== 'enabled') {
                throw ValidationException::withMessages(['item_id' => 'Item 必须维护启用的库存基本单位。']);
            }
            if ((int) $purchaseUnit->id === (int) $baseUnit->id) {
                throw ValidationException::withMessages(['purchase_unit_id' => '采购单位不能与 Item 库存基本单位相同；相同单位无需维护采购换算。']);
            }
            if ((float) $data['factor'] <= 0) {
                throw ValidationException::withMessages(['factor' => '换算因子必须大于 0。']);
            }

            $current = $id
                ? ItemPurchaseConversion::where('item_id', $item->id)->lockForUpdate()->findOrFail($id)
                : null;
            $from = $current ? now() : ($data['effective_from'] ?? now());
            $to = $data['effective_to'] ?? null;
            if ($to && $to <= $from) {
                throw ValidationException::withMessages(['effective_to' => '有效期止必须晚于有效期起。']);
            }

            $overlap = ItemPurchaseConversion::where('item_id', $item->id)
                ->where('purchase_unit_id', $purchaseUnit->id)
                ->where('status', 'active')
                ->when($current, fn ($q) => $q->whereKeyNot($current->id))
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $from))
                ->when($to, fn ($q) => $q->where(fn ($period) => $period->whereNull('effective_from')->orWhere('effective_from', '<', $to)))
                ->lockForUpdate()->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['effective_from' => '同一 Item、同一采购单位的有效期不能重叠。']);
            }

            if ($current) {
                $current->update([
                    'status' => 'inactive',
                    'is_default' => false,
                    'effective_to' => now(),
                    'operator_id' => $operatorId,
                    'operator_name' => $operatorName,
                ]);
            }
            if (!empty($data['is_default'])) {
                ItemPurchaseConversion::where('item_id', $item->id)
                    ->where('status', 'active')->where('is_default', true)
                    ->lockForUpdate()->get()->each->update(['is_default' => false]);
            }

            return ItemPurchaseConversion::create([
                'item_id' => $item->id,
                'purchase_unit_id' => $purchaseUnit->id,
                'base_unit_id' => $baseUnit->id,
                'factor' => $data['factor'],
                'is_default' => (bool) ($data['is_default'] ?? false),
                'allow_actual_conversion' => (bool) ($data['allow_actual_conversion'] ?? false),
                'effective_from' => $from,
                'effective_to' => $to,
                'status' => 'active',
                'change_reason' => $data['change_reason'],
                'operator_id' => $operatorId,
                'operator_name' => $operatorName,
                'remark' => $data['remark'] ?? null,
            ])->load(['purchaseUnit.standardUnit', 'baseUnit.standardUnit']);
        });
    }

    public function disable(int $itemId, int $id, string $reason, ?int $operatorId, string $operatorName): ItemPurchaseConversion
    {
        return DB::transaction(function () use ($itemId, $id, $reason, $operatorId, $operatorName) {
            Item::lockForUpdate()->findOrFail($itemId);
            $record = ItemPurchaseConversion::where('item_id', $itemId)->lockForUpdate()->findOrFail($id);
            if ($record->status !== 'active') {
                throw ValidationException::withMessages(['status' => '该采购换算关系已经停用。']);
            }
            $record->update([
                'status' => 'inactive',
                'is_default' => false,
                'effective_to' => now(),
                'change_reason' => $reason,
                'operator_id' => $operatorId,
                'operator_name' => $operatorName,
            ]);
            return $record->fresh(['purchaseUnit.standardUnit', 'baseUnit.standardUnit']);
        });
    }
}
