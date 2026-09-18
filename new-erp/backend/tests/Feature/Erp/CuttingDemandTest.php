<?php

namespace Tests\Feature\Erp;

use App\Services\Erp\CuttingConfirmationService;
use App\Services\Erp\CuttingDemandService;
use App\Services\Erp\CuttingHandoverService;
use App\Services\Erp\CuttingRecordService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\CuttingTestFixtures;
use Tests\TestCase;

class CuttingDemandTest extends TestCase
{
    use CuttingTestFixtures;
    use DatabaseTransactions;

    public function test_http_generation_is_source_idempotent_and_read_projection_is_paginated(): void
    {
        $fixture = $this->fixture('none', '10', '10', false, false);
        $token = $this->token($fixture['user']);
        $payload = $this->payload(0) + [
            'source_requirement_id' => $fixture['targetRequirement'],
            'producer_work_order_id' => $fixture['wo']->id,
            'producer_stage_id' => $fixture['stage'],
        ];

        $response = $this->withToken($token)->postJson('/api/v1/erp/production/cutting/demands/generate', $payload)
            ->assertCreated()->assertJsonPath('message', '正式下料需求已生成')
            ->assertJsonPath('data.status', 'ACTIVE')->assertJsonPath('data.generated_now', true)
            ->assertJsonPath('data.planned_qty', '0.00000000')
            ->assertJsonPath('data.remaining_demand_qty', '10.00000000');
        $demand = $response->json('data');
        $this->withToken($token)->postJson('/api/v1/erp/production/cutting/demands/generate', $payload)
            ->assertCreated()->assertExactJson($response->json());

        $second = $this->payload(0) + [
            'source_requirement_id' => $fixture['targetRequirement'],
            'producer_work_order_id' => $fixture['wo']->id,
            'producer_stage_id' => $fixture['stage'],
        ];
        $this->withToken($token)->postJson('/api/v1/erp/production/cutting/demands/generate', $second)
            ->assertCreated()->assertJsonPath('data.id', $demand['id'])->assertJsonPath('data.generated_now', false);
        $this->assertSame(1, DB::table('erp_cutting_demands')->where('source_requirement_id', $fixture['targetRequirement'])->count());
        $this->assertSame(1, DB::table('erp_cutting_events')->where('aggregate_type', 'demand')
            ->where('aggregate_id', $demand['id'])->where('action', 'generate')->count());

        $this->withToken($token)->getJson('/api/v1/erp/production/cutting/demands?per_page=1&keyword='.$demand['demand_no'])
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $demand['id'])
            ->assertJsonPath('data.0.consumer_work_order.id', $fixture['consumerWo']->id);
        $this->withToken($token)->getJson('/api/v1/erp/production/cutting/demands/'.$demand['id'].'?revision_per_page=1')
            ->assertOk()->assertJsonPath('data.revisions.meta.total', 0)
            ->assertJsonPath('data.source_requirement_id', $fixture['targetRequirement']);

