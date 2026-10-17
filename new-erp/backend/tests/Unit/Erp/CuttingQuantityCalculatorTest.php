<?php

namespace Tests\Unit\Erp;

use App\Models\Erp\{Bom, BomItem, Item, Unit, WorkOrder};
use App\Services\Erp\{BomMatcher, CuttingQuantityCalculator, LengthCutRequirementService, ProductionDataScopeResolver, ProductionExecutionFoundationService, ReleaseGateApplicationService, SalesOrderFundingGateService, UnitConversionDomainService, WorkOrderTechnicalService};
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\{ArrayLoader, Translator};
use Illuminate\Validation\{Factory, ValidationException};
use PHPUnit\Framework\TestCase;

/** Pure in-memory models and services; no Laravel application, connection or fixture writes. */
class CuttingQuantityCalculatorTest extends TestCase
{
    private CuttingQuantityCalculator $calculator;
    private LengthCutRequirementService $requirements;
    private mixed $previousContainer;
    private mixed $previousFacade;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacade = Facade::getFacadeApplication();
        $container = new Container;
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();
        $container->instance('validator', new Factory(new Translator(new ArrayLoader, 'zh_CN'), $container));
        $container->bind('db', fn () => throw new \RuntimeException('A pure cutting test must never open a database connection.'));
        $this->calculator = new CuttingQuantityCalculator;
        $container->instance(CuttingQuantityCalculator::class, $this->calculator);
        $container->instance(UnitConversionDomainService::class, new UnitConversionDomainService);
        $this->requirements = new LengthCutRequirementService($this->calculator, new UnitConversionDomainService);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Container::setInstance($this->previousContainer);
        Facade::setFacadeApplication($this->previousFacade);
        parent::tearDown();
    }

    public function test_metre_quantity_is_length_times_pieces_and_does_not_require_an_invented_stock_length(): void
    {
        [$item, $unit] = $this->material('length', 'M');
        $snapshot = $this->calculator->calculate($item, $unit, $this->length('300.25', 3));
        $this->assertSame('0.90075000', $snapshot['theoretical_qty']);
        $this->assertSame('0.9008', $snapshot['stored_qty']);
        $this->assertNull($snapshot['stock_dimensions']);
        $this->assertSame('300.25', $snapshot['dimensions']['length_mm']);
    }

    public function test_root_quantity_remains_a_fraction_and_never_becomes_one_whole_input(): void
    {
        [$item, $unit] = $this->material('length', 'ROOT', '6000');
        $snapshot = $this->calculator->calculate($item, $unit, $this->length('300', 2));
        $this->assertSame('0.10000000', $snapshot['theoretical_qty']);
        $this->assertSame('0.1000', $snapshot['stored_qty']);
        $this->assertSame('6000.00', $snapshot['stock_dimensions']['length_mm']);
        $this->assertSame('item_standard_length', $snapshot['stock_source']['type']);
        $this->assertSame(0, $unit->decimal_places);
    }

    public function test_root_denominator_can_come_from_an_explicit_real_material_configuration(): void
    {
        [$item, $unit] = $this->material('length', 'ROOT', '6000');
        $snapshot = $this->calculator->calculate($item, $unit, $this->length('300', 2), [
            'dimensions' => ['length_mm' => '3000'], 'source' => ['type' => 'material_configuration', 'id' => 21, 'version_no' => 2],
        ]);
        $this->assertSame('0.20000000', $snapshot['theoretical_qty']);
        $this->assertSame(21, $snapshot['stock_source']['id']);
    }

    public function test_root_missing_standard_length_is_rejected(): void
    {
        [$item, $unit] = $this->material('length', 'ROOT');
        $this->expectException(ValidationException::class);
        $this->calculator->calculate($item, $unit, $this->length('300', 2));
    }

    public function test_sheet_area_quantity_uses_square_metres_and_preserves_thickness_without_using_it_as_area(): void
    {
        [$item, $unit] = $this->material('sheet', 'M2');
        $snapshot = $this->calculator->calculate($item, $unit, $this->sheet('500', '400', '1.5', 3));
        $this->assertSame('0.60000000', $snapshot['theoretical_qty']);
        $this->assertSame('1.50', $snapshot['dimensions']['thickness_mm']);
        $this->assertNull($snapshot['stock_dimensions']);
    }

    public function test_sheet_count_is_theoretical_area_share_with_eight_place_snapshot(): void
    {
        [$item, $unit] = $this->material('sheet', 'SHEET');
        $snapshot = $this->calculator->calculate($item, $unit, $this->sheet('500', '400', '1.5', 3), [
            'dimensions' => ['length_mm' => '2440', 'width_mm' => '1220', 'thickness_mm' => '1.5'], 'source' => ['type' => 'bom_explicit'],
        ]);
        $this->assertSame('0.20155872', $snapshot['theoretical_qty']);
        $this->assertSame('0.2016', $snapshot['stored_qty']);
        $this->assertSame('2440.00', $snapshot['stock_dimensions']['length_mm']);
        $this->assertNotSame($snapshot['dimensions']['length_mm'], $snapshot['stock_dimensions']['length_mm']);
    }

    public function test_sheet_count_cannot_use_its_cut_dimensions_as_the_stock_denominator(): void
    {
        [$item, $unit] = $this->material('sheet', 'SHEET');
        $this->expectException(ValidationException::class);
        $this->calculator->calculate($item, $unit, $this->sheet('500', '400', '1.5', 3));
    }

    public function test_sheet_rotation_requires_explicit_permission(): void
    {
        [$item, $unit] = $this->material('sheet', 'SHEET');
        $stock = ['dimensions' => ['length_mm' => '800', 'width_mm' => '1000']];
        $line = $this->sheet('900', '600', '1.5', 1);
        $line['allow_cut_rotation'] = true;
        $this->assertSame('0.67500000', $this->calculator->calculate($item, $unit, $line, $stock)['theoretical_qty']);
        $line['allow_cut_rotation'] = false;
        $this->expectException(ValidationException::class);
        $this->calculator->calculate($item, $unit, $line, $stock);
    }

    public function test_real_thickness_mismatch_is_rejected_without_substitution(): void
    {
        [$item, $unit] = $this->material('sheet', 'SHEET');
        $this->expectException(ValidationException::class);
        $this->calculator->calculate($item, $unit, $this->sheet('500', '400', '1.5', 1), [
            'dimensions' => ['length_mm' => '2440', 'width_mm' => '1220', 'thickness_mm' => '2'],
        ]);
    }

    public function test_unit_name_is_not_used_to_guess_an_unknown_unit(): void
    {
        [$item, $unit] = $this->material('length', 'UNKNOWN');
        $unit->unit_name = '米';
        $this->expectException(ValidationException::class);
        $this->calculator->calculate($item, $unit, $this->length('300', 2));
    }

    public function test_sheet_material_cannot_be_consumed_in_length_units(): void
    {
        [$item, $unit] = $this->material('sheet', 'M');
        $this->expectException(ValidationException::class);
        $this->calculator->calculate($item, $unit, $this->sheet('500', '400', '1.5', 1));
    }

    public function test_fractional_piece_count_is_rejected(): void
    {
        [$item, $unit] = $this->material('length', 'M');
        $this->expectException(ValidationException::class);
        $this->calculator->calculate($item, $unit, $this->length('300', '1.5'));
    }

    public function test_positive_theoretical_quantity_cannot_silently_round_to_zero_in_existing_bom_storage(): void
    {
        [$item, $unit] = $this->material('length', 'M');
        $this->expectException(ValidationException::class);
        $this->calculator->calculate($item, $unit, $this->length('0.01', 1));
    }

    public function test_technical_cut_dimensions_recalculate_quantity_without_mutating_the_approved_bom(): void
    {
        [$bom, $line] = $this->bom('length', 'M', $this->length('300', 2));
        $resolved = $this->requirements->resolveBomLines($bom, ['technical_bom_lines' => [['bom_item_id' => 11, 'cut_length_mm' => '500']]])->first();
        $this->assertSame('1.00000000', $resolved['qty']);
        $this->assertSame('work_order_technical_version', $resolved['cut_requirement_source']);
        $this->assertSame('300.00', $line->cut_length_mm);
        $this->assertSame('99.0000', $line->qty);
    }

    public function test_configuration_override_and_original_bom_share_one_calculation(): void
    {
        [$bom] = $this->bom('sheet', 'M2', $this->sheet('500', '400', '1.5', 2));
        $resolved = $this->requirements->resolveBomLines($bom, ['cut_requirements' => [
            ['component_item_id' => 1] + $this->sheet('600', '500', '1.5', 4),
        ]])->first();
        $this->assertSame('1.20000000', $resolved['qty']);
        $this->assertSame('600.00', $resolved['cutting_quantity_snapshot']['dimensions']['length_mm']);
    }

    public function test_technical_stock_configuration_is_separate_from_output_dimensions(): void
    {
        [$bom] = $this->bom('sheet', 'SHEET', $this->sheet('500', '400', '1.5', 1));
        $configuration = ['stock_bom_lines' => [['bom_item_id' => 11, 'configuration' => [
            'id' => 21, 'version_no' => 2, 'dimensions' => ['length_mm' => '2000', 'width_mm' => '1000', 'thickness_mm' => '1.5'],
        ]]]];
        $resolved = $this->requirements->resolveBomLines($bom, $configuration)->first();
        $this->assertSame('0.10000000', $resolved['qty']);
        $this->assertSame('500.00', $resolved['cutting_quantity_snapshot']['dimensions']['length_mm']);
        $this->assertSame('2000.00', $resolved['cutting_quantity_snapshot']['stock_dimensions']['length_mm']);
        $this->assertSame(21, $resolved['cutting_quantity_snapshot']['stock_source']['id']);
    }

    public function test_unmatched_configuration_cannot_guess_among_multiple_geometry_rows(): void
    {
        [$bom, $first] = $this->bom('length', 'M', $this->length('300', 2));
        $second = clone $first; $second->id = 12; $second->cut_length_mm = '400';
        $bom->setRelation('items', new Collection([$first, $second]));
        $this->expectException(ValidationException::class);
        $this->requirements->resolveBomLines($bom, ['cut_requirements' => [['component_item_id' => 1] + $this->length('500', 2)]]);
    }

    public function test_technical_material_reference_must_belong_to_the_same_bom(): void
    {
        [$bom] = $this->bom('length', 'M', $this->length('300', 2));
        $this->expectException(ValidationException::class);
        $this->requirements->resolveBomLines($bom, ['stock_bom_lines' => [['bom_item_id' => 999, 'configuration' => ['dimensions' => ['length_mm' => '6000']]]]]);
    }

    public function test_sales_configuration_cannot_forge_internal_technical_material_versions(): void
    {
        $this->expectException(ValidationException::class);
        $this->requirements->normalizeConfiguration(['stock_bom_lines' => [['bom_item_id' => 11, 'configuration' => ['id' => 21]]]]);
    }

    public function test_effective_configuration_uses_frozen_material_and_output_configuration_separately(): void
    {
        $workOrder = new WorkOrder(['technical_version' => 2, 'technical_snapshot' => [
            'configuration_snapshot' => ['technical_bom_lines' => [['bom_item_id' => 11, 'cut_length_mm' => '500']]],
            'materials' => [['bom_item_id' => 11, 'configuration' => ['id' => 21, 'version_no' => 2, 'dimensions' => ['length_mm' => '6000']]]],
        ]]);
        $configuration = (new WorkOrderTechnicalService)->effectiveConfiguration($workOrder);
        $this->assertSame('500', $configuration['technical_bom_lines'][0]['cut_length_mm']);
        $this->assertSame('6000', $configuration['stock_bom_lines'][0]['configuration']['dimensions']['length_mm']);
    }

    public function test_release_freezes_eight_place_quantity_and_applies_loss_and_fixed_use_once(): void
    {
        [$bom, $line] = $this->bom('length', 'M', $this->length('300.25', 3));
        $line->loss_rate = '10'; $line->fixed_qty = '0.2';
        $service = new ReleaseGateApplicationService(new BomMatcher($this->requirements),
            // Unused final dependencies are not constructed: this test exercises
            // only buildMaterialRows, which consumes the explicit in-memory BOM.
            (new \ReflectionClass(ProductionDataScopeResolver::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(ProductionExecutionFoundationService::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(SalesOrderFundingGateService::class))->newInstanceWithoutConstructor(), $this->requirements);
        $workOrder = new WorkOrder(['id' => 31, 'target_base_qty' => '10', 'technical_version' => 0]);
        $row = $service->buildMaterialRows($workOrder, $bom)[0];
        $snapshot = json_decode($row['cutting_requirement_snapshot'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('0.90075000', $row['per_output_qty']);
        $this->assertSame('10.10825000', $row['required_qty']);
        $this->assertSame('10.10825000', $row['base_required_qty']);
        $this->assertSame(30.0, $row['required_piece_qty']);
        $this->assertSame('0.90075000', $snapshot['quantity_calculation']['theoretical_qty']);
        $this->assertSame('300.25', $snapshot['dimensions']['length_mm']);
    }

    private function material(string $mode, string $code, ?string $stockLength = null): array
    {
        $unit = new Unit(['id' => 2, 'unit_code' => $code, 'unit_name' => $code, 'symbol' => $code, 'status' => 'enabled',
            'is_legacy' => false, 'decimal_places' => in_array($code, ['ROOT', 'SHEET'], true) ? 0 : 3]);
        $unit->setRelation('standardUnit', null);
        $item = new Item(['id' => 1, 'item_code' => 'RAW1', 'item_name' => '真实原料', 'status' => 'enabled',
            'cutting_mode' => $mode, 'standard_stock_length_mm' => $stockLength, 'unit_id' => 2]);
        $item->setRelation('unit', $unit);
        return [$item, $unit];
    }

    private function bom(string $mode, string $code, array $dimensions): array
    {
        [$item, $unit] = $this->material($mode, $code);
        $line = new BomItem(['id' => 11, 'bom_id' => 41, 'line_no' => 10, 'component_item_id' => 1, 'component_item_code' => 'RAW1',
            'component_item_name' => '真实原料', 'unit_id' => 2, 'qty' => '99', 'loss_rate' => '0', 'fixed_qty' => '0'] + $dimensions);
        $line->setRelation('componentItem', $item); $line->setRelation('unit', $unit);
        $bom = new Bom(['id' => 41]); $bom->setRelation('items', new Collection([$line]));
        return [$bom, $line];
    }

    private function length(string $length, mixed $pieces): array
    {
        return ['cut_length_mm' => $length, 'cut_width_mm' => null, 'cut_thickness_mm' => null, 'piece_qty' => $pieces, 'allow_cut_rotation' => false];
    }

    private function sheet(string $length, string $width, string $thickness, int $pieces): array
    {
        return ['cut_length_mm' => $length, 'cut_width_mm' => $width, 'cut_thickness_mm' => $thickness, 'piece_qty' => $pieces, 'allow_cut_rotation' => false];
    }
}
