<?php

namespace App\Services\Erp;

/** Sales orders and warehouse adapters share the same amount visibility boundary. */
final class SalesAmountVisibilityService
{
    public function redact(array $payload): array
    {
        $redacted = [];
        foreach ($payload as $key => $value) {
            $name = is_string($key) ? strtolower($key) : '';
            if ($name !== '' && (preg_match('/(^|_)(amount|price|cost)(_|$)/', $name)
                || in_array($name, ['receipt_ratio', 'production_threshold_value'], true))) continue;
            $redacted[$key] = is_array($value) ? $this->redact($value) : $value;
        }
        return $redacted;
    }
}
