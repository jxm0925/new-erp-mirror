<?php

namespace App\Services\Erp;

use App\Models\Erp\SalesOrderLine;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesOrderFulfillment;
use App\Exceptions\Erp\WorkOrderDomainException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Quantity decisions shared by confirmation, purchasing backlog and shipment projection. */
final class SalesOrderFulfillmentPolicy
{
    public const TYPES = ['inventory', 'production', 'purchase', 'service', 'no_delivery'];

    public static function isPhysical(string $lineType): bool
    {
        return ! in_array($lineType, ['service', 'no_delivery', 'fee', 'auxiliary', 'deduction'], true);
    }

    /** 扣减项（红包、优惠券、抹零）：订单里按负数减钱，不发货、不生产、不占库存、不算数量。 */
    public const DEDUCTION = 'deduction';

    /** 单价规则：扣减项必须是负数（录入时填正数，系统自动按减钱存）；其余行可以填 0（赠送、免费服务），不能为负。 */
    public static function priceMessage(?string $lineType, $price, int|string|null $lineNo = null): ?string
    {
        $price = (float) $price;
        $row = $lineNo !== null && $lineNo !== '' ? "第 {$lineNo} 行" : '订单行';
        if ($lineType === self::DEDUCTION) return $price < -0.00000001 ? null : "{$row}是扣减项，扣减金额要大于 0";
        return $price >= 0 ? null : "{$row}单价不能小于 0；要减钱请用扣减项（如红包、优惠）";
    }

    /** 单价为 0 的自动算赠送（成本计入销售费用），其余按正常销售。 */
    public static function commercialRole(?string $lineType, $price): string
    {
        return $lineType !== self::DEDUCTION && abs((float) $price) < 0.00000001 ? 'gift' : 'sale';
    }

    public static function effectiveQuantity(float $ordered, float $cancelled): float
    {
        return round(max(0, $ordered - $cancelled), 8);
    }

    public static function requiresDelivery(array $lines): bool
    {
        foreach ($lines as $line) {
            if (self::isPhysical((string) ($line['line_type'] ?? 'physical'))
                && self::effectiveQuantity((float) ($line['order_qty'] ?? 0), (float) ($line['cancelled_qty'] ?? 0)) > 0.00000001) return true;
        }
        return false;
    }

    public static function confirmationRequirements(array $header, array $lines): array
    {
        $delivery = self::requiresDelivery($lines);
        $address = ($header['full_address'] ?? null) ?: data_get($header, 'shipping_address_snapshot.full_address') ?: data_get($header, 'shipping_address_snapshot.address');
        return [
            'requires_delivery' => $delivery,
            'customer_complete' => filled($header['customer_name'] ?? null) && filled($header['contact_phone'] ?? null),
            'shipping_complete' => ! $delivery || filled($address),
            'carrier_complete' => ! $delivery || filled(($header['default_carrier_id'] ?? null) ?: ($header['carrier_id'] ?? null)),
        ];
    }

    public static function assertConfirmationRequirements(SalesOrder $order): void
    {
        $requirements = self::confirmationRequirements($order->attributesToArray(), $order->lines->map(fn ($line) => $line->getAttributes())->all());
        if (! $requirements['customer_complete']) throw ValidationException::withMessages(['customer' => '订单确认前必须填写客户名称及联系电话。']);
        if (! $requirements['shipping_complete']) throw ValidationException::withMessages(['full_address' => '实物订单确认前必须填写收货地址。']);
        if (! $requirements['carrier_complete']) throw ValidationException::withMessages(['carrier_id' => '实物订单确认前必须选择默认承运方式。']);
    }

    public static function isPurchaseLine(SalesOrderLine $line): bool
    {
        if (! self::isPhysical((string) $line->line_type)) return false;
        $strategy = data_get($line->item_match_snapshot, 'fulfillment_strategy');
        // A confirmed order keeps the supply choice accepted with its Item identity.
        // Later master-data edits must not turn an existing purchasing backlog into production.
        if (in_array($strategy, ['purchase', 'production'], true)) return $strategy === 'purchase';
        return $line->item?->isPurchaseFulfilledItem() ?? false;
    }

    public static function split(float $required, float $available, bool $purchase): array
    {
        $inventory = round(min(max(0, $required), max(0, $available)), 8);
        $shortage = round(max(0, $required - $inventory), 8);
        return [
            'suggested_inventory_qty' => $inventory,
            'suggested_production_qty' => $purchase ? 0.0 : $shortage,
            'suggested_purchase_qty' => $purchase ? $shortage : 0.0,
        ];
    }

