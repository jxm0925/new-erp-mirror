<?php

namespace App\Services\Erp;

use App\Models\Erp\Item;
use App\Models\Erp\Product;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesOrderAttachment;
use App\Models\Erp\SalesOrderLine;
use App\Models\Erp\Sku;
use Illuminate\Support\Str;

class SalesOrderLineService
{
    public function __construct(
        private readonly SkuItemMatcher $matcher,
        private readonly SalesOrderAttachmentService $attachments,
        private readonly UnitConversionDomainService $conversions,
    ) {
    }

    public function sync(
        SalesOrder $order,
        array $lines,
        array $deletedLineIds = [],
        ?string $draftToken = null,
        string $operator = 'system'
    ): void {
        $this->deleteExplicitLines($order, $deletedLineIds, $operator);

        foreach (array_values($lines) as $index => $line) {
            $existing = !empty($line['id'])
                ? SalesOrderLine::query()->where('sales_order_id', $order->id)->find($line['id'])
                : null;
            $data = $this->payload($order, $line, $index, $existing);

            if ($existing) {
                $existing->update($data);
                $saved = $existing->fresh();
            } else {
                $saved = SalesOrderLine::create($data);
            }

            $this->attachments->bindLineDraft(
                $order->id,
                $saved->id,
                $draftToken,
                $saved->line_uuid
            );
        }
    }

    private function deleteExplicitLines(SalesOrder $order, array $ids, string $operator): void
    {
        if (!$ids) return;

        $lines = SalesOrderLine::query()
            ->where('sales_order_id', $order->id)
            ->whereIn('id', $ids)
            ->where('line_status', 'open')
            ->get();

        foreach ($lines as $line) {
            SalesOrderAttachment::query()
                ->where('sales_order_line_id', $line->id)
                ->where('status', 'active')
                ->update(['status' => 'deleted', 'deleted_by' => $operator, 'deleted_at' => now()]);
            $line->delete();
        }
    }

