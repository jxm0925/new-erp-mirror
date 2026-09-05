<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\Item;
use App\Models\Erp\ProductionLaborSession;
use App\Models\Erp\ProductionOutputRecord;
use App\Models\Erp\ProductionQuantityOperation;
use App\Models\Erp\ProductionTask;
use App\Models\Erp\Unit;
use App\Models\Erp\WorkOrder;
use App\Services\Erp\ProductionLaborStatisticsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductionLaborStatisticsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_fastest_and_suggested_standard_use_only_qualified_complete_non_outlier_samples(): void
    {
        $suffix = Str::upper(Str::random(8));
        $unit = Unit::create(['unit_code' => 'EA-'.$suffix, 'unit_name' => '件', 'unit_type' => 'quantity', 'decimal_places' => 0, 'is_base' => true, 'status' => 'enabled']);
        $item = Item::create(['item_code' => 'ITEM-'.$suffix, 'item_name' => '历史工时物料', 'item_type' => 'finished_good', 'unit_id' => $unit->id, 'status' => 'enabled']);
        $operationId = DB::table('erp_production_operations')->insertGetId(['operation_no' => 'OP-'.$suffix,
            'operation_name' => '总装', 'status' => 'enabled', 'sort' => 10, 'business_version' => 1,
            'created_at' => now(), 'updated_at' => now()]);
        $routingId = DB::table('erp_production_routings')->insertGetId(['routing_no' => 'RT-'.$suffix,
            'routing_name' => '历史工时路线', 'output_item_id' => $item->id, 'version' => 3,
            'status' => 'active', 'is_default' => true, 'default_scope_key' => $item->id,
            'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $routingOperationId = DB::table('erp_production_routing_operations')->insertGetId(['routing_id' => $routingId,
            'operation_id' => $operationId, 'sequence' => 10, 'is_key_operation' => true,
            'standard_minutes' => 60, 'setup_standard_minutes' => 0, 'unit_standard_minutes' => 60,
            'created_at' => now(), 'updated_at' => now()]);
        foreach ([50, 52, 54, 56, 58, 60, 1000] as $index => $minutes) {
            $workOrder = WorkOrder::create([
                'work_order_no' => 'WO-'.$suffix.'-'.$index, 'source_type' => 'stock_prebuild',
                'output_item_id' => $item->id, 'production_routing_id' => $routingId,
                'routing_version_snapshot' => 3, 'target_qty' => 1, 'target_base_qty' => 1,
                'target_unit_id' => $unit->id, 'base_unit_id' => $unit->id,
                'status' => 'COMPLETED', 'business_version' => 1,
            ]);
            $target = ProductionQuantityOperation::create([
                'work_order_id' => $workOrder->id, 'routing_operation_id_snapshot' => $routingOperationId,
                'operation_id_snapshot' => $operationId, 'operation_code_snapshot' => 'ASSEMBLY',
                'operation_name_snapshot' => '总装', 'sequence_no_snapshot' => 10,
                'status' => 'COMPLETED', 'planned_base_qty' => 1, 'completed_base_qty' => 1,
                'scrapped_base_qty' => 0, 'remaining_base_qty' => 0,
                'standard_minutes_snapshot' => 60, 'quality_mode_snapshot' => 'none',
                'output_mode_snapshot' => 'warehouse_required', 'completed_at' => now(), 'business_version' => 1,
            ]);
            $task = ProductionTask::create([
                'task_no' => 'PT-'.$suffix.'-'.$index, 'work_order_id' => $workOrder->id,
                'execution_mode' => 'quantity', 'routing_operation_id_snapshot' => $routingOperationId,
                'operation_code_snapshot' => 'ASSEMBLY', 'operation_name_snapshot' => '总装',
                'sequence_no_snapshot' => 10, 'status' => 'COMPLETED', 'business_version' => 1,
            ]);
            ProductionLaborSession::create([
                'task_id' => $task->id, 'target_type' => 'quantity_operation', 'target_id' => $target->id,
                'employee_legacy_id' => 33001, 'role' => 'owner', 'status' => 'ENDED',
                'started_at' => now()->subMinutes($minutes), 'ended_at' => now(),
                'actual_labor_minutes' => $minutes, 'responsibility_weight_snapshot' => 1,
                'credited_labor_minutes' => $minutes,
            ]);
            ProductionOutputRecord::create([
                'output_no' => 'POUT-'.$suffix.'-'.$index, 'work_order_id' => $workOrder->id,
                'source_target_type' => 'quantity_operation', 'source_target_id' => $target->id,
                'output_item_id' => $item->id, 'output_base_qty' => 1,
                'output_mode_snapshot' => 'warehouse_required', 'quality_mode_snapshot' => 'none',
                'status' => 'WAREHOUSED', 'created_by_legacy_id' => 33001, 'produced_at' => now(), 'business_version' => 1,
            ]);
        }

        $result = app(ProductionLaborStatisticsService::class)->statistics([
            'output_item_id' => $item->id, 'routing_id' => $routingId, 'routing_version' => 3,
            'routing_operation_id' => $routingOperationId, 'employee_legacy_id' => 33001,
        ], ['production.labor_stats.view']);

        $this->assertSame(7, $result['sample_count_before_outlier_filter']);
        $this->assertSame(6, $result['qualified_sample_count']);
        $this->assertSame(1, $result['excluded_outlier_count']);
        $this->assertSame(50.0, $result['historical_fastest_qualified_minutes']);
        $this->assertSame(55.0, $result['historical_average_qualified_minutes']);
        $this->assertSame(50.0, $result['employee_fastest_qualified_minutes']);
        $this->assertSame(55.0, $result['suggested_standard_minutes']);
        $this->assertTrue($result['suggestion_sample_sufficient']);
        $this->assertTrue($result['suggestion_is_read_only']);
    }
}