        $outsider = $this->employee('demand-outsider-');
        $outsiderToken = $this->token($outsider, 'self');
        $this->withToken($outsiderToken)->getJson('/api/v1/erp/production/cutting/demands?source_requirement_id='.$fixture['targetRequirement'])
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->withToken($outsiderToken)->getJson('/api/v1/erp/production/cutting/demands/'.$demand['id'])
            ->assertForbidden()->assertJsonPath('error_code', 'data_scope_denied');
    }

    public function test_revision_records_only_relevant_source_change_and_rejects_quantity_below_commitment(): void
    {
        $fixture = $this->fixture('none', '10', '6');
        $token = $this->token($fixture['user']);
        $demand = DB::table('erp_cutting_demands')->where('source_requirement_id', $fixture['targetRequirement'])->sole();
        DB::table('erp_production_target_material_requirements')->where('id', $fixture['targetRequirement'])->update([
            'required_base_qty' => '12', 'business_version' => 2, 'updated_at' => now(),
        ]);
        $payload = $this->payload(1) + ['reason' => '正式装配数量增加'];
        $response = $this->withToken($token)->postJson('/api/v1/erp/production/cutting/demands/'.$demand->id.'/revisions', $payload)
            ->assertOk()->assertJsonPath('message', '正式下料需求变更已记录')
            ->assertJsonPath('data.business_version', 2)->assertJsonPath('data.required_base_qty', '12.00000000')
            ->assertJsonPath('data.planned_qty', '6.00000000')->assertJsonPath('data.remaining_demand_qty', '6.00000000')
            ->assertJsonPath('data.revision.quantity_delta', '2.00000000');
        $this->withToken($token)->postJson('/api/v1/erp/production/cutting/demands/'.$demand->id.'/revisions', $payload)
            ->assertOk()->assertExactJson($response->json());

        DB::table('erp_production_target_material_requirements')->where('id', $fixture['targetRequirement'])->update([
            'satisfied_base_qty' => '2', 'status' => 'PARTIALLY_SATISFIED', 'business_version' => 3, 'updated_at' => now(),
        ]);
        $this->withToken($token)->postJson('/api/v1/erp/production/cutting/demands/'.$demand->id.'/revisions',
            $this->payload(2) + ['reason' => '仅同步接收进度'])
            ->assertStatus(409)->assertJsonPath('error_code', 'demand_revision_not_required');

        DB::table('erp_production_target_material_requirements')->where('id', $fixture['targetRequirement'])->update([
            'required_base_qty' => '5', 'satisfied_base_qty' => '0', 'status' => 'OPEN',
            'business_version' => 4, 'updated_at' => now(),
        ]);
        $this->withToken($token)->postJson('/api/v1/erp/production/cutting/demands/'.$demand->id.'/revisions',
            $this->payload(2) + ['reason' => '错误减少数量'])
            ->assertStatus(409)->assertJsonPath('error_code', 'demand_revision_below_commitment')
            ->assertJsonPath('details.committed_qty', '6.00000000');
        $this->assertSame(1, DB::table('erp_cutting_demand_revisions')->where('demand_id', $demand->id)->count());

        DB::table('erp_production_target_material_requirements')->where('id', $fixture['targetRequirement'])->update([
            'required_base_qty' => '12', 'status' => 'CLOSED', 'business_version' => 5, 'updated_at' => now(),
        ]);
        $this->withToken($token)->postJson('/api/v1/erp/production/cutting/demands/'.$demand->id.'/revisions',
            $this->payload(2) + ['reason' => '正式需求已经关闭'])
            ->assertOk()->assertJsonPath('data.status', 'CLOSED')->assertJsonPath('data.business_version', 3)
            ->assertJsonPath('data.revision.demand_status_before', 'ACTIVE')
            ->assertJsonPath('data.revision.demand_status_after', 'CLOSED');
        $this->assertSame(2, DB::table('erp_cutting_demand_revisions')->where('demand_id', $demand->id)->count());
    }

    public function test_cutting_receipt_does_not_double_count_the_plan_and_remaining_quantity_can_be_planned(): void
    {
        $fixture = $this->fixture('none', '10', '6');
        $receiver = $this->employee('demand-receiver-');
        $this->consumerTask($fixture, $receiver);
        $batch = $this->issue($fixture);
        $batchId = $batch['settlement_batch_id'];
        $resultId = $this->save($fixture, $batchId, '6')['result_ids'][0];
        $split = app(CuttingRecordService::class)->splitRoutes($resultId, $this->payload(1) + ['routes' => [[
            'route_type' => 'NEXT_OPERATION', 'quantity' => '6',
            'target_material_requirement_id' => $fixture['targetRequirement'],
        ]]], $fixture['user'], self::PERMISSIONS, true);
        $routeId = $split['routes'][0]['id'];
        app(CuttingConfirmationService::class)->confirm(
            $batchId, $this->confirmation($fixture, $batchId, $resultId, '3000'),
            $fixture['user'], self::PERMISSIONS, true
        );
        $routeVersion = (int) DB::table('erp_cutting_result_routes')->where('id', $routeId)->value('business_version');
        $handover = app(CuttingHandoverService::class)->dispatch(
            $routeId, $this->payload($routeVersion) + ['quantity' => '6'],
            $fixture['user'], self::PERMISSIONS, true
        );
        app(CuttingHandoverService::class)->accept(
            $handover['handover_id'], $this->payload(1) + ['quantity' => '6'],
            $receiver, self::PERMISSIONS, true
        );

        $demandId = (int) DB::table('erp_cutting_demands')->where('source_requirement_id', $fixture['targetRequirement'])->value('id');
        $before = app(CuttingDemandService::class)->show($demandId, [], $fixture['user'], self::PERMISSIONS, true);
        $this->assertSame('6.00000000', $before['planned_qty']);
        $this->assertSame('6.00000000', $before['received_qty']);
        $this->assertSame('6.00000000', $before['trace']['direct_cutting_received_qty']);
        $this->assertSame('0.00000000', $before['trace']['external_received_qty']);
        $this->assertSame('4.00000000', $before['remaining_demand_qty']);

        $plan = $fixture['planPayload'];
        $plan['planned_qty'] = '4';
        app(CuttingRecordService::class)->publish(
            $this->payload(0) + ['plans' => [$plan]], $fixture['user'], self::PERMISSIONS, true
        );
        $after = app(CuttingDemandService::class)->show($demandId, [], $fixture['user'], self::PERMISSIONS, true);
        $this->assertSame('10.00000000', $after['planned_qty']);
        $this->assertSame('0.00000000', $after['remaining_demand_qty']);
        $this->assertSame(1, DB::table('erp_cutting_demands')->where('source_requirement_id', $fixture['targetRequirement'])->count());
    }

    public function test_summary_keeps_reported_quality_and_formal_allocation_states_separate(): void
    {
        $fixture = $this->fixture('required');
        $batch = $this->issue($fixture);
        $batchId = $batch['settlement_batch_id'];
        $resultId = $this->save($fixture, $batchId, '10')['result_ids'][0];
        app(CuttingRecordService::class)->splitRoutes($resultId, $this->payload(1) + ['routes' => [[
            'route_type' => 'NEXT_OPERATION', 'quantity' => '10',
            'target_material_requirement_id' => $fixture['targetRequirement'],
        ]]], $fixture['user'], self::PERMISSIONS, true);
        $demandId = (int) DB::table('erp_cutting_demands')->where('source_requirement_id', $fixture['targetRequirement'])->value('id');
        $service = app(CuttingDemandService::class);

        $draft = $service->show($demandId, [], $fixture['user'], self::PERMISSIONS, true);
        $this->assertSame('0.00000000', $draft['reported_qty']);
        $this->assertSame('10.00000000', $draft['trace']['draft_reported_qty']);
        $this->assertSame('0.00000000', $draft['waiting_quality_qty']);
        $this->assertSame('0.00000000', $draft['qualified_produced_qty']);

        $this->submitBatch($fixture, $batchId);
        $waiting = $service->show($demandId, [], $fixture['user'], self::PERMISSIONS, true);
        $this->assertSame('10.00000000', $waiting['reported_qty']);
        $this->assertSame('10.00000000', $waiting['waiting_quality_qty']);
        $this->assertSame('0.00000000', $waiting['trace']['pending_settlement_qty']);

        app(CuttingRecordService::class)->inspect(
            $resultId, $this->payload(2) + ['result' => 'passed', 'reason' => '尺寸合格'],
            $fixture['user'], self::PERMISSIONS, true
        );
        $passed = $service->show($demandId, [], $fixture['user'], self::PERMISSIONS, true);
        $this->assertSame('0.00000000', $passed['waiting_quality_qty']);
        $this->assertSame('10.00000000', $passed['trace']['pending_settlement_qty']);
        $this->assertSame('0.00000000', $passed['qualified_produced_qty']);

        app(CuttingConfirmationService::class)->confirm(
            $batchId, $this->confirmation($fixture, $batchId, $resultId, '3000', false),
            $fixture['user'], self::PERMISSIONS, true
        );
        $confirmed = $service->show($demandId, [], $fixture['user'], self::PERMISSIONS, true);
        $this->assertSame('10.00000000', $confirmed['reported_qty']);
        $this->assertSame('10.00000000', $confirmed['qualified_produced_qty']);
        $this->assertSame('10.00000000', $confirmed['allocated_qty']);
        $this->assertSame('0.00000000', $confirmed['trace']['pending_settlement_qty']);
    }
}
