<?php

namespace Tests\Unit\Erp;

use App\Models\Erp\Item;
use App\Services\Erp\ShipmentDirectSourcePolicy;
use PHPUnit\Framework\TestCase;

class ShipmentDirectSourcePolicyTest extends TestCase
{
    private ShipmentDirectSourcePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new ShipmentDirectSourcePolicy;
    }

    public function test_purchased_product_can_use_shipment_only_route_without_a_fake_work_order(): void
    {
        $item = new Item(['management_scope' => 'factory', 'item_type' => 'finished_product', 'is_stock_item' => true,
            'manufacturing_strategy' => 'purchase', 'is_production_item' => true]);
        $this->assertTrue($this->policy->allowsShipmentOnlyRouting($item));
        $item->manufacturing_strategy = 'make';
        $this->assertFalse($this->policy->allowsShipmentOnlyRouting($item));
        $item->manufacturing_strategy = 'purchase'; $item->management_scope = 'office';
        $this->assertFalse($this->policy->allowsShipmentOnlyRouting($item));
        $item->management_scope = 'factory'; $item->item_type = 'service';
        $this->assertFalse($this->policy->allowsShipmentOnlyRouting($item));
    }

    public function test_real_purchase_receipt_and_exact_order_reservation_establish_direct_source(): void
    {
        [$line, $reservation, $balance, $inbound] = $this->facts();
        $evidence = $this->policy->inventoryEvidence($line, $reservation, $balance, $inbound, true, false);
        $this->assertSame('direct_purchase_shipment', $evidence['source_origin']);
        $source = $this->source($evidence);
        $this->assertTrue($this->policy->hasDirectEvidence($source));
        $this->assertFalse($this->policy->isCompletedDirectSource($source));
        $source['trace_status'] = 'complete';
        $this->assertTrue($this->policy->isCompletedDirectSource($source));
    }

    public function test_formally_adjusted_purchased_inventory_is_a_distinct_traceable_source(): void
    {
        [$line, $reservation, $balance, $inbound] = $this->facts();
        $inbound[0]['source_type'] = 'inventory_adjustment'; $inbound[0]['transaction_type'] = 'manual_adjustment';
        $evidence = $this->policy->inventoryEvidence($line, $reservation, $balance, $inbound, true, false);
        $this->assertSame('direct_inventory_shipment', $evidence['source_origin']);
        $this->assertTrue($this->policy->hasDirectEvidence($this->source($evidence)));
    }

    public function test_missing_production_output_cannot_be_relabelled_as_direct_stock(): void
    {
        [$line, $reservation, $balance, $inbound] = $this->facts();
        $this->assertNull($this->policy->inventoryEvidence($line, $reservation, $balance, $inbound, true, true));
        $this->assertNull($this->policy->inventoryEvidence($line, $reservation, $balance, $inbound, false, false));
        foreach (['production', 'legacy_stock', 'unresolved'] as $origin) {
            $this->assertFalse($this->policy->isCompletedDirectSource(['source_origin' => $origin, 'trace_status' => 'complete', 'base_qty' => '2']));
        }
    }

    public function test_missing_and_unposted_inbound_documents_are_not_complete_history(): void
    {
        [$line, $reservation, $balance, $inbound] = $this->facts();
        $this->assertNull($this->policy->inventoryEvidence($line, $reservation, $balance, [], true, false));
        $inbound[0]['posting_status'] = 'draft';
        $this->assertNull($this->policy->inventoryEvidence($line, $reservation, $balance, $inbound, true, false));
    }

    public function test_reservation_must_belong_to_the_same_line_batch_and_quantity(): void
    {
        [$line, $reservation, $balance, $inbound] = $this->facts();
        foreach (['source_order_line_id' => 8, 'inventory_balance_id' => 99, 'item_id' => 99,
            'warehouse_id' => 99, 'location_id' => 99, 'batch_no' => 'OTHER', 'reserved_qty' => '1', 'reservation_status' => 'released', 'source_type' => 'work_order'] as $key => $value) {
            $other = $reservation; $other[$key] = $value;
            $this->assertNull($this->policy->inventoryEvidence($line, $other, $balance, $inbound, true, false), $key);
        }
    }

    public function test_receipt_at_a_different_location_or_with_insufficient_quantity_is_not_a_source(): void
    {
        [$line, $reservation, $balance, $inbound] = $this->facts();
        $inbound[0]['location_id'] = 99;
        $this->assertNull($this->policy->inventoryEvidence($line, $reservation, $balance, $inbound, true, false));
        $inbound[0]['location_id'] = 4; $inbound[0]['change_qty'] = '1';
        $this->assertNull($this->policy->inventoryEvidence($line, $reservation, $balance, $inbound, true, false));
    }

    public function test_numbered_purchase_uses_its_actual_receipt_identity(): void
    {
        [$line, $reservation, $balance, $inbound] = $this->facts();
        $serial = ['id' => 15, 'item_id' => 2, 'inventory_balance_id' => 9, 'source_document_type' => 'purchase_receipt', 'source_document_id' => 20];
        $evidence = $this->policy->inventoryEvidence($line, $reservation, $balance, $inbound, true, false, $serial);
        $this->assertSame(15, $evidence['inventory_serial_id']);
        $source = $this->source($evidence); $source['base_qty'] = '1'; $source['inventory_serial_id'] = 15;
        $this->assertTrue($this->policy->hasDirectEvidence($source));
        $serial['source_document_id'] = 99;
        $this->assertNull($this->policy->inventoryEvidence($line, $reservation, $balance, $inbound, true, false, $serial));
        $serial['source_document_type'] = 'production_output';
        $this->assertNull($this->policy->inventoryEvidence($line, $reservation, $balance, $inbound, true, false, $serial));
    }

    public function test_frozen_direct_source_rejects_forged_origin_duplicate_receipts_and_missing_evidence(): void
    {
        [$line, $reservation, $balance, $inbound] = $this->facts();
        $evidence = $this->policy->inventoryEvidence($line, $reservation, $balance, $inbound, true, false);
        $source = $this->source($evidence); $source['trace_status'] = 'complete';
        $source['source_origin'] = 'direct_inventory_shipment';
        $this->assertFalse($this->policy->isCompletedDirectSource($source));
        $source['source_origin'] = 'direct_purchase_shipment';
        $source['source_evidence']['inbound_facts'][] = $inbound[0];
        $this->assertFalse($this->policy->isCompletedDirectSource($source));
        $source['source_evidence'] = [];
        $this->assertFalse($this->policy->isCompletedDirectSource($source));
    }

    public function test_production_id_always_requires_production_trace_even_with_purchase_evidence(): void
    {
        [$line, $reservation, $balance, $inbound] = $this->facts();
        $evidence = $this->policy->inventoryEvidence($line, $reservation, $balance, $inbound, true, false);
        $source = $this->source($evidence); $source['trace_status'] = 'complete'; $source['output_record_id'] = 32;
        $this->assertFalse($this->policy->isCompletedDirectSource($source));
    }

    public function test_direct_commission_requires_actual_outbound_transport_and_both_packing_nodes(): void
    {
        $facts = $this->completedShipment();
        $this->assertTrue($this->policy->shipmentCompleted(...$facts));
        $facts[1]['shipment_status'] = 'outbound_posted';
        $this->assertFalse($this->policy->shipmentCompleted(...$facts));
        $facts[1]['shipment_status'] = 'shipped'; $facts[1]['shipped_at'] = null;
        $this->assertFalse($this->policy->shipmentCompleted(...$facts));
        $facts = $this->completedShipment(); $facts[2] = null;
        $this->assertFalse($this->policy->shipmentCompleted(...$facts));
        $facts = $this->completedShipment(); array_pop($facts[5]);
        $this->assertFalse($this->policy->shipmentCompleted(...$facts));
    }

    public function test_serial_source_slices_keep_the_exact_sales_unit_total_and_allocate_the_residual_once(): void
    {
        $allocated = '0'; $slices = [];
        for ($index = 0; $index < 3; $index++) {
            $slice = $this->policy->salesQuantitySlice('1', '3', '1', $allocated, $index === 2);
            $slices[] = $slice; $allocated = bcadd($allocated, $slice, 8);
        }
        $this->assertSame(['0.33333333', '0.33333333', '0.33333334'], $slices);
        $this->assertSame('1.00000000', $allocated);
        $this->assertSame('0.66666667', $this->policy->salesQuantitySlice('1', '3', '2', $slices[0], true));
    }

    public function test_outbound_for_another_line_batch_or_quantity_cannot_unlock_commission(): void
    {
        foreach (['source_item_id' => 99, 'item_id' => 99, 'warehouse_id' => 99, 'location_id' => 99,
            'source_id' => 99, 'batch_no' => 'OTHER', 'change_qty' => '-1', 'posting_status' => 'draft'] as $key => $value) {
            $facts = $this->completedShipment(); $facts[2][$key] = $value;
            $this->assertFalse($this->policy->shipmentCompleted(...$facts), $key);
        }
    }

    public function test_partial_duplicate_or_wrong_package_contents_do_not_unlock_direct_commission(): void
    {
        $facts = $this->completedShipment(); $facts[4][0]['base_qty'] = '1';
        $this->assertFalse($this->policy->shipmentCompleted(...$facts));
        $facts = $this->completedShipment(); $facts[4][] = $facts[4][0];
        $this->assertFalse($this->policy->shipmentCompleted(...$facts));
        $facts = $this->completedShipment(); $facts[4][0]['packaging_scheme_id'] = 99;
        $this->assertFalse($this->policy->shipmentCompleted(...$facts));
        $facts = $this->completedShipment(); $facts[5][0]['package_id'] = 99;
        $this->assertFalse($this->policy->shipmentCompleted(...$facts));
    }

    public function test_unfinished_or_uninspected_packing_cannot_masquerade_as_direct_complete_source(): void
    {
        $facts = $this->completedShipment(); $facts[5][0]['status'] = 'WAIT_QUALITY';
        $this->assertFalse($this->policy->shipmentCompleted(...$facts));
        $facts = $this->completedShipment(); $facts[5][0]['completed_base_qty'] = '1';
        $this->assertFalse($this->policy->shipmentCompleted(...$facts));
        $facts = $this->completedShipment(); $facts[5][0]['quality_mode_snapshot'] = 'required';
        $this->assertFalse($this->policy->shipmentCompleted(...$facts));
        $facts[5][0]['quality_result'] = 'passed';
        $this->assertTrue($this->policy->shipmentCompleted(...$facts));
        $facts[5][] = $facts[5][0];
        $this->assertFalse($this->policy->shipmentCompleted(...$facts));
    }

    private function completedShipment(): array
    {
        $line = ['id' => 6, 'shipment_id' => 8, 'item_id' => 2, 'warehouse_id' => 3, 'location_id' => 4, 'batch_no' => 'B-1', 'unit_id' => 6, 'base_qty' => '2'];
        $shipment = ['shipment_status' => 'shipped', 'shipped_at' => '2026-10-14 12:00:00'];
        $outbound = ['source_type' => 'sales_shipment', 'source_id' => 8, 'source_item_id' => 6, 'item_id' => 2,
            'warehouse_id' => 3, 'location_id' => 4, 'batch_no' => 'B-1', 'unit_id' => 6, 'change_qty' => '-2',
            'posting_status' => 'posted', 'transaction_type' => 'sales_shipment_outbound'];
        $route = ['operations' => [['routing_operation_id' => 21, 'execution_context' => 'shipment', 'packaging_scheme_id' => 1],
            ['routing_operation_id' => 22, 'execution_context' => 'shipment', 'packaging_scheme_id' => 1]]];
        $contents = [['id' => 10, 'shipment_id' => 8, 'shipment_line_id' => 6, 'package_id' => 11, 'packaging_scheme_id' => 1, 'base_qty' => '2']];
        $operations = [];
        foreach ([21, 22] as $node) $operations[] = ['shipment_id' => 8, 'package_id' => 11, 'routing_operation_id' => $node,
            'packing_content_ids' => '[10]', 'status' => 'COMPLETED', 'planned_base_qty' => '2', 'completed_base_qty' => '2', 'quality_mode_snapshot' => 'none'];
        return [$line, $shipment, $outbound, $route, $contents, $operations];
    }

    private function facts(): array
    {
        $line = ['inventory_reservation_id' => 5, 'sales_order_line_id' => 7, 'item_id' => 2, 'warehouse_id' => 3,
            'location_id' => 4, 'batch_no' => 'B-1', 'unit_id' => 6, 'base_qty' => '2'];
        $reservation = ['id' => 5, 'source_order_line_id' => 7, 'inventory_balance_id' => 9, 'item_id' => 2,
            'warehouse_id' => 3, 'location_id' => 4, 'batch_no' => 'B-1', 'reserved_qty' => '2', 'reservation_status' => 'converted_to_shipment', 'source_type' => 'sales_order'];
        $balance = ['id' => 9, 'item_id' => 2, 'warehouse_id' => 3, 'location_id' => 4, 'batch_no' => 'B-1', 'unit_id' => 6];
        $inbound = [['id' => 11, 'transaction_id' => 12, 'item_id' => 2, 'warehouse_id' => 3, 'location_id' => 4,
            'batch_no' => 'B-1', 'unit_id' => 6, 'change_qty' => '2', 'source_type' => 'purchase_receipt', 'source_id' => 20,
            'transaction_type' => 'purchase_receipt_posting', 'posting_status' => 'posted']];
        return [$line, $reservation, $balance, $inbound];
    }

    private function source(array $evidence): array
    {
        return ['output_record_id' => null, 'source_origin' => $evidence['source_origin'], 'source_evidence' => $evidence,
            'base_qty' => '2', 'inventory_serial_id' => null, 'trace_status' => 'pending'];
    }
}
