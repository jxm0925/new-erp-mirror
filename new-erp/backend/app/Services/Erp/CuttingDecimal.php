<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;

/** Decimal quantities and authoritative total amounts; never unit-price back multiplication. */
final class CuttingDecimal
{
    public static function value(mixed $value, int $scale = 8, bool $zero = false): string
    {
        if (! is_string($value) && ! is_int($value)) self::fail('数量和金额请使用十进制字符串，不能使用浮点数。');
        if (! preg_match('/^\d{1,10}(?:\.\d{1,'.$scale.'})?$/', (string) $value)) self::fail('数量或金额精度不合法。');
        if (bccomp((string) $value, '0', $scale) < ($zero ? 0 : 1)) self::fail('数量或金额必须'.($zero ? '非负' : '大于零').'。');
        return bcadd((string) $value, '0', $scale);
    }

    public static function share(string $remainingAmount, string $remainingQty, string $qty): string
    {
        if (bccomp($qty, '0', 8) <= 0 || bccomp($qty, $remainingQty, 8) > 0) self::fail('分配数量超过剩余数量。');
        // The final share takes the entire remainder, including the rounding tail.
        return bccomp($qty, $remainingQty, 8) === 0
            ? $remainingAmount : bcdiv(bcmul($remainingAmount, $qty, 12), $remainingQty, 4);
    }

    private static function fail(string $message): never
    {
        throw new WorkOrderDomainException('decimal_invalid', $message, 422);
    }
}
