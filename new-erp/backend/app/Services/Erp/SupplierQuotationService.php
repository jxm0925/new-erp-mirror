<?php

namespace App\Services\Erp;

use App\Models\Erp\{Item, ItemSupplierPrice, Supplier, SupplierCategoryCapability, SupplierItemRelation};
use App\Models\Erp\SupplierQuotationHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class SupplierQuotationService
{
    public function __construct(private readonly UnitConversionDomainService $conversions) {}

    public function effectiveQuoteQuery(
        int $itemId,
        float $quantity,
        ?int $supplierId = null
    ): Builder {
        return ItemSupplierPrice::query()
            ->with(['supplier', 'item.unit', 'unit'])
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

    public function saveQuote(array $data, ?int $operatorId): ItemSupplierPrice
    {
        return DB::transaction(function () use ($data, $operatorId) {
            $supplier = Supplier::lockForUpdate()->findOrFail($data['supplier_id']);
            $item = Item::lockForUpdate()->findOrFail($data['item_id']);
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
            abort_unless($scopeAllowed, 422, '供应商尚未获准供应该 Item 或其末级 Item 类目，不能维护报价。');
            $changeReason = $data['change_reason'] ?? null;
            $conversion = $this->conversions->purchaseConversion((int) $data['item_id'], (int) $data['unit_id'], null, true);
            $standardFactor = (float) $conversion->factor;
            $useSupplierFactor = (bool) ($data['use_supplier_factor'] ?? false);
            $supplierFactor = (float) ($data['supplier_conversion_factor'] ?? 0);
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
            $data['base_unit_price'] = $this->conversions->calculateBaseUnitPrice($data['price'], $finalFactor);
            unset($data['change_reason'], $data['use_supplier_factor'], $data['supplier_conversion_factor']);
            $quote = ItemSupplierPrice::where('supplier_id', $data['supplier_id'])
                ->where('item_id', $data['item_id'])
                ->lockForUpdate()
                ->first() ?: new ItemSupplierPrice();
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
                'quotation_snapshot' => $quote->fresh()->toArray(),
                'change_reason' => $changeReason ?: ($action === 'create' ? '新增供应商报价' : '更新供应商报价'),
                'operator_id' => $operatorId,
                'created_at' => now(),
            ]);

            return $quote->fresh(['supplier', 'item.unit', 'unit']);
        });
    }

    public function disableQuote(int $supplierId, int $quotationId, string $reason, ?int $operatorId): ItemSupplierPrice
    {
        return DB::transaction(function () use ($supplierId, $quotationId, $reason, $operatorId) {
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
                'quotation_snapshot' => $quote->fresh()->toArray(),
                'change_reason' => $reason,
                'operator_id' => $operatorId,
                'created_at' => now(),
            ]);

            return $quote->fresh(['supplier', 'item.unit', 'unit']);
        });
    }
}
