<?php

namespace App\Services\Erp;

use App\Models\Erp\Item;

/** Direct fulfilment needs warehouse facts, never a fabricated production output. */
final class ShipmentDirectSourcePolicy
{
    public const ORIGINS = ['direct_purchase_shipment', 'direct_inventory_shipment'];

    public function allowsShipmentOnlyRouting(Item $item): bool
    {
        return $item->isPurchaseFulfilledItem();
    }

    public function inventoryEvidence(array $line, array $reservation, array $balance, array $inbound, bool $purchaseFulfilled, bool $productionSource, ?array $serial = null): ?array
    {
        if (! $purchaseFulfilled || $productionSource || $inbound === []) return null;
        if ((int) ($line['inventory_reservation_id'] ?? 0) !== (int) ($reservation['id'] ?? 0)
            || (int) ($reservation['inventory_balance_id'] ?? 0) !== (int) ($balance['id'] ?? 0)
            || (int) ($reservation['source_order_line_id'] ?? 0) !== (int) ($line['sales_order_line_id'] ?? 0)
            || ($reservation['source_type'] ?? '') !== 'sales_order'
            || ! in_array($reservation['reservation_status'] ?? '', ['converted_to_shipment', 'consumed'], true)
            || (int) ($line['unit_id'] ?? 0) <= 0 || (int) $line['unit_id'] !== (int) ($balance['unit_id'] ?? 0)) return null;
        foreach (['item_id', 'warehouse_id', 'location_id', 'batch_no'] as $field) {
            if ((string) ($line[$field] ?? '') !== (string) ($balance[$field] ?? '')
                || (string) ($line[$field] ?? '') !== (string) ($reservation[$field] ?? '')) return null;
        }
        if (! $this->positive($line['base_qty'] ?? null)
            || bccomp((string) $line['base_qty'], (string) ($reservation['reserved_qty'] ?? '0'), 8) !== 0) return null;
        if ($serial && ((int) ($serial['item_id'] ?? 0) !== (int) $line['item_id']
            || (int) ($serial['inventory_balance_id'] ?? 0) !== (int) $balance['id']
            || ($serial['source_document_type'] ?? '') === 'production_output')) return null;

        $quantity = '0'; $facts = []; $purchase = true;
        foreach ($inbound as $fact) {
            if (! $this->positive($fact['change_qty'] ?? null) || ($fact['posting_status'] ?? '') !== 'posted') return null;
            foreach (['item_id', 'warehouse_id', 'location_id', 'batch_no', 'unit_id'] as $field) {
                if ((string) ($fact[$field] ?? '') !== (string) $line[$field]) return null;
            }
            $isPurchase = ($fact['source_type'] ?? '') === 'purchase_receipt'
                && in_array($fact['transaction_type'] ?? '', ['purchase_receipt_posting', 'purchase_receipt_quality_release'], true);
            $isAdjustment = ($fact['source_type'] ?? '') === 'inventory_adjustment' && ($fact['transaction_type'] ?? '') === 'manual_adjustment';
            if (! $isPurchase && ! $isAdjustment) return null;
            if ($serial && (($serial['source_document_type'] ?? '') !== $fact['source_type']
                || (int) ($serial['source_document_id'] ?? 0) !== (int) ($fact['source_id'] ?? 0))) continue;
            if ((int) ($fact['id'] ?? 0) <= 0 || (int) ($fact['transaction_id'] ?? 0) <= 0) return null;
            $facts[] = $fact; $quantity = bcadd($quantity, (string) $fact['change_qty'], 8); $purchase = $purchase && $isPurchase;
        }
        $required = $serial ? '1' : (string) $line['base_qty'];
        if ($facts === [] || bccomp($quantity, $required, 8) < 0) return null;
        return ['version' => 1, 'source_origin' => $purchase ? self::ORIGINS[0] : self::ORIGINS[1],
            'purchase_fulfilled_snapshot' => true, 'inventory_balance_id' => (int) $balance['id'],
            'inventory_reservation_id' => (int) $reservation['id'], 'item_id' => (int) $line['item_id'],
            'warehouse_id' => (int) $line['warehouse_id'], 'location_id' => (int) $line['location_id'],
            'batch_no' => (string) $line['batch_no'], 'unit_id' => (int) $line['unit_id'], 'inventory_serial_id' => $serial ? (int) $serial['id'] : null,
            'inbound_facts' => $facts];
    }

