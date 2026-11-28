<?php

namespace App\Services\Erp;

use App\Domain\Finance\Money;
use App\Models\Erp\{Item, ItemSupplierPrice, Supplier, SupplierCategoryCapability, SupplierItemRelation};
use App\Models\Erp\SupplierQuotationHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SupplierQuotationService
{
    public function __construct(private readonly UnitConversionDomainService $conversions) {}

    public function effectiveQuoteQuery(
        int $itemId,
        float $quantity,
        ?int $supplierId = null
    ): Builder {
        return ItemSupplierPrice::query()
            ->with(['supplier', 'item.unit', 'unit', 'latestPriceHistory'])
            ->where('item_id', $itemId)
            ->where('status', 'enabled')
            ->where('quote_status', 'approved')
            ->where('min_order_qty', '<=', $quantity)
            ->where(function (Builder $query) use ($quantity) {
                $query->whereNull('max_order_qty')->orWhere('max_order_qty', '>=', $quantity);
            })
            ->where(function (Builder $query) {
                $query->whereNull('valid_from')->orWhere('valid_from', '<=', now()->toDateString());
            })
            ->where(function (Builder $query) {
                $query->whereNull('valid_until')->orWhere('valid_until', '>=', now()->toDateString());
            })
            ->when($supplierId, fn (Builder $query) => $query->where('supplier_id', $supplierId));
    }

    public function updateQuotation(int $supplierId, int $quotationId, array $data, ?int $operatorId): ItemSupplierPrice
    {
        abort_if(isset($data['supplier_id']) && (int) $data['supplier_id'] !== $supplierId, 422, '不能改变报价的供应商。');
        $data['supplier_id'] = $supplierId;
        return $this->saveQuote($data, $operatorId, $quotationId);
    }

    public function saveQuote(array $data, ?int $operatorId, ?int $quotationId = null): ItemSupplierPrice
    {
        return DB::transaction(function () use ($data, $operatorId, $quotationId) {
            $supplier = Supplier::lockForUpdate()->findOrFail($data['supplier_id']);
            if ($quotationId !== null) {
                $itemId = ItemSupplierPrice::where('supplier_id', $supplier->id)->whereKey($quotationId)->value('item_id');
                abort_unless($itemId, 404);
                abort_if(isset($data['item_id']) && (int) $data['item_id'] !== (int) $itemId, 422, '不能改变报价的物料，请为新物料另建报价。');
                $data['item_id'] = (int) $itemId;
            }
            $item = Item::lockForUpdate()->findOrFail($data['item_id']);
            $quote = ItemSupplierPrice::where('supplier_id', $supplier->id)->where('item_id', $item->id)
                ->when($quotationId !== null, fn ($query) => $query->whereKey($quotationId))
                ->lockForUpdate()->first();
            abort_if($quotationId !== null && !$quote, 404);
            $quote ??= new ItemSupplierPrice();
            if ($quote->exists) {
                $data += [
                    'use_supplier_factor' => $quote->factor_source === 'supplier_specific',
                    'supplier_factor_reason' => $quote->supplier_factor_reason,
                    'tier_prices' => $quote->tier_prices,
                ];
                if ($quote->factor_source === 'supplier_specific') $data += ['supplier_conversion_factor' => $quote->final_conversion_factor];
            }
            if ($quotationId !== null) {
                // Read editable values only after locking; the history accessor preserves eight-place prices.
                $quote->load('latestPriceHistory');
                $data += array_intersect_key($quote->attributesToArray(), array_flip([
                    'unit_id', 'price', 'currency', 'tax_mode', 'tax_rate', 'lead_time_days', 'min_order_qty',
                    'max_order_qty', 'valid_from', 'valid_until', 'tier_prices', 'remark',
                    'status', 'quote_status', 'approved_by', 'approved_at', 'data_source',
                ]));
            }
            Validator::make($data, [
                'max_order_qty' => 'nullable|numeric|gte:min_order_qty',
                'valid_until' => 'nullable|date|after_or_equal:valid_from',
            ])->validate();
            $eligible = $supplier->status === 'enabled'
                && $supplier->approval_status === 'approved'
                && !$supplier->is_blacklisted
                && !$supplier->purchase_restricted
                && in_array($supplier->cooperation_status, [null, 'normal'], true)
                && in_array($supplier->quality_status, [null, 'normal'], true);
            abort_unless($eligible, 422, '供应商未启用、未通过准入或当前受采购/质量限制，不能维护有效报价。');
            $scopeAllowed = SupplierItemRelation::where('supplier_id', $supplier->id)
                    ->where('item_id', $item->id)->where('relation_status', 'active')->exists()
                || ($item->category_id && SupplierCategoryCapability::where('supplier_id', $supplier->id)
                    ->where('item_category_id', $item->category_id)->where('status', 'active')
                    ->where(fn ($query) => $query->whereNull('expired_at')->orWhere('expired_at', '>', now()))->exists());
            abort_unless($scopeAllowed, 422, '供应商尚未获准供应该物料或其末级 Item 类目，不能维护报价。');
            $changeReason = $data['change_reason'] ?? null;
            $conversion = $this->conversions->purchaseConversion((int) $data['item_id'], (int) $data['unit_id'], null, true);
            $standardFactor = (string) $conversion->factor;
            $useSupplierFactor = (bool) ($data['use_supplier_factor'] ?? false);
            $supplierFactor = $data['supplier_conversion_factor'] ?? 0;
            $supplierFactorReason = trim((string) ($data['supplier_factor_reason'] ?? ''));
            if ($useSupplierFactor && $supplierFactor <= 0) {
                abort(422, '供应商专用换算因子必须大于 0。');
            }
            if ($useSupplierFactor && $supplierFactorReason === '') {
                abort(422, '使用供应商专用换算因子时必须填写原因。');
            }
            $finalFactor = $useSupplierFactor ? $supplierFactor : $standardFactor;
            if ($finalFactor <= 0) abort(422, '最终换算因子必须大于 0。');
            $data['standard_conversion_factor'] = $standardFactor;
            $data['final_conversion_factor'] = $finalFactor;
            $data['factor_source'] = $useSupplierFactor ? 'supplier_specific' : 'item_standard';
            $data['supplier_factor_reason'] = $useSupplierFactor ? $supplierFactorReason : null;
            $price = $data['price'];
            $data['price'] = Money::normalize(is_float($price) ? sprintf('%.8F', $price) : (string) $price, 8);
            $data['base_unit_price'] = $this->conversions->calculateBaseUnitPrice($data['price'], $finalFactor);
            $finance = app(PurchaseFinancialFactService::class);
            $finance->assertUnitPrice($data['price']);
            $finance->assertUnitPrice($data['base_unit_price']);
            $data['tier_prices'] = $this->normalizeTiers($data['tier_prices'] ?? null, $finalFactor);
            unset($data['change_reason'], $data['use_supplier_factor'], $data['supplier_conversion_factor']);
            $action = $quote->exists ? 'update' : 'create';

            $quote->fill($data + [
                'status' => 'enabled',
                'quote_status' => 'approved',
                'approved_by' => $operatorId,
                'approved_at' => now(),
                'data_source' => 'manual',
            ])->save();

            SupplierQuotationHistory::create([
                'quotation_id' => $quote->id,
                'supplier_id' => $quote->supplier_id,
                'item_id' => $quote->item_id,
                'action' => $action,
                'quotation_snapshot' => array_replace($quote->fresh()->attributesToArray(), ['price' => $data['price']]),
                'change_reason' => $changeReason ?: ($action === 'create' ? '新增供应商报价' : '更新供应商报价'),
                'operator_id' => $operatorId,
                'created_at' => now(),
            ]);

            return $quote->fresh(['supplier', 'item.unit', 'unit', 'latestPriceHistory']);
        });
    }

    public function disableQuote(int $supplierId, int $quotationId, string $reason, ?int $operatorId): ItemSupplierPrice
    {
        return DB::transaction(function () use ($supplierId, $quotationId, $reason, $operatorId) {
            Supplier::whereKey($supplierId)->lockForUpdate()->firstOrFail();
            $itemId = ItemSupplierPrice::where('supplier_id', $supplierId)->whereKey($quotationId)->value('item_id');
            abort_unless($itemId, 404);
            Item::whereKey($itemId)->lockForUpdate()->firstOrFail();
            $quote = ItemSupplierPrice::where('supplier_id', $supplierId)
                ->whereKey($quotationId)
                ->lockForUpdate()
                ->firstOrFail();
            $quote->update(['status' => 'disabled', 'quote_status' => 'expired']);
            SupplierQuotationHistory::create([
                'quotation_id' => $quote->id,
                'supplier_id' => $quote->supplier_id,
                'item_id' => $quote->item_id,
                'action' => 'disable',
                'quotation_snapshot' => $quote->fresh(['latestPriceHistory', 'item'])->attributesToArray(),
                'change_reason' => $reason,
                'operator_id' => $operatorId,
                'created_at' => now(),
            ]);

            return $quote->fresh(['supplier', 'item.unit', 'unit', 'latestPriceHistory']);
        });
    }

    private function normalizeTiers(?array $tiers, string|int|float $factor): ?array
    {
        if ($tiers === null) return null;
        abort_unless(array_is_list($tiers), 422, '阶梯报价必须按起订数量排序后提交列表。');
        Validator::make(['tiers' => $tiers], [
            'tiers.*' => 'required|array:min_qty,max_qty,price,base_unit_price',
            'tiers.*.min_qty' => 'required|numeric|min:0|decimal:0,8',
            'tiers.*.max_qty' => 'nullable|numeric|min:0|decimal:0,8',
            'tiers.*.price' => 'required|numeric|gt:0|decimal:0,8|max:9999999999.99999999',
        ])->validate();
        $previousMin = null;
        $previousMax = null;
        $decimal = fn ($value) => Money::normalize(is_float($value) ? sprintf('%.8F', $value) : (string) $value, 8);
        $finance = app(PurchaseFinancialFactService::class);
        foreach ($tiers as &$tier) {
            $min = $decimal($tier['min_qty']);
            $max = isset($tier['max_qty']) ? $decimal($tier['max_qty']) : null;
            abort_if($max !== null && bccomp($max, $min, 8) < 0, 422, '阶梯报价的上限数量不能小于起订数量。');
            abort_if($previousMin !== null && (bccomp($min, $previousMin, 8) <= 0
                || ($previousMax !== null && bccomp($min, $previousMax, 8) <= 0)),
                422, '阶梯起订数量必须递增，数量区间不能重叠。');
            // An omitted upper bound ends at the next tier's threshold; only the final tier is open-ended.
            $tier = ['min_qty' => $min, ...(array_key_exists('max_qty', $tier) ? ['max_qty' => $max] : []),
                'price' => $decimal($tier['price'])];
            $tier['base_unit_price'] = $this->conversions->calculateBaseUnitPrice($tier['price'], $factor);
            $finance->assertUnitPrice($tier['price']);
            $finance->assertUnitPrice($tier['base_unit_price']);
            $previousMin = $min;
            $previousMax = $max;
        }
        unset($tier);
        return $tiers;
    }
}
