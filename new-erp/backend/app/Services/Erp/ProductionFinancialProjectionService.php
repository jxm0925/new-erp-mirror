<?php

namespace App\Services\Erp;

use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/** Worker execution DTOs never disclose commercial amounts or performance rates. */
final class ProductionFinancialProjectionService
{
    public function redact(mixed $payload, bool $inventoryAmounts = false, bool $commercialAmounts = false): mixed
    {
        if ($payload instanceof DateTimeInterface) return $payload->format('Y-m-d H:i:s');
        if ($payload instanceof Arrayable) $payload = $payload->toArray();
        elseif ($payload instanceof JsonSerializable) $payload = $payload->jsonSerialize();
        $object = $payload instanceof \stdClass;
        if ($object) $payload = get_object_vars($payload);
        if (! is_array($payload)) return $payload;

        $result = [];
        foreach ($payload as $key => $value) {
            if (is_string($key) && $this->sensitive($key)
                && ! ($inventoryAmounts && app(InventoryAmountVisibilityService::class)->isCostField($key))
                && ! ($commercialAmounts && in_array($key, ['unit_price', 'unit_price_snapshot', 'amount', 'amount_snapshot',
                    'amount_excl_tax', 'amount_incl_tax', 'tax_amount', 'tax_amount_snapshot', 'freight_amount', 'total_amount',
                    'payable_amount', 'settlement_amount', 'line_amount', 'discount_amount', 'discount_rate', 'tax_rate',
                    'receivable_amount', 'final_receivable_amount', 'difference_amount'], true))) continue;
            // Frozen snapshots and command replay responses may contain JSON strings;
            // filtering only the outer DTO would leave their monetary facts readable.
            $decoded = is_string($value) ? json_decode($value) : null;
            $result[$key] = is_array($decoded) || $decoded instanceof \stdClass
                ? json_encode($this->redact($decoded, $inventoryAmounts, $commercialAmounts), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                : $this->redact($value, $inventoryAmounts, $commercialAmounts);
        }
        return $object ? (object) $result : $result;
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
