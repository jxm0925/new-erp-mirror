<?php

namespace App\Services\Erp;

use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use JsonSerializable;

/** Inventory valuation is independent of sales prices, purchasing settlement and stock quantities. */
final class InventoryAmountVisibilityService
{
    public function canView(?Request $request = null): bool
    {
        $request ??= request();
        $cached = $request->attributes->get('erp_inventory_amount_visible');
        if (is_bool($cached)) return $cached;
        $auth = app(AuthContextService::class);
        $user = $request->attributes->get('erp_user') ?? $auth->currentUser($request);
        $allowed = $user && in_array('inventory.amount.view', $auth->permissionCodes($user), true);
        $request->attributes->set('erp_inventory_amount_visible', $allowed);
        return $allowed;
    }

    public function forResponse(mixed $payload, ?Request $request = null): mixed
    {
        $request ??= request();
        if ($this->canView($request)) return $payload;
        $warehouse = $request->is('api/v1/erp/inventory/warehouse-*');
        return $this->redact($payload, $request->is('api/v1/erp/inventory/*') && ! $warehouse,
            $request->is('api/v1/erp/purchase/*'), false, false, $warehouse || $request->is('api/v1/erp/sales/*'));
    }

    public function isCostField(string $key): bool
    {
        $name = $this->name($key);
        if (in_array($name, ['cost_method', 'cost_status', 'cost_status_label', 'cost_source', 'cost_source_type', 'cost_source_id',
            'cost_source_complete', 'cost_collection_complete', 'material_cost_collection_complete', 'cost_allocation_method'], true)
            || in_array($key, ['成本方法', '成本状态', '成本来源', '成本归集状态'], true)) return false;
        return (bool) preg_match('/(^|_)(cost|costs|profit|profits|margin|margins)(_|$)/', $name)
            || in_array($name, ['inventory_value', 'stock_value', 'last_purchase_price', 'recent_purchase_price'], true)
            || (bool) preg_match('/成本|最近采购价|库存金额|库存价值|毛利|利润/', $key);
    }

    public function isSensitiveField(string $key, bool $inventory = false): bool
    {
        return $this->sensitive($key, $inventory, false, false, false);
    }

    public function redact(mixed $payload, bool $inventory = false, bool $purchase = false, bool $procurement = false, bool $package = false, bool $sales = false): mixed
    {
        if ($payload instanceof DateTimeInterface) return $payload;
        if ($payload instanceof Arrayable) $payload = $payload->toArray();
        elseif ($payload instanceof JsonSerializable) $payload = $payload->jsonSerialize();
        $object = $payload instanceof \stdClass;
        if ($object) $payload = get_object_vars($payload);
        if (! is_array($payload)) return $payload;

        $replacement = ($payload['settlement_mode'] ?? null) === 'replacement_no_charge';
        $inventory = $inventory || $replacement || $this->inventoryRecord($payload);
        $procurement = $procurement || ($sales && ! $purchase && $this->procurementRecord($payload));
        $package = $package || array_key_exists('package_no', $payload) || array_key_exists('package_sequence', $payload);
        if ($this->hiddenFieldRow($payload, $inventory, $purchase, $procurement, $package, $sales)) return $object ? new \stdClass : [];
        $result = [];
        foreach ($payload as $key => $value) {
            $name = is_string($key) ? $this->name($key) : '';
            if ($name !== '' && $this->sensitive($key, $inventory, $purchase, $procurement, $package, $sales)) continue;
            $childInventory = $inventory || in_array($name, ['balance', 'balances', 'inventory_balance', 'inventory_balances',
                'inventory_transaction', 'inventory_transaction_items', 'material_physical', 'material_physicals', 'physicals',
                'physical_entries', 'holding', 'holdings', 'stocktake', 'stocktakes'], true);
            if ($replacement && $purchase && $name === 'order') $childInventory = false;
            $childProcurement = $procurement || ($sales && ! $purchase && (bool) preg_match('/(^|_)(purchase|procurement)(_|$)/', $name));
            $childPackage = $package || in_array($name, ['package', 'packages', 'shipment_package', 'shipment_packages'], true);
            $structured = is_object($value) ? get_object_vars($value) : $value;
            if (is_array($structured) && $this->hiddenFieldRow($structured, $childInventory, $purchase, $childProcurement, $childPackage, $sales)) continue;
            if (is_string($value) && $this->jsonField($name)) {
                $decoded = json_decode($value);
                if (json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || $decoded instanceof \stdClass)) {
                    $value = json_encode($this->redact($decoded, $childInventory, $purchase, $childProcurement, $childPackage, $sales),
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                }
                $result[$key] = $value;
                continue;
            }
            $result[$key] = $this->redact($value, $childInventory, $purchase, $childProcurement, $childPackage, $sales);
        }
        if ($object) return (object) $result;
        return array_is_list($payload) ? array_values($result) : $result;
    }

