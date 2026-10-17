<?php

namespace Tests\Unit\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Services\Erp\CuttingReturnAllocationPolicy;
use PHPUnit\Framework\TestCase;

final class CuttingReturnAllocationPolicyTest extends TestCase
{
    private function batch(int $line = 10, int $holding = 30, string $kind = 'root'): array
    {
        return ['quantity' => 1, 'source_input_holding_id' => $holding, 'consumed_quantity' => 0,
            'physical_material_ids' => [102], 'allocation' => ['unit_kind' => $kind, 'picking_task_line_id' => $line,
                'target_material_requirement_id' => 20, 'sources' => [
                    ['source_key' => $kind === 'sheet' ? 'physical:101' : 'root:0', 'physical_material_id' => $kind === 'sheet' ? 101 : null,
                        'covered_piece_qty' => 3, 'coverage_qty' => '0.30000000', 'formal_coverage_qty' => '0.60000000'],
                    ['source_key' => $kind === 'sheet' ? 'physical:102' : 'root:1', 'physical_material_id' => $kind === 'sheet' ? 102 : null,
                        'covered_piece_qty' => 1, 'coverage_qty' => '0.10000000', 'formal_coverage_qty' => '0.20000000'],
                ]]];
    }

    public function test_root_return_restores_its_own_coverage_after_processing(): void
    {
        $batch = $this->batch(); $batch['consumed_quantity'] = 1;
        $result = CuttingReturnAllocationPolicy::freeze([$batch], 20, 1, []);
        self::assertSame(1, $result['covered_piece_qty']); self::assertSame('0.10000000', $result['coverage_qty']);
        self::assertSame('0.20000000', $result['formal_coverage_qty']); self::assertSame('root:1', $result['sources'][0]['source_key']);
    }

    public function test_sheet_return_uses_the_actual_cost_allocated_identity(): void
    {
        $result = CuttingReturnAllocationPolicy::freeze([$this->batch(kind: 'sheet')], 20, 1, []);
        self::assertSame(102, $result['sources'][0]['physical_material_id']); self::assertSame('0.10000000', $result['coverage_qty']);
        self::assertSame(30, $result['sources'][0]['source_input_holding_id']);
    }

    public function test_root_keys_from_different_pick_lines_are_distinct(): void
    {
        $result = CuttingReturnAllocationPolicy::freeze([$this->batch(), $this->batch(11, 31)], 20, 2, ['10|root:0']);
        self::assertSame(['root:1', 'root:0'], array_column($result['sources'], 'source_key'));
        self::assertSame([10, 11], array_column($result['sources'], 'picking_task_line_id'));
        self::assertSame('0.40000000', $result['coverage_qty']);
    }

    public function test_repeat_identity_is_rejected(): void
    { $this->expectException(WorkOrderDomainException::class); CuttingReturnAllocationPolicy::freeze([$this->batch(kind: 'sheet')], 20, 1, ['10|physical:102']); }

    public function test_missing_cutting_source_cannot_be_mixed_with_cutting_return(): void
    { $this->expectException(WorkOrderDomainException::class); CuttingReturnAllocationPolicy::freeze([$this->batch(), ['allocation' => null]], 20, 2, []); }

    public function test_return_cannot_restore_another_target(): void
    { $this->expectException(WorkOrderDomainException::class); CuttingReturnAllocationPolicy::freeze([$this->batch()], 99, 1, []); }

    public function test_actual_quantity_must_equal_cost_sources(): void
    { $this->expectException(WorkOrderDomainException::class); CuttingReturnAllocationPolicy::freeze([$this->batch()], 20, 2, []); }

    public function test_ordinary_return_retains_existing_policy(): void
    { self::assertNull(CuttingReturnAllocationPolicy::freeze([['allocation' => null]], 20, 1, [])); }

