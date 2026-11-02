<?php

namespace App\Services\Erp;

/** Sales orders and warehouse adapters share the same amount visibility boundary. */
final class SalesAmountVisibilityService
{
    public function redact(mixed $payload, bool $inventoryAmounts = false): mixed
    {
        if ($payload instanceof \DateTimeInterface) return $payload;
        if ($payload instanceof \Illuminate\Contracts\Support\Arrayable) $payload = $payload->toArray();
        elseif ($payload instanceof \JsonSerializable) $payload = $payload->jsonSerialize();
        $object = $payload instanceof \stdClass;
        if ($object) $payload = get_object_vars($payload);
        if (! is_array($payload)) return $payload;
        $redacted = [];
        foreach ($payload as $key => $value) {
            $name = is_string($key) ? strtolower($key) : '';
            if ($name !== '' && (preg_match('/(^|_)(amount|price|cost)(_|$)/', $name)
                || in_array($name, ['receipt_ratio', 'production_threshold_value'], true))
                && ! ($inventoryAmounts && app(InventoryAmountVisibilityService::class)->isCostField($key))) continue;
            $redacted[$key] = $this->redact($value, $inventoryAmounts);
        }
        return $object ? (object) $redacted : $redacted;
    }
}