    private function sensitive(string $key, bool $inventory, bool $purchase, bool $procurement, bool $package, bool $sales = false): bool
    {
        $name = $this->name($key);
        // receipt_cost is also the historical purchase settlement amount, not only inventory valuation.
        if (! $inventory && ! $procurement && in_array($name, ['receipt_cost', 'other_purchase_cost_amount', 'other_purchase_cost_amount_snapshot'], true)) return false;
        if ($this->isCostField($key)) return true;
        if ($inventory && preg_match('/(^|_)(price|amount)(_|$)/', $name)) return true;
        if ($purchase || ! $sales) return false;
        if (preg_match('/^(actual_freight|carrier_fee|carrier_price|supplier_price|purchase_price|purchase_unit_price|purchase_amount|source_contract_amount)(_|$)/', $name)) return true;
        if ($name === 'freight_amount_snapshot') return true;
        if (in_array($name, ['procurement_by_currency', 'purchase_payments', 'purchase_payment_allocations', 'cash_purchase_allocations'], true)) return true;
        return ($procurement && (bool) preg_match('/(^|_)(amount|price|fee|freight|tax|rate)(_|$)/', $name))
            || ($package && (bool) preg_match('/(^|_)(freight|fee|price|amount)(_|$)/', $name));
    }

    private function hiddenFieldRow(array $row, bool $inventory, bool $purchase, bool $procurement, bool $package, bool $sales): bool
    {
        foreach (['semantic_key', 'field', 'field_key', 'field_name', 'field_path', 'path'] as $key) {
            if (isset($row[$key]) && is_string($row[$key]) && $this->sensitive($row[$key], $inventory, $purchase, $procurement, $package, $sales)) return true;
        }
        if (array_key_exists('before', $row) || array_key_exists('after', $row) || array_key_exists('value', $row)) {
            if (isset($row['key']) && is_string($row['key']) && $this->sensitive($row['key'], $inventory, $purchase, $procurement, $package, $sales)) return true;
            if (isset($row['label']) && is_string($row['label']) && $this->isCostField($row['label'])) return true;
        }
        return false;
    }

    private function inventoryRecord(array $row): bool
    {
        if (isset($row['kind']) && in_array($row['kind'], ['stocktake', 'inventory_stocktake', 'inventory_transfer', 'inventory_adjustment'], true)) return true;
        if (array_key_exists('quantity_on_hand', $row) || array_key_exists('average_unit_cost', $row)) return true;
        foreach (['business_type', 'document_type', 'source_type', 'target_type', 'object_code'] as $field) {
            if (isset($row[$field]) && is_string($row[$field]) && preg_match('/^(erp_)?(inventory_|material_(physical|holding|lot|movement))/', $row[$field])) return true;
        }
        return false;
    }

    private function procurementRecord(array $row): bool
    {
        foreach (['purchase_order_id', 'purchase_order_item_id', 'purchase_receipt_id', 'source_contract_amount'] as $field) if (array_key_exists($field, $row)) return true;
        foreach (['source_business_type', 'document_type', 'target_type', 'source_type'] as $field) {
            if (isset($row[$field]) && is_string($row[$field]) && str_starts_with($row[$field], 'purchase_')) return true;
        }
        return false;
    }

    private function jsonField(string $name): bool
    {
        return in_array($name, ['before', 'after', 'payload', 'data', 'diff', 'diffs', 'structured_diffs', 'legacy_payload', 'raw_data', 'normalized_data'], true)
            || str_ends_with($name, '_snapshot') || str_ends_with($name, '_payload');
    }

    private function name(string $name): string
    {
        $snake = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name);
        return strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $snake));
    }
}
