<?php

namespace App\Services\Erp;

use App\Models\Erp\Item;
use App\Models\Erp\ItemCategory;
use App\Models\Erp\SupplierCategoryCapability;
use App\Models\Erp\SupplierItemRelation;
use App\Models\Erp\SupplierItemStat;
use Illuminate\Support\Collection;

class PurchaseSupplierRecommendationService
{
    public function __construct(
        private readonly SupplierCapabilityService $capabilities,
        private readonly SupplierQuotationService $quotations,
        private readonly PurchasePriceComparisonService $prices
    ) {
    }

    public function recommend(
        Item $item,
        float $quantity,
        int $unitId,
        ?string $requiredDate,
        string $currency,
        string $taxMode
    ): array {
        abort_unless($item->is_purchase_item && $item->status === 'enabled', 422, '只有已启用的采购 Item 可以推荐供应商。');

        $eligibleSuppliers = $this->capabilities->supplierEligibleQuery()
            ->get()
            ->keyBy('id');

        $relations = $this->capabilities->activeRelationQuery()
            ->where('item_id', $item->id)
            ->with('supplier')
            ->get()
            ->keyBy('supplier_id');

        $quoteRows = $this->quotations->effectiveQuoteQuery($item->id, $quantity)->get();
        $quotes = $quoteRows->groupBy('supplier_id')->map(function (Collection $rows) use ($item, $unitId, $currency, $taxMode) {
            return $rows->map(function ($quote) use ($item, $unitId, $currency, $taxMode) {
                return ['quote' => $quote] + $this->prices->comparableQuote($quote, $item, $unitId, $currency, $taxMode);
            })->sortBy(fn (array $row) => $row['comparable'] ? $row['comparable_price'] : PHP_FLOAT_MAX)->first();
        });

        $purchases = $this->prices->recentComparablePurchases($item, $unitId, $currency, $taxMode);
        $stats = SupplierItemStat::query()
            ->where('item_id', $item->id)
            ->get()
            ->keyBy('supplier_id');

        // Supplier scope is maintained on selectable leaf categories. Parent nodes are navigation only.
        $categoryIds = $item->category_id ? [(int) $item->category_id] : [0];
        $categorySupplierIds = SupplierCategoryCapability::query()
            ->whereIn('item_category_id', $categoryIds)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('expired_at')->orWhere('expired_at', '>', now());
            })
            ->pluck('supplier_id');

        $candidateIds = collect()
            ->merge($relations->keys())
            ->merge($quotes->keys())
            ->merge($purchases->keys())
            ->merge($stats->keys())
            ->merge($categorySupplierIds)
            ->push($item->default_supplier_id)
            ->filter()
            ->unique()
            ->values();

        $candidates = $candidateIds
            ->filter(fn ($supplierId) => $eligibleSuppliers->has((int) $supplierId))
            ->map(function ($supplierId) use ($eligibleSuppliers, $relations, $quotes, $purchases, $stats, $categorySupplierIds, $quantity, $requiredDate, $item) {
                $supplierId = (int) $supplierId;
                $supplier = $eligibleSuppliers->get($supplierId);
                $relation = $relations->get($supplierId);
                $quoteResult = $quotes->get($supplierId);
                $quote = $quoteResult['quote'] ?? null;
                $purchase = $purchases->get($supplierId);
                $stat = $stats->get($supplierId);
                $isCategoryCandidate = $categorySupplierIds->contains($supplierId);

                $isItemDefault = (int) $item->default_supplier_id === $supplierId;
                $basis = $this->basis($relation, $quoteResult, $purchase, $stat, $isItemDefault, $isCategoryCandidate);
                $leadTimeDays = $quote?->lead_time_days;
                $arrivalRisk = $requiredDate && $leadTimeDays
                    ? now()->addDays((int) $leadTimeDays)->toDateString() > $requiredDate
                    : false;
                $minimumMet = !$quote || (float) $quote->min_order_qty <= $quantity;
                $autoSelectable = $basis !== 'CATEGORY_CANDIDATE' && !$arrivalRisk && $minimumMet;

                return [
                    'supplier_id' => $supplierId,
                    'supplier_code' => $supplier->supplier_code,
                    'supplier_name' => $supplier->supplier_name,
                    'capability_source' => $relation?->capability_source ?: ($quote ? 'quotation' : ($purchase ? 'purchase_history' : ($isItemDefault ? 'item_default' : 'category_candidate'))),
                    'capability_level' => $relation && $relation->capability_source !== 'category_candidate'
                        ? 'confirmed_item'
                        : ($quote ? 'quotation' : ($purchase ? 'purchase_history' : ($isItemDefault ? 'item_default' : 'category_candidate'))),
                    'recommendation_basis' => $basis,
                    'comparable_price' => $quoteResult['comparable_price'] ?? $purchase['comparable_price'] ?? null,
                    'price_source' => $quoteResult['price_source'] ?? $purchase['price_source'] ?? null,
                    'price_date' => $quoteResult['price_date'] ?? $purchase['price_date'] ?? null,
                    'quote_price' => $quote?->price !== null ? (float) $quote->price : null,
                    'quote_unit_id' => $quote?->unit_id,
                    'currency' => $quote?->currency,
                    'tax_mode' => $quote?->tax_mode,
                    'tax_rate' => $quote?->tax_rate !== null ? (float) $quote->tax_rate : null,
                    'min_order_qty' => $quote?->min_order_qty !== null ? (float) $quote->min_order_qty : null,
                    'max_order_qty' => $quote?->max_order_qty !== null ? (float) $quote->max_order_qty : null,
                    'lead_time_days' => $leadTimeDays !== null ? (int) $leadTimeDays : null,
                    'valid_until' => optional($quote?->valid_until)->toDateString(),
                    'is_default' => $isItemDefault || (bool) ($relation?->is_default),
                    'last_successful_at' => optional($stat?->last_receipt_at)->toDateTimeString(),
                    'arrival_risk' => $arrivalRisk,
                    'minimum_order_met' => $minimumMet,
                    'auto_selectable' => $autoSelectable,
                    'recommended' => false,
                    'selection_note' => $basis === 'CATEGORY_CANDIDATE'
                        ? '仅为品类候选，必须人工确认具体 Item 供货能力和价格。'
                        : ($arrivalRisk ? '交期可能晚于需求日期。' : null),
                ];
            })
            ->values();

        $candidates = $this->scoreCandidates($candidates, $stats);

        $recommendedIndex = $this->recommendedIndex($candidates);
        if ($recommendedIndex !== null) {
            $candidates = $candidates->map(function (array $candidate, int $index) use ($recommendedIndex) {
                if ($index === $recommendedIndex) {
                    $candidate['recommended'] = true;
                }

                return $candidate;
            });
        }

        return [
            'item' => [
                'id' => $item->id,
                'item_code' => $item->item_code,
                'item_name' => $item->item_name,
                'unit_id' => $unitId,
            ],
            'conditions' => compact('quantity', 'unitId', 'requiredDate', 'currency', 'taxMode'),
            'recommended_supplier_id' => $recommendedIndex !== null ? $candidates[$recommendedIndex]['supplier_id'] : null,
            'candidates' => $candidates->all(),
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    private function basis(?SupplierItemRelation $relation, ?array $quote, ?array $purchase, $stat, bool $isItemDefault, bool $isCategoryCandidate): string
    {
        if (($quote['comparable'] ?? false) === true) return 'VALID_QUOTE_BEST_PRICE';
        if ($relation && $relation->capability_source !== 'category_candidate') return 'CONFIRMED_CAPABILITY';
        if ($stat?->last_receipt_at || $purchase) return 'LAST_SUCCESSFUL_SUPPLIER';
        if ($isItemDefault || $relation?->is_default) return 'DEFAULT_SUPPLIER';
        if ($isCategoryCandidate) return 'CATEGORY_CANDIDATE';
        return 'CONFIRMED_CAPABILITY';
    }

    private function recommendedIndex(Collection $candidates): ?int
    {
        $sorted = $candidates->map(fn (array $candidate, int $index) => $candidate + ['_index' => $index])
            ->filter(fn (array $candidate) => $candidate['auto_selectable'])
            ->sortBy([
                fn (array $a, array $b) => ($b['recommendation_score'] ?? 0) <=> ($a['recommendation_score'] ?? 0),
                fn (array $a, array $b) => ($a['comparable_price'] ?? PHP_FLOAT_MAX) <=> ($b['comparable_price'] ?? PHP_FLOAT_MAX),
                fn (array $a, array $b) => $a['supplier_id'] <=> $b['supplier_id'],
            ])
            ->first();

        if (!$sorted || !$sorted['auto_selectable']) return null;

        return (int) $sorted['_index'];
    }

    private function scoreCandidates(Collection $candidates, Collection $stats): Collection
    {
        $weights = config('purchase_algorithm.supplier_recommendation.weights');
        $neutral = (float) config('purchase_algorithm.supplier_recommendation.no_history_score', 60);
        $threshold = (float) config('purchase_algorithm.supplier_recommendation.price_abnormal_threshold', 0.12);
        $lowestPrice = $candidates->where('auto_selectable', true)->pluck('comparable_price')->filter(fn ($price) => $price !== null && $price > 0)->min();
        $weightTotal = max(1, array_sum($weights));

        return $candidates->map(function (array $candidate) use ($weights, $neutral, $threshold, $lowestPrice, $weightTotal, $stats): array {
            $stat = $stats->get($candidate['supplier_id']);
            $priceScore = $candidate['comparable_price'] && $lowestPrice
                ? min(100, round($lowestPrice / $candidate['comparable_price'] * 100, 2))
                : $neutral;
            $qualityScore = $stat?->qualified_rate !== null ? round((float) $stat->qualified_rate * 100, 2) : $neutral;
            $deliveryScore = $stat?->on_time_rate !== null ? round((float) $stat->on_time_rate * 100, 2) : $neutral;
            $returnScore = $stat?->return_rate !== null ? round(max(0, 1 - (float) $stat->return_rate) * 100, 2) : $neutral;
            $cooperationScore = $candidate['is_default'] ? 100 : ($candidate['capability_level'] === 'confirmed_item' ? 85 : 50);
            $score = round((
                $priceScore * $weights['price']
                + $qualityScore * $weights['quality']
                + $deliveryScore * $weights['delivery']
                + $returnScore * $weights['return']
                + $cooperationScore * $weights['cooperation']
            ) / $weightTotal, 2);
            $avgPrice = (float) ($stat?->avg_price ?? 0);
            $priceIncreaseRate = $avgPrice > 0 && $candidate['comparable_price']
                ? round(($candidate['comparable_price'] - $avgPrice) / $avgPrice, 4)
                : null;

            $candidate['recommendation_score'] = $candidate['auto_selectable'] ? $score : null;
            $candidate['score_details'] = [
                'price' => ['score' => $priceScore, 'weight' => $weights['price'], 'rank_basis' => $candidate['comparable_price']],
                'quality' => ['score' => $qualityScore, 'weight' => $weights['quality'], 'qualified_rate' => $stat?->qualified_rate],
                'delivery' => ['score' => $deliveryScore, 'weight' => $weights['delivery'], 'on_time_rate' => $stat?->on_time_rate],
                'return' => ['score' => $returnScore, 'weight' => $weights['return'], 'return_rate' => $stat?->return_rate],
                'cooperation' => ['score' => $cooperationScore, 'weight' => $weights['cooperation']],
            ];
            $candidate['price_abnormal'] = $priceIncreaseRate !== null && $priceIncreaseRate > $threshold;
            $candidate['price_increase_rate'] = $priceIncreaseRate;
            $candidate['recommendation_explanation'] = $candidate['auto_selectable']
                ? "综合评分 {$score}：价格 {$priceScore}、质量 {$qualityScore}、交付 {$deliveryScore}、退货 {$returnScore}、合作 {$cooperationScore}。"
                : $candidate['selection_note'];

            return $candidate;
        });
    }

    private function categoryLineage(?int $categoryId): array
    {
        $ids = [];
        $cursor = $categoryId;
        while ($cursor && !in_array($cursor, $ids, true)) {
            $ids[] = (int) $cursor;
            $cursor = ItemCategory::whereKey($cursor)->value('parent_id');
        }

        return $ids ?: [0];
    }
}
