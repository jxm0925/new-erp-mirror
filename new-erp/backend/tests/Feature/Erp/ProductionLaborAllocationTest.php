<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\Item;
use App\Models\Erp\ProductionLaborSession;
use App\Models\Erp\ProductionTask;
use App\Models\Erp\Unit;
use App\Models\Erp\WorkOrder;
use App\Services\Erp\ProductionLaborAllocationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductionLaborAllocationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_actual_labor_is_never_rewritten_and_responsibility_labor_uses_frozen_rule(): void
    {
        $suffix = Str::upper(Str::random(8));
        $unit = Unit::create(['unit_code' => 'EA-'.$suffix, 'unit_name' => '件', 'unit_type' => 'quantity', 'decimal_places' => 0, 'is_base' => true, 'status' => 'enabled']);
        $item = Item::create(['item_code' => 'ITEM-'.$suffix, 'item_name' => '协同工时物料', 'item_type' => 'finished_good', 'unit_id' => $unit->id, 'status' => 'enabled']);
        $workOrder = WorkOrder::create(['work_order_no' => 'WO-'.$suffix, 'source_type' => 'stock_prebuild',
            'output_item_id' => $item->id, 'target_qty' => 1, 'target_base_qty' => 1, 'target_unit_id' => $unit->id,
            'base_unit_id' => $unit->id, 'status' => 'IN_PROGRESS', 'business_version' => 1]);
        $task = ProductionTask::create(['task_no' => 'PT-'.$suffix, 'work_order_id' => $workOrder->id,
            'execution_mode' => 'unit', 'operation_code_snapshot' => 'ASSEMBLY', 'operation_name_snapshot' => '装配',
            'sequence_no_snapshot' => 10, 'status' => 'IN_PROGRESS', 'assignee_user_legacy_id' => 101,
            'labor_allocation_rule_version' => 1, 'labor_allocation_rule_snapshot' => [
                'rule_no' => 'GLOBAL_LABOR_ALLOCATION', 'version_no' => 1, 'owner_ratio' => 0.6,
                'collaborator_total_ratio' => 0.4, 'collaborator_allocation_method' => 'actual_labor_ratio',
            ], 'business_version' => 1]);
        foreach ([[101, 'owner', 60], [102, 'collaborator', 120], [103, 'collaborator', 60]] as [$employee, $role, $minutes]) {
            ProductionLaborSession::create(['task_id' => $task->id, 'target_type' => 'unit_operation', 'target_id' => 88001,
                'employee_legacy_id' => $employee, 'role' => $role, 'status' => 'ENDED', 'started_at' => now()->subMinutes($minutes),
                'ended_at' => now(), 'actual_labor_minutes' => $minutes, 'responsibility_weight_snapshot' => 0,
                'credited_labor_minutes' => 0]);
        }

        $result = app(ProductionLaborAllocationService::class)->allocate($task, 'unit_operation', 88001);
        $sessions = ProductionLaborSession::query()->where('task_id', $task->id)->orderBy('employee_legacy_id')->get();
        $this->assertSame([60.0, 120.0, 60.0], $sessions->map(fn ($row) => (float) $row->actual_labor_minutes)->all());
        $this->assertSame([144.0, 64.0, 32.0], $sessions->map(fn ($row) => (float) $row->credited_labor_minutes)->all());
        $this->assertSame(240.0, $result['actual_labor_minutes']);
        $this->assertSame(240.0, $result['credited_labor_minutes']);
        $this->assertSame(1, data_get($result, 'rule_snapshot.version_no'));
    }
}
