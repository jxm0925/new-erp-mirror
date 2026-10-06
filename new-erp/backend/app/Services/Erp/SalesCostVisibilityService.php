<?php

namespace App\Services\Erp;

use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/** Sales responses never disclose procurement or fulfillment cost, even to admins. */
final class SalesCostVisibilityService
{
    public function redact(mixed $payload): mixed
    {
        return $this->walk($payload, false, false);
    }

    /** Reapply current stored costs after sanitizing a sales edit under its row lock. */
    public function preserveStoredCosts(array $incoming, array $stored): array
    {
        return $this->restore($this->redact($incoming), $stored, false, false);
    }

    private function restore(array $incoming, array $stored, bool $procurement, bool $package): array
    {
        $procurement = $procurement || $this->isProcurementRecord($stored);
        $package = $package || $this->isPackageRecord($stored);
        foreach ($stored as $key => $value) {
            $name = is_string($key) ? $this->name($key) : '';
            if ($name !== '' && $this->sensitive($name, $procurement, $package)) {
                $incoming[$key] = $value;
                continue;
            }
            $childProcurement = $procurement || (bool) preg_match('/(^|_)(purchase|procurement)(_|$)/', $name);
            $childPackage = $package || in_array($name, ['package', 'packages', 'shipment_package', 'shipment_packages'], true);
            $decoded = is_string($value) && $this->jsonField($name) ? json_decode($value, true) : null;
            $storedChild = is_array($value) ? $value : (is_array($decoded) ? $decoded : null);
            if ($storedChild === null || $this->walk($storedChild, $childProcurement, $childPackage) === $storedChild) continue;
            if (! array_key_exists($key, $incoming)) {
                // An omitted hidden snapshot must not be replaced by a partial
                // cost-only object, nor may an old client erase its facts.
                $incoming[$key] = $value;
                continue;
            }
            if (array_is_list($storedChild)) {
                $incoming[$key] = $value; // Never move hidden package costs by a client-supplied array index.
                continue;
            }
            $current = $incoming[$key];
            if (is_string($current) && $this->jsonField($name)) $current = json_decode($current, true);
            $restored = $this->restore(is_array($current) ? $current : [], $storedChild, $childProcurement, $childPackage);
            $incoming[$key] = is_string($value) ? json_encode($restored, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : $restored;
        }
        return $incoming;
    }

    private function walk(mixed $value, bool $procurement, bool $package): mixed
    {
        $value = $this->structuredValue($value);
        if (! is_array($value)) return $value;

        $procurement = $procurement || $this->isProcurementRecord($value);
        $package = $package || $this->isPackageRecord($value);
        if ($this->sensitiveDiff($value, $procurement, $package)) return [];
        $list = array_is_list($value);
        $result = [];
        foreach ($value as $key => $child) {
            $name = is_string($key) ? $this->name($key) : '';
            if ($name !== '' && $this->sensitive($name, $procurement, $package)) continue;
            $childProcurement = $procurement || (bool) preg_match('/(^|_)(purchase|procurement)(_|$)/', $name);
            $childPackage = $package || in_array($name, ['package', 'packages', 'shipment_package', 'shipment_packages'], true);
            $serialized = $this->structuredValue($child);
            if (is_array($serialized) && $this->sensitiveDiff($serialized, $childProcurement, $childPackage)) continue;

            // Some legacy snapshots contain JSON inside a string rather than a
            // JSON-cast column. Parse only known structured fields; ordinary
            // remarks and audit content must retain their original meaning.
            if (is_string($serialized) && $this->jsonField($name)) {
                $decoded = json_decode($serialized, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    if ($this->sensitiveDiff($decoded, $childProcurement, $childPackage)) continue;
                    $serialized = json_encode($this->walk($decoded, $childProcurement, $childPackage), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                }
                $result[$key] = $serialized;
                continue;
            }
            $result[$key] = $this->walk($serialized, $childProcurement, $childPackage);
        }
        return $list ? array_values($result) : $result;
    }

    private function structuredValue(mixed $value): mixed
    {
        // Carbon is JsonSerializable, but dates are scalar business values.
        // Retain their objects so Eloquent/DB bindings choose the SQL format;
        // response()->json remains responsible for their HTTP representation.
        if ($value instanceof DateTimeInterface) return $value;
        if ($value instanceof Arrayable) return $value->toArray();
        if ($value instanceof JsonSerializable) return $value->jsonSerialize();
        return $value instanceof \stdClass ? (array) $value : $value;
    }

    private function sensitive(string $name, bool $procurement, bool $package): bool
    {
        if (preg_match('/(^|_)(cost|costs|profit|profits|margin|margins)(_|$)/', $name)) return true;
        if (preg_match('/^(actual_freight|carrier_fee|carrier_price|supplier_price|purchase_price|purchase_unit_price|purchase_amount|source_contract_amount)(_|$)/', $name)) return true;
        if ($name === 'freight_amount_snapshot') return true;
        if (preg_match('/(^|_)(inventory_value|stock_value)(_|$)/', $name)) return true;
        if (preg_match('/(^|_)(purchase|procurement)(_|$)/', $name) && preg_match('/(^|_)(amount|price|fee|freight|tax|rate)(_|$)/', $name)) return true;
        if (preg_match('/(^|_)(supplier|carrier)(_|$)/', $name) && preg_match('/(^|_)(amount|price|fee)(_|$)/', $name)) return true;
        if (preg_match('/(^|_)(package|packages)(_|$)/', $name) && preg_match('/(^|_)(freight|fee|amount)(_|$)/', $name)) return true;
        if (in_array($name, ['procurement_by_currency', 'purchase_payments', 'purchase_payment_allocations', 'cash_purchase_allocations'], true)) return true;
        // A sales order's freight_amount is a customer charge included in its
        // sales total; a package's field with the same name is carrier expense.
        if ($package && preg_match('/(^|_)(freight|fee|price|amount)(_|$)/', $name)) return true;
        if ($procurement && preg_match('/(^|_)(amount|price|fee|freight|tax|rate)(_|$)/', $name)) return true;
        return false;
    }

    private function sensitiveDiff(array $row, bool $procurement, bool $package): bool
    {
        foreach (['semantic_key', 'field', 'field_name', 'field_path', 'path'] as $field) {
            if (isset($row[$field]) && is_string($row[$field]) && $this->sensitive($this->name($row[$field]), $procurement, $package)) return true;
        }
        if (array_key_exists('before', $row) || array_key_exists('after', $row) || array_key_exists('value', $row)) {
            if (isset($row['key']) && is_string($row['key']) && $this->sensitive($this->name($row['key']), $procurement, $package)) return true;
            if (isset($row['label']) && is_string($row['label']) && preg_match('/成本|利润|毛利|采购价|采购金额|采购合同|实际运费|承运费/', $row['label'])) return true;
        }
        return false;
    }

    private function isProcurementRecord(array $row): bool
    {
        foreach (['purchase_order_id', 'purchase_order_item_id', 'purchase_receipt_id', 'source_contract_amount'] as $field) if (array_key_exists($field, $row)) return true;
        foreach (['source_business_type', 'document_type', 'target_type', 'source_type'] as $field) {
            if (isset($row[$field]) && is_string($row[$field]) && str_starts_with($row[$field], 'purchase_')) return true;
        }
        return false;
    }

    private function isPackageRecord(array $row): bool
    {
        return array_key_exists('package_no', $row) || array_key_exists('package_sequence', $row)
            || (array_key_exists('shipment_id', $row) && (array_key_exists('tracking_no', $row) || array_key_exists('package_type', $row)));
    }

    private function jsonField(string $name): bool
    {
        return in_array($name, ['before', 'after', 'payload', 'data', 'diff', 'diffs', 'structured_diffs', 'legacy_payload'], true)
            || str_ends_with($name, '_snapshot') || str_ends_with($name, '_payload');
    }

    private function name(string $name): string
    {
        $snake = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name);
        return strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $snake));
    }
}
