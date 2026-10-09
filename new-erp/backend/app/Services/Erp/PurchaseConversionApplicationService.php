<?php

namespace App\Services\Erp;

use App\Models\Erp\{Item, PurchaseOrderItem};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseConversionApplicationService
{
    public function __construct(private readonly UnitConversionDomainService $conversions) {}

    public function orderLineSnapshot(array $line, ?PurchaseOrderItem $existing = null): array
    {
        return DB::transaction(function () use ($line, $existing) {
            $item = Item::with('unit.standardUnit')->lockForUpdate()->findOrFail($line['item_id']);
            app(PurchaseManagementScopeService::class)->assertScope($item->management_scope);
            if (array_key_exists('management_scope', $line)) app(PurchaseManagementScopeService::class)->assertItemScope($item,
                app(PurchaseManagementScopeService::class)->assertScope($line['management_scope']));
            if ($existing && (int) $existing->item_id === (int) $line['item_id']
                && (int) ($line['purchase_unit_id'] ?? $existing->purchase_unit_id) === (int) $existing->purchase_unit_id
                && (float) $existing->conversion_factor_snapshot > 0) {
                $quantity = (float) ($line['order_qty'] ?? $line['purchase_qty']);
                $price = (float) ($line['unit_price'] ?? 0);
                $factor = (float) $existing->conversion_factor_snapshot;
                $this->assertExpectedFactor($line, $factor);
                $this->conversions->assertUnitPrecision($quantity, $existing->purchaseUnit, 'order_qty');
                $this->conversions->assertUnitPrecision($quantity * $factor, $existing->baseUnit, 'order_qty');
                return [
                    ...$existing->only(['purchase_unit_id', 'purchase_unit_name_snapshot', 'conversion_factor_snapshot',
                        'allow_actual_conversion_snapshot', 'base_unit_id', 'base_unit_name_snapshot']),
                    'purchase_qty' => $quantity, 'planned_base_qty' => round($quantity * $factor, 8),
                    'purchase_conversion_snapshot' => $existing->purchase_conversion_snapshot ? array_replace($existing->purchase_conversion_snapshot, [
                        'purchase_unit_price' => $price, 'base_unit_price' => $this->conversions->calculateBaseUnitPrice($price, $factor), 'amount' => round($quantity * $price, 4),
                    ]) : null,
                    'purchase_unit_price' => $price, 'base_unit_price' => $this->conversions->calculateBaseUnitPrice($price, $factor),
                ];
            }
            $purchaseUnitId = (int) ($line['purchase_unit_id'] ?? 0);
            $itemBaseUnit = $this->conversions->canonicalUnit($item->unit);
            if ($purchaseUnitId && $itemBaseUnit && $purchaseUnitId === (int) $itemBaseUnit->id) {
                $quantity = (float) ($line['order_qty'] ?? $line['purchase_qty']);
                $purchaseUnitPrice = (float) ($line['unit_price'] ?? $line['purchase_unit_price'] ?? 0);
                $this->conversions->assertUnitPrecision($quantity, $itemBaseUnit, 'order_qty');
                $this->assertExpectedFactor($line, 1);
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
            $this->assertExpectedFactor($line, (float) $conversion->factor);
            $purchaseUnit = $this->conversions->canonicalUnit($conversion->purchaseUnit);
            $baseUnit = $this->conversions->canonicalUnit($conversion->baseUnit);
            $this->conversions->assertUnitPrecision($calculated['purchase_qty'], $purchaseUnit, 'order_qty');
            $this->conversions->assertUnitPrecision($calculated['purchase_qty'] * (float) $conversion->factor, $baseUnit, 'order_qty');
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

    public function orderLineSnapshotFromBaseRequirement(array $line): array
    {
        $snapshot = app(PurchasePlanningConversionService::class)->calculate([
            ...$line, 'required_qty' => $line['base_qty'] ?? 0,
        ]);
        return \Illuminate\Support\Arr::only($snapshot, [
            'purchase_unit_id', 'purchase_unit_name_snapshot', 'conversion_factor_snapshot',
            'allow_actual_conversion_snapshot', 'base_unit_id', 'base_unit_name_snapshot',
            'purchase_qty', 'planned_base_qty', 'purchase_unit_price', 'base_unit_price', 'amount',
        ]);
    }

    private function assertExpectedFactor(array $line, float $factor): void
    {
        if (isset($line['expected_conversion_factor']) && abs((float) $line['expected_conversion_factor'] - $factor) > 0.00000001) {
            throw ValidationException::withMessages(['purchase_unit_id' => '采购换算已变化，请重新选择采购单位并核对数量后保存。']);
        }
    }

    public function receiptLineSnapshot(array $line, bool $forceBaseUnit = false): array
    {
        return DB::transaction(function () use ($line, $forceBaseUnit) {
            $item = Item::with('unit.standardUnit')->lockForUpdate()->findOrFail($line['item_id']);
            app(PurchaseManagementScopeService::class)->assertScope($item->management_scope);
            if (array_key_exists('management_scope', $line)) app(PurchaseManagementScopeService::class)->assertItemScope($item,
                app(PurchaseManagementScopeService::class)->assertScope($line['management_scope']));
            $orderLine = !$forceBaseUnit && !empty($line['order_item_id'])
                ? PurchaseOrderItem::with(['purchaseUnit.standardUnit', 'baseUnit.standardUnit'])->lockForUpdate()->findOrFail($line['order_item_id'])
                : null;
            if ($orderLine) {
                $purchaseUnit = $this->conversions->canonicalUnit($orderLine->purchaseUnit);
                $baseUnit = $this->conversions->canonicalUnit($orderLine->baseUnit);
                $factor = (float) $orderLine->conversion_factor_snapshot;
                $allowActual = (bool) $orderLine->allow_actual_conversion_snapshot;
            } else {
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
