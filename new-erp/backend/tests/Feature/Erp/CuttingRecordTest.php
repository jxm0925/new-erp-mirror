<?php

namespace Tests\Feature\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{Bom, BomItem, InventoryBalance, Item, Location, PurchaseReceipt, PurchaseReceiptItem, Supplier, Unit, Warehouse, WorkOrder, WorkOrderMaterialRequirement};
use App\Services\Erp\{CuttingConfirmationService, CuttingDecimal, CuttingInputService, CuttingRecordService, InventoryService, PurchaseReceiptPostingRepairApplicationService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CuttingRecordTest extends TestCase
{
    use DatabaseTransactions;

    private const PERMISSIONS = ['production.cutting.plan','production.cutting.issue','production.cutting.record',
        'production.cutting.view','production.cutting.material_manage','production.cutting.confirm','production.output.quality'];

    public function test_two_plates_keep_two_same_named_results_and_submission_has_no_stock_effect(): void
    {
        $f = $this->fixture(); $one = $this->issue($f, 0); $two = $this->issue($f, 1);
        $r1 = $this->save($f, $one['settlement_batch_id'], '6'); $r2 = $this->save($f, $two['settlement_batch_id'], '4');
        $this->assertNotSame($r1['result_ids'][0], $r2['result_ids'][0]);
        $rows = DB::table('erp_cutting_results')->whereIn('id', [$r1['result_ids'][0], $r2['result_ids'][0]])->orderBy('id')->get();
        $this->assertSame([$f['output']->id, $f['output']->id], $rows->pluck('item_id')->all());
        $this->assertSame(['6.00000000','4.00000000'], $rows->pluck('actual_qty')->all());
        $this->assertNotSame($rows[0]->settlement_batch_id, $rows[1]->settlement_batch_id);
        $this->assertSame('3000.0000', $one['original_total_cost']); $this->assertSame('3000.0000', $two['original_total_cost']);
        $this->route($f, $rows[0]->id, '6'); $this->route($f, $rows[1]->id, '4');
        $before = DB::table('erp_inventory_balances')->where('item_id', $f['output']->id)->count();
        $transactions = DB::table('erp_inventory_transactions')->count();
        foreach ([$one, $two] as $batch) {
            $id = $batch['settlement_batch_id']; $version = DB::table('erp_cutting_settlement_batches')->where('id', $id)->value('business_version');
            $p = $this->payload((int) $version); $submitted = app(CuttingRecordService::class)->submit($id, $p, $f['user'], self::PERMISSIONS, true);
            $this->assertSame('WAIT_CONFIRM', $submitted['status']); $this->assertSame('加工结果已提交', $submitted['message']);
            $this->assertSame($submitted, app(CuttingRecordService::class)->submit($id, $p, $f['user'], self::PERMISSIONS, true));
        }
        $this->assertSame($before, DB::table('erp_inventory_balances')->where('item_id', $f['output']->id)->count());
        $this->assertSame($transactions, DB::table('erp_inventory_transactions')->count());
        $this->assertSame(0, DB::table('erp_cutting_result_routes')->whereIn('result_id', $rows->pluck('id'))->where('status', '!=', 'PLANNED')->count());
    }

    public function test_route_split_cannot_target_another_result_or_accept_warehouse_fields(): void
    {
        $f = $this->fixture(); $batch = $this->issue($f); $result = $this->save($f, $batch['settlement_batch_id'], '10')['result_ids'][0];
        foreach (['warehouse_id','location_id','batch_no','result_id'] as $field) {
            $p = $this->payload(1) + ['routes' => [['route_type' => 'WAREHOUSE', 'quantity' => '10', $field => 123]]];
            $this->domain('route_fields_invalid', fn () => app(CuttingRecordService::class)->splitRoutes($result, $p, $f['user'], self::PERMISSIONS, true));
        }
        $p = $this->payload(1) + ['routes' => [['route_type' => 'WAREHOUSE','quantity' => '9']]];
        $this->domain('route_quantity_mismatch', fn () => app(CuttingRecordService::class)->splitRoutes($result, $p, $f['user'], self::PERMISSIONS, true));
        $this->assertSame(0, DB::table('erp_cutting_result_routes')->where('result_id', $result)->count());
    }

    public function test_source_and_output_identity_are_validated_on_api_service_not_only_selector(): void
    {
        $f = $this->fixture(); $batch = $this->issue($f); $id = $batch['settlement_batch_id'];
        $p = $this->payload(1) + ['results' => [['client_row_id' => 'x','result_type' => 'product','allowed_output_id' => 999999999,'actual_qty' => '4']]];
        $this->domain('output_not_allowed', fn () => app(CuttingRecordService::class)->saveResults($id, $p, $f['user'], self::PERMISSIONS, true));
        $p['results'][0]['allowed_output_id'] = $f['allowed']; $p['results'][0]['settlement_batch_id'] = $id + 1;
        $this->domain('result_fields_invalid', fn () => app(CuttingRecordService::class)->saveResults($id, $p, $f['user'], self::PERMISSIONS, true));
        $p['results'][0] = ['client_row_id' => 'x','result_type' => 'product','allowed_output_id' => $f['allowed'],'actual_qty' => '4'];
        $this->domain('permission_denied', fn () => app(CuttingRecordService::class)->saveResults($id, $p, $f['user'], [], true), 403);
        $this->assertSame(0, DB::table('erp_cutting_results')->where('settlement_batch_id', $id)->count());
    }

    public function test_physical_input_is_one_real_plate_not_a_quantity_spinner_and_reservation_is_unique(): void
    {
        $f = $this->fixture(); $c = app(CuttingInputService::class);
        $c->reserve($f['order'], $this->payload(1) + ['physical_material_ids' => [$f['physicals'][0]]], $f['user'], self::PERMISSIONS, true);
        $this->domain('physical_unavailable', fn () => $c->reserve($f['order'], $this->payload(2) + ['physical_material_ids' => [$f['physicals'][0]]], $f['user'], self::PERMISSIONS, true), 409);
        $this->domain('physical_quantity_forbidden', fn () => $c->issue($f['order'], $this->payload(2) + ['physical_material_id' => $f['physicals'][0], 'input_qty' => '2'], $f['user'], self::PERMISSIONS, true));
        $this->assertSame(1, DB::table('erp_material_physical_reservations')->where('physical_material_id', $f['physicals'][0])->where('status','ACTIVE')->count());
        $this->assertSame(0, bccomp('2', $f['balance']->fresh()->quantity_on_hand, 8));
    }

    public function test_four_other_result_types_preserve_unmeasured_undocumented_and_explicit_zero(): void
    {
        $f = $this->fixture(); $batch = $this->issue($f); $id = $batch['settlement_batch_id'];
        $rows = [['client_row_id' => 'product','result_type' => 'product','allowed_output_id' => $f['allowed'],'actual_qty' => '4'],
            ['client_row_id' => 'remnant','result_type' => 'usable_remnant','measurement_status' => 'NOT_MEASURED'],
            ['client_row_id' => 'scrap','result_type' => 'recyclable_scrap','measurement_status' => 'MEASURED','actual_qty' => '0'],
            ['client_row_id' => 'loss','result_type' => 'process_loss','measurement_status' => 'NOT_RECORDED'],
            ['client_row_id' => 'failed','result_type' => 'scrapped_output','measurement_status' => 'MEASURED','actual_qty' => '1']];
        app(CuttingRecordService::class)->saveResults($id, $this->payload(1) + ['results' => $rows], $f['user'], self::PERMISSIONS, true);
        $saved = DB::table('erp_cutting_results')->where('settlement_batch_id', $id)->get()->keyBy('client_row_id');
        $this->assertCount(5, $saved); $this->assertNull($saved['remnant']->actual_qty); $this->assertNull($saved['loss']->actual_qty);
        $this->assertSame('0.00000000', $saved['scrap']->actual_qty);
        $this->assertSame('1.00000000', $saved['failed']->actual_qty);
        $rows[1]['actual_qty'] = '0';
        $this->domain('measurement_quantity_invalid', fn () => app(CuttingRecordService::class)->saveResults($id, $this->payload(2) + ['results' => $rows], $f['user'], self::PERMISSIONS, true));
        $this->assertNull(DB::table('erp_cutting_results')->where('settlement_batch_id',$id)->where('client_row_id','remnant')->value('actual_qty'));
    }

    public function test_required_quality_submission_and_inspection_do_not_settle_or_receive(): void
    {
        $f = $this->fixture('required'); $batch = $this->issue($f); $id = $batch['settlement_batch_id'];
        $result = $this->save($f, $id, '10')['result_ids'][0]; $this->route($f, $result, '10');
        $version = DB::table('erp_cutting_settlement_batches')->where('id',$id)->value('business_version');
        $response = app(CuttingRecordService::class)->submit($id, $this->payload((int) $version), $f['user'], self::PERMISSIONS, true);
        $this->assertSame('WAIT_QUALITY', $response['status']);
        $transactions = DB::table('erp_inventory_transactions')->count();
        $response = app(CuttingRecordService::class)->inspect($result, $this->payload(2) + ['result'=>'passed'], $f['user'], self::PERMISSIONS, true);
        $this->assertSame('WAIT_CONFIRM', $response['batch_status']);
        $this->assertNull(DB::table('erp_cutting_settlement_batches')->where('id',$id)->value('confirmed_at'));
        $this->assertSame($transactions, DB::table('erp_inventory_transactions')->count());
    }

    public function test_idempotency_binds_actor_payload_and_aggregate_and_stale_versions_roll_back(): void
    {
        $f = $this->fixture(); $batch = $this->issue($f); $id = $batch['settlement_batch_id'];
        $p = $this->payload(1) + ['results' => [['client_row_id'=>'x','result_type'=>'product','allowed_output_id'=>$f['allowed'],'actual_qty'=>'4']]];
        $service = app(CuttingRecordService::class); $saved = $service->saveResults($id, $p, $f['user'], self::PERMISSIONS, true);
        $this->assertSame($saved, $service->saveResults($id, $p, $f['user'], self::PERMISSIONS, true));
        $p['results'][0]['actual_qty'] = '5';
        $this->domain('idempotency_hash_conflict', fn () => $service->saveResults($id, $p, $f['user'], self::PERMISSIONS, true), 409);
        $p['client_command_id'] = (string) Str::uuid();
        $this->domain('version_conflict', fn () => $service->saveResults($id, $p, $f['user'], self::PERMISSIONS, true), 409);
        $this->assertSame('4.00000000', DB::table('erp_cutting_results')->where('settlement_batch_id',$id)->value('actual_qty'));
    }

    public function test_decimal_partial_cost_assigns_final_tail_without_binary_float(): void
    {
        $amount = '10.0000'; $qty = '3';
        $first = CuttingDecimal::share($amount,$qty,'1'); $amount = bcsub($amount,$first,4); $qty = bcsub($qty,'1',8);
        $second = CuttingDecimal::share($amount,$qty,'1'); $amount = bcsub($amount,$second,4); $qty = bcsub($qty,'1',8);
        $last = CuttingDecimal::share($amount,$qty,'1');
        $this->assertSame(['3.3333','3.3333','3.3334'], [$first,$second,$last]);
        $this->assertSame('10.0000', bcadd(bcadd($first,$second,4),$last,4));
    }

    public function test_authenticated_http_selectors_and_execution_are_paginated_and_preserve_result_ids(): void
    {
        $f = $this->fixture(); $token = $this->token($f['user']); $base = '/api/v1/erp/production/cutting';
        $commands = DB::table('erp_cutting_commands')->count();
        $this->withToken($token)->getJson($base.'/orders/'.$f['order'].'/input-candidates?per_page=1')
            ->assertOk()->assertJsonCount(1,'data')->assertJsonPath('meta.total',2);
        $this->withToken($token)->getJson($base.'/orders/'.$f['order'].'/allowed-outputs?keyword='.urlencode('电箱侧板').'&per_page=1')
            ->assertOk()->assertJsonPath('meta.total',1)->assertJsonPath('data.0.id',$f['allowed']);
        $this->assertSame($commands,DB::table('erp_cutting_commands')->count());
        $one = $this->issue($f); $two = $this->issue($f,1);
        $r1 = $this->save($f,$one['settlement_batch_id'],'6'); $r2 = $this->save($f,$two['settlement_batch_id'],'4');
        $this->withToken($token)->getJson($base.'/orders/'.$f['order'].'/execution?per_page=10')->assertOk()
            ->assertJsonCount(2,'data.results.data')->assertJsonPath('data.results.data.0.id',$r1['result_ids'][0])
            ->assertJsonPath('data.results.data.1.id',$r2['result_ids'][0])->assertJsonPath('data.page_title','下料记录');
        $this->withToken($token)->getJson($base.'/orders?keyword='.urlencode(DB::table('erp_cutting_orders')->where('id',$f['order'])->value('cutting_order_no')))
            ->assertOk()->assertJsonPath('meta.total',1);
        $this->withToken($token)->getJson($base.'/orders/'.$f['order'].'/allowed-outputs?per_page=101')->assertStatus(422);
        $this->withToken($token)->putJson($base.'/results/'.$r1['result_ids'][0].'/routes',$this->payload(1)+[
            'routes'=>[['route_type'=>'WAREHOUSE','quantity'=>'6','warehouse_id'=>$f['warehouse']->id]]])->assertStatus(422);
    }

    public function test_http_self_scope_and_replayed_command_are_denied_after_source_scope_changes(): void
    {
        $f = $this->fixture(); $token = $this->token($f['user'],'self'); $batch = $this->issue($f); $id = $batch['settlement_batch_id'];
        $p = $this->payload(1)+['results'=>[['client_row_id'=>'side','result_type'=>'product','allowed_output_id'=>$f['allowed'],'actual_qty'=>'6']]];
        $url = '/api/v1/erp/production/cutting/settlements/'.$id.'/results';
        $this->withToken($token)->putJson($url,$p)->assertOk();
        $f['wo']->update(['responsible_user_legacy_id'=>$f['user']->legacy_id+1]);
        $this->withToken($token)->putJson($url,$p)->assertStatus(403);
        $this->withToken($token)->getJson('/api/v1/erp/production/cutting/orders/'.$f['order'].'/execution')->assertStatus(403);
        $this->assertSame('6.00000000',DB::table('erp_cutting_results')->where('settlement_batch_id',$id)->value('actual_qty'));
    }

    public function test_settlement_execution_locks_one_source_and_never_projects_another_plate(): void
    {
        $f = $this->fixture(); $one = $this->issue($f); $two = $this->issue($f,1);
        $r1 = $this->save($f,$one['settlement_batch_id'],'6')['result_ids'][0];
        $r2 = $this->save($f,$two['settlement_batch_id'],'4')['result_ids'][0];
        app(CuttingRecordService::class)->splitRoutes($r1,$this->payload(1)+['routes'=>[[
            'route_type'=>'NEXT_OPERATION','quantity'=>'6','target_material_requirement_id'=>$f['targetRequirement']
        ]]],$f['user'],self::PERMISSIONS,true);
        $this->route($f,$r2,'4');
        $token = $this->token($f['user'],'self'); $base = '/api/v1/erp/production/cutting/settlements/';
        $before = DB::table('erp_cutting_commands')->count();
        $response = $this->withToken($token)->getJson($base.$one['settlement_batch_id'].'/execution?per_page=1&settlement_batch_id='.$two['settlement_batch_id'])
            ->assertOk()->assertJsonPath('data.page_scope','SETTLEMENT_BATCH')->assertJsonPath('data.source_locked',true)
            ->assertJsonPath('data.can_add_input',false)->assertJsonPath('data.source.id',$one['settlement_batch_id'])
            ->assertJsonPath('data.source.physical_material_id',$f['physicals'][0])->assertJsonPath('data.results.meta.total',1)
            ->assertJsonPath('data.results.data.0.id',$r1)->assertJsonPath('data.results.data.0.routes.0.result_id',$r1)
            ->assertJsonPath('data.results.data.0.routes.0.display_status','待交接');
        $response->assertJsonMissingPath('data.source.original_total_cost')
            ->assertJsonMissingPath('data.results.data.0.total_cost')
            ->assertJsonMissingPath('data.results.data.0.routes.0.holding_id');
        $this->withToken($token)->getJson($base.$two['settlement_batch_id'].'/execution')
            ->assertOk()->assertJsonPath('data.source.id',$two['settlement_batch_id'])->assertJsonPath('data.results.data.0.id',$r2)
            ->assertJsonPath('data.results.data.0.routes.0.display_status','待入库确认');
        $this->assertSame($before,DB::table('erp_cutting_commands')->count());
        $this->withToken($token)->getJson($base.'999999999/execution')->assertNotFound();
        $f['consumerWo']->update(['responsible_user_legacy_id'=>$f['user']->legacy_id+1]);
        $this->withToken($token)->getJson($base.$one['settlement_batch_id'].'/execution')->assertForbidden();
    }

    public function test_database_rejects_unbound_plans_and_mismatched_formal_demand_source(): void
    {
        $f = $this->fixture(); $other = $this->fixture();
        $plan = DB::table('erp_cutting_plan_allocations')->where('cutting_order_id',$f['order'])->first();
        $otherDemand = DB::table('erp_cutting_plan_allocations')->where('cutting_order_id',$other['order'])->value('demand_id');
        foreach (['demand_id','target_material_requirement_id','input_material_requirement_id'] as $field) {
            try {
                DB::table('erp_cutting_plan_allocations')->where('id',$plan->id)->update([$field=>null]);
                $this->fail('Database accepted an unbound formal plan: '.$field);
            } catch (\Illuminate\Database\QueryException $e) {
                $this->assertStringContainsString('cannot be null',$e->getMessage());
            }
        }
        try {
            DB::table('erp_cutting_plan_allocations')->where('id',$plan->id)->update(['demand_id'=>$otherDemand]);
            $this->fail('Database accepted a different requirement demand.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('cut_plan_demand_source_nn_fk',$e->getMessage());
        }
        $saved = DB::table('erp_cutting_plan_allocations')->where('id',$plan->id)->first();
        $this->assertSame((int) $plan->demand_id,(int) $saved->demand_id);
        $this->assertSame($f['targetRequirement'],(int) $saved->target_material_requirement_id);
    }

    public function test_confirm_creates_exact_cost_wip_and_effective_plan_allocation_not_inventory_receipt(): void
    {
        $f = $this->fixture(); $batch = $this->issue($f); $id = $batch['settlement_batch_id'];
        $result = $this->save($f,$id,'10')['result_ids'][0];
        app(CuttingRecordService::class)->splitRoutes($result,$this->payload(1)+['routes'=>[
            ['route_type'=>'WAREHOUSE','quantity'=>'6'],['route_type'=>'WAREHOUSE','quantity'=>'4']]],$f['user'],self::PERMISSIONS,true);
        $p = $this->confirmation($f,$id,$result,'3000'); $transactions = DB::table('erp_inventory_transactions')->count();
        $receipts = DB::table('erp_material_receipt_lines')->count();
        $confirmed = app(CuttingConfirmationService::class)->confirm($id,$p,$f['user'],self::PERMISSIONS,true);
        $this->assertSame('CONFIRMED',$confirmed['status']); $this->assertSame('3000.0000',$confirmed['confirmed_total_cost']);
        $this->assertSame($confirmed,app(CuttingConfirmationService::class)->confirm($id,$p,$f['user'],self::PERMISSIONS,true));
        $routes = DB::table('erp_cutting_result_routes')->where('result_id',$result)->orderBy('id')->get();
        $this->assertSame(['1800.0000','1200.0000'],$routes->pluck('total_cost')->all());
        $this->assertSame(['WAIT_WAREHOUSE','WAIT_WAREHOUSE'],$routes->pluck('status')->all());
        $this->assertSame('3000.0000',bcadd((string) DB::table('erp_material_holdings')->whereIn('id',$routes->pluck('holding_id'))->sum('total_cost'),'0',4));
        $this->assertSame(2,DB::table('erp_cutting_output_allocations')->where('result_id',$result)->count());
        $source = DB::table('erp_material_holdings')->where('id',DB::table('erp_cutting_settlement_batches')->where('id',$id)->value('wip_holding_id'))->first();
        $this->assertSame('CONSUMED',$source->status); $this->assertSame('0.0000',$source->total_cost);
        $this->assertSame('CONSUMED',DB::table('erp_material_physicals')->where('id',$f['physicals'][0])->value('status'));
        $this->assertSame($transactions,DB::table('erp_inventory_transactions')->count());
        $this->assertSame($receipts,DB::table('erp_material_receipt_lines')->count());
        $this->assertSame(0,DB::table('erp_inventory_balances')->where('item_id',$f['output']->id)->count());
    }

    public function test_cost_mismatch_cross_batch_cost_and_over_allocation_are_atomic_rejections(): void
    {
        $f = $this->fixture(); $batch = $this->issue($f); $id = $batch['settlement_batch_id']; $result = $this->save($f,$id,'12')['result_ids'][0]; $this->route($f,$result,'12');
        $p = $this->confirmation($f,$id,$result,'2999'); $service = app(CuttingConfirmationService::class);
        $this->domain('cost_not_conserved',fn () => $service->confirm($id,$p,$f['user'],self::PERMISSIONS,true));
        $p['costs'][0]['total_cost'] = '3000';
        $this->domain('allocation_exceeds_plan',fn () => $service->confirm($id,$p,$f['user'],self::PERMISSIONS,true));
        $p['costs'][0]['result_id'] = $result+99999999;
        $this->domain('cost_source_invalid',fn () => $service->confirm($id,$p,$f['user'],self::PERMISSIONS,true));
        $this->assertSame('WAIT_CONFIRM',DB::table('erp_cutting_settlement_batches')->where('id',$id)->value('status'));
        $this->assertSame(0,DB::table('erp_cutting_output_allocations')->where('result_id',$result)->count());
        $this->assertNull(DB::table('erp_cutting_results')->where('id',$result)->value('total_cost'));
        $this->assertSame(0,DB::table('erp_cutting_commands')->where('client_command_id',$p['client_command_id'])->count());
    }

    public function test_overproduction_requires_explicit_public_unallocated_disposition(): void
    {
        $f = $this->fixture(); $batch = $this->issue($f); $id = $batch['settlement_batch_id']; $result = $this->save($f,$id,'12')['result_ids'][0]; $this->route($f,$result,'12');
        $p = $this->confirmation($f,$id,$result,'3000'); $route = $p['allocations'][0]['route_id'];
        $p['allocations'][0]['quantity'] = '10'; $p['allocations'][] = ['route_id'=>$route,'quantity'=>'2','disposition'=>'PUBLIC_UNALLOCATED'];
        app(CuttingConfirmationService::class)->confirm($id,$p,$f['user'],self::PERMISSIONS,true);
        $facts = DB::table('erp_cutting_output_allocations')->where('result_id',$result)->orderBy('id')->get();
        $this->assertSame(['10.00000000','2.00000000'],$facts->pluck('quantity')->all());
        $this->assertSame(['2500.0000','500.0000'],$facts->pluck('total_cost')->all());
        $this->assertNull($facts[1]->plan_id); $this->assertSame('PUBLIC_UNALLOCATED',$facts[1]->disposition);
    }

    public function test_quality_is_formal_versioned_and_return_for_edit_does_not_reuse_old_pass(): void
    {
        $f = $this->fixture('required'); $batch = $this->issue($f); $id = $batch['settlement_batch_id']; $result = $this->save($f,$id,'10')['result_ids'][0]; $this->route($f,$result,'10');
        $this->submitBatch($f,$id);
        app(CuttingRecordService::class)->inspect($result,$this->payload(2)+['result'=>'passed'],$f['user'],self::PERMISSIONS,true);
        $this->assertSame(1,DB::table('erp_production_quality_inspections')->where('cutting_result_id',$result)->where('result','passed')->count());
        $version = (int) DB::table('erp_cutting_settlement_batches')->where('id',$id)->value('business_version');
        app(CuttingRecordService::class)->returnForEdit($id,$this->payload($version)+['reason'=>'复核数量'],$f['user'],self::PERMISSIONS,true);
        $this->submitBatch($f,$id);
        $this->assertSame('WAIT_QUALITY',DB::table('erp_cutting_settlement_batches')->where('id',$id)->value('status'));
        $version = (int) DB::table('erp_cutting_results')->where('id',$result)->value('business_version');
        app(CuttingRecordService::class)->inspect($result,$this->payload($version)+['result'=>'passed'],$f['user'],self::PERMISSIONS,true);
        $p = $this->confirmation($f,$id,$result,'3000',false);
        app(CuttingConfirmationService::class)->confirm($id,$p,$f['user'],self::PERMISSIONS,true);
        $this->assertSame(2,DB::table('erp_production_quality_inspections')->where('cutting_result_id',$result)->count());
    }

    private function submitBatch(array $f, int $id): array
    { return app(CuttingRecordService::class)->submit($id,$this->payload((int) DB::table('erp_cutting_settlement_batches')->where('id',$id)->value('business_version')),$f['user'],self::PERMISSIONS,true); }

    private function confirmation(array $f, int $id, int $result, string $cost, bool $submit = true): array
    {
        if ($submit) $this->submitBatch($f,$id);
        $plan = DB::table('erp_cutting_allowed_outputs')->where('id',$f['allowed'])->value('plan_id');
        return $this->payload((int) DB::table('erp_cutting_settlement_batches')->where('id',$id)->value('business_version'))+['costs'=>[['result_id'=>$result,'total_cost'=>$cost]],
            'allocations'=>DB::table('erp_cutting_result_routes')->where('result_id',$result)->orderBy('id')->get()->map(fn ($r) => ['route_id'=>$r->id,'plan_id'=>$plan,'quantity'=>$r->quantity,'disposition'=>'PLAN'])->all()];
    }

    public function test_publish_requires_unique_formal_requirement_and_explicit_input_line(): void
    {
        $f = $this->fixture(); $s = app(CuttingRecordService::class); $plan = $f['planPayload'];
        unset($plan['target_material_requirement_id']);
        $this->domain('formal_requirement_required',fn () => $s->publish($this->payload(0)+['plans'=>[$plan]],$f['user'],self::PERMISSIONS,true));
        $plan = $f['planPayload']; unset($plan['input_material_requirement_id']);
        $this->domain('input_requirement_required',fn () => $s->publish($this->payload(0)+['plans'=>[$plan]],$f['user'],self::PERMISSIONS,true));
        $this->assertSame(1,DB::table('erp_cutting_demands')->where('source_requirement_id',$f['targetRequirement'])->count());
    }

    public function test_ten_assemblies_with_forty_panels_plan_by_requirement_not_wo_quantity(): void
    {
        $f = $this->fixture('none','40','40');
        $this->assertSame(0,bccomp((string) $f['consumerWo']->target_base_qty,'10',8));
        $plan = DB::table('erp_cutting_plan_allocations')->where('cutting_order_id',$f['order'])->first();
        $this->assertSame('40.00000000',$plan->planned_qty); $this->assertNotNull($plan->demand_id);
        $demand = DB::table('erp_cutting_demands')->where('id',$plan->demand_id)->first();
        $this->assertSame($f['targetRequirement'],(int) $demand->source_requirement_id); $this->assertSame('40.00000000',$demand->required_base_qty_snapshot);
        $this->assertSame($f['created'],app(CuttingRecordService::class)->publish($f['publishPayload'],$f['user'],self::PERMISSIONS,true));
        $extra = $f['planPayload']; $extra['planned_qty'] = '1';
        $this->domain('plan_exceeds_source',fn () => app(CuttingRecordService::class)->publish($this->payload(0)+['plans'=>[$extra]],$f['user'],self::PERMISSIONS,true));
        $this->assertSame(1,DB::table('erp_cutting_demands')->where('source_requirement_id',$f['targetRequirement'])->count());
    }

    public function test_two_cutting_orders_share_one_formal_demand_without_duplicate_capacity(): void
    {
        $f = $this->fixture('none','10','6'); $plan = $f['planPayload']; $plan['planned_qty'] = '4';
        $other = app(CuttingRecordService::class)->publish($this->payload(0)+['plans'=>[$plan]],$f['user'],self::PERMISSIONS,true);
        $demandIds = DB::table('erp_cutting_plan_allocations')->whereIn('cutting_order_id',[$f['order'],$other['cutting_order_id']])->pluck('demand_id');
        $this->assertCount(1,$demandIds->unique()); $this->assertSame(1,DB::table('erp_cutting_demands')->where('source_requirement_id',$f['targetRequirement'])->count());
        $plan['planned_qty'] = '1';
        $this->domain('plan_exceeds_source',fn () => app(CuttingRecordService::class)->publish($this->payload(0)+['plans'=>[$plan]],$f['user'],self::PERMISSIONS,true));
    }

    public function test_other_cuttable_bom_material_is_not_a_candidate_or_allowed_for_formal_issue(): void
    {
        $f = $this->fixture(); $other = $this->fixture(); $this->additionalInput($f,$other['raw'],true);
        $token = $this->token($f['user']);
        $this->withToken($token)->getJson('/api/v1/erp/production/cutting/orders/'.$f['order'].'/input-candidates?keyword='.urlencode($other['raw']->item_code))
            ->assertOk()->assertJsonPath('meta.total',0);
        $version = (int) DB::table('erp_cutting_orders')->where('id',$f['order'])->value('business_version');
        $this->domain('input_not_allowed',fn () => app(CuttingInputService::class)->reserve($f['order'],$this->payload($version)+['physical_material_ids'=>[$other['physicals'][0]]],$f['user'],self::PERMISSIONS,true));
        $this->domain('input_not_allowed',fn () => app(CuttingInputService::class)->issue($f['order'],$this->payload($version)+['inventory_balance_id'=>$other['balance']->id,'input_qty'=>'1'],$f['user'],self::PERMISSIONS,true));
        $this->assertSame('AVAILABLE',DB::table('erp_material_physicals')->where('id',$other['physicals'][0])->value('status'));
        $this->assertSame(0,bccomp('2',$other['balance']->fresh()->quantity_on_hand,8));
    }

    public function test_explicit_raw_requirement_at_another_stage_is_rejected(): void
    {
        $f = $this->fixture(); $other = $this->fixture(); $r = $this->additionalInput($f,$other['raw'],false);
        $plan = $f['planPayload']; $plan['input_material_requirement_id'] = $r->id;
        $this->domain('input_requirement_wrong_stage',fn () => app(CuttingRecordService::class)->publish($this->payload(0)+['plans'=>[$plan]],$f['user'],self::PERMISSIONS,true));
    }

    public function test_removing_inspected_result_voids_history_and_filters_old_routes_from_submit_and_projection(): void
    {
        $f = $this->fixture('required'); $batch = $this->issue($f); $id = $batch['settlement_batch_id']; $s = app(CuttingRecordService::class);
        $saved = $s->saveResults($id,$this->payload(1)+['results'=>[
            ['client_row_id'=>'one','result_type'=>'product','allowed_output_id'=>$f['allowed'],'actual_qty'=>'6'],
            ['client_row_id'=>'two','result_type'=>'product','allowed_output_id'=>$f['allowed'],'actual_qty'=>'4']]],$f['user'],self::PERMISSIONS,true);
        [$one,$two] = $saved['result_ids']; $this->route($f,$one,'6'); $this->route($f,$two,'4'); $this->submitBatch($f,$id);
        foreach ([$one,$two] as $r) $s->inspect($r,$this->payload(2)+['result'=>'passed'],$f['user'],self::PERMISSIONS,true);
        $s->returnForEdit($id,$this->payload((int) DB::table('erp_cutting_settlement_batches')->where('id',$id)->value('business_version'))+['reason'=>'取消第二条结果'],$f['user'],self::PERMISSIONS,true);
        $edited = $s->saveResults($id,$this->payload((int) DB::table('erp_cutting_settlement_batches')->where('id',$id)->value('business_version'))+['results'=>[
            ['client_row_id'=>'one','result_type'=>'product','allowed_output_id'=>$f['allowed'],'actual_qty'=>'6']]],$f['user'],self::PERMISSIONS,true);
        $this->assertNotSame($one,$edited['result_ids'][0]);
        $this->assertSame('SUPERSEDED',DB::table('erp_cutting_results')->where('id',$one)->value('status'));
        $this->assertSame('VOIDED',DB::table('erp_cutting_results')->where('id',$two)->value('status'));
        $this->assertSame('4.00000000',DB::table('erp_cutting_results')->where('id',$two)->value('actual_qty'));
        $this->assertSame(2,DB::table('erp_production_quality_inspections')->whereIn('cutting_result_id',[$one,$two])->count());
        $this->assertSame(2,DB::table('erp_cutting_result_routes')->whereIn('result_id',[$one,$two])->where('status','CANCELLED')->count());
        $this->assertSame('WAIT_QUALITY',$this->submitBatch($f,$id)['status']);
        $this->withToken($this->token($f['user']))->getJson('/api/v1/erp/production/cutting/orders/'.$f['order'].'/execution')->assertOk()
            ->assertJsonPath('data.results.meta.total',1)->assertJsonPath('data.results.data.0.id',$edited['result_ids'][0])->assertJsonCount(1,'data.results.data.0.routes');
    }

    public function test_editing_inspected_quantity_creates_replacement_without_mutating_old_quality_source(): void
    {
        $f = $this->fixture('required'); $batch = $this->issue($f); $id = $batch['settlement_batch_id']; $old = $this->save($f,$id,'10')['result_ids'][0]; $this->route($f,$old,'10'); $this->submitBatch($f,$id);
        $s = app(CuttingRecordService::class); $s->inspect($old,$this->payload(2)+['result'=>'passed'],$f['user'],self::PERMISSIONS,true);
        $s->returnForEdit($id,$this->payload((int) DB::table('erp_cutting_settlement_batches')->where('id',$id)->value('business_version'))+['reason'=>'数量应为8'],$f['user'],self::PERMISSIONS,true);
        $edited = $s->saveResults($id,$this->payload((int) DB::table('erp_cutting_settlement_batches')->where('id',$id)->value('business_version'))+['results'=>[
            ['client_row_id'=>'side','result_type'=>'product','allowed_output_id'=>$f['allowed'],'actual_qty'=>'8']]],$f['user'],self::PERMISSIONS,true);
        $new = $edited['result_ids'][0]; $this->assertNotSame($old,$new);
        $this->assertSame('10.00000000',DB::table('erp_cutting_results')->where('id',$old)->value('actual_qty'));
        $row = DB::table('erp_cutting_results')->where('id',$new)->first(); $this->assertSame('8.00000000',$row->actual_qty); $this->assertSame($old,(int) $row->supersedes_result_id);
        $fact = DB::table('erp_production_quality_inspections')->where('cutting_result_id',$old)->first(); $snapshot = json_decode($fact->inspection_snapshot,true,512,JSON_THROW_ON_ERROR);
        $this->assertSame('10.00000000',$snapshot['result_snapshot']['actual_qty']); $this->assertSame('10.00000000',$fact->qualified_base_qty);
        $this->route($f,$new,'8'); $this->assertSame('WAIT_QUALITY',$this->submitBatch($f,$id)['status']);
        $this->domain('quality_not_waiting',fn () => $s->inspect($old,$this->payload(4)+['result'=>'passed'],$f['user'],self::PERMISSIONS,true),409);
    }

    private function additionalInput(array $f, Item $raw, bool $sameStage): WorkOrderMaterialRequirement
    {
        $r = $f['inputRequirement']->replicate(); $r->line_no = 2; $r->component_item_id = $raw->id;
        $r->component_item_code_snapshot = $raw->item_code; $r->component_item_name_snapshot = $raw->item_name;
        $bomItem = BomItem::create(['bom_id'=>$f['wo']->bom_id,'line_no'=>2,'component_item_id'=>$raw->id,'component_item_code'=>$raw->item_code,'component_item_name'=>$raw->item_name,
            'qty'=>'0.2','unit_id'=>$raw->unit_id,'loss_rate'=>0,'fixed_qty'=>0,'replaceable'=>false]);
        $r->bom_item_id = $bomItem->id; $r->save(); $stage = $f['stage']; $operation = $f['producerOperation'];
        if (! $sameStage) {
            $node = (array) DB::table('erp_production_routing_operations')->where('id',$stage)->first(); unset($node['id']); $node['sequence'] = 20;
            $stage = DB::table('erp_production_routing_operations')->insertGetId($node);
            $node = (array) DB::table('erp_production_quantity_operations')->where('id',$operation)->first(); unset($node['id']); $node['routing_operation_id_snapshot'] = $stage; $node['sequence_no_snapshot'] = 20;
            $operation = DB::table('erp_production_quantity_operations')->insertGetId($node);
        }
        $supply = $this->supply($f['wo'],$r,$stage,$raw->id,'2'); $this->targetRequirement($f['wo'],$r,$supply,$operation,$raw->id,'2'); return $r;
    }

    private function fixture(string $quality = 'none', string $required = '10', string $planned = '10'): array
    {
        $s = strtoupper(substr((string) Str::ulid(), -10)); $user = (object) ['legacy_id'=>random_int(100000000,999999999),'username'=>'cut-'.$s];
        DB::table('erp_legacy_admin_users')->insert(['legacy_id'=>$user->legacy_id,'username'=>$user->username,'status'=>'normal','auth_group_names'=>'[]','created_at'=>now(),'updated_at'=>now()]);
        $unit = Unit::create(['unit_code'=>'CUT-U-'.$s,'unit_name'=>'件','unit_type'=>'count','decimal_places'=>0,'is_base'=>true,'status'=>'enabled']);
        $raw = Item::create(['item_code'=>'CUT-RM-'.$s,'item_name'=>'304整板','item_type'=>'raw_material','unit_id'=>$unit->id,
            'is_purchase_item'=>true,'is_stock_item'=>true,'status'=>'enabled','material_management_mode'=>'physical','cutting_mode'=>'sheet','serial_tracking_mode'=>'none']);
        $output = Item::create(['item_code'=>'CUT-OUT-'.$s,'item_name'=>'电箱侧板','item_type'=>'semi_finished','unit_id'=>$unit->id,'is_stock_item'=>true,'is_production_item'=>true,'status'=>'enabled']);
        $supplier = Supplier::create(['supplier_code'=>'CUT-SUP-'.$s,'supplier_name'=>'板材供应商','supplier_type'=>'manufacturer','status'=>'enabled']);
        $warehouse = Warehouse::create(['warehouse_code'=>'CUT-WH-'.$s,'warehouse_name'=>'板材仓库','status'=>'enabled']);
        $location = Location::create(['location_code'=>'CUT-LOC-'.$s,'location_name'=>'板材库位','warehouse_id'=>$warehouse->id,'status'=>'enabled']);
        $receipt = PurchaseReceipt::create(['receipt_no'=>'CUT-PRC-'.$s,'supplier_id'=>$supplier->id,'receipt_date'=>now()->toDateString(),
            'receipt_status'=>'confirmed','confirm_status'=>'confirmed','stock_post_status'=>'pending','total_receipt_qty'=>2,'total_qualified_qty'=>2,'total_amount'=>6000]);
        $line = PurchaseReceiptItem::create(['receipt_id'=>$receipt->id,'item_id'=>$raw->id,'purchase_unit_id'=>$unit->id,'purchase_unit_name_snapshot'=>'件',
            'conversion_factor_snapshot'=>1,'base_unit_id'=>$unit->id,'base_unit_name_snapshot'=>'件','receipt_qty'=>2,'qualified_qty'=>2,'unqualified_qty'=>0,
            'standard_base_qty'=>2,'actual_base_qty'=>2,'qualified_base_qty'=>2,'unqualified_base_qty'=>0,'is_stock_item_snapshot'=>true,
            'quality_fact_origin'=>'current','original_received_qty'=>2,'original_qualified_qty'=>2,'original_unqualified_qty'=>0,
            'original_received_base_qty'=>2,'original_qualified_base_qty'=>2,'original_unqualified_base_qty'=>0,'final_stockable_base_qty'=>2,
            'physical_received_base_qty'=>2,'contract_fulfilled_base_qty'=>2,'unit_price'=>3000,'receipt_cost'=>6000,'batch_no'=>'CUT-BAT-'.$s,'inventory_posting_status'=>'pending']);
        app(PurchaseReceiptPostingRepairApplicationService::class)->repair($receipt->id, [['receipt_item_id'=>$line->id,'allocations'=>[
            ['warehouse_id'=>$warehouse->id,'location_id'=>$location->id,'base_qty'=>2,'serial_nos'=>[]]]]],'下料专项');
        $tx = app(InventoryService::class)->postPurchaseReceipt($receipt->id); $txLine = $tx->items->first();
        $balance = InventoryBalance::where('item_id',$raw->id)->where('batch_no','CUT-BAT-'.$s)->firstOrFail();
        $physicals = []; for ($n=0;$n<2;$n++) $physicals[] = app(CuttingInputService::class)->registerPhysical($this->payload(0) + [
            'source_transaction_item_id'=>$txLine->id,'dimensions'=>['length_mm'=>'2440','width_mm'=>'1220','thickness_mm'=>'2']],$user,self::PERMISSIONS)['physical_material_id'];
        $bom = Bom::create(['bom_no'=>'CUT-BOM-'.$s,'bom_name'=>'侧板BOM','output_item_id'=>$output->id,'bom_type'=>'standard','version'=>'V1','status'=>'active','audit_status'=>'approved']);
        $bomItem = BomItem::create(['bom_id'=>$bom->id,'line_no'=>1,'component_item_id'=>$raw->id,'component_item_code'=>$raw->item_code,
            'component_item_name'=>$raw->item_name,'qty'=>'0.2','unit_id'=>$unit->id,'loss_rate'=>0,'fixed_qty'=>0,'replaceable'=>false]);
        $wo = WorkOrder::create(['work_order_no'=>'CUT-WO-'.$s,'source_type'=>'stock_prebuild','output_item_id'=>$output->id,'target_qty'=>10,'target_base_qty'=>10,
            'target_unit_id'=>$unit->id,'base_unit_id'=>$unit->id,'status'=>'RELEASED','business_version'=>1,'bom_id'=>$bom->id,'bom_version_id'=>$bom->id,
            'bom_version'=>'V1','responsible_user_legacy_id'=>$user->legacy_id,'created_by_legacy_id'=>$user->legacy_id,'updated_by_legacy_id'=>$user->legacy_id]);
        $inputRequirement = WorkOrderMaterialRequirement::create(['work_order_id'=>$wo->id,'line_no'=>1,'bom_id'=>$bom->id,'bom_item_id'=>$bomItem->id,'component_item_id'=>$raw->id,
            'component_item_code_snapshot'=>$raw->item_code,'component_item_name_snapshot'=>$raw->item_name,'unit_id'=>$unit->id,'unit_name_snapshot'=>'件',
            'per_output_qty'=>'0.2','loss_rate'=>0,'fixed_qty'=>0,'required_qty'=>2,'base_unit_id'=>$unit->id,'base_unit_name_snapshot'=>'件',
            'base_required_qty'=>2,'issued_qty'=>0,'returned_qty'=>0,'remaining_qty'=>2,'status'=>'OPEN','business_version'=>1]);
        $op = DB::table('erp_production_operations')->insertGetId(['operation_no'=>'CUT-OP-'.$s,'operation_name'=>'下料','status'=>'enabled','sort'=>10,'business_version'=>1,'created_at'=>now(),'updated_at'=>now()]);
        $rt = DB::table('erp_production_routings')->insertGetId(['routing_no'=>'CUT-RT-'.$s,'routing_name'=>'侧板路线','output_item_id'=>$output->id,
            'version'=>1,'status'=>'active','is_default'=>true,'default_scope_key'=>$output->id,'business_version'=>1,'created_at'=>now(),'updated_at'=>now()]);
        $stage = DB::table('erp_production_routing_operations')->insertGetId(['routing_id'=>$rt,'operation_id'=>$op,'sequence'=>10,'is_key_operation'=>true,
            'output_item_id'=>$output->id,'output_mode'=>'warehouse_optional','quality_mode'=>$quality,'work_mode'=>'manual','created_at'=>now(),'updated_at'=>now()]);
        $producerOperation = DB::table('erp_production_quantity_operations')->insertGetId(['work_order_id'=>$wo->id,'routing_operation_id_snapshot'=>$stage,'operation_id_snapshot'=>$op,
            'operation_code_snapshot'=>'CUT-OP-'.$s,'operation_name_snapshot'=>'下料','sequence_no_snapshot'=>10,'status'=>'WAIT_MATERIAL',
            'planned_base_qty'=>10,'completed_base_qty'=>0,'scrapped_base_qty'=>0,'remaining_base_qty'=>10,'output_item_id_snapshot'=>$output->id,
            'output_mode_snapshot'=>'warehouse_optional','quality_mode_snapshot'=>$quality,'work_mode_snapshot'=>'manual','business_version'=>1,'created_at'=>now(),'updated_at'=>now()]);
        $inputSupply = $this->supply($wo,$inputRequirement,$stage,$raw->id,'2');
        $this->targetRequirement($wo,$inputRequirement,$inputSupply,$producerOperation,$raw->id,'2');
        $assembly = Item::create(['item_code'=>'CUT-ASM-'.$s,'item_name'=>'总装电箱','item_type'=>'semi_finished','unit_id'=>$unit->id,'is_production_item'=>true,'status'=>'enabled']);
        $consumerBom = Bom::create(['bom_no'=>'CUT-ABOM-'.$s,'bom_name'=>'电箱总装BOM','output_item_id'=>$assembly->id,'bom_type'=>'standard','version'=>'V1','status'=>'active','audit_status'=>'approved']);
        $consumerBomItem = BomItem::create(['bom_id'=>$consumerBom->id,'line_no'=>1,'component_item_id'=>$output->id,'component_item_code'=>$output->item_code,
            'component_item_name'=>$output->item_name,'qty'=>bcdiv($required,'10',8),'unit_id'=>$unit->id,'loss_rate'=>0,'fixed_qty'=>0,'replaceable'=>false]);
        $consumerWo = WorkOrder::create(['work_order_no'=>'CUT-ASMWO-'.$s,'source_type'=>'stock_prebuild','output_item_id'=>$assembly->id,'target_qty'=>10,'target_base_qty'=>10,
            'target_unit_id'=>$unit->id,'base_unit_id'=>$unit->id,'status'=>'RELEASED','business_version'=>1,'bom_id'=>$consumerBom->id,'bom_version_id'=>$consumerBom->id,'bom_version'=>'V1',
            'responsible_user_legacy_id'=>$user->legacy_id,'created_by_legacy_id'=>$user->legacy_id,'updated_by_legacy_id'=>$user->legacy_id]);
        $consumerRequirement = WorkOrderMaterialRequirement::create(['work_order_id'=>$consumerWo->id,'line_no'=>1,'bom_id'=>$consumerBom->id,'bom_item_id'=>$consumerBomItem->id,
            'component_item_id'=>$output->id,'component_item_code_snapshot'=>$output->item_code,'component_item_name_snapshot'=>$output->item_name,'unit_id'=>$unit->id,'unit_name_snapshot'=>'件',
            'per_output_qty'=>bcdiv($required,'10',8),'loss_rate'=>0,'fixed_qty'=>0,'required_qty'=>$required,'base_unit_id'=>$unit->id,'base_unit_name_snapshot'=>'件','base_required_qty'=>$required,
            'issued_qty'=>0,'returned_qty'=>0,'remaining_qty'=>$required,'status'=>'OPEN','business_version'=>1]);
        $consumerRoute = DB::table('erp_production_routings')->insertGetId(['routing_no'=>'CUT-ART-'.$s,'routing_name'=>'总装路线','output_item_id'=>$assembly->id,'version'=>1,
            'status'=>'active','is_default'=>true,'default_scope_key'=>$assembly->id,'business_version'=>1,'created_at'=>now(),'updated_at'=>now()]);
        $consumerStage = DB::table('erp_production_routing_operations')->insertGetId(['routing_id'=>$consumerRoute,'operation_id'=>$op,'sequence'=>10,'is_key_operation'=>true,
            'output_item_id'=>$assembly->id,'output_mode'=>'warehouse_required','quality_mode'=>'none','work_mode'=>'manual','created_at'=>now(),'updated_at'=>now()]);
        $consumerOperation = DB::table('erp_production_quantity_operations')->insertGetId(['work_order_id'=>$consumerWo->id,'routing_operation_id_snapshot'=>$consumerStage,'operation_id_snapshot'=>$op,
            'operation_code_snapshot'=>'CUT-OP-'.$s,'operation_name_snapshot'=>'总装','sequence_no_snapshot'=>10,'status'=>'WAIT_MATERIAL','planned_base_qty'=>10,'completed_base_qty'=>0,
            'scrapped_base_qty'=>0,'remaining_base_qty'=>10,'output_item_id_snapshot'=>$assembly->id,'output_mode_snapshot'=>'warehouse_required','quality_mode_snapshot'=>'none','work_mode_snapshot'=>'manual',
            'business_version'=>1,'created_at'=>now(),'updated_at'=>now()]);
        $consumerSupply = $this->supply($consumerWo,$consumerRequirement,$consumerStage,$output->id,$required);
        $targetRequirement = $this->targetRequirement($consumerWo,$consumerRequirement,$consumerSupply,$consumerOperation,$output->id,$required);
        $planPayload = ['work_order_id'=>$wo->id,'stage_id'=>$stage,'planned_qty'=>$planned,'target_material_requirement_id'=>$targetRequirement,'input_material_requirement_id'=>$inputRequirement->id];
        $publishPayload = $this->payload(0)+['plans'=>[$planPayload]];
        $created = app(CuttingRecordService::class)->publish($publishPayload,$user,self::PERMISSIONS,true);
        $order = $created['cutting_order_id']; $allowed = DB::table('erp_cutting_allowed_outputs')->where('cutting_order_id',$order)->value('id');
        return compact('user','raw','output','physicals','balance','order','allowed','wo','stage','warehouse','location','targetRequirement','inputRequirement','planPayload','consumerWo','consumerStage','consumerOperation','consumerRequirement','producerOperation','publishPayload','created');
    }

    private function supply(WorkOrder $wo, WorkOrderMaterialRequirement $r, int $stage, int $item, string $qty): int
    { return DB::table('erp_work_order_material_supply_rules')->insertGetId(['work_order_id'=>$wo->id,'material_requirement_id'=>$r->id,'component_item_id'=>$item,
        'target_routing_operation_id_snapshot'=>$stage,'target_operation_code_snapshot'=>'CUT','target_operation_name_snapshot'=>'加工','required_base_qty_snapshot'=>$qty,
        'supply_mode_snapshot'=>'dedicated_delivery','requires_delivery_snapshot'=>true,'participates_in_kitting_snapshot'=>true,'allow_partial_delivery_snapshot'=>true,
        'delivery_location_type_snapshot'=>'operation_station','rule_snapshot'=>json_encode(['required_qty_ratio'=>'1'],JSON_THROW_ON_ERROR),'created_at'=>now(),'updated_at'=>now()]); }

    private function targetRequirement(WorkOrder $wo, WorkOrderMaterialRequirement $r, int $supply, int $operation, int $item, string $qty): int
    { return DB::table('erp_production_target_material_requirements')->insertGetId(['work_order_id'=>$wo->id,'target_type'=>'quantity_operation','target_id'=>$operation,
        'material_requirement_id'=>$r->id,'material_supply_rule_snapshot_id'=>$supply,'component_item_id'=>$item,'required_base_qty'=>$qty,'satisfied_base_qty'=>0,'consumed_base_qty'=>0,
        'returned_base_qty'=>0,'status'=>'OPEN','business_version'=>1,'created_at'=>now(),'updated_at'=>now()]); }

    private function issue(array $f, int $plate = 0): array
    {
        $service = app(CuttingInputService::class); $version = (int) DB::table('erp_cutting_orders')->where('id',$f['order'])->value('business_version');
        $reserved = $service->reserve($f['order'],$this->payload($version)+['physical_material_ids'=>[$f['physicals'][$plate]]],$f['user'],self::PERMISSIONS,true);
        return $service->issue($f['order'],$this->payload($reserved['business_version'])+['physical_material_id'=>$f['physicals'][$plate]],$f['user'],self::PERMISSIONS,true);
    }

    private function save(array $f, int $batchId, string $qty): array
    { return app(CuttingRecordService::class)->saveResults($batchId,$this->payload(1)+['results'=>[['client_row_id'=>'side','result_type'=>'product','allowed_output_id'=>$f['allowed'],'actual_qty'=>$qty]]],$f['user'],self::PERMISSIONS,true); }

    private function route(array $f, int $resultId, string $qty): array
    { return app(CuttingRecordService::class)->splitRoutes($resultId,$this->payload(1)+['routes'=>[['route_type'=>'WAREHOUSE','quantity'=>$qty]]],$f['user'],self::PERMISSIONS,true); }

    private function payload(int $version): array { return ['client_command_id'=>(string) Str::uuid(),'expected_version'=>$version]; }
    private function token(object $user, string $scope = 'all'): string
    {
        $role = DB::table('erp_rbac_roles')->insertGetId(['code'=>'cut-test-'.Str::uuid(),'name'=>'下料测试角色','data_scope'=>$scope,'enabled'=>true,'created_at'=>now(),'updated_at'=>now()]);
        foreach (self::PERMISSIONS as $code) {
            $id = DB::table('erp_rbac_permissions')->where('code',$code)->value('id');
            if (! $id) $id = DB::table('erp_rbac_permissions')->insertGetId(['code'=>$code,'name'=>$code,'type'=>'button','enabled'=>true,'sort'=>1,'created_at'=>now(),'updated_at'=>now()]);
            DB::table('erp_rbac_role_permissions')->insert(['role_id'=>$role,'permission_id'=>$id]);
        }
        DB::table('erp_rbac_user_roles')->insert(['user_legacy_id'=>$user->legacy_id,'role_id'=>$role]);
        $token = Str::random(64); DB::table('erp_auth_tokens')->insert(['user_legacy_id'=>$user->legacy_id,'token_hash'=>hash('sha256',$token),
            'created_at'=>now(),'updated_at'=>now(),'expires_at'=>now()->addHour()]); return $token;
    }
    private function domain(string $code, callable $action, int $status = 422): void
    { try { $action(); $this->fail('Expected '.$code); } catch (WorkOrderDomainException $e) { $this->assertSame($code,$e->errorCode); $this->assertSame($status,$e->status); } }
}
