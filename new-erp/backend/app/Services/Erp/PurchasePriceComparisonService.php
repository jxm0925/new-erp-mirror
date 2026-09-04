<?php

namespace App\Services\Erp;

use App\Models\Erp\{Item, ItemSupplierPrice, PurchasePriceHistory, Unit};
use Illuminate\Support\Collection;

class PurchasePriceComparisonService
{
    public function __construct(private readonly UnitConversionDomainService $conversions) {}

    public function comparableQuote(
        ItemSupplierPrice $quote,
        Item $item,
        int $targetUnitId,
        string $targetCurrency,
        string $targetTaxMode
    ): array {
        $currency = strtoupper((string) ($quote->currency ?: ''));
        if ($currency === '' || $currency !== strtoupper($targetCurrency)) {
            return $this->notComparable('币种不同且未提供汇率，不能直接比较');
        }
        $baseUnit = $this->canonicalUnitById((int) $item->unit_id);
        $targetUnit = $this->canonicalUnitById($targetUnitId);
        if (!$baseUnit || !$targetUnit || (int) $baseUnit->id !== (int) $targetUnit->id) {
            return $this->notComparable('报价未统一到同一个 Item 库存基本单位');
        }
        if (!in_array($quote->tax_mode, ['tax_included', 'tax_excluded'], true)) {
            return $this->notComparable('税价口径不明确');
        }
        $price = (float) $quote->base_unit_price;
        if ($price < 0 || (float) $quote->final_conversion_factor <= 0) {
            return $this->notComparable('报价缺少有效采购换算');
        }
        $comparable = $this->normalizeTax($price, (string) $quote->tax_mode, (float) $quote->tax_rate, $targetTaxMode);
        if ($comparable === null) return $this->notComparable('税率不可换算');
        return [
            'comparable' => true,
            'comparable_price' => round($comparable, 8),
            'price_source' => 'valid_quote_base_unit',
            'price_date' => optional($quote->valid_from)->toDateString() ?: optional($quote->updated_at)->toDateString(),
            'reason' => null,
        ];
    }

    public function recentComparablePurchases(
        Item $item,
        int $targetUnitId,
        string $targetCurrency,
        string $targetTaxMode,
        int $days = 180
    ): Collection {
        $targetUnit = $this->canonicalUnitById($targetUnitId);
        $itemUnit = $this->canonicalUnitById((int) $item->unit_id);
        if (!$targetUnit || !$itemUnit || (int) $targetUnit->id !== (int) $itemUnit->id) return collect();

        return PurchasePriceHistory::query()
            ->with(['supplier', 'item.unit', 'unit'])
            ->where('item_id', $item->id)
            ->where('effective_date', '>=', now()->subDays($days)->toDateString())
            ->latest('effective_date')
            ->get()
            ->groupBy('supplier_id')
            ->map(function (Collection $records) use ($targetCurrency, $targetTaxMode, $targetUnit) {
                foreach ($records as $record) {
                    if (strtoupper((string) ($record->currency ?? 'CNY')) !== strtoupper($targetCurrency)) continue;
                    if (!in_array($record->tax_mode, ['tax_included', 'tax_excluded'], true)) continue;
                    $recordBaseUnit = $this->canonicalUnitById((int) ($record->base_unit_id ?: 0));
                    if (!$recordBaseUnit || (int) $recordBaseUnit->id !== (int) $targetUnit->id) continue;
                    $price = (float) $record->base_unit_price;
                    $comparable = $this->normalizeTax($price, (string) $record->tax_mode, (float) $record->tax_rate, $targetTaxMode);
                    if ($comparable === null) continue;
                    return [
                        'supplier_id' => (int) $record->supplier_id,
                        'comparable' => true,
                        'comparable_price' => round($comparable, 8),
                        'price_source' => 'purchase_history_base_unit',
                        'price_date' => optional($record->effective_date)->toDateString(),
                        'record' => $record,
                    ];
                }
                return null;
            })->filter();
    }

    private function normalizeTax(float $price, string $sourceMode, float $taxRate, string $targetMode): ?float
    {
        if ($taxRate <= -100) return null;
        $included = $sourceMode === 'tax_included' ? $price : $price * (1 + $taxRate / 100);
        return $targetMode === 'tax_excluded' ? $included / (1 + $taxRate / 100) : $included;
    }

    private function canonicalUnitById(int $id): ?Unit
    {
        if ($id <= 0) return null;
        return $this->conversions->canonicalUnit(Unit::with('standardUnit')->find($id));
    }

    private function notComparable(string $reason): array
    {
        return [
            'comparable' => false,
            'comparable_price' => null,
            'price_source' => null,
            'price_date' => null,
            'reason' => $reason,
        ];
    }
}
