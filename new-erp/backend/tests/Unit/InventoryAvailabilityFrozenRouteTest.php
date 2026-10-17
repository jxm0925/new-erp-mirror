<?php

namespace Tests\Unit;

use App\Models\Erp\{Item, ProductionOperation, ProductionRouting, ProductionRoutingOperation};
use App\Services\Erp\{DocumentNumberService, InventoryAvailabilityService, ProductionMasterDataService, RoutingOperationOutputRuleService};
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Frozen facts and fully loaded model relations only: no app or database connection. */
class InventoryAvailabilityFrozenRouteTest extends TestCase
{
    public function test_terminal_production_is_finished_even_when_shipment_packing_follows(): void
    {
        self::assertTrue(InventoryAvailabilityService::frozenRouteAllowsFinishedStock($this->workOrder(), $this->source(13, 103, 30)));
    }

    public function test_assembly_prebuild_is_not_finished_when_its_frozen_route_still_requires_water_test(): void
    {
        // Only the assembly task may have been instantiated, but the accepted
        // complete route proves that later production still belongs to the item.
        self::assertFalse(InventoryAvailabilityService::frozenRouteAllowsFinishedStock($this->workOrder(), $this->source(12, 102, 20)));
    }

    public function test_only_a_proven_complete_route_may_make_assembly_a_true_terminal_node(): void
    {
        $order = $this->workOrder();
        array_splice($order['routing_snapshot']['operations'], 2, 1);
        $order['routing_snapshot']['operation_count'] = 3;
        $order['routing_snapshot']['production_operation_count'] = 2;
        self::assertTrue(InventoryAvailabilityService::frozenRouteAllowsFinishedStock($order, $this->source(12, 102, 20)));
    }

    public function test_truncating_the_list_without_the_original_counts_cannot_claim_completeness(): void
    {
        $order = $this->workOrder();
        $order['routing_snapshot']['operations'] = array_slice($order['routing_snapshot']['operations'], 0, 2);
        self::assertFalse(InventoryAvailabilityService::frozenRouteAllowsFinishedStock($order, $this->source(12, 102, 20)));
    }

    public function test_a_legacy_truncated_snapshot_without_completeness_evidence_remains_blocked(): void
    {
        $order = $this->workOrder();
        unset($order['routing_snapshot']['snapshot_scope'], $order['routing_snapshot']['operation_count'], $order['routing_snapshot']['production_operation_count']);
        $order['routing_snapshot']['operations'] = array_slice($order['routing_snapshot']['operations'], 0, 2);
        self::assertFalse(InventoryAvailabilityService::frozenRouteAllowsFinishedStock($order, $this->source(12, 102, 20)));
    }

    public function test_unmarked_legacy_terminal_snapshot_does_not_guess_that_it_was_complete(): void
    {
        $order = $this->workOrder(); unset($order['routing_snapshot']['snapshot_scope']);
        self::assertFalse(InventoryAvailabilityService::frozenRouteAllowsFinishedStock($order, $this->source(13, 103, 30)));
    }

    public function test_json_from_the_database_uses_the_same_frozen_terminal_check(): void
    {
        $order = $this->workOrder(); $order['routing_snapshot'] = json_encode($order['routing_snapshot']);
        self::assertTrue(InventoryAvailabilityService::frozenRouteAllowsFinishedStock($order, $this->source(13, 103, 30)));
        $order['routing_snapshot'] = '{invalid';
        self::assertFalse(InventoryAvailabilityService::frozenRouteAllowsFinishedStock($order, $this->source(13, 103, 30)));
    }

    public static function corruptFrozenFacts(): array
    {
        return [
            'wrong route' => ['routing_id', 9], 'wrong version' => ['version', 1],
            'wrong Item' => ['output_item_id', 99], 'missing count' => ['operation_count', null],
            'wrong production count' => ['production_operation_count', 4],
            'fractional route' => ['routing_id', 5.1], 'Boolean count' => ['operation_count', true],
        ];
    }

    #[DataProvider('corruptFrozenFacts')]
    public function test_corrupted_route_identity_or_counts_are_blocked(string $field, mixed $value): void
    {
        $order = $this->workOrder(); $order['routing_snapshot'][$field] = $value;
        self::assertFalse(InventoryAvailabilityService::frozenRouteAllowsFinishedStock($order, $this->source(13, 103, 30)));
    }

    public static function sourceMismatches(): array
    {
        return [
            'wrong WO' => ['work_order_id', 8], 'wrong route node' => ['routing_operation_id_snapshot', 99],
            'wrong operation' => ['operation_id_snapshot', 99], 'wrong sequence' => ['sequence_no_snapshot', 29],
            'invalid sequence' => ['sequence_no_snapshot', 0],
        ];
    }