    public static function shipmentStatus(array $lines): string
    {
        $physical = array_values(array_filter($lines, fn (array $line): bool => self::isPhysical((string) ($line['line_type'] ?? 'physical'))
            && self::effectiveQuantity((float) $line['order_qty'], (float) ($line['cancelled_qty'] ?? 0)) > 0.00000001));
        if ($physical === []) return 'not_required';
        $anyShipped = false;
        $incomplete = false;
        foreach ($physical as $line) {
            $shipped = (float) ($line['shipped_qty'] ?? 0);
            $anyShipped = $anyShipped || $shipped > 0.00000001;
            if ($shipped + 0.00000001 < self::effectiveQuantity((float) $line['order_qty'], (float) ($line['cancelled_qty'] ?? 0))) {
                // Continue so a later shipped line can distinguish zero delivery from partial delivery.
                $incomplete = true;
            }
        }
        return $incomplete ? ($anyShipped ? 'partially_shipped' : 'not_shipped') : 'shipped';
    }

    public static function purchaseTail(float $currentSalesQty, float $coveredSalesQty, float $factor, ?float $currentBaseQty, array $snapshot, array $conversions): array
    {
        if (! is_finite($currentSalesQty) || ! is_finite($coveredSalesQty) || ! is_finite($factor)
            || $currentSalesQty <= 0 || $factor <= 0 || $coveredSalesQty <= 0 || $coveredSalesQty > $currentSalesQty + 0.00000001) {
            throw new WorkOrderDomainException('purchase_conversion_quantity_invalid', '外购缺口核销数量或单位因子无效。', 409);
        }
        if ($currentBaseQty !== null && (! is_finite($currentBaseQty) || abs($currentBaseQty - round($currentSalesQty * $factor, 8)) > 0.00000001)) {
            throw new WorkOrderDomainException('purchase_conversion_quantity_invalid', '外购待到货数量与冻结单位因子不一致，请先核对原确认事实。', 409);
        }
        $snapshot['original_purchase_quantity'] ??= ['sales_qty' => $currentSalesQty, 'item_base_qty' => $currentBaseQty, 'fulfillment_factor_snapshot' => $factor];
        $snapshot['inventory_conversions'] = array_merge((array) ($snapshot['inventory_conversions'] ?? []), $conversions);
        $unmet = round(max(0, $currentSalesQty - $coveredSalesQty), 8);
        if ($unmet <= 0.00000001) {
            return ['match_snapshot' => $snapshot, 'demand_status' => 'superseded', 'reservation_status' => 'converted_to_inventory'];
        }
        return ['match_snapshot' => $snapshot, 'sales_qty' => $unmet, 'item_base_qty' => round($unmet * $factor, 8), 'fulfillment_qty' => round($unmet * $factor, 8)];
    }

    public static function productionFulfillmentForDemand(Collection $fulfillments, int $demandId): ?SalesOrderFulfillment
    {
        $matched = $fulfillments->filter(fn ($row) => (int) data_get($row->match_snapshot, 'production_requirement_id') === $demandId);
        if ($matched->count() === 1) return $matched->first();
        if ($matched->isEmpty() && $fulfillments->count() === 1 && ! data_get($fulfillments->first()->match_snapshot, 'production_requirement_id')) {
            return $fulfillments->first();
        }
        if ($fulfillments->isNotEmpty()) {
            throw new WorkOrderDomainException('sales_production_fulfillment_ambiguous', '销售生产入库无法唯一定位对应确认批次，请先核对需求与履约关联，不能默认绑定最新批次。', 409);
        }
        return null;
    }

    public static function confirmationHash(array $decisions, ?string $remark, ?string $adjustmentReason, ?int $expectedVersion, bool $automatic): string
    {
        usort($decisions, fn (array $a, array $b): int => (int) $a['sales_order_line_id'] <=> (int) $b['sales_order_line_id']);
        $normalized = array_map(function (array $line): array {
            $result = ['sales_order_line_id' => (int) $line['sales_order_line_id']];
            foreach (array_merge(['confirm'], self::TYPES) as $type) {
                $result[$type.'_qty'] = number_format((float) ($line[$type.'_qty'] ?? 0), 8, '.', '');
            }
            return $result;
        }, $decisions);
        return hash('sha256', json_encode([
            'lines' => $normalized, 'remark' => $remark, 'adjustment_reason' => $adjustmentReason,
            'expected_version' => $expectedVersion, 'automatic' => $automatic,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
