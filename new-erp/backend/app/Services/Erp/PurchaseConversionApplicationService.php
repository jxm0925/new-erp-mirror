<?php

namespace App\Services\Erp;

use App\Models\Erp\{Item, PurchaseOrderItem};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseConversionApplicationService
{
    public function __construct(private readonly UnitConversionDomainService $conversions) {}

    public function orderLineSnapshot(array $line): array
    {
        return DB::transaction(function () use ($line) {
            $item = Item::with('unit.standardUnit')->lockForUpdate()->findOrFail($line['item_id']);
            $purchaseUnitId = (int) ($line['purchase_unit_id'] ?? 0);
            $itemBaseUnit = $this->conversions->canonicalUnit($item->unit);
            if ($purchaseUnitId && $itemBaseUnit && $purchaseUnitId === (int) $itemBaseUnit->id) {
                $quantity = (float) ($line['order_qty'] ?? $line['purchase_qty']);
                $purchaseUnitPrice = (float) ($line['unit_price'] ?? $line['purchase_unit_price'] ?? 0);
                return [
                    'purchase_unit_id' => $itemBaseUnit->id,
                    'purchase_unit_name_snapshot' => $itemBaseUnit->unit_name,
                    'conversion_factor_snapshot' => 1,
                    'allow_actual_conversion_snapshot' => false,
                    'base_unit_id' => $itemBaseUnit->id,
                    'base_unit_name_snapshot' => $itemBaseUnit->unit_name,
                    'purchase_qty' => $quantity,
                    'planned_base_qty' => round($quantity, (int) $itemBaseUnit->decimal_places),
                    'purchase_unit_price' => $purchaseUnitPrice,
                    'base_unit_price' => $purchaseUnitPrice,
                ];
            }
            $conversion = $purchaseUnitId
                ? $this->conversions->purchaseConversion($item->id, $purchaseUnitId)
                : $this->conversions->defaultPurchaseConversion($item->id);
            $calculated = $this->conversions->calculatePlannedBaseQuantity(
                $item->id,
                $conversion->purchase_unit_id,
                $line['order_qty'] ?? $line['purchase_qty']
            );
            $purchaseUnitPrice = (float) ($line['unit_price'] ?? $line['purchase_unit_price'] ?? 0);
            $purchaseUnit = $this->conversions->canonicalUnit($conversion->purchaseUnit);
            $baseUnit = $this->conversions->canonicalUnit($conversion->baseUnit);
            return [
                'purchase_unit_id' => $purchaseUnit->id,
                'purchase_unit_name_snapshot' => $purchaseUnit->unit_name,
                'conversion_factor_snapshot' => $conversion->factor,
                'allow_actual_conversion_snapshot' => (bool) $conversion->allow_actual_conversion,
                'base_unit_id' => $baseUnit->id,
                'base_unit_name_snapshot' => $baseUnit->unit_name,
                'purchase_qty' => $calculated['purchase_qty'],
                'planned_base_qty' => $calculated['base_qty'],
                'purchase_unit_price' => $purchaseUnitPrice,
                'base_unit_price' => $this->conversions->calculateBaseUnitPrice($purchaseUnitPrice, $conversion->factor),
            ];
        });
    }

    /**
     * Convert a plan allocation expressed in the Item base unit into the
     * supplier's purchase unit. Plan splits store base quantity and a
     * comparable base-unit price; purchase orders store package quantity and
     * package price.
     */
    public function orderLineSnapshotFromBaseRequirement(array $line): array
    {
        return DB::transaction(function () use ($line) {
            $item = Item::with('unit.standardUnit')->lockForUpdate()->findOrFail($line['item_id']);
            $baseUnit = $this->conversions->canonicalUnit($item->unit);
            $baseQuantity = (float) ($line['base_qty'] ?? 0);
            if ($baseQuantity <= 0) {
                throw ValidationException::withMessages(['base_qty' => '采购计划基本数量必须大于 0。']);
            }

            // Supplier quotations are recommendations only. The actual purchase
            // price is entered on the plan/order and must never be overwritten
            // by a reference quotation. Unit selection follows the Item's
            // default purchase conversion, independently from quotations.
            $conversion = $this->conversions->activePurchaseConversions($item->id)
                ->where('is_default', true)
                ->with(['purchaseUnit.standardUnit', 'baseUnit.standardUnit'])
                ->lockForUpdate()
                ->first();
            if ($conversion) {
                $purchaseUnit = $this->conversions->canonicalUnit($conversion->purchaseUnit);
                $factor = (float) $conversion->factor;
                $purchaseUnitPrice = (float) ($line['base_unit_price'] ?? 0) * $factor;
            } else {
                $purchaseUnit = $baseUnit;
                $factor = 1.0;
                $purchaseUnitPrice = (float) ($line['base_unit_price'] ?? 0);
            }

            if (!$purchaseUnit || !$baseUnit || $factor <= 0) {
                throw ValidationException::withMessages(['purchase_unit_id' => '采购单位或换算关系无效，不能生成采购订单。']);
            }

            $purchaseQuantity = round($baseQuantity / $factor, (int) $purchaseUnit->decimal_places);
            if ($purchaseQuantity <= 0 || abs($purchaseQuantity * $factor - $baseQuantity) > 0.00000001) {
                throw ValidationException::withMessages([
                    'purchase_qty' => "计划基本数量 {$baseQuantity} {$baseUnit->unit_name} 无法按 {$factor} {$baseUnit->unit_name}/{$purchaseUnit->unit_name} 精确换算，请调整计划数量。",
                ]);
            }

            return [
                'purchase_unit_id' => $purchaseUnit->id,
                'purchase_unit_name_snapshot' => $purchaseUnit->unit_name,
                'conversion_factor_snapshot' => $factor,
                'allow_actual_conversion_snapshot' => (bool) ($conversion?->allow_actual_conversion ?? false),
                'base_unit_id' => $baseUnit->id,
                'base_unit_name_snapshot' => $baseUnit->unit_name,
                'purchase_qty' => $purchaseQuantity,
                'planned_base_qty' => $baseQuantity,
                'purchase_unit_price' => $purchaseUnitPrice,
                'base_unit_price' => $this->conversions->calculateBaseUnitPrice($purchaseUnitPrice, $factor),
                'amount' => round($purchaseQuantity * $purchaseUnitPrice, 4),
            ];
        });
    }

    public function receiptLineSnapshot(array $line, bool $forceBaseUnit = false): array
    {
        return DB::transaction(function () use ($line, $forceBaseUnit) {
            $orderLine = !$forceBaseUnit && !empty($line['order_item_id'])
                ? PurchaseOrderItem::with(['purchaseUnit.standardUnit', 'baseUnit.standardUnit'])->lockForUpdate()->findOrFail($line['order_item_id'])
                : null;
            if ($orderLine) {
                $purchaseUnit = $this->conversions->canonicalUnit($orderLine->purchaseUnit);
                $baseUnit = $this->conversions->canonicalUnit($orderLine->baseUnit);
                $factor = (float) $orderLine->conversion_factor_snapshot;
                $allowActual = (bool) $orderLine->allow_actual_conversion_snapshot;
            } else {
                $item = Item::with('unit.standardUnit')->lockForUpdate()->findOrFail($line['item_id']);
                $itemBaseUnit = $this->conversions->canonicalUnit($item->unit);
                if (!empty($line['purchase_unit_id']) && $itemBaseUnit && (int) $line['purchase_unit_id'] === (int) $itemBaseUnit->id) {
                    $purchaseUnit = $itemBaseUnit;
                    $baseUnit = $itemBaseUnit;
                    $factor = 1.0;
                    $allowActual = false;
                } else {
                    $conversion = !empty($line['purchase_unit_id'])
                        ? $this->conversions->purchaseConversion($item->id, (int) $line['purchase_unit_id'])
                        : $this->conversions->defaultPurchaseConversion($item->id);
                    $purchaseUnit = $this->conversions->canonicalUnit($conversion->purchaseUnit);
                    $baseUnit = $this->conversions->canonicalUnit($conversion->baseUnit);
                    $factor = (float) $conversion->factor;
                    $allowActual = (bool) $conversion->allow_actual_conversion;
                }
            }
            $quantity = $this->conversions->calculateReceiptBaseQuantity(
                $line['receipt_qty'],
                $factor,
                array_key_exists('actual_base_qty', $line) && $line['actual_base_qty'] !== null ? (float) $line['actual_base_qty'] : null,
                $allowActual,
                $line['difference_reason'] ?? null,
                $baseUnit,
            );
            $quality = $this->conversions->calculateReceiptQualityBaseQuantities(
                $line['receipt_qty'],
                $line['qualified_qty'] ?? $line['receipt_qty'],
                $line['unqualified_qty'] ?? 0,
                $quantity['actual_base_qty'],
                $baseUnit,
            );
            return [
                'purchase_unit_id' => $purchaseUnit?->id,
                'purchase_unit_name_snapshot' => $purchaseUnit?->unit_name,
                'conversion_factor_snapshot' => $factor,
                'base_unit_id' => $baseUnit?->id,
                'base_unit_name_snapshot' => $baseUnit?->unit_name,
                'allow_actual_conversion' => $allowActual,
                'inventory_posting_status' => 'pending',
            ] + $quantity + $quality;
        });
    }
}
