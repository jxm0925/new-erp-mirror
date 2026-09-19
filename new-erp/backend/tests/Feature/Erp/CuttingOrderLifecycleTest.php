<?php

namespace Tests\Feature\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Services\Erp\{CuttingConfirmationService, CuttingDemandService, CuttingInputService, CuttingOrderLifecycleService,
    CuttingHandoverService, CuttingReadService, CuttingRecordService, CuttingTaskExecutionService, CuttingWarehouseReceiptService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CuttingOrderLifecycleTest extends TestCase
{
    use DatabaseTransactions;
    use \Tests\Support\CuttingTestFixtures;

    public function test_authenticated_http_runs_one_complete_cutting_order_from_publish_to_close(): void
    {
        $f = $this->fixture(publish: false); $token = $this->token($f['user']);
        $base = '/api/v1/erp/production/cutting';
        $created = $this->withToken($token)->postJson($base.'/orders/publish',
            $this->payload(0) + ['plans' => [$f['planPayload']]])
            ->assertCreated()->assertJsonPath('data.status', 'PUBLISHED')->json('data');
        $f['order'] = (int) $created['cutting_order_id'];
        $f['created'] = $created;
        $f['allowed'] = (int) DB::table('erp_cutting_allowed_outputs')->where('cutting_order_id', $f['order'])->value('id');
        $taskId = (int) $created['cutting_task_id'];

        $reserved = $this->withToken($token)->postJson($base.'/orders/'.$f['order'].'/reserve', $this->payload(1) + [
            'physical_material_ids' => [$f['physicals'][0]],
        ])->assertOk()->json('data');
        $issued = $this->withToken($token)->postJson($base.'/orders/'.$f['order'].'/issue',
            $this->payload((int) $reserved['business_version']) + ['physical_material_id' => $f['physicals'][0]])
            ->assertCreated()->assertJsonPath('data.status', 'PROCESSING')->json('data');
        $batchId = (int) $issued['settlement_batch_id'];

        $this->withToken($token)->postJson($base.'/tasks/'.$taskId.'/claim', $this->payload(1))
            ->assertOk()->assertJsonPath('data.status', 'READY');
        $this->withToken($token)->postJson($base.'/tasks/'.$taskId.'/start', $this->payload($this->taskVersion($taskId)))
            ->assertOk()->assertJsonPath('data.status', 'IN_PROGRESS');

        $saved = $this->withToken($token)->putJson($base.'/settlements/'.$batchId.'/results',
            $this->payload(1) + ['results' => [[
                'client_row_id' => 'http-e2e', 'result_type' => 'product',
                'allowed_output_id' => $f['allowed'], 'actual_qty' => '10',
            ]]])->assertOk()->json('data');
        $resultId = (int) $saved['result_ids'][0];
        $route = $this->withToken($token)->putJson($base.'/results/'.$resultId.'/routes', $this->payload(1) + [
            'routes' => [['route_type' => 'WAREHOUSE', 'quantity' => '10']],
        ])->assertOk()->json('data.routes.0');
        $this->withToken($token)->postJson($base.'/settlements/'.$batchId.'/submit',
            $this->payload((int) DB::table('erp_cutting_settlement_batches')->where('id', $batchId)->value('business_version')))
            ->assertOk()->assertJsonPath('data.status', 'WAIT_CONFIRM');
        $confirm = $this->confirmation($f, $batchId, $resultId, $issued['original_total_cost'], false);
        $this->withToken($token)->postJson($base.'/settlements/'.$batchId.'/confirm', $confirm)
            ->assertOk()->assertJsonPath('data.status', 'CONFIRMED');
        $this->withToken($token)->postJson($base.'/tasks/'.$taskId.'/finish', $this->payload($this->taskVersion($taskId)))
            ->assertOk()->assertJsonPath('data.status', 'FINISHED');
        $this->withToken($token)->postJson($base.'/routes/'.$route['id'].'/warehouse',
            $this->payload((int) DB::table('erp_cutting_result_routes')->where('id', $route['id'])->value('business_version')) + [
                'quantity' => '10', 'warehouse_id' => $f['warehouse']->id, 'location_id' => $f['location']->id,
                'batch_no' => 'CUT-HTTP-'.Str::ulid(),
            ])->assertCreated()->assertJsonPath('data.route_status', 'WAREHOUSED');
        $this->withToken($token)->postJson($base.'/orders/'.$f['order'].'/close',
            $this->payload($this->orderVersion($f['order'])) + ['reason' => 'HTTP全流程事实均已完成'])
            ->assertOk()->assertJsonPath('data.status', 'CLOSED');
        $this->withToken($token)->getJson($base.'/orders/'.$f['order'].'/execution')
            ->assertOk()->assertJsonPath('data.order.status', 'CLOSED')
            ->assertJsonPath('data.lifecycle.status', 'CLOSED')
            ->assertJsonPath('data.results.data.0.routes.0.status', 'WAREHOUSED');
    }

    public function test_real_warehouse_flow_finishes_before_close_and_close_releases_shortfall_commitment(): void
    {
        $f = $this->fixture(required: '10', planned: '10');
        $taskId = (int) $f['created']['cutting_task_id'];
        $batch = $this->issue($f); $batchId = (int) $batch['settlement_batch_id'];
        $tasks = app(CuttingTaskExecutionService::class);
        $tasks->claim($taskId, $this->payload($this->taskVersion($taskId)), $f['user'], self::PERMISSIONS, true);
        $tasks->start($taskId, $this->payload($this->taskVersion($taskId)), $f['user'], self::PERMISSIONS, true);

        $resultId = (int) $this->save($f, $batchId, '6')['result_ids'][0];
        $routeId = (int) $this->route($f, $resultId, '6')['routes'][0]['id'];
        app(CuttingConfirmationService::class)->confirm($batchId,
            $this->confirmation($f, $batchId, $resultId, $batch['original_total_cost']),
            $f['user'], self::PERMISSIONS, true);
        $finished = $tasks->finish($taskId, $this->payload($this->taskVersion($taskId)), $f['user'], self::PERMISSIONS, true);
        $this->assertSame('FINISHED', $finished['status']);
        $this->assertSame('PUBLISHED', DB::table('erp_cutting_orders')->where('id', $f['order'])->value('status'));

        $lifecycle = app(CuttingOrderLifecycleService::class);
        $blockedPayload = $this->payload($this->orderVersion($f['order'])) + ['reason' => '加工任务已完成，申请关单'];
        $blocked = $this->domain(fn () => $lifecycle->close($f['order'], $blockedPayload, $f['user'], self::PERMISSIONS, true));
        $this->assertSame('cutting_order_close_blocked', $blocked->errorCode);
        $this->assertContains('route_not_received', array_column($blocked->details['blockers'], 'code'));
        $this->assertSame(0, DB::table('erp_cutting_events')->where('aggregate_type', 'order')
            ->where('aggregate_id', $f['order'])->where('action', 'close')->count());

        app(CuttingWarehouseReceiptService::class)->post($routeId, $this->payload(2) + [
            'quantity' => '6', 'warehouse_id' => $f['warehouse']->id, 'location_id' => $f['location']->id,
            'batch_no' => 'CUT-CLOSE-'.Str::ulid(),
        ], $f['user'], self::PERMISSIONS, true);

        $payload = $this->payload($this->orderVersion($f['order'])) + ['reason' => '本次实际完成六件，余量释放后续安排'];
        $token = $this->token($f['user']);
        $closed = $this->withToken($token)->postJson('/api/v1/erp/production/cutting/orders/'.$f['order'].'/close', $payload)
            ->assertOk()->assertJsonPath('message', '下料单已关闭')->assertJsonPath('data.status', 'CLOSED')->json('data');
        $this->withToken($token)->postJson('/api/v1/erp/production/cutting/orders/'.$f['order'].'/close', $payload)
            ->assertOk()->assertJsonPath('data.business_version', $closed['business_version']);

        $plan = DB::table('erp_cutting_plan_allocations')->where('cutting_order_id', $f['order'])->first();
        $this->assertSame('10.00000000', $plan->planned_qty);
        $this->assertSame('6.00000000', $plan->closed_planned_qty);
        $demand = app(CuttingDemandService::class)->show((int) $plan->demand_id, [], $f['user'], self::PERMISSIONS, true);
        $this->assertSame('6.00000000', $demand['planned_qty']);
        $this->assertSame('4.00000000', $demand['remaining_demand_qty']);
        $this->assertSame(1, DB::table('erp_cutting_events')->where('aggregate_type', 'order')
            ->where('aggregate_id', $f['order'])->where('action', 'close')->count());

        $projection = app(CuttingReadService::class)->execution($f['order'], [], $f['user'], self::PERMISSIONS, true);
        $this->assertSame('CLOSED', $projection['lifecycle']['status']);
        $this->assertFalse($projection['lifecycle']['can_close']);
        $this->assertTrue($projection['lifecycle']['close_permitted']);

        $other = $this->employee('close-replay-');
        $conflict = $this->domain(fn () => $lifecycle->close($f['order'], $payload, $other, self::PERMISSIONS, true));
        $this->assertSame('idempotency_hash_conflict', $conflict->errorCode);
        $this->assertSame(1, DB::table('erp_cutting_events')->where('aggregate_type', 'order')
            ->where('aggregate_id', $f['order'])->where('action', 'close')->count());
    }

    public function test_cancel_before_issue_releases_each_reserved_physical_and_restores_formal_demand(): void
    {
        $f = $this->fixture();
        $reserved = app(CuttingInputService::class)->reserve($f['order'], $this->payload(1) + [
            'physical_material_ids' => $f['physicals'],
        ], $f['user'], self::PERMISSIONS, true);
        $payload = $this->payload((int) $reserved['business_version']) + ['reason' => '正式计划撤回，材料尚未领出'];
        $service = app(CuttingOrderLifecycleService::class);
        $cancelled = $service->cancel($f['order'], $payload, $f['user'], self::PERMISSIONS, true);
        $this->assertSame('CANCELLED', $cancelled['status']);
        $this->assertEqualsCanonicalizing(array_map('intval', $f['physicals']), $cancelled['released_physical_material_ids']);
        $this->assertSame(['AVAILABLE', 'AVAILABLE'], DB::table('erp_material_physicals')->whereIn('id', $f['physicals'])
            ->orderBy('id')->pluck('status')->all());
        $this->assertSame(2, DB::table('erp_material_physical_reservations')->where('cutting_order_id', $f['order'])
            ->where('status', 'RELEASED')->count());
        $this->assertSame('CANCELLED', DB::table('erp_cutting_tasks')->where('cutting_order_id', $f['order'])->value('status'));

        $plan = DB::table('erp_cutting_plan_allocations')->where('cutting_order_id', $f['order'])->first();
        $demand = app(CuttingDemandService::class)->show((int) $plan->demand_id, [], $f['user'], self::PERMISSIONS, true);
        $this->assertSame('0.00000000', $demand['planned_qty']);
        $this->assertSame('10.00000000', $demand['remaining_demand_qty']);
        $this->assertSame($cancelled, $service->cancel($f['order'], $payload, $f['user'], self::PERMISSIONS, true));
        $this->assertSame(1, DB::table('erp_cutting_events')->where('aggregate_type', 'order')
            ->where('aggregate_id', $f['order'])->where('action', 'cancel')->count());
    }

    public function test_real_next_operation_handover_must_be_received_before_order_can_close(): void
    {
        $f = $this->fixture(); $receiver = $this->employee('close-receiver-'); $this->consumerTask($f, $receiver);
        $taskId = (int) $f['created']['cutting_task_id']; $batch = $this->issue($f);
        $tasks = app(CuttingTaskExecutionService::class);
        $tasks->claim($taskId, $this->payload($this->taskVersion($taskId)), $f['user'], self::PERMISSIONS, true);
        $tasks->start($taskId, $this->payload($this->taskVersion($taskId)), $f['user'], self::PERMISSIONS, true);
        $resultId = (int) $this->save($f, $batch['settlement_batch_id'], '10')['result_ids'][0];
        $split = app(CuttingRecordService::class)->splitRoutes($resultId, $this->payload(1) + ['routes' => [[
            'route_type' => 'NEXT_OPERATION', 'quantity' => '10',
            'target_material_requirement_id' => $f['targetRequirement'],
        ]]], $f['user'], self::PERMISSIONS, true);
        $routeId = (int) $split['routes'][0]['id'];
        app(CuttingConfirmationService::class)->confirm($batch['settlement_batch_id'],
            $this->confirmation($f, $batch['settlement_batch_id'], $resultId, $batch['original_total_cost']),
            $f['user'], self::PERMISSIONS, true);
        $tasks->finish($taskId, $this->payload($this->taskVersion($taskId)), $f['user'], self::PERMISSIONS, true);

        $service = app(CuttingOrderLifecycleService::class);
        $before = DB::table('erp_inventory_transactions')->count();
        $blocked = $this->domain(fn () => $service->close($f['order'],
            $this->payload($this->orderVersion($f['order'])) + ['reason' => '等待下一工序接收'],
            $f['user'], self::PERMISSIONS, true));
        $this->assertSame('cutting_order_close_blocked', $blocked->errorCode);
        $this->assertContains('route_not_received', array_column($blocked->details['blockers'], 'code'));

        $handover = app(CuttingHandoverService::class);
        $dispatched = $handover->dispatch($routeId,
            $this->payload((int) DB::table('erp_cutting_result_routes')->where('id', $routeId)->value('business_version')) + ['quantity' => '10'],
            $f['user'], self::PERMISSIONS, true);
        $received = $handover->accept($dispatched['handover_id'], $this->payload(1) + ['quantity' => '10'],
            $receiver, self::PERMISSIONS, true);
        $this->assertSame('RECEIVED', $received['route_status']);

        $closed = $service->close($f['order'], $this->payload($this->orderVersion($f['order'])) + [
            'reason' => '加工、核算和下一工序接收均已完成',
        ], $f['user'], self::PERMISSIONS, true);
        $this->assertSame('CLOSED', $closed['status']);
        $this->assertSame($before, DB::table('erp_inventory_transactions')->count(), '真实工序交接与关单不得伪造仓库流水');
        $this->assertSame('10.00000000', (string) DB::table('erp_production_target_material_requirements')
            ->where('id', $f['targetRequirement'])->value('satisfied_base_qty'));
    }

    public function test_cancel_after_formal_issue_is_rejected_without_changing_inventory_or_execution_facts(): void
    {
        $f = $this->fixture(); $issued = $this->issue($f); $batchId = (int) $issued['settlement_batch_id'];
        $physical = DB::table('erp_material_physicals')->where('id', $f['physicals'][0])->first();
        $balance = $f['balance']->fresh();
        $payload = $this->payload($this->orderVersion($f['order'])) + ['reason' => '错误取消尝试'];
        $exception = $this->domain(fn () => app(CuttingOrderLifecycleService::class)
            ->cancel($f['order'], $payload, $f['user'], self::PERMISSIONS, true));
        $this->assertSame('cutting_order_cancel_blocked', $exception->errorCode);
        $this->assertContains('input_already_issued', array_column($exception->details['blockers'], 'code'));
        $this->assertSame('PUBLISHED', DB::table('erp_cutting_orders')->where('id', $f['order'])->value('status'));
        $this->assertSame('PROCESSING', DB::table('erp_cutting_settlement_batches')->where('id', $batchId)->value('status'));
        $this->assertSame($physical->status, DB::table('erp_material_physicals')->where('id', $physical->id)->value('status'));
        $this->assertSame((string) $balance->quantity_on_hand, (string) $f['balance']->fresh()->quantity_on_hand);
        $this->assertSame((string) $balance->inventory_value, (string) $f['balance']->fresh()->inventory_value);
        $this->assertSame(0, DB::table('erp_cutting_events')->where('aggregate_type', 'order')
            ->where('aggregate_id', $f['order'])->where('action', 'cancel')->count());
    }

    private function taskVersion(int $id): int
    { return (int) DB::table('erp_cutting_tasks')->where('id', $id)->value('business_version'); }

    private function orderVersion(int $id): int
    { return (int) DB::table('erp_cutting_orders')->where('id', $id)->value('business_version'); }

    private function domain(callable $callback): WorkOrderDomainException
    {
        try { $callback(); $this->fail('Expected domain exception.'); }
        catch (WorkOrderDomainException $exception) { return $exception; }
    }
}
