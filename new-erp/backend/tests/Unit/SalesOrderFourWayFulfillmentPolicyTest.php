<?php

namespace Tests\Unit;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\Item;
use App\Models\Erp\SalesOrderFulfillment;
use App\Models\Erp\SalesOrderLine;
use App\Services\Erp\SalesOrderFulfillmentApplicationService;
use App\Services\Erp\SalesOrderFulfillmentPolicy as Policy;
use App\Services\Erp\SalesOrderProductionChangeService;
use App\Services\Erp\SalesOrderChangeApplicationService;
use App\Services\Erp\SalesOrderInventoryLockService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Factory as ValidationFactory;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/** Pure quantities/models only: no Laravel application, DB connection or business fixtures. */
class SalesOrderFourWayFulfillmentPolicyTest extends TestCase
{
    private mixed $previousFacadeApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $container = new Container();
        $container->instance('validator', new ValidationFactory(new Translator(new ArrayLoader(), 'zh'), $container));
        Facade::clearResolvedInstance('validator');
        Facade::setFacadeApplication($container);
        // The container deliberately has no database binding or application kernel.
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstance('validator');
        Facade::setFacadeApplication($this->previousFacadeApplication);
        parent::tearDown();
    }

    public function test_explicit_purchase_wins_over_a_production_use_flag(): void
    {
        $item = $this->item(['manufacturing_strategy' => 'purchase', 'is_production_item' => true]);
        self::assertTrue($item->isPurchaseFulfilledItem());
    }

    public function test_explicit_make_does_not_fall_back_to_purchasing_flags(): void
    {
        self::assertFalse($this->item(['manufacturing_strategy' => 'make', 'is_production_item' => false])->isPurchaseFulfilledItem());
    }

    public function test_unspecified_purchase_only_item_uses_the_legacy_flags(): void
    {
        self::assertTrue($this->item(['manufacturing_strategy' => 'unspecified'])->isPurchaseFulfilledItem());
        self::assertFalse($this->item(['manufacturing_strategy' => 'unspecified', 'is_production_item' => true])->isPurchaseFulfilledItem());
    }

    public function test_service_office_and_nonstock_items_are_never_purchase_delivery_items(): void
    {
        foreach ([['item_type' => 'service'], ['management_scope' => 'office'], ['is_stock_item' => false]] as $overrides) {
            self::assertFalse($this->item($overrides)->isPurchaseFulfilledItem());
        }
    }

    public function test_purchase_shortage_and_partial_inventory_conserve_sales_quantity(): void
    {
        self::assertSame(['suggested_inventory_qty' => 4.0, 'suggested_production_qty' => 0.0, 'suggested_purchase_qty' => 6.0], Policy::split(10, 4, true));
        self::assertSame(['suggested_inventory_qty' => 0.0, 'suggested_production_qty' => 0.0, 'suggested_purchase_qty' => 10.0], Policy::split(10, 0, true));
        self::assertSame(['suggested_inventory_qty' => 10.0, 'suggested_production_qty' => 0.0, 'suggested_purchase_qty' => 0.0], Policy::split(10, 30, true));
    }

    public function test_manufactured_shortage_remains_production(): void
    {
        self::assertSame(['suggested_inventory_qty' => 4.0, 'suggested_production_qty' => 6.0, 'suggested_purchase_qty' => 0.0], Policy::split(10, 4, false));
    }

    public function test_frozen_purchase_choice_survives_a_later_make_master_setting(): void
    {
        $line = $this->line('physical', 'purchase');
        $line->setRelation('item', $this->item(['manufacturing_strategy' => 'make']));
        self::assertTrue(Policy::isPurchaseLine($line));
    }

    public function test_frozen_production_choice_cannot_be_reclassified_by_later_purchase_settings(): void
    {
        $line = $this->line('physical', 'production');
        $line->setRelation('item', $this->item());
        self::assertFalse(Policy::isPurchaseLine($line));
    }

    public function test_service_and_nondelivery_lines_take_priority_over_item_strategy(): void
    {
        foreach (['service', 'no_delivery', 'fee', 'auxiliary'] as $type) self::assertFalse(Policy::isPurchaseLine($this->line($type, 'purchase')));
    }

    public function test_mixed_order_finishes_when_all_effective_physical_goods_are_shipped(): void
    {
        self::assertSame('shipped', Policy::shipmentStatus([
            ['line_type' => 'physical', 'order_qty' => 10, 'cancelled_qty' => 3, 'shipped_qty' => 7],
            ['line_type' => 'service', 'order_qty' => 99, 'shipped_qty' => 0],
            ['line_type' => 'no_delivery', 'order_qty' => 5, 'shipped_qty' => 0],
        ]));
    }

    public function test_only_nondelivery_or_fully_cancelled_goods_need_no_shipment_fact(): void
    {
        self::assertSame('not_required', Policy::shipmentStatus([
            ['line_type' => 'physical', 'order_qty' => 5, 'cancelled_qty' => 5, 'shipped_qty' => 0],
            ['line_type' => 'service', 'order_qty' => 1, 'shipped_qty' => 0],
        ]));
    }

    public function test_zero_price_gift_still_requires_delivery_as_a_physical_line(): void
    {
        $gift = ['line_type' => 'physical', 'commercial_role' => 'gift', 'unit_price' => 0, 'order_qty' => 1, 'shipped_qty' => 0];
        self::assertTrue(Policy::requiresDelivery([$gift]));
        self::assertSame('not_shipped', Policy::shipmentStatus([$gift]));
    }

    public function test_partial_status_is_independent_of_line_order(): void
    {
        $lines = [['line_type' => 'physical', 'order_qty' => 1, 'shipped_qty' => 0], ['line_type' => 'physical', 'order_qty' => 1, 'shipped_qty' => 1]];
        self::assertSame('partially_shipped', Policy::shipmentStatus($lines));
        self::assertSame('partially_shipped', Policy::shipmentStatus(array_reverse($lines)));
    }

    public function test_nondelivery_skips_shipping_requirements_but_retains_commercial_identity(): void
    {
        $header = ['customer_name' => '客户', 'contact_phone' => '13800000000'];
        $lines = [['line_type' => 'service', 'order_qty' => 1]];
        self::assertSame(['requires_delivery' => false, 'customer_complete' => true, 'shipping_complete' => true, 'carrier_complete' => true], Policy::confirmationRequirements($header, $lines));
        self::assertFalse(Policy::confirmationRequirements([], $lines)['customer_complete']);
        $physical = [['line_type' => 'physical', 'order_qty' => 1]];
        self::assertFalse(Policy::confirmationRequirements($header, $physical)['shipping_complete']);
        self::assertFalse(Policy::confirmationRequirements($header, $physical)['carrier_complete']);
    }

    public function test_partial_purchase_receipt_keeps_the_tail_and_original_snapshot(): void
    {
        $tail = Policy::purchaseTail(10, 4, 2, 20, ['confirmation_command_id' => 'batch-a'], [['inventory_fulfillment_id' => 11, 'sales_qty' => 4]]);
        self::assertSame(6.0, $tail['sales_qty']);
        self::assertSame(12.0, $tail['item_base_qty']);
        self::assertSame(10.0, $tail['match_snapshot']['original_purchase_quantity']['sales_qty']);
        $tail2 = Policy::purchaseTail(6, 3, 2, 12, $tail['match_snapshot'], [['inventory_fulfillment_id' => 12, 'sales_qty' => 3]]);
        self::assertSame(3.0, $tail2['sales_qty']);
        self::assertSame(10.0, $tail2['match_snapshot']['original_purchase_quantity']['sales_qty']);
        self::assertCount(2, $tail2['match_snapshot']['inventory_conversions']);
    }

    public function test_final_purchase_receipt_supersedes_the_backlog_without_deleting_its_history(): void
    {
        $tail = Policy::purchaseTail(3, 3, 2, 6, ['source' => 'accepted_purchase'], [['inventory_fulfillment_id' => 13]]);
        self::assertSame('superseded', $tail['demand_status']);
        self::assertSame('converted_to_inventory', $tail['reservation_status']);
        self::assertSame('accepted_purchase', $tail['match_snapshot']['source']);
        self::assertArrayNotHasKey('sales_qty', $tail);
    }

    public function test_purchase_conversion_cannot_cover_more_than_the_backlog(): void
    {
        $this->expectException(WorkOrderDomainException::class);
        Policy::purchaseTail(3, 4, 2, 6, [], []);
    }

    public function test_purchase_conversion_rejects_a_corrupted_base_quantity_snapshot(): void
    {
        $this->expectException(WorkOrderDomainException::class);
        Policy::purchaseTail(3, 1, 2, 8, [], []);
    }

    public function test_confirmation_hash_is_stable_for_numeric_format_and_line_order(): void
    {
        $a = [$this->decision(1, 5, 5), $this->decision(2, 3, 3)];
        $b = array_reverse($a);
        $b[0]['confirm_qty'] = '3.00000000';
        self::assertSame(Policy::confirmationHash($a, null, null, 1, false), Policy::confirmationHash($b, null, null, 1, false));
        self::assertNotSame(Policy::confirmationHash($a, null, null, 1, false), Policy::confirmationHash($a, null, null, 2, false));
        $b[0]['production_qty'] = 2;
        self::assertNotSame(Policy::confirmationHash($a, null, null, 1, false), Policy::confirmationHash($b, null, null, 1, false));
    }

    public function test_two_equal_confirmation_batches_are_valid_against_their_own_remaining_quantity(): void
    {
        $first = $this->validate($this->line('physical', 'production'), $this->decision(1, 5, 5), 10);
        $second = $this->validate($this->line('physical', 'production'), $this->decision(1, 5, 5), 5);
        self::assertSame(5.0, $first['production_qty']);
        self::assertSame(5.0, $second['production_qty']);
        self::assertSame(0.0, (float) $second['undetermined_qty']);
    }

    public function test_later_confirmation_cannot_exceed_the_remaining_effective_quantity(): void
    {
        $this->expectException(ValidationException::class);
        $this->validate($this->line('physical', 'production'), $this->decision(1, 6, 6), 5);
    }

    public function test_purchased_line_accepts_inventory_plus_purchase_without_production(): void
    {
        $row = $this->decision(1, 10, 0) + ['purchase_qty' => 6];
        $row['inventory_qty'] = 4;
        self::assertSame(6.0, $this->validate($this->line('physical', 'purchase'), $row, 10)['purchase_qty']);
    }

    public function test_purchased_line_rejects_a_manual_production_override(): void
    {
        $this->expectException(ValidationException::class);
        $this->validate($this->line('physical', 'purchase'), $this->decision(1, 10, 10), 10);
    }

    public function test_manufactured_line_rejects_a_manual_purchase_override(): void
    {
        $this->expectException(ValidationException::class);
        $this->validate($this->line('physical', 'production'), $this->decision(1, 10, 0) + ['purchase_qty' => 10], 10);
    }

    public function test_service_and_nondelivery_quantities_cannot_be_diverted_into_purchasing(): void
    {
        foreach (['service', 'no_delivery'] as $type) {
            try {
                $this->validate($this->line($type, 'purchase'), $this->decision(1, 10, 0) + ['purchase_qty' => 10], 10);
                self::fail('免发货行不应接受外购数量。');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('lines', $exception->errors());
            }
        }
    }

    public function test_distribution_must_equal_the_confirmation_batch_quantity(): void
    {
        $this->expectException(ValidationException::class);
        $this->validate($this->line('physical', 'production'), $this->decision(1, 10, 9), 10);
    }

    public function test_receipt_binds_to_its_production_batch_instead_of_latest_fulfillment(): void
    {
        $first = new SalesOrderFulfillment(['id' => 10, 'match_snapshot' => ['production_requirement_id' => 101]]);
        $second = new SalesOrderFulfillment(['id' => 20, 'match_snapshot' => ['production_requirement_id' => 202]]);
        self::assertSame($first, Policy::productionFulfillmentForDemand(new Collection([$first, $second]), 101));
        self::assertSame($second, Policy::productionFulfillmentForDemand(new Collection([$first, $second]), 202));
    }

    public function test_multiple_unbound_legacy_fulfillments_are_explicitly_ambiguous(): void
    {
        $this->expectException(WorkOrderDomainException::class);
        Policy::productionFulfillmentForDemand(new Collection([new SalesOrderFulfillment(['id' => 10]), new SalesOrderFulfillment(['id' => 20])]), 101);
    }

    public function test_only_one_unbound_legacy_fulfillment_has_a_safe_fallback(): void
    {
        $legacy = new SalesOrderFulfillment(['id' => 10]);
        self::assertSame($legacy, Policy::productionFulfillmentForDemand(new Collection([$legacy]), 101));
        self::assertNull(Policy::productionFulfillmentForDemand(new Collection(), 101));
    }

    public function test_a_single_fulfillment_bound_to_another_batch_cannot_be_reused(): void
    {
        $this->expectException(WorkOrderDomainException::class);
        Policy::productionFulfillmentForDemand(new Collection([new SalesOrderFulfillment(['id' => 10, 'match_snapshot' => ['production_requirement_id' => 999]])]), 101);
    }

    public function test_sales_change_can_reverse_only_an_entire_unreleased_tree(): void
    {
        self::assertTrue(SalesOrderProductionChangeService::treeIsUnreleased([['status' => 'WAIT_RELEASE'], ['status' => 'DRAFT'], ['status' => 'CANCELLED']]));
        foreach (['RELEASED', 'IN_PROGRESS', 'COMPLETED', 'CLOSED', 'UNKNOWN'] as $status) {
            self::assertFalse(SalesOrderProductionChangeService::treeIsUnreleased([['status' => 'WAIT_RELEASE'], ['status' => $status]]));
        }
        self::assertFalse(SalesOrderProductionChangeService::treeIsUnreleased([['status' => 'DRAFT', 'released_at' => '2026-10-14 12:00:00']]));
    }

    public function test_commercial_calculation_uses_the_accepted_factor_and_preserves_old_supply_identity(): void
    {
        $line = $this->line('physical', 'purchase');
        $line->fill(['item_id' => 12, 'commercial_role' => 'sale', 'unit_price' => 100, 'discount_rate' => 1,
            'tax_rate' => 0, 'price_tax_mode' => 'tax_inclusive', 'commercial_snapshot' => []]);
        $line->setRelation('item', $this->item(['id' => 99, 'manufacturing_strategy' => 'make']));
        $service = (new ReflectionClass(SalesOrderChangeApplicationService::class))->newInstanceWithoutConstructor();
        $changes = $service->normalizeLine($line, ['unit_price' => 120]);
        self::assertSame(1200.0, $changes['amount_incl_tax']);
        self::assertArrayNotHasKey('item_base_required_qty', $changes);
        self::assertArrayNotHasKey('item_id', $changes);
        self::assertArrayNotHasKey('item_match_snapshot', $changes);
        self::assertSame(12, $line->item_id);
        self::assertTrue(Policy::isPurchaseLine($line));
    }

    public function test_tax_exclusive_price_adds_the_actual_tax_amount(): void
    {
        $line = $this->commercialLine();
        $service = (new ReflectionClass(SalesOrderChangeApplicationService::class))->newInstanceWithoutConstructor();
        $changes = $service->normalizeLine($line, ['price_tax_mode' => 'tax_exclusive', 'tax_rate' => 13, 'discount_rate' => 0.9]);
        self::assertSame(900.0, $changes['amount_excl_tax']);
        self::assertSame(117.0, $changes['tax_amount']);
        self::assertSame(1017.0, $changes['amount_incl_tax']);
        self::assertSame($changes['amount_incl_tax'], $changes['commercial_snapshot']['amount_incl_tax']);
    }

    public function test_quantity_change_uses_the_accepted_factor(): void
    {
        $service = (new ReflectionClass(SalesOrderChangeApplicationService::class))->newInstanceWithoutConstructor();
        $changes = $service->normalizeLine($this->commercialLine(), ['order_qty' => 12]);
        self::assertSame(24.0, $changes['item_base_required_qty']);
    }

    public function test_quantity_change_rejects_a_missing_frozen_conversion(): void
    {
        $line = $this->commercialLine()->fill(['fulfillment_factor_snapshot' => null]);
        $service = (new ReflectionClass(SalesOrderChangeApplicationService::class))->newInstanceWithoutConstructor();
        $this->expectException(ValidationException::class);
        $service->normalizeLine($line, ['order_qty' => 12]);
    }

    public function test_nondelivery_quantity_change_keeps_no_physical_requirement(): void
    {
        $line = $this->commercialLine()->fill(['line_type' => 'service', 'fulfillment_factor_snapshot' => null]);
        $service = (new ReflectionClass(SalesOrderChangeApplicationService::class))->newInstanceWithoutConstructor();
        self::assertSame(0.0, $service->normalizeLine($line, ['order_qty' => 12])['item_base_required_qty']);
    }

    public function test_tax_inclusive_price_splits_without_adding_tax_twice(): void
    {
        $service = (new ReflectionClass(SalesOrderChangeApplicationService::class))->newInstanceWithoutConstructor();
        $changes = $service->normalizeLine($this->commercialLine(), ['unit_price' => 113, 'tax_rate' => 0.13]);
        self::assertSame(1000.0, $changes['amount_excl_tax']);
        self::assertSame(130.0, $changes['tax_amount']);
        self::assertSame(1130.0, $changes['amount_incl_tax']);
    }

    public function test_remark_only_change_preserves_operative_identity_and_detects_the_edit(): void
    {
        $line = $this->commercialLine();
        $line->fill(['remark' => '原备注', 'item_id' => 12, 'unit_id' => 3, 'item_base_unit_id' => 4]);
        $service = (new ReflectionClass(SalesOrderChangeApplicationService::class))->newInstanceWithoutConstructor();
        $changes = $service->normalizeLine($line, ['remark' => '新备注']);
        self::assertSame('新备注', $changes['remark']);
        foreach (['item_id', 'unit_id', 'item_base_unit_id', 'item_match_snapshot', 'fulfillment_factor_snapshot'] as $field) {
            self::assertArrayNotHasKey($field, $changes);
        }
        self::assertTrue((new ReflectionMethod($service, 'isChanged'))->invoke($service, $line, $changes));
        self::assertFalse((new ReflectionMethod($service, 'isChanged'))->invoke($service, $line, $service->normalizeLine($line, [])));
    }

    public function test_database_decimal_formats_are_not_spurious_commercial_changes(): void
    {
        $line = $this->commercialLine()->fill(['order_qty' => '10.0000', 'unit_price' => '100.0000', 'discount_rate' => '1.000000', 'tax_rate' => '0.000000']);
        $service = (new ReflectionClass(SalesOrderChangeApplicationService::class))->newInstanceWithoutConstructor();
        self::assertFalse((new ReflectionMethod($service, 'isChanged'))->invoke($service, $line, $service->normalizeLine($line, [])));
        self::assertTrue((new ReflectionMethod($service, 'isChanged'))->invoke($service, $line, $service->normalizeLine($line, ['unit_price' => 100.0001])));
    }

    public function test_idle_task_and_operation_have_no_execution_facts(): void
    {
        foreach (['WAIT_CLAIM', 'WAIT_PREDECESSOR', 'WAIT_ACCEPT', 'CANCELLED'] as $status) {
            self::assertFalse(SalesOrderProductionChangeService::taskHasExecutionFacts(['status' => $status]));
        }
        foreach (['WAIT_PREVIOUS', 'WAIT_CLAIM', 'CANCELLED'] as $status) {
            self::assertFalse(SalesOrderProductionChangeService::operationHasExecutionFacts(['status' => $status]));
        }
    }

    public function test_claimed_task_or_unknown_state_blocks_automatic_sales_cancellation(): void
    {
        self::assertTrue(SalesOrderProductionChangeService::taskHasExecutionFacts(['status' => 'WAIT_CLAIM', 'claimed_at' => '2026-10-14 12:00:00']));
        foreach (['CLAIMED', 'IN_PROGRESS', 'COMPLETED', 'UNKNOWN'] as $status) {
            self::assertTrue(SalesOrderProductionChangeService::taskHasExecutionFacts(['status' => $status]));
        }
    }

    public function test_operation_facts_still_block_after_its_state_was_reset(): void
    {
        foreach (['claimed_at', 'kitting_confirmed_at', 'started_at', 'paused_at', 'completed_at'] as $field) {
            self::assertTrue(SalesOrderProductionChangeService::operationHasExecutionFacts(['status' => 'WAIT_CLAIM', $field => '2026-10-14 12:00:00']));
        }
        foreach (['actual_labor_minutes', 'completed_base_qty', 'unqualified_base_qty', 'scrapped_base_qty'] as $field) {
            self::assertTrue(SalesOrderProductionChangeService::operationHasExecutionFacts(['status' => 'CANCELLED', $field => 0.01]));
        }
        self::assertTrue(SalesOrderProductionChangeService::operationHasExecutionFacts(['status' => 'UNKNOWN']));
    }

    public function test_inventory_lock_rejects_missing_version_before_any_database_access(): void
    {
        $service = (new ReflectionClass(SalesOrderInventoryLockService::class))->newInstanceWithoutConstructor();
        $this->expectException(WorkOrderDomainException::class);
        (new ReflectionMethod($service, 'command'))->invoke($service, 1, ['client_command_id' => 'lock-1'], (object) ['legacy_id' => 1], fn () => []);
    }

    public function test_inventory_lock_rejects_fictional_actor_before_any_database_access(): void
    {
        $service = (new ReflectionClass(SalesOrderInventoryLockService::class))->newInstanceWithoutConstructor();
        $this->expectException(WorkOrderDomainException::class);
        (new ReflectionMethod($service, 'command'))->invoke($service, 1, ['client_command_id' => 'lock-1', 'expected_version' => 1], (object) ['nickname' => '系统'], fn () => []);
    }

    private function commercialLine(): SalesOrderLine
    {
        return $this->line('physical', 'purchase')->fill(['commercial_role' => 'sale', 'unit_price' => 100,
            'discount_rate' => 1, 'tax_rate' => 0, 'price_tax_mode' => 'tax_inclusive', 'commercial_snapshot' => []]);
    }

    private function item(array $overrides = []): Item
    {
        return new Item($overrides + ['manufacturing_strategy' => 'purchase', 'is_purchase_item' => true, 'is_production_item' => false,
            'is_stock_item' => true, 'item_type' => 'finished_product', 'management_scope' => 'factory']);
    }

    private function line(string $type, string $strategy): SalesOrderLine
    {
        return new SalesOrderLine(['line_no' => 1, 'line_type' => $type, 'order_qty' => 10, 'fulfillment_factor_snapshot' => 2,
            'unit_id' => null, 'item_base_unit_id' => null, 'item_match_snapshot' => ['fulfillment_strategy' => $strategy]]);
    }

    private function decision(int $lineId, float $confirm, float $production): array
    {
        return ['sales_order_line_id' => $lineId, 'confirm_qty' => $confirm, 'inventory_qty' => 0, 'production_qty' => $production,
            'service_qty' => 0, 'no_delivery_qty' => 0];
    }

    private function validate(SalesOrderLine $line, array $row, float $remaining): array
    {
        $service = (new ReflectionClass(SalesOrderFulfillmentApplicationService::class))->newInstanceWithoutConstructor();
        return (new ReflectionMethod($service, 'validateDecisionQuantities'))->invoke($service, $line, $row, $remaining);
    }
}
