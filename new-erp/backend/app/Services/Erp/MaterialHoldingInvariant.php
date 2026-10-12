<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;

/** Value-only counterpart of cut_holding_authority_ck; never repairs stored facts. */
final class MaterialHoldingInvariant
{
    public static function attributes(array $attributes): array
    {
        $warehouse = ($attributes['position_type'] ?? null) === 'WAREHOUSE';
        $balance = $attributes['inventory_balance_id'] ?? null;
        $quantity = $attributes['quantity'] ?? null;
        $cost = $attributes['total_cost'] ?? null;
        $valid = isset($attributes['position_type']) && ($warehouse
            ? $balance !== null && $quantity === null && $cost === null
            : $balance === null && self::nonNegative($quantity) && self::nonNegative($cost));
        if (!$valid) {
            throw new WorkOrderDomainException('material_holding_authority_invalid',
                '仓库持有记录必须引用库存余额且不另存数量金额；非仓库持有记录必须独立保存非负数量和金额。');
        }

        return $attributes;
    }

    public static function changes(object $holding, array $changes): array
    {
        self::attributes((array) $holding);
        self::attributes(array_replace((array) $holding, $changes));

        return $changes;
    }

    /** Keep the existing quantity scale of 8 and cost scale of 4 without rounding via floats. */
    public static function debit(object $holding, string $quantity, string $cost): array
    {
        self::attributes((array) $holding);
        self::attributes(['position_type' => $holding->position_type,
            'inventory_balance_id' => $holding->inventory_balance_id ?? null,
            'quantity' => $quantity, 'total_cost' => $cost]);

        return self::changes($holding, [
            'quantity' => bcsub((string) $holding->quantity, $quantity, 8),
            'total_cost' => bcsub((string) $holding->total_cost, $cost, 4),
        ]);
    }

    private static function nonNegative(mixed $value): bool
    {
        // Existing callers may provide cast numeric values. Validate without converting or
        // normalizing them; decimal strings retain their full precision, including tiny negatives.
        if (is_int($value)) return $value >= 0;
        if (is_float($value)) return is_finite($value) && $value >= 0;
        if (!is_string($value) || !preg_match('/^[+-]?\d+(?:\.\d+)?$/D', $value)) return false;
        $decimal = strpos($value, '.');

        return bccomp($value, '0', $decimal === false ? 0 : strlen($value) - $decimal - 1) >= 0;
    }
}
