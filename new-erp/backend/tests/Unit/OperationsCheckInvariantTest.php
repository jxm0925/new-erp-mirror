<?php

namespace Tests\Unit;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Services\Erp\MaterialHoldingInvariant;
use App\Services\Erp\ProductionMaterialCostService;
use App\Services\Erp\ProductionQualitySubject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Pure value tests: no Laravel application, environment file, or database connection. */
final class OperationsCheckInvariantTest extends TestCase
{
    #[DataProvider('validHoldings')]
    public function test_valid_holdings_are_returned_without_normalizing_amounts_or_status(array $row): void
    {
        $this->assertSame($row, MaterialHoldingInvariant::attributes($row));
    }

    public static function validHoldings(): array
    {
        return [
            'warehouse authority' => [['position_type' => 'WAREHOUSE', 'inventory_balance_id' => 3, 'quantity' => null, 'total_cost' => null]],
            'warehouse nullable defaults' => [['position_type' => 'WAREHOUSE', 'inventory_balance_id' => 3]],
            'maximum precision' => [['position_type' => 'OUTPUT_WIP', 'quantity' => '9999999999.00000001', 'total_cost' => '99999999999999.9999']],
            'closed zero balance' => [['position_type' => 'PRODUCTION_INPUT', 'inventory_balance_id' => null, 'quantity' => '0.00000000', 'total_cost' => '0.0000', 'status' => 'CONSUMED']],
            'physical disposal' => [['position_type' => 'DISPOSAL', 'quantity' => '1', 'total_cost' => '0', 'status' => 'DISPOSED']],
            'existing numeric cast' => [['position_type' => 'OUTPUT_WIP', 'quantity' => 1.25, 'total_cost' => 0]],
        ];
    }

    #[DataProvider('invalidHoldings')]
    public function test_invalid_holdings_are_rejected(array $row): void
    {
        $this->expectException(WorkOrderDomainException::class);
        MaterialHoldingInvariant::attributes($row);
    }

    public static function invalidHoldings(): array
    {
        return [
            'warehouse lacks balance' => [['position_type' => 'WAREHOUSE']],
            'warehouse quantity duplicates authority' => [['position_type' => 'WAREHOUSE', 'inventory_balance_id' => 3, 'quantity' => '0']],
            'warehouse cost duplicates authority' => [['position_type' => 'WAREHOUSE', 'inventory_balance_id' => 3, 'total_cost' => '0']],
            'nonwarehouse has balance' => [['position_type' => 'OUTPUT_WIP', 'inventory_balance_id' => 3, 'quantity' => '1', 'total_cost' => '0']],
            'nonwarehouse quantity absent' => [['position_type' => 'OUTPUT_WIP', 'total_cost' => '0']],
            'nonwarehouse cost absent' => [['position_type' => 'OUTPUT_WIP', 'quantity' => '1']],
            'negative quantity' => [['position_type' => 'OUTPUT_WIP', 'quantity' => '-0.00000001', 'total_cost' => '0']],
            'negative cost' => [['position_type' => 'OUTPUT_WIP', 'quantity' => '1', 'total_cost' => '-0.0001']],
            'negative below cost scale not rounded away' => [['position_type' => 'OUTPUT_WIP', 'quantity' => '1', 'total_cost' => '-0.00000001']],
            'position absent' => [['quantity' => '1', 'total_cost' => '0']],
            'invalid decimal' => [['position_type' => 'OUTPUT_WIP', 'quantity' => 'not a number', 'total_cost' => '0']],
            'nonfinite numeric cast' => [['position_type' => 'OUTPUT_WIP', 'quantity' => INF, 'total_cost' => '0']],
        ];
    }

    public function test_debit_preserves_last_decimal_digits_and_does_not_mutate_source(): void
    {
        $row = (object) ['position_type' => 'PRODUCTION_INPUT', 'quantity' => '9999999999.00000002', 'total_cost' => '99999999999999.9999', 'status' => 'ACTIVE'];
        $before = clone $row;
        $this->assertSame(['quantity' => '0.00000001', 'total_cost' => '0.0001'],
            MaterialHoldingInvariant::debit($row, '9999999999.00000001', '99999999999999.9998'));
        $this->assertEquals($before, $row);
    }

    public function test_exact_exhaustion_keeps_zero_and_does_not_choose_a_business_status(): void
    {
        $row = (object) ['position_type' => 'OUTPUT_WIP', 'quantity' => '1.00000001', 'total_cost' => '12.3456'];
        $this->assertSame(['quantity' => '0.00000000', 'total_cost' => '0.0000'],
            MaterialHoldingInvariant::debit($row, '1.00000001', '12.3456'));
    }

    #[DataProvider('invalidDebits')]
    public function test_service_rejects_invalid_debit_before_any_database_access(string $quantity, string $cost): void
    {
        $service = (new \ReflectionClass(ProductionMaterialCostService::class))->newInstanceWithoutConstructor();
        $row = (object) ['position_type' => 'PRODUCTION_INPUT', 'quantity' => '1.00000000', 'total_cost' => '1.0000'];
        $this->expectException(WorkOrderDomainException::class);
        (new \ReflectionMethod($service, 'reduceHolding'))->invoke($service, $row, $quantity, $cost);
    }

    public static function invalidDebits(): array
    {
        return [
            'quantity overdraft' => ['1.00000001', '1.0000'],
            'cost overdraft' => ['1.00000000', '1.0001'],
            'negative quantity debit' => ['-1', '0'],
            'negative cost debit' => ['0', '-1'],
        ];
    }

    public function test_changes_do_not_silently_repair_invalid_existing_facts(): void
    {
        $row = (object) ['position_type' => 'WAREHOUSE', 'inventory_balance_id' => 3, 'quantity' => '0', 'total_cost' => '0'];
        $this->expectException(WorkOrderDomainException::class);
        MaterialHoldingInvariant::changes($row, ['quantity' => null, 'total_cost' => null]);
    }

    public function test_changes_reject_warehouse_transition_without_clearing_local_amounts(): void
    {
        $row = (object) ['position_type' => 'OUTPUT_WIP', 'quantity' => '1', 'total_cost' => '0'];
        $this->expectException(WorkOrderDomainException::class);
        MaterialHoldingInvariant::changes($row, ['position_type' => 'WAREHOUSE', 'inventory_balance_id' => 3]);
    }

    #[DataProvider('qualitySubjects')]
    public function test_quality_subject_requires_exactly_one_nonnull_reference(array $row, bool $valid): void
    {
        if (!$valid) $this->expectException(WorkOrderDomainException::class);
        $this->assertSame($row, ProductionQualitySubject::attributes($row));
    }

    public static function qualitySubjects(): array
    {
        return [
            'output with omitted nullable cutting id' => [['output_record_id' => 3, 'status' => 'COMPLETED'], true],
            'cutting subject' => [['output_record_id' => null, 'cutting_result_id' => 4], true],
            'both ids' => [['output_record_id' => 3, 'cutting_result_id' => 4], false],
            'both null' => [['output_record_id' => null, 'cutting_result_id' => null], false],
            'both omitted' => [[], false],
        ];
    }
}