    #[DataProvider('sourceMismatches')]
    public function test_output_source_must_match_its_own_frozen_work_order(string $field, int $value): void
    {
        $source = $this->source(13, 103, 30); $source[$field] = $value;
        self::assertFalse(InventoryAvailabilityService::frozenRouteAllowsFinishedStock($this->workOrder(), $source));
    }

    public function test_shipment_node_cannot_be_used_as_a_production_terminal(): void
    {
        self::assertFalse(InventoryAvailabilityService::frozenRouteAllowsFinishedStock($this->workOrder(), $this->source(14, 104, 40)));
    }

    public function test_duplicate_or_unknown_nodes_block_the_claim_of_a_complete_route(): void
    {
        foreach ([['routing_operation_id', 12], ['sequence', 20], ['execution_context', 'unknown'], ['execution_context', null], ['sequence', 30.5]] as [$field, $value]) {
            $order = $this->workOrder(); $order['routing_snapshot']['operations'][2][$field] = $value;
            self::assertFalse(InventoryAvailabilityService::frozenRouteAllowsFinishedStock($order, $this->source(13, 103, 30)));
        }
    }

    public function test_current_master_information_cannot_override_frozen_remaining_production(): void
    {
        $order = $this->workOrder();
        $order['current_master_route'] = ['operations' => [['sequence' => 20, 'execution_context' => 'production']]];
        self::assertFalse(InventoryAvailabilityService::frozenRouteAllowsFinishedStock($order, $this->source(12, 102, 20)));
        $order['current_master_route']['operations'][] = ['sequence' => 50, 'execution_context' => 'production'];
        self::assertTrue(InventoryAvailabilityService::frozenRouteAllowsFinishedStock($order, $this->source(13, 103, 30)));
    }

    public function test_snapshot_generator_counts_the_entire_loaded_route_before_execution_can_be_truncated(): void
    {
        // Laravel constructs a query even when loadMissing sees fully loaded
        // relations. Stub only that loader; every frozen fact below is real model data.
        $routing = $this->getMockBuilder(ProductionRouting::class)->onlyMethods(['loadMissing'])->getMock();
        $routing->method('loadMissing')->willReturnSelf();
        $routing->fill(['id' => 5, 'version' => 2, 'routing_no' => 'R-5', 'output_item_id' => 8]);
        $routing->setRelation('outputItem', new Item(['id' => 8]));
        $routing->setRelation('product', null); $routing->setRelation('sku', null);
        $nodes = [];
        foreach ($this->workOrder()['routing_snapshot']['operations'] as $fact) {
            $node = new ProductionRoutingOperation(['id' => $fact['routing_operation_id'], 'operation_id' => $fact['operation_id'],
                'sequence' => $fact['sequence'], 'execution_context' => $fact['execution_context']]);
            $node->setRelation('operation', new ProductionOperation(['id' => $fact['operation_id']]));
            foreach (['outputItem', 'stage', 'packagingScheme'] as $relation) $node->setRelation($relation, null);
            foreach (['outputRules', 'materialSupplyRules'] as $relation) $node->setRelation($relation, new Collection());
            $nodes[] = $node;
        }
        $routing->setRelation('operations', new Collection($nodes));
        $service = new ProductionMasterDataService($this->createMock(DocumentNumberService::class), new RoutingOperationOutputRuleService());
        $snapshot = $service->snapshot($routing);
        self::assertSame('complete_route', $snapshot['snapshot_scope']);
        self::assertSame(4, $snapshot['operation_count']); self::assertSame(3, $snapshot['production_operation_count']);
        self::assertCount(4, $snapshot['operations']);
        self::assertSame(30, $snapshot['operations'][2]['sequence']);
    }

    private function workOrder(): array
    {
        return ['id' => 7, 'output_item_id' => 8, 'production_routing_id' => 5, 'routing_version_snapshot' => 2,
            'routing_snapshot' => ['routing_id' => 5, 'version' => 2, 'output_item_id' => 8, 'snapshot_scope' => 'complete_route',
                'operation_count' => 4, 'production_operation_count' => 3, 'operations' => [
                    ['routing_operation_id' => 11, 'operation_id' => 101, 'sequence' => 10, 'execution_context' => 'production'],
                    ['routing_operation_id' => 12, 'operation_id' => 102, 'sequence' => 20, 'execution_context' => 'production'],
                    ['routing_operation_id' => 13, 'operation_id' => 103, 'sequence' => 30, 'execution_context' => 'production'],
                    ['routing_operation_id' => 14, 'operation_id' => 104, 'sequence' => 40, 'execution_context' => 'shipment'],
                ]]];
    }

    private function source(int $routingId, int $operationId, int $sequence): array
    {
        return ['work_order_id' => 7, 'routing_operation_id_snapshot' => $routingId,
            'operation_id_snapshot' => $operationId, 'sequence_no_snapshot' => $sequence];
    }
}
