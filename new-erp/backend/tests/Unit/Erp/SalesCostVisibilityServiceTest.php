<?php

namespace Tests\Unit\Erp;

use App\Models\Erp\SalesOrder;
use App\Services\Erp\SalesCostVisibilityService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

class SalesCostVisibilityServiceTest extends TestCase
{
    public function test_cost_scrubbing_preserves_date_objects_for_database_bindings(): void
    {
        $orderedAt = Carbon::create(2026, 10, 5, 14, 20, 36, 'Asia/Shanghai')->setMicrosecond(228144);
        $immutable = CarbonImmutable::create(2026, 10, 6, 8, 0, 0, 'Asia/Shanghai');
        $native = new \DateTimeImmutable('2026-10-07 09:30:00', new \DateTimeZone('Asia/Shanghai'));
        $payload = ['channel_ordered_at' => $orderedAt, 'carrier_fee' => 999,
            'shipping_snapshot' => ['scheduled_at' => $immutable, 'actual_freight_amount' => 999],
            'other_snapshot' => ['received_at' => $native]];
        $service = new SalesCostVisibilityService();
        $redacted = $service->redact($payload);
        $preserved = $service->preserveStoredCosts($payload, ['carrier_fee' => 11,
            'shipping_snapshot' => ['actual_freight_amount' => 12]]);
        foreach ([$redacted, $preserved] as $result) {
            $this->assertSame($orderedAt, $result['channel_ordered_at']);
            $this->assertSame($immutable, $result['shipping_snapshot']['scheduled_at']);
            $this->assertSame($native, $result['other_snapshot']['received_at']);
            $bindings = (new SalesOrder())->getConnection()->prepareBindings([$result['channel_ordered_at']]);
            $this->assertSame(['2026-10-05 14:20:36'], $bindings);
        }
        $this->assertArrayNotHasKey('carrier_fee', $redacted);
        $this->assertArrayNotHasKey('actual_freight_amount', $redacted['shipping_snapshot']);
        $this->assertSame(11, $preserved['carrier_fee']);
        $this->assertSame(12, $preserved['shipping_snapshot']['actual_freight_amount']);
    }

    public function test_nested_models_snapshots_and_paginators_hide_cost_without_hiding_customer_prices(): void
    {
        $order = new SalesOrder(['total_amount' => 1000, 'freight_amount' => 23, 'carrier_fee' => 11, 'cost_amount' => 100,
            'shipping_snapshot' => ['tracking_no' => 'TRACK', 'actual_freight' => 12]]);
        $order->setRelation('lines', collect([['unit_price' => 100, 'amount' => 1000, 'item' => ['last_purchase_price' => 10, 'inventory_value' => 90],
            'costAllocations' => [['amount' => 11]], 'drawing_snapshot' => ['supplier_price' => 12, 'name' => '图纸']]]));
        $order->setRelation('packages', collect([['tracking_no' => 'TRACK', 'freight_amount' => 8]]));
        $data = (new SalesCostVisibilityService())->redact(new LengthAwarePaginator([$order], 1, 20));
        $row = $data['data'][0];
        $this->assertSame(1000, $row['total_amount']);
        $this->assertSame(23, $row['freight_amount']);
        $this->assertArrayNotHasKey('carrier_fee', $row);
        $this->assertArrayNotHasKey('cost_amount', $row);
        $this->assertSame(['tracking_no' => 'TRACK'], $row['shipping_snapshot']);
        $this->assertSame(['tracking_no' => 'TRACK'], $row['packages'][0]);
        $this->assertSame(100, $row['lines'][0]['unit_price']);
        $this->assertSame(1000, $row['lines'][0]['amount']);
        $this->assertSame([], $row['lines'][0]['item']);
        $this->assertArrayNotHasKey('costAllocations', $row['lines'][0]);
        $this->assertSame(['name' => '图纸'], $row['lines'][0]['drawing_snapshot']);
        $this->assertSame(11, $order->carrier_fee);
    }

    public function test_structured_cost_diffs_and_json_encoded_snapshots_cannot_reveal_values(): void
    {
        $data = (new SalesCostVisibilityService())->redact([
            'content' => '备注中保留成本二字，未记录金额',
            'legacy_payload' => json_encode(['freight_amount_snapshot' => 777, 'unit_price' => 100, 'actualFreightAmount' => 777]),
            'structured_diffs' => [
                (object) ['semantic_key' => 'carrier_fee', 'before' => 777, 'after' => 778],
                ['field_path' => 'purchase_order.unit_price', 'before' => 777, 'after' => 778],
                ['key' => 'unit_price', 'label' => '销售单价', 'before' => 100, 'after' => 110],
            ],
            'payload' => json_encode(['field' => 'cost_amount', 'before' => 777, 'after' => 778]),
            'purchase_links' => [['purchase_order_id' => 12, 'amount' => 777, 'source_snapshot' => ['unit_price' => 778, 'source_purchase_qty' => 3]]],
        ]);
        $this->assertSame('备注中保留成本二字，未记录金额', $data['content']);
        $this->assertSame(['unit_price' => 100], json_decode($data['legacy_payload'], true));
        $this->assertSame([['key' => 'unit_price', 'label' => '销售单价', 'before' => 100, 'after' => 110]], $data['structured_diffs']);
        $this->assertArrayNotHasKey('payload', $data);
        $this->assertSame([['purchase_order_id' => 12, 'source_snapshot' => ['source_purchase_qty' => 3]]], $data['purchase_links']);
        $this->assertStringNotContainsString('777', json_encode($data));
        $this->assertStringNotContainsString('778', json_encode($data));
    }

    public function test_sales_edit_keeps_current_stored_costs_and_rejects_new_cost_values(): void
    {
        $stored = ['carrier_fee' => 11, 'cost_amount' => 100, 'freight_amount' => 23,
            'shipping_snapshot' => ['tracking_no' => 'OLD', 'actual_freight_amount' => 12],
            'logistics_snapshot' => ['packages' => [['tracking_no' => 'A', 'freight_amount' => 8]]],
            'legacy_payload' => json_encode(['name' => '原始数据', 'cost_amount' => 13])];
        $edited = (new SalesCostVisibilityService())->preserveStoredCosts([
            'carrier_fee' => 999, 'freight_amount' => 30, 'new_cost' => 999,
            'shipping_snapshot' => ['tracking_no' => 'NEW', 'actual_freight_amount' => 999],
            'logistics_snapshot' => ['packages' => []],
        ], $stored);
        $this->assertSame(11, $edited['carrier_fee']);
        $this->assertSame(100, $edited['cost_amount']);
        $this->assertSame(30, $edited['freight_amount']);
        $this->assertArrayNotHasKey('new_cost', $edited);
        $this->assertSame(['tracking_no' => 'NEW', 'actual_freight_amount' => 12], $edited['shipping_snapshot']);
        $this->assertSame($stored['logistics_snapshot'], $edited['logistics_snapshot']);
        $this->assertSame($stored['legacy_payload'], $edited['legacy_payload']);
    }
}
