<?php

namespace App\Services\Erp;

use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/** Worker execution DTOs never disclose commercial amounts or performance rates. */
final class ProductionFinancialProjectionService
{
    public function redact(mixed $payload): mixed
    {
        if ($payload instanceof DateTimeInterface) return $payload->format('Y-m-d H:i:s');
        if ($payload instanceof Arrayable) $payload = $payload->toArray();
        elseif ($payload instanceof JsonSerializable) $payload = $payload->jsonSerialize();
        elseif (is_object($payload)) $payload = get_object_vars($payload);
        if (! is_array($payload)) return $payload;

        $result = [];
        foreach ($payload as $key => $value) {
            if (is_string($key) && $this->sensitive($key)) continue;
            // Frozen snapshots and command replay responses may contain JSON strings;
            // filtering only the outer DTO would leave their monetary facts readable.
            $decoded = is_string($value) ? json_decode($value, true) : null;
            $result[$key] = is_array($decoded)
                ? json_encode($this->redact($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                : $this->redact($value);
        }
        return $result;
    }

    /** Compatibility name for explicitly projected worker arrays; permissions do not bypass this boundary. */
    public function sanitize(array $payload, array $permissions = []): array
    {
        return $this->redact($payload);
    }

    private function sensitive(string $key): bool
    {
        $normalized = strtolower(preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key));
        return (bool) preg_match('/(^|_)(price|amount|cost|profit|margin|receivable|payable|freight|fee|commission|wage|salary)(_|$)/', $normalized)
            || (bool) preg_match('/(^|_)performance(_|$)/', $normalized)
            || in_array($normalized, ['basis_policy', 'basis_mode', 'basis_snapshot', 'tax_rate', 'discount_rate'], true)
            || (bool) preg_match('/金额|价格|单价|成本|计提|绩效|工资|运费/', $key);
    }
}
