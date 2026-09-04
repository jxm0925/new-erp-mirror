<?php

namespace App\Services\Erp;

use App\Models\Erp\{ItemPurchaseConversion, SalesOrderLine, Sku, SkuItemRelation, Unit};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class UnitConversionDomainService
{
    public function activePurchaseConversions(int $itemId, ?\DateTimeInterface $at = null): Builder
    {
        $at ??= now();
        return ItemPurchaseConversion::query()
            ->where('item_id', $itemId)
            ->where('status', 'active')
            ->where(fn (Builder $query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', $at))
            ->where(fn (Builder $query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $at));
    }

    public function defaultPurchaseConversion(int $itemId, ?\DateTimeInterface $at = null): ItemPurchaseConversion
    {
        $conversion = $this->activePurchaseConversions($itemId, $at)
            ->where('is_default', true)
            ->with(['purchaseUnit.standardUnit', 'baseUnit.standardUnit'])
            ->first();
        if (!$conversion) {
            throw ValidationException::withMessages([
                'purchase_unit_id' => '该 Item 尚未维护默认采购单位换算，请先完成换算关系后再继续。',
            ]);
        }
        return $conversion;
    }

    public function purchaseConversion(int $itemId, int $purchaseUnitId, ?\DateTimeInterface $at = null, bool $lock = false): ItemPurchaseConversion
    {
        $query = $this->activePurchaseConversions($itemId, $at)
            ->where('purchase_unit_id', $purchaseUnitId)
            ->with(['purchaseUnit.standardUnit', 'baseUnit.standardUnit']);
        if ($lock) $query->lockForUpdate();
        $conversion = $query->first();
        if (!$conversion) {
            throw ValidationException::withMessages([
                'purchase_unit_id' => '所选采购单位没有有效的 Item 采购换算关系。',
            ]);
        }
        return $conversion;
    }

    public function calculatePlannedBaseQuantity(int $itemId, int $purchaseUnitId, float|int|string $purchaseQty): array
    {
        $conversion = $this->purchaseConversion($itemId, $purchaseUnitId);
        $quantity = $this->positive($purchaseQty, '采购包装数量必须大于 0。');
        $baseUnit = $this->canonicalUnit($conversion->baseUnit);
        return [
            'conversion' => $conversion,
            'purchase_qty' => $quantity,
            'base_qty' => $this->roundForUnit($quantity * (float) $conversion->factor, $baseUnit),
        ];
    }

    public function calculateBaseUnitPrice(float|int|string $purchaseUnitPrice, float|int|string $factor): float
    {
        $price = $this->nonNegative($purchaseUnitPrice, '采购单位报价不能小于 0。');
        $ratio = $this->positive($factor, '换算因子必须大于 0。');
        return round($price / $ratio, 8);
    }

    public function calculateReceiptBaseQuantity(
        float|int|string $purchaseQty,
        float|int|string $factor,
        ?float $actualBaseQty,
        bool $allowActual,
        ?string $differenceReason,
        ?Unit $baseUnit = null,
    ): array {
        $quantity = $this->nonNegative($purchaseQty, '到货采购数量不能小于 0。');
        $ratio = $this->positive($factor, '到货换算因子必须大于 0。');
        $baseUnit = $this->canonicalUnit($baseUnit);
        $planned = $this->roundForUnit($quantity * $ratio, $baseUnit);
        if (!$allowActual && $actualBaseQty !== null && abs($actualBaseQty - $planned) > 0.00000001) {
            throw ValidationException::withMessages([
                'actual_base_qty' => '当前换算不允许录入实际基本数量，实际数量必须等于计划数量。',
            ]);
        }
        $actual = $actualBaseQty === null
            ? $planned
            : $this->nonNegative($actualBaseQty, '实际基本数量不能小于 0。');
        $actual = $this->roundForUnit($actual, $baseUnit);
        $difference = $this->roundForUnit($actual - $planned, $baseUnit);
        if (abs($difference) > 0.00000001 && blank($differenceReason)) {
            throw ValidationException::withMessages([
                'difference_reason' => '实际基本数量与计划基本数量不一致时，必须填写差异原因。',
            ]);
        }
        return [
            'standard_base_qty' => $planned,
            'actual_base_qty' => $actual,
            'difference_qty' => $difference,
        ];
    }

    public function calculateReceiptQualityBaseQuantities(
        float|int|string $receiptQty,
        float|int|string $qualifiedQty,
        float|int|string $unqualifiedQty,
        float|int|string $actualBaseQty,
        ?Unit $baseUnit = null,
    ): array {
        $received = $this->nonNegative($receiptQty, '本次到货采购数量不能小于 0。');
        $qualified = $this->nonNegative($qualifiedQty, '合格采购数量不能小于 0。');
        $unqualified = $this->nonNegative($unqualifiedQty, '不合格采购数量不能小于 0。');
        if (abs(($qualified + $unqualified) - $received) > 0.00000001) {
            throw ValidationException::withMessages([
                'quality_qty' => '合格采购数量与不合格采购数量之和必须等于本次到货采购数量。',
            ]);
        }
        $actual = $this->nonNegative($actualBaseQty, '实际基本数量不能小于 0。');
        $baseUnit = $this->canonicalUnit($baseUnit);
        if ($received <= 0) {
            return ['qualified_base_qty' => 0.0, 'unqualified_base_qty' => 0.0];
        }
        $qualifiedBase = $this->roundForUnit($actual * $qualified / $received, $baseUnit);
        $unqualifiedBase = $this->roundForUnit($actual - $qualifiedBase, $baseUnit);
        if (abs(($qualifiedBase + $unqualifiedBase) - $this->roundForUnit($actual, $baseUnit)) > 0.00000001) {
            throw ValidationException::withMessages([
                'quality_base_qty' => '合格基本数量与不合格基本数量之和必须等于实际基本数量。',
            ]);
        }
        return ['qualified_base_qty' => $qualifiedBase, 'unqualified_base_qty' => $unqualifiedBase];
    }

    public function defaultFulfillmentRelation(int $skuId, bool $lock = false): ?SkuItemRelation
    {
        $query = SkuItemRelation::query()
            ->where('sku_id', $skuId)
            ->where('status', 'active')
            ->where('is_primary', true)
            ->with(['item.unit.standardUnit', 'sku.salesUnit.standardUnit']);
        if ($lock) $query->lockForUpdate();
        return $query->first();
    }

    public function calculateSalesRequirement(Sku $sku, float|int|string $salesQty, bool $lock = false): array
    {
        $quantity = $this->positive($salesQty, '销售数量必须大于 0。');
        $lineType = $sku->order_line_type ?: $sku->line_type;
        if (in_array($lineType, ['service', 'no_delivery', 'fee', 'auxiliary'], true)) {
            return ['relation' => null, 'item' => null, 'factor' => null, 'base_qty' => 0.0];
        }
        $relation = $this->defaultFulfillmentRelation($sku->id, $lock);
        if (!$relation || !$relation->item || $relation->item->status !== 'enabled') {
            throw ValidationException::withMessages([
                'sku_id' => '实物 SKU 必须维护唯一且启用的默认履约 Item。',
            ]);
        }
        $factor = $this->positive($relation->qty, 'SKU–Item 履约因子必须大于 0。');
        $baseUnit = $this->canonicalUnit($relation->item->unit);
        return [
            'relation' => $relation,
            'item' => $relation->item,
            'base_unit' => $baseUnit,
            'factor' => $factor,
            'base_qty' => $this->roundForUnit($quantity * $factor, $baseUnit),
        ];
    }

    public function groupBaseRequirements(Collection $lines): array
    {
        return $lines
            ->filter(fn ($line) => (float) data_get($line, 'item_base_required_qty', 0) > 0)
            ->groupBy(fn ($line) => (data_get($line, 'item_base_unit_id') ?: 0).'|'.(data_get($line, 'item_base_unit_name_snapshot') ?: '未维护单位'))
            ->map(fn (Collection $group, string $key) => [
                'unit_id' => data_get($group->first(), 'item_base_unit_id'),
                'unit_name' => explode('|', $key, 2)[1],
                'quantity' => round($group->sum(fn ($line) => (float) data_get($line, 'item_base_required_qty')), 8),
            ])->values()->all();
    }

    public function demandForLine(SalesOrderLine $line): array
    {
        $lineType = $line->order_line_type ?: $line->line_type;
        $type = $line->fulfillment_type ?: $lineType;
        if ($lineType === 'service') $type = 'service';
        if (in_array($lineType, ['no_delivery', 'fee', 'auxiliary'], true)) $type = 'no_delivery';
        return match ($type) {
            'inventory' => ['type' => 'inventory', 'quantity' => (float) $line->item_base_required_qty],
            'service' => ['type' => 'service', 'quantity' => (float) $line->order_qty],
            'no_delivery' => ['type' => 'no_delivery', 'quantity' => 0.0],
            default => ['type' => 'production', 'quantity' => (float) $line->item_base_required_qty],
        };
    }

    public function assertUnitPrecision(float|int|string $value, Unit $unit, string $field = 'quantity'): void
    {
        $unit = $this->canonicalUnit($unit) ?: $unit;
        $numeric = (float) $value;
        $rounded = round($numeric, (int) $unit->decimal_places);
        if (abs($numeric - $rounded) > 0.00000001) {
            throw ValidationException::withMessages([
                $field => "单位 {$unit->unit_name} 最多允许 {$unit->decimal_places} 位小数。",
            ]);
        }
    }

    public function canonicalUnit(?Unit $unit): ?Unit
    {
        if (!$unit) return null;
        if ($unit->is_legacy && $unit->standard_unit_id) {
            return $unit->relationLoaded('standardUnit')
                ? ($unit->standardUnit ?: $unit)
                : (Unit::find($unit->standard_unit_id) ?: $unit);
        }
        return $unit;
    }

    private function roundForUnit(float $value, ?Unit $unit): float
    {
        return round($value, $unit ? (int) $unit->decimal_places : 8);
    }

    private function positive(float|int|string $value, string $message): float
    {
        $number = (float) $value;
        if ($number <= 0) throw ValidationException::withMessages(['quantity' => $message]);
        return $number;
    }

    private function nonNegative(float|int|string $value, string $message): float
    {
        $number = (float) $value;
        if ($number < 0) throw ValidationException::withMessages(['quantity' => $message]);
        return $number;
    }
}