    public function hasDirectEvidence(array $source): bool
    {
        $evidence = $source['source_evidence'] ?? [];
        if (! (empty($source['output_record_id']) && in_array($source['source_origin'] ?? '', self::ORIGINS, true)
            && ($evidence['version'] ?? null) === 1 && ($evidence['purchase_fulfilled_snapshot'] ?? false) === true
            && ($evidence['source_origin'] ?? null) === $source['source_origin']
            && (int) ($evidence['inventory_balance_id'] ?? 0) > 0 && (int) ($evidence['inventory_reservation_id'] ?? 0) > 0
            && (int) ($source['inventory_serial_id'] ?? 0) === (int) ($evidence['inventory_serial_id'] ?? 0)
            && $this->positive($source['base_qty'] ?? null) && ! empty($evidence['inbound_facts']))) return false;
        $quantity = '0'; $ids = []; $purchase = true;
        foreach ($evidence['inbound_facts'] as $fact) {
            if (! is_array($fact) || (int) ($fact['id'] ?? 0) <= 0 || isset($ids[$fact['id']])
                || (int) ($fact['transaction_id'] ?? 0) <= 0 || ($fact['posting_status'] ?? '') !== 'posted'
                || ! $this->positive($fact['change_qty'] ?? null)) return false;
            foreach (['item_id', 'warehouse_id', 'location_id', 'batch_no', 'unit_id'] as $field) {
                if ((string) ($fact[$field] ?? '') !== (string) ($evidence[$field] ?? '')) return false;
            }
            $isPurchase = ($fact['source_type'] ?? '') === 'purchase_receipt'
                && in_array($fact['transaction_type'] ?? '', ['purchase_receipt_posting', 'purchase_receipt_quality_release'], true);
            if (! $isPurchase && (($fact['source_type'] ?? '') !== 'inventory_adjustment' || ($fact['transaction_type'] ?? '') !== 'manual_adjustment')) return false;
            $ids[$fact['id']] = true; $purchase = $purchase && $isPurchase; $quantity = bcadd($quantity, (string) $fact['change_qty'], 8);
        }
        return bccomp($quantity, (string) $source['base_qty'], 8) >= 0
            && $source['source_origin'] === ($purchase ? self::ORIGINS[0] : self::ORIGINS[1]);
    }

    public function isCompletedDirectSource(array $source): bool
    {
        return $this->hasDirectEvidence($source) && ($source['trace_status'] ?? '') === 'complete';
    }

    public function salesQuantitySlice(string $salesQty, string $baseQty, string $sliceBaseQty, string $allocatedSalesQty, bool $lastSlice): string
    {
        // A three-piece source sold as one set must remain exactly one set.
        // Put the decimal residual on its last source instead of truncating all SNs.
        return $lastSlice ? bcsub($salesQty, $allocatedSalesQty, 8)
            : bcmul(bcdiv($salesQty, $baseQty, 12), $sliceBaseQty, 8);
    }

    /** The financial boundary is actual outbound plus transport plus every selected packing node. */
    public function shipmentCompleted(array $line, array $shipment, ?array $outbound, array $route, array $contents, array $operations): bool
    {
        if (! in_array($shipment['shipment_status'] ?? '', ['shipped', 'completed'], true) || empty($shipment['shipped_at']) || ! $outbound
            || ($outbound['posting_status'] ?? '') !== 'posted' || ($outbound['transaction_type'] ?? '') !== 'sales_shipment_outbound'
            || ($outbound['source_type'] ?? '') !== 'sales_shipment' || (int) ($outbound['source_id'] ?? 0) !== (int) ($line['shipment_id'] ?? 0)
            || (int) ($outbound['source_item_id'] ?? 0) !== (int) ($line['id'] ?? 0) || ! $this->positive($line['base_qty'] ?? null)
            || bccomp((string) ($outbound['change_qty'] ?? '0'), bcsub('0', (string) $line['base_qty'], 8), 8) !== 0) return false;
        foreach (['item_id', 'warehouse_id', 'location_id', 'batch_no', 'unit_id'] as $field) {
            if ((string) ($outbound[$field] ?? '') !== (string) ($line[$field] ?? '')) return false;
        }
        $nodes = array_values(array_filter($route['operations'] ?? [], fn ($node) => ($node['execution_context'] ?? 'production') === 'shipment'));
        if ($nodes === [] || $contents === []) return false;
        $quantity = '0'; $contentIds = [];
        foreach ($contents as $content) {
            if (! $this->positive($content['base_qty'] ?? null) || isset($contentIds[$content['id'] ?? 0])
                || (int) ($content['shipment_line_id'] ?? 0) !== (int) $line['id']
                || (int) ($content['shipment_id'] ?? 0) !== (int) $line['shipment_id']) return false;
            $contentIds[$content['id']] = true; $quantity = bcadd($quantity, (string) $content['base_qty'], 8);
            $contentRoute = $this->selectedPackingRoute($line, $route, $content);
            if ($contentRoute === null) return false;
            $contentNodes = array_filter($contentRoute['operations'] ?? [], fn ($node) => ($node['execution_context'] ?? 'production') === 'shipment');
            $chain = array_filter($contentNodes, fn ($node) => (int) ($node['packaging_scheme_id'] ?? 0) === (int) ($content['packaging_scheme_id'] ?? 0));
            if ($chain === []) return false;
            foreach ($chain as $node) {
                $matches = array_filter($operations, function ($op) use ($line, $content, $node): bool {
                    $ids = is_array($op['packing_content_ids'] ?? null) ? $op['packing_content_ids'] : json_decode($op['packing_content_ids'] ?? '[]', true);
                    return (int) ($op['shipment_id'] ?? 0) === (int) $line['shipment_id']
                        && (int) ($op['package_id'] ?? 0) === (int) $content['package_id']
                        && (int) ($op['routing_operation_id'] ?? 0) === (int) $node['routing_operation_id']
                        && is_array($ids) && in_array((int) $content['id'], array_map('intval', $ids), true);
                });
                if (count($matches) !== 1) return false;
                $op = reset($matches);
                if (($op['status'] ?? '') !== 'COMPLETED' || ! $this->positive($op['planned_base_qty'] ?? null)
                    || bccomp((string) ($op['completed_base_qty'] ?? '0'), (string) $op['planned_base_qty'], 8) !== 0
                    || (($op['quality_mode_snapshot'] ?? 'none') !== 'none' && ($op['quality_result'] ?? null) !== 'passed')) return false;
            }
        }
        return bccomp($quantity, (string) $line['base_qty'], 8) === 0;
    }

