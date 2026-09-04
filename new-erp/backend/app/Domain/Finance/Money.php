<?php

namespace App\Domain\Finance;

use InvalidArgumentException;

final class Money
{
    public const SCALE = 4;
    public const CALC_SCALE = 8;

    public static function normalize(string|int $amount): string
    {
        $value = trim((string) $amount);
        if (!preg_match('/^-?\d+(?:\.\d+)?$/', $value)) {
            throw new InvalidArgumentException('Invalid decimal money value.');
        }
        return self::round($value, self::SCALE);
    }

    public static function add(string|int $left, string|int $right): string
    {
        return bcadd(self::normalize($left), self::normalize($right), self::SCALE);
    }

    public static function sub(string|int $left, string|int $right): string
    {
        return bcsub(self::normalize($left), self::normalize($right), self::SCALE);
    }

    public static function compare(string|int $left, string|int $right): int
    {
        return bccomp(self::normalize($left), self::normalize($right), self::SCALE);
    }

    public static function ratio(string|int $part, string|int $whole): string
    {
        $whole = self::normalize($whole);
        if (self::compare($whole, '0') === 0) return '0.00000000';
        return bcdiv(self::normalize($part), $whole, self::CALC_SCALE);
    }

    public static function negate(string|int $amount): string
    {
        return bcmul(self::normalize($amount), '-1', self::SCALE);
    }

    public static function maxZero(string|int $amount): string
    {
        $amount = self::normalize($amount);
        return self::compare($amount, '0') < 0 ? '0.0000' : $amount;
    }

    private static function round(string $value, int $scale): string
    {
        $negative = str_starts_with($value, '-');
        $absolute = $negative ? substr($value, 1) : $value;
        $factor = '0.'.str_repeat('0', $scale).'5';
        $rounded = bcadd($absolute, $factor, $scale);
        return $negative && bccomp($rounded, '0', $scale) !== 0 ? '-'.$rounded : $rounded;
    }
}