    private function payload(
        SalesOrder $order,
        array $line,
        int $index,
        ?SalesOrderLine $existing
    ): array {
        $sku = Sku::with(['product', 'salesUnit'])->find($line['sku_id'] ?? null);
        abort_if(!$sku, 422, '订单行必须选择有效 SKU');
        abort_if($sku->status !== 'enabled' || !$sku->is_sale_item, 422, 'SKU 未启用或不可销售');
        abort_if(!$sku->sales_unit_id || !$sku->salesUnit || $sku->salesUnit->status !== 'enabled', 422, 'SKU 未维护有效销售单位');

        $product = Product::find($line['product_id'] ?? $sku->product_id);
        abort_if(!$product || $product->status !== 'enabled', 422, '订单行必须选择已启用 Product');
        abort_if((int) $sku->product_id !== (int) $product->id, 422, 'SKU 不属于当前 Product');

        $lineType = $this->lineType($sku, $product, $line['legacy_goods_type'] ?? null);
        $qty = round((float) $line['order_qty'], 4);
        $match = $this->matcher->match(['product_id' => $product->id, 'sku_id' => $sku->id]);
        if ($lineType === 'physical') {
            abort_if($match['match_status'] !== 'matched', 422, $match['block_reason'] ?: '实物 SKU 未维护唯一有效默认 Item');
        }
        $requirement = $this->conversions->calculateSalesRequirement($sku, $qty);
        $item = $requirement['item'];

        $isCustomized = (bool) ($line['is_customized'] ?? false);
        $isSpecialCustomized = (bool) ($line['is_special_customized'] ?? false);
        abort_if($isCustomized && !$sku->allow_customized, 422, '当前 SKU 不允许普通定制');
        abort_if($isSpecialCustomized && !$sku->allow_special_customized, 422, '当前 SKU 不允许特殊定制');

        $electric = trim((string) ($line['electric'] ?? '')) ?: null;
        $needPump = array_key_exists('need_pump', $line) && $line['need_pump'] !== null
            ? (bool) $line['need_pump']
            : null;
        $configuration = is_array($line['configuration_snapshot'] ?? null)
            ? $line['configuration_snapshot']
            : [];
        unset($configuration['electric'], $configuration['need_pump']);

        $price = round((float) ($line['unit_price'] ?? 0), 4);
        $discountRate = round((float) ($line['discount_rate'] ?? 1), 6);
        abort_if($discountRate < 0 || $discountRate > 1, 422, '订单行折扣率必须在 0 到 1 之间');
        $taxRate = round((float) ($line['tax_rate'] ?? 0), 6);
        if ($taxRate > 1) $taxRate = round($taxRate / 100, 6);
        abort_if($taxRate < 0 || $taxRate > 1, 422, '订单行税率不合法');
        $priceTaxMode = $line['price_tax_mode'] ?? 'tax_inclusive';
        abort_if(!in_array($priceTaxMode, ['tax_inclusive', 'tax_exclusive'], true), 422, '订单行含税方式不合法');
        $commercialAmount = round($qty * $price * $discountRate, 4);
        $amountExclTax = $priceTaxMode === 'tax_exclusive'
            ? $commercialAmount
            : round($commercialAmount / (1 + $taxRate), 4);
        $taxAmount = round($commercialAmount - $amountExclTax, 4);
        $amountInclTax = $priceTaxMode === 'tax_exclusive'
            ? round($commercialAmount + $taxAmount, 4)
            : $commercialAmount;
        $description = trim((string) (
            $line['customization_description']
            ?? data_get($configuration, 'special_custom_description')
            ?? ''
        ));

        return [
            'sales_order_id' => $order->id,
            'line_uuid' => $line['line_uuid'] ?? $existing?->line_uuid ?? (string) Str::uuid(),
            'line_no' => $index + 1,
            'sort_order' => (int) ($line['sort_order'] ?? $index),
            'legacy_order_product_id' => $line['legacy_order_product_id'] ?? $existing?->legacy_order_product_id,
            'legacy_goods_id' => $line['legacy_goods_id'] ?? $existing?->legacy_goods_id,
            'legacy_sku_id' => $line['legacy_sku_id'] ?? $existing?->legacy_sku_id,
            'product_id' => $product->id,
            'sku_id' => $sku->id,
            'item_id' => $item?->id,
            'item_base_unit_id' => $item?->unit_id,
            'item_base_unit_name_snapshot' => $item?->unit?->unit_name,
            'item_base_unit_code_snapshot' => $item?->unit?->unit_code,
            'fulfillment_factor_snapshot' => $requirement['factor'],
            'item_base_required_qty' => $requirement['base_qty'],
            'item_match_status' => $match['match_status'],
            'item_match_rule' => $match['match_rule'],
            'item_match_block_reason' => $match['block_reason'],
            'item_match_snapshot' => ['candidates' => $match['conflict_candidates']],
            'product_name' => $product->product_name,
            'sku_name' => $sku->sku_name,
            'item_name' => $item?->item_name,
            'legacy_goods_type' => $line['legacy_goods_type'] ?? null,
            'line_type' => $lineType,
            'order_qty' => $qty,
            'unit_id' => $sku->salesUnit->id,
            'unit_name_snapshot' => $sku->salesUnit->unit_name,
            'unit_code_snapshot' => $sku->salesUnit->unit_code,
            'unit_conversion_ratio_snapshot' => 1,
            'unit_price' => $price,
            'amount' => $amountInclTax,
            'fulfillment_method' => $line['fulfillment_method'] ?? $existing?->fulfillment_method ?? 'auto',
            'price_tax_mode' => $priceTaxMode,
            'discount_rate' => $discountRate,
            'tax_rate' => $taxRate,
            'amount_excl_tax' => $amountExclTax,
            'tax_amount' => $taxAmount,
            'amount_incl_tax' => $amountInclTax,
            'need_pump' => $needPump,
            'electric' => $electric,
            'is_customized' => $isCustomized || (bool) $sku->is_custom_sku,
            'is_special_customized' => $isSpecialCustomized,
            'customization_description' => $description ?: null,
            'configuration_snapshot' => [
                'need_pump' => $needPump,
                'electric' => $electric,
                'is_customized' => $isCustomized || (bool) $sku->is_custom_sku,
                'is_special_customized' => $isSpecialCustomized,
                'special_custom_description' => $description ?: null,
            ] + $configuration,
            'product_snapshot' => $product->only([
                'id', 'product_code', 'product_name', 'product_type', 'model', 'unit_id', 'image',
            ]),
            'sku_snapshot' => $sku->only([
                'id', 'product_id', 'sku_code', 'sku_name', 'spec_text', 'order_line_type',
                'fulfillment_type', 'production_policy', 'is_need_bom', 'is_need_production',
                'electric_mode', 'need_pump_mode', 'allow_customized', 'allow_special_customized',
                'special_custom_drawing_required', 'special_custom_agreement_required',
                'special_custom_description_required', 'delivery_inspection_required', 'image',
            ]),
            'commercial_snapshot' => [
                'unit_price' => $price,
                'price_tax_mode' => $priceTaxMode,
                'discount_rate' => $discountRate,
                'tax_rate' => $taxRate,
                'amount_excl_tax' => $amountExclTax,
                'tax_amount' => $taxAmount,
                'amount_incl_tax' => $amountInclTax,
                'fulfillment_method' => $line['fulfillment_method'] ?? $existing?->fulfillment_method ?? 'auto',
            ],
            'item_snapshot' => $item ? [
                ...$item->only(['id', 'item_code', 'item_name', 'item_type', 'spec', 'unit_id']),
                'base_unit_code' => $item->unit?->unit_code,
                'base_unit_name' => $item->unit?->unit_name,
                'base_unit_decimal_places' => $item->unit?->decimal_places,
                'fulfillment_factor' => $requirement['factor'],
                'item_base_required_qty' => $requirement['base_qty'],
            ] : null,
            'bom_snapshot' => null,
            'routing_snapshot' => null,
            'drawing_snapshot' => $line['drawing_snapshot'] ?? $existing?->drawing_snapshot,
            'technical_attachment_snapshot' => $line['technical_attachment_snapshot'] ?? $existing?->technical_attachment_snapshot,
            'inspection_snapshot' => ['delivery_inspection_required' => (bool) $sku->delivery_inspection_required],
            'image' => $sku->image ?: $product->image,
            'design' => null,
            'remark' => $line['remark'] ?? null,
        ];
    }

    private function lineType(Sku $sku, Product $product, ?string $legacyGoodsType): string
    {
        if ($legacyGoodsType === '6') return 'no_delivery';
        if ($legacyGoodsType === '5') return 'auxiliary';
        $type = $sku->order_line_type ?: $sku->fulfillment_type;
        if ($type === 'virtual') $type = 'no_delivery';
        if (in_array($type, ['service', 'no_delivery', 'fee', 'auxiliary'], true)) return $type;
        return $product->product_type === 'service' ? 'service' : 'physical';
    }
}
