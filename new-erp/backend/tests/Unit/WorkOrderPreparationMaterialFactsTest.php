<?php

namespace Tests\Unit;

use App\Services\Erp\WorkOrderPreparationMaterialService;
use PHPUnit\Framework\TestCase;

/** No Laravel application, credentials, migrations or database connection. */
class WorkOrderPreparationMaterialFactsTest extends TestCase
{
    public function test_partial_purchase_then_receipt_then_issue_preserves_remaining_ten(): void
    {
        $service = new WorkOrderPreparationMaterialService;
        foreach ([['0', '0', '10', '0'], ['0', '10', '10', '10'], ['10', '0', '10', '10']] as [$secured, $available, $committed, $received]) {
            $this->assertSame('10.00000000', $service->netQuantities('20', $secured, $available, $committed, $received)['procureable_qty']);
        }
    }

    public function test_incoming_quantity_and_posted_stock_are_never_counted_twice(): void
    {
        $service = new WorkOrderPreparationMaterialService;
        $this->assertSame('0.00000000', $service->netQuantities('20', '0', '10', '10', '0')['procureable_qty']);
        $this->assertSame('0.00000000', $service->netQuantities('20', '0', '20', '10', '10')['procureable_qty']);
        $this->assertSame('0.00000000', $service->netQuantities('20', '10', '0', '20', '10')['procureable_qty']);
        $this->assertSame('10.00000000', $service->netQuantities('20', '10', '0', '20', '20')['procureable_qty']);
    }

    public function test_net_quantities_preserve_decimal_precision_and_clamp_coverage(): void
    {
        $service = new WorkOrderPreparationMaterialService;
        $this->assertSame('0.00000001', $service->netQuantities('9999999999.00000002', '9999999999.00000001', '0', '0', '0')['procureable_qty']);
        $this->assertSame('0.00000000', $service->netQuantities('5', '9', '20', '4', '4')['procureable_qty']);
    }

    public function test_resolved_occurrences_of_one_bom_template_have_distinct_stable_identity(): void
    {
        $service = new WorkOrderPreparationMaterialService;
        $a = ['bom_item_id' => 1, 'line_no' => 10, 'component_item_id' => 5, 'cut_length_mm_snapshot' => 1000];
        $b = [...$a, 'line_no' => 20, 'cut_length_mm_snapshot' => 1500];
        $this->assertNotSame($service->materialIdentity($a), $service->materialIdentity($b));
        $this->assertSame($service->materialIdentity($a), $service->materialIdentity([...$a, 'required_qty' => '20.00000000']));
        $this->assertSame(hash('sha256', '{"bom_item_id":1,"line_no":10,"component_item_id":5}'), $service->materialIdentity($a));
    }

    public function test_frozen_output_plan_does_not_change_input_but_route_and_rule_edits_do(): void
    {
        $route = ['version' => 1, 'operations' => [['routing_operation_id' => 7, 'sequence' => 10, 'material_supply_rules' => [['required_qty_ratio' => 1]]]]];
        $before = $this->routeHash($route);
        $this->assertSame($before, $this->routeHash([...$route, 'output_plan' => ['frozen_at' => '2026-10-12', 'rows' => []]]));
        $route['operations'][0]['sequence'] = 20;
        $this->assertNotSame($before, $this->routeHash($route));
        $route['operations'][0]['sequence'] = 10;
        $route['operations'][0]['material_supply_rules'][0]['required_qty_ratio'] = 0.5;
        $this->assertNotSame($before, $this->routeHash($route));
    }

    public function test_json_object_key_order_is_irrelevant_but_array_order_is_not(): void
    {
        $a = ['routing' => ['version' => 1, 'operations' => [['id' => 1], ['id' => 2]]], 'configuration' => ['width' => 2, 'length' => 3]];
        $b = ['configuration' => ['length' => 3, 'width' => 2], 'routing' => ['operations' => [['id' => 1], ['id' => 2]], 'version' => 1]];
        $this->assertSame($this->invoke('hash', $a), $this->invoke('hash', $b));
        $b['routing']['operations'] = array_reverse($b['routing']['operations']);
        $this->assertNotSame($this->invoke('hash', $a), $this->invoke('hash', $b));
    }

    public function test_material_facts_normalize_database_decimals_without_losing_the_last_digit(): void
    {
        $row = ['bom_item_id' => '1', 'line_no' => '10', 'component_item_id' => '5', 'required_qty' => '9999999999.00000001',
            'configuration_snapshot' => '{"length":1000,"width":20}'];
        $facts = $this->invoke('materialFacts', $row);
        $reloaded = [...$row, 'bom_item_id' => 1, 'configuration_snapshot' => ['width' => 20, 'length' => 1000]];
        $this->assertSame('9999999999.00000001', $facts['required_qty']);
        $this->assertSame($this->invoke('hash', $facts), $this->invoke('hash', $this->invoke('materialFacts', $reloaded)));
    }

    public function test_existing_snapshot_remains_valid_after_hash_normalization_but_real_change_does_not(): void
    {
        $stored = '{"bom_id":1,"technical_version":2,"routing_snapshot":{"version":1,"operations":[{"id":7,"sequence":10}]},"rows":[{"line_no":10,"required_qty":"2.00000000"}]}';
        $current = ['rows' => [['required_qty' => '2.00000000', 'line_no' => 10]],
            'routing_snapshot' => ['operations' => [['sequence' => 10, 'id' => 7]], 'version' => 1, 'output_plan' => ['frozen_at' => '2026-10-12']],
            'technical_version' => 2, 'bom_id' => 1];
        $this->assertTrue($this->invoke('inputMatches', $stored, $current));
        $this->assertFalse($this->invoke('inputMatches', $stored, [...$current, 'technical_version' => 3]));
        $current['routing_snapshot']['operations'][0]['sequence'] = 20;
        $this->assertFalse($this->invoke('inputMatches', $stored, $current));
    }

    public function test_distinct_configuration_and_unit_pools_do_not_consume_each_others_stock(): void
    {
        $a = ['component_item_id' => 5, 'base_unit_id' => 1, 'configuration_id' => 11];
        $this->assertFalse($this->invoke('sameStockPool', $a, [...$a, 'configuration_id' => 12]));
        $this->assertFalse($this->invoke('sameStockPool', $a, [...$a, 'base_unit_id' => 2]));
        $this->assertTrue($this->invoke('sameStockPool', $a, [...$a, 'line_no' => 20]));
    }

    private function routeHash(array $route): string { return $this->invoke('hash', $this->invoke('routingDefinition', $route)); }
    private function invoke(string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod(WorkOrderPreparationMaterialService::class, $method))->invoke(new WorkOrderPreparationMaterialService, ...$args);
    }
}
