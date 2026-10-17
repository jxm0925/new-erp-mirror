<?php

namespace Tests\Unit\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Services\Erp\CuttingStockAllocationPolicy;
use PHPUnit\Framework\TestCase;

final class CuttingStockAllocationPolicyTest extends TestCase
{
    public function test_one_whole_sheet_can_cover_one_piece_with_point_one_theoretical_consumption(): void
    {
        $plan = CuttingStockAllocationPolicy::plan([['physical_material_id' => 7, 'capacity_piece_qty' => 1]], 1, 1, '0.1', 1, '0.1');
        $this->assertCount(1, $plan['sources']);
        $this->assertSame(1, $plan['planned_cut_piece_qty']);
        $this->assertSame('0.10000000', $plan['planned_coverage_qty']);
        $receipt = CuttingStockAllocationPolicy::select($plan, 1, [7]);
        $this->assertSame('0.10000000', $receipt['coverage_qty']);
        $this->assertSame(1, $receipt['covered_piece_qty']);
    }

    public function test_one_sheet_that_fits_one_piece_cannot_cover_ten_pieces(): void
    {
        $this->expectException(WorkOrderDomainException::class);
        CuttingStockAllocationPolicy::plan([['physical_material_id' => 7, 'capacity_piece_qty' => 1]], 10, 10, '1', 10, '1');
    }

    public function test_partial_actual_picking_and_receipts_follow_the_selected_identity(): void
    {
        $plan = CuttingStockAllocationPolicy::plan([['physical_material_id' => 7, 'capacity_piece_qty' => 3], ['physical_material_id' => 8, 'capacity_piece_qty' => 2]], 5, 5, '0.5', 10, '1');
        $first = CuttingStockAllocationPolicy::select($plan, 1, [7]);
        $second = CuttingStockAllocationPolicy::select($plan, 1, [8]);
        $this->assertSame(3, $first['covered_piece_qty']);
        $this->assertSame('0.30000000', $first['coverage_qty']);
        $this->assertSame(2, $second['covered_piece_qty']);
        $this->assertSame('0.20000000', $second['coverage_qty']);
        $this->assertSame('0.50000000', bcadd($first['coverage_qty'], $second['coverage_qty'], 8));
        $plan['sources'][1]['picked'] = true;
        $this->assertSame($second['sources'][0]['source_key'], CuttingStockAllocationPolicy::select($plan, 1, [8], [], true)['sources'][0]['source_key']);
    }

    public function test_whole_roots_retain_source_capacity_and_do_not_round_theoretical_quantity(): void
    {
        $plan = CuttingStockAllocationPolicy::plan([['capacity_piece_qty' => 4], ['capacity_piece_qty' => 4]], 5, 5, '0.1', 5, '0.1');
        $this->assertSame([4, 1], array_column($plan['sources'], 'covered_piece_qty'));
        $this->assertSame('0.08000000', CuttingStockAllocationPolicy::select($plan, 1)['coverage_qty']);
        $this->assertSame('0.02000000', CuttingStockAllocationPolicy::select($plan, 1, null, ['root:0'])['coverage_qty']);
    }

    public function test_already_received_or_not_picked_stock_cannot_be_selected_again(): void
    {
        $plan = CuttingStockAllocationPolicy::plan([['physical_material_id' => 7, 'capacity_piece_qty' => 1]], 1, 1, '0.1', 1, '0.1');
        $plan['sources'][0]['picked'] = true;
        $this->expectException(WorkOrderDomainException::class);
        CuttingStockAllocationPolicy::select($plan, 1, [7], ['physical:7'], true);
    }

    public function test_duplicate_or_foreign_physical_identity_cannot_advance_coverage(): void
    {
        foreach ([[7, 7], [99]] as $ids) {
            $plan = CuttingStockAllocationPolicy::plan([['physical_material_id' => 7, 'capacity_piece_qty' => 2]], 1, 1, '0.1', 1, '0.1');
            try { CuttingStockAllocationPolicy::select($plan, count($ids), $ids); $this->fail('Invalid identity was accepted.'); }
            catch (WorkOrderDomainException $e) { $this->assertSame('cutting_allocation_invalid', $e->errorCode); }
        }
    }

    public function test_extra_whole_stock_without_a_covered_piece_is_rejected(): void
    {
        $this->expectException(WorkOrderDomainException::class);
        CuttingStockAllocationPolicy::plan([['capacity_piece_qty' => 4], ['capacity_piece_qty' => 4]], 1, 1, '0.1', 1, '0.1');
    }
}