    /** Only a saved, internally associated first-packing choice changes this content's tail. */
    private function selectedPackingRoute(array $line, array $original, array $content): ?array
    {
        $saved = $this->arrayValue($content['routing_snapshot'] ?? null);
        $choice = $saved['packing_route_selection'] ?? null;
        if ($choice === null) return $original;
        if (! is_array($choice) || (int) ($choice['shipment_line_id'] ?? 0) !== (int) ($line['id'] ?? 0)
            || (int) ($choice['shipment_id'] ?? 0) !== (int) ($line['shipment_id'] ?? 0)
            || (int) ($choice['item_id'] ?? 0) !== (int) ($line['item_id'] ?? 0)
            || (int) ($content['item_id'] ?? 0) !== (int) ($line['item_id'] ?? 0)
            || (int) ($saved['output_item_id'] ?? 0) !== (int) ($line['item_id'] ?? 0)
            || (int) ($choice['production_routing_id'] ?? 0) !== (int) ($saved['routing_id'] ?? 0)
            || (int) ($choice['expected_routing_version'] ?? 0) <= 0
            || (int) ($choice['selected_by_legacy_id'] ?? 0) <= 0 || empty($choice['selected_at']) || trim((string) ($choice['reason'] ?? '')) === ''
            || ($saved['snapshot_scope'] ?? '') !== 'shipment_tail'
            || ($choice['before_route'] ?? null) !== $this->routeIdentity($original)
            || ($choice['packing_route'] ?? null) !== $this->routeIdentity($saved)) return null;
        $source = $this->arrayValue($content['source_snapshot'] ?? null);
        $originalSource = $this->arrayValue($line['packing_source_snapshot'] ?? null);
        $hash = fn (array $value): string => hash('sha256', json_encode($this->canonical($value), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        if ($hash($source) !== $hash($originalSource) || ! hash_equals((string) ($choice['source_snapshot_hash'] ?? ''), $hash($source))) return null;
        return $saved;
    }

    private function arrayValue(mixed $value): array
    { return is_array($value) ? $value : (is_string($value) ? (json_decode($value, true) ?: []) : []); }
    private function routeIdentity(array $route): array
    { return ['id' => (int) ($route['routing_id'] ?? 0), 'routing_no' => $route['routing_no'] ?? null,
        'routing_name' => $route['routing_name'] ?? null, 'version' => (int) ($route['version'] ?? 0)]; }
    private function canonical(array $value): array
    {
        if (! array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as &$child) if (is_array($child)) $child = $this->canonical($child);
        return $value;
    }

    private function positive(mixed $quantity): bool
    {
        return preg_match('/^\d+(?:\.\d+)?$/D', (string) $quantity) === 1 && bccomp((string) $quantity, '0', 8) > 0;
    }
}