    public function test_one_actual_root_restores_each_bom_members_own_coverage(): void
    {
        $batch = $this->batch();
        $batch['allocation']['sources'][0]['members'] = [
            ['target_material_requirement_id' => 20, 'material_requirement_id' => 50, 'bom_item_id' => 70, 'covered_piece_qty' => 2, 'covered_base_qty' => '0.20000000', 'coverage_qty' => '0.20000000', 'formal_coverage_qty' => '0.40000000'],
            ['target_material_requirement_id' => 21, 'material_requirement_id' => 51, 'bom_item_id' => 71, 'covered_piece_qty' => 1, 'covered_base_qty' => '0.10000000', 'coverage_qty' => '0.10000000', 'formal_coverage_qty' => '0.20000000'],
        ];
        $result = CuttingReturnAllocationPolicy::freeze([$batch], 20, 1, []);
        self::assertCount(1, $result['sources']); self::assertCount(2, $result['members']);
        self::assertSame('0.20000000', $result['target_coverages'][20]['coverage_qty']);
        self::assertSame('0.10000000', $result['target_coverages'][21]['coverage_qty']);
        self::assertSame('0.40000000', $result['formal_coverages'][50]['formal_coverage_qty']);
        self::assertSame('0.20000000', $result['formal_coverages'][51]['formal_coverage_qty']);
    }

    public function test_returned_source_members_are_not_taken_from_another_stock_piece(): void
    {
        $batch = $this->batch(kind: 'sheet');
        foreach ([0 => 20, 1 => 21] as $index => $id) $batch['allocation']['sources'][$index]['members'] = [[
            'target_material_requirement_id' => $id, 'material_requirement_id' => $id + 30, 'bom_item_id' => $id + 50,
            'covered_piece_qty' => $index === 0 ? 3 : 1, 'covered_base_qty' => $index === 0 ? '0.30000000' : '0.10000000',
            'coverage_qty' => $index === 0 ? '0.30000000' : '0.10000000', 'formal_coverage_qty' => $index === 0 ? '0.60000000' : '0.20000000',
        ]];
        $result = CuttingReturnAllocationPolicy::freeze([$batch], 20, 1, []);
        self::assertSame([21], array_keys($result['target_coverages'])); self::assertSame([51], array_keys($result['formal_coverages']));
        self::assertSame(102, $result['sources'][0]['physical_material_id']);
    }

    public function test_metre_return_uses_frozen_root_identity_and_exact_stock_quantity(): void
    {
        $batch = $this->batch(); $batch['quantity'] = '6'; $batch['allocation']['stock_unit_kind'] = 'm';
        foreach ($batch['allocation']['sources'] as &$source) $source['actual_stock_qty'] = '6.00000000'; unset($source);
        $batch['consumed_source_keys'] = ['root:0'];
        $result = CuttingReturnAllocationPolicy::freeze([$batch], 20, '6', []);
        self::assertSame(1, $result['source_count']); self::assertSame('6.00000000', $result['actual_stock_qty']);
        self::assertSame('root:1', $result['sources'][0]['source_key']); self::assertSame('0.10000000', $result['coverage_qty']);
    }

    public function test_square_metre_return_preserves_eight_place_area_without_board_count_substitution(): void
    {
        $batch = $this->batch(kind: 'sheet'); $batch['quantity'] = '0.11954'; $batch['allocation']['stock_unit_kind'] = 'm2';
        $batch['allocation']['sources'][0]['actual_stock_qty'] = '0.80000000';
        $batch['allocation']['sources'][1]['actual_stock_qty'] = '0.11954000';
        $result = CuttingReturnAllocationPolicy::freeze([$batch], 20, '0.11954', []);
        self::assertSame(1, $result['source_count']); self::assertSame('0.11954000', $result['actual_stock_qty']);
        self::assertSame(102, $result['sources'][0]['physical_material_id']);
    }

    public function test_partial_root_metres_are_rejected_instead_of_inventing_a_root_fraction(): void
    {
        $batch = $this->batch(); $batch['quantity'] = '5'; $batch['allocation']['stock_unit_kind'] = 'm';
        foreach ($batch['allocation']['sources'] as &$source) $source['actual_stock_qty'] = '6'; unset($source);
        $this->expectException(WorkOrderDomainException::class); CuttingReturnAllocationPolicy::freeze([$batch], 20, '5', []);
    }

    public function test_area_stock_without_a_frozen_actual_quantity_is_rejected(): void
    {
        $batch = $this->batch(kind: 'sheet'); $batch['quantity'] = '1'; $batch['allocation']['stock_unit_kind'] = 'm2';
        $this->expectException(WorkOrderDomainException::class); CuttingReturnAllocationPolicy::freeze([$batch], 20, '1', []);
    }
}
