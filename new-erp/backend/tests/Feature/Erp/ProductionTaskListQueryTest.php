<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\{Item, Unit, WorkOrder, ProductionTask, ProductionQuantityOperation, ProductionTaskTarget};
use App\Services\Erp\ProductionTaskQueryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductionTaskListQueryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_filters_and_summary_use_all_targets_before_twenty_row_pagination(): void
    {
        $suffix = Str::upper(Str::random(8));
        $unit = Unit::create(['unit_code' => 'QL-'.$suffix, 'unit_name' => '件', 'unit_type' => 'quantity', 'decimal_places' => 0, 'is_base' => true, 'status' => 'enabled']);
        $item = Item::create(['item_code' => 'QL-'.$suffix, 'item_name' => '查询验证物料', 'item_type' => 'finished_good', 'unit_id' => $unit->id, 'status' => 'enabled']);
        $order = WorkOrder::create(['work_order_no' => 'QL-'.$suffix, 'source_type' => 'stock_prebuild', 'output_item_id' => $item->id, 'target_qty' => 25, 'target_base_qty' => 25, 'target_unit_id' => $unit->id, 'base_unit_id' => $unit->id, 'status' => 'RELEASED', 'business_version' => 1]);
        $user = (object) ['legacy_id' => 33001];
        $permissions = ['production.task.view'];
        $service = app(ProductionTaskQueryService::class);
        $tasks = [];
        $operationId = DB::table('erp_production_operations')->insertGetId(['operation_no' => 'QL-'.$suffix, 'operation_name' => '装配', 'status' => 'enabled', 'sort' => 10, 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $routingId = DB::table('erp_production_routings')->insertGetId(['routing_no' => 'QL-'.$suffix, 'routing_name' => '查询验证路线', 'output_item_id' => $item->id, 'version' => 1, 'status' => 'draft', 'is_default' => false, 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        for ($i = 0; $i < 25; $i++) {
            $nodeId = DB::table('erp_production_routing_operations')->insertGetId(['routing_id' => $routingId, 'operation_id' => $operationId, 'sequence' => $i + 1, 'is_key_operation' => false, 'created_at' => now(), 'updated_at' => now()]);
            $task = ProductionTask::create(['task_no' => 'QL-'.$suffix.'-'.$i, 'work_order_id' => $order->id, 'execution_mode' => 'quantity', 'routing_operation_id_snapshot' => $nodeId, 'operation_code_snapshot' => 'OP', 'operation_name_snapshot' => '装配', 'sequence_no_snapshot' => $i + 1, 'status' => 'CLAIMED', 'assignee_user_legacy_id' => 33001, 'business_version' => 1]);
            $target = ProductionQuantityOperation::create(['work_order_id' => $order->id, 'routing_operation_id_snapshot' => $nodeId, 'operation_id_snapshot' => $operationId, 'operation_code_snapshot' => 'OP', 'operation_name_snapshot' => '装配', 'sequence_no_snapshot' => $i + 1, 'status' => 'WAIT_MATERIAL', 'planned_base_qty' => 1, 'remaining_base_qty' => 1, 'business_version' => 1]);
            ProductionTaskTarget::create(['task_id' => $task->id, 'target_type' => 'quantity_operation', 'target_id' => $target->id, 'status_snapshot' => 'WAIT_MATERIAL']);
            $tasks[] = [$task, $target];
        }
        $filters = ['work_order_id' => $order->id, 'view' => 'owned', 'per_page' => 20];
        $page = $service->paginate($filters, $user, $permissions, true);
        $this->assertSame(25, $page->total());
        $this->assertCount(20, $page->items());
        Paginator::currentPageResolver(fn () => 2);
        try {
            $next = $service->paginate($filters, $user, $permissions, true);
            $this->assertCount(5, $next->items());
            $this->assertEmpty(array_intersect($page->getCollection()->pluck('id')->all(), $next->getCollection()->pluck('id')->all()));
        } finally {
            Paginator::currentPageResolver(fn ($name = 'page') => max(1, (int) request()->input($name, 1)));
        }
        $tasks[0][1]->update(['status' => 'COMPLETED', 'completed_at' => now()->subDay()]);
        $tasks[1][1]->update(['status' => 'COMPLETED', 'completed_at' => now()]);
        // First target completed, second still processing: the task must stay in progress.
        $tasks[2][1]->update(['status' => 'COMPLETED', 'completed_at' => now()]);
        $tasks[3][1]->update(['status' => 'IN_PROGRESS']);
        ProductionTaskTarget::where('task_id', $tasks[3][0]->id)->update(['task_id' => $tasks[2][0]->id]);
        $tasks[3][0]->delete();
        $stats = $service->summary($filters, $user, $permissions, true);
        $this->assertSame(24, $stats['total']);
        $this->assertSame(21, $stats['kitting']);
        $this->assertSame(1, $stats['running']);
        $this->assertSame(2, $stats['completed']);
        $this->assertSame(1, $stats['completed_today']);
        $workbench = $service->workbenchSummary(['work_order_id' => $order->id, 'view' => 'owned'], $user, $permissions, true);
        $this->assertSame(24, $workbench['total']);
        $this->assertSame(1, $workbench['running']);
        $this->assertSame(21, $workbench['waiting']);
        $this->assertSame(2, $workbench['completed']);
        $this->assertSame(0, $workbench['exception']);
        $this->assertSame(8.3, $workbench['completion_rate']);
        $this->assertCount(7, $workbench['trend']);
        $this->assertSame(1, $workbench['trend'][5]['completed']);
        $this->assertSame(1, $workbench['trend'][6]['completed']);
        $filtered = $service->paginate($filters + ['execution_filter' => 'running'], $user, $permissions, true);
        $this->assertSame($tasks[2][0]->id, $filtered->items()[0]->id);
        $searched = $service->paginate($filters + ['keyword' => '查询验证物料'], $user, $permissions, true);
        $this->assertSame(24, $searched->total());
        $this->assertSame(0, $service->paginate($filters, (object) ['legacy_id' => 33002], $permissions, true)->total());
    }
}
