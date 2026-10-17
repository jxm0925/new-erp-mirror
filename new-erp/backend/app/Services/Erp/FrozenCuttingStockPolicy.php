<?php

namespace App\Services\Erp;

/** Geometry comes from the published requirement, never Item descriptions or guessed gauges. */
final class FrozenCuttingStockPolicy
{
    public static function isTotalLength(array $snapshot): bool
    {
        return ($snapshot['quantity_calculation']['quantity_basis'] ?? $snapshot['quantity_basis'] ?? null) === 'total_length';
    }

    public static function totalLength(array $snapshot): ?string
    {
        return self::positiveDecimal($snapshot['quantity_calculation']['total_length_mm'] ?? $snapshot['total_length_mm'] ?? null);
    }

    public static function requiredTotalLength(array $snapshot): ?string
    {
        return self::positiveDecimal($snapshot['required_total_length_mm'] ?? null);
    }

    public static function requirement(array $snapshot, mixed $legacyLength, string $itemMode): array
    {
        $mode = $snapshot['quantity_calculation']['mode'] ?? (isset($snapshot['width_mm']) || isset($snapshot['dimensions']['width_mm']) ? 'sheet'
            : (isset($snapshot['length_mm']) || isset($snapshot['dimensions']['length_mm']) ? 'length' : $itemMode));
        if (isset($snapshot['quantity_calculation']['mode']) && ! in_array($mode, ['length', 'sheet'], true)) return ['mode' => $mode, 'valid' => false];
        if (! in_array($mode, ['length', 'sheet'], true)) return ['mode' => 'none', 'valid' => true];
        if (self::isTotalLength($snapshot)) {
            $total = self::totalLength($snapshot);
            return ['mode' => $mode, 'quantity_basis' => 'total_length', 'valid' => $mode === 'length' && $total !== null,
                'total_length_mm' => $total, 'length_mm' => null, 'width_mm' => null, 'thickness_mm' => null, 'allow_rotation' => false];
        }
        $dimensions = $snapshot['dimensions'] ?? [];
        $length = self::positiveDecimal($snapshot['length_mm'] ?? $dimensions['length_mm'] ?? $legacyLength);
        $width = $mode === 'sheet' ? self::positiveDecimal($snapshot['width_mm'] ?? $dimensions['width_mm'] ?? null) : null;
        $thickness = $mode === 'sheet' ? self::positiveDecimal($snapshot['thickness_mm'] ?? $dimensions['thickness_mm'] ?? null) : null;
        return ['mode' => $mode, 'valid' => $length !== null && ($mode !== 'sheet' || ($width !== null && $thickness !== null)),
            'length_mm' => $length, 'width_mm' => $width, 'thickness_mm' => $thickness,
            'allow_rotation' => (bool) ($snapshot['allow_rotation'] ?? $dimensions['allow_rotation'] ?? false)];
    }

    public static function allows(array $requirement, array $source): bool
    {
        if (! ($requirement['valid'] ?? false)) return false;
        if ($requirement['mode'] === 'none') return true;
        if ($requirement['mode'] === 'sheet' && ($source['shape'] ?? null) === 'IRREGULAR') {
            $thickness = self::positiveDecimal($source['nominal_thickness_mm'] ?? $source['thickness_mm'] ?? null);
            $area = self::area($source);
            return $thickness !== null && $area !== null && bccomp($requirement['thickness_mm'], $thickness, 8) === 0
                && bccomp(bcmul($requirement['length_mm'], $requirement['width_mm'], 8), $area, 8) <= 0;
        }
        $length = self::positiveDecimal($source['length_mm'] ?? null);
        if ($length === null) return false;
        if (($requirement['quantity_basis'] ?? null) === 'total_length') return $requirement['mode'] === 'length';
        if ($requirement['mode'] === 'length') return bccomp($requirement['length_mm'], $length, 8) <= 0;
        $width = self::positiveDecimal($source['width_mm'] ?? null);
        // Explicit nominal thickness is authoritative; a bad value cannot silently fall back.
        $thickness = self::positiveDecimal($source['nominal_thickness_mm'] ?? $source['thickness_mm'] ?? null);
        if ($width === null || $thickness === null || bccomp($requirement['thickness_mm'], $thickness, 8) !== 0) return false;
        return (bccomp($requirement['length_mm'], $length, 8) <= 0 && bccomp($requirement['width_mm'], $width, 8) <= 0)
            || ($requirement['allow_rotation'] && bccomp($requirement['length_mm'], $width, 8) <= 0 && bccomp($requirement['width_mm'], $length, 8) <= 0);
    }

    public static function positiveDecimal(mixed $value): ?string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';
        return preg_match('/^\d{1,10}(?:\.\d{1,8})?$/D', $text) && bccomp($text, '0', 8) > 0 ? bcadd($text, '0', 8) : null;
    }

    public static function sameDimensions(array $frozen, array $actual): bool
    {
        if (($frozen['shape'] ?? 'RECTANGLE') !== ($actual['shape'] ?? 'RECTANGLE')) return false;
        if (($frozen['shape'] ?? null) === 'IRREGULAR') {
            $left = self::area($frozen); $right = self::area($actual);
            if ($left === null || $right === null || bccomp($left, $right, 8) !== 0) return false;
        }
        foreach (['length_mm', 'width_mm', 'thickness_mm', 'nominal_thickness_mm'] as $key) {
            if (! isset($frozen[$key]) && ! isset($actual[$key])) continue;
            $left = self::positiveDecimal($frozen[$key] ?? null); $right = self::positiveDecimal($actual[$key] ?? null);
            if ($left === null || $right === null || bccomp($left, $right, 8) !== 0) return false;
        }
        return true;
    }

    /** A rectangle's measured edges take precedence over any supplied area. */
    public static function area(array $dimensions): ?string
    {
        if (($dimensions['shape'] ?? null) === 'IRREGULAR') return self::positiveDecimal($dimensions['area_mm2'] ?? null);
        $length = self::positiveDecimal($dimensions['length_mm'] ?? null);
        $width = self::positiveDecimal($dimensions['width_mm'] ?? null);
        return $length !== null && $width !== null ? bcmul($length, $width, 8) : null;
    }
}
