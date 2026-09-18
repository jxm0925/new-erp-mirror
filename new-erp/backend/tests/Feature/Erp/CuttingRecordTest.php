<?php

namespace Tests\Feature\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{Bom, BomItem, InventoryBalance, Item, Location, ProductionQuantityOperation, ProductionTask, PurchaseReceipt, PurchaseReceiptItem, Supplier, Unit, Warehouse, WorkOrder, WorkOrderMaterialRequirement};
use App\Services\Erp\{CuttingConfirmationService, CuttingCorrectionService, CuttingDecimal, CuttingHandoverService, CuttingInputService, CuttingInventoryReservationService, CuttingReadService, CuttingRecordService, CuttingTaskExecutionService, CuttingWarehouseReceiptService, InventoryService, ProductionInternalIssueService, ProductionKittingService, ProductionLaborSessionService, PurchaseReceiptPostingRepairApplicationService, RbacBootstrapService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CuttingRecordTest extends TestCase
{
    use DatabaseTransactions;
    use \Tests\Support\CuttingTestFixtures;


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

    public function test_route_split_rejects_foreign_fields_and_only_rejects_quantity_above_actual(): void
    {
        $f = $this->fixture(); $batch = $this->issue($f); $result = $this->save($f, $batch['settlement_batch_id'], '10')['result_ids'][0];
        foreach (['warehouse_id','location_id','batch_no','result_id'] as $field) {
            $p = $this->payload(1) + ['routes' => [['route_type' => 'WAREHOUSE', 'quantity' => '10', $field => 123]]];
            $this->domain('route_fields_invalid', fn () => app(CuttingRecordService::class)->splitRoutes($result, $p, $f['user'], self::PERMISSIONS, true));
        }
        $p = $this->payload(1) + ['routes' => [['route_type' => 'WAREHOUSE','quantity' => '11']]];
        $this->domain('route_quantity_exceeded', fn () => app(CuttingRecordService::class)->splitRoutes($result, $p, $f['user'], self::PERMISSIONS, true));
        $this->assertSame(0, DB::table('erp_cutting_result_routes')->where('result_id', $result)->count());
        $empty = app(CuttingRecordService::class)->splitRoutes($result,$this->payload(1)+['routes'=>[]],$f['user'],self::PERMISSIONS,true);
        $this->assertSame('0.00000000',$empty['assigned_qty']); $this->assertSame('10.00000000',$empty['unassigned_qty']);
        $this->assertFalse($empty['route_complete']); $this->assertSame([], $empty['routes']);
    }

    public function test_partial_route_can_submit_wait_route_and_complete_without_resubmitting_results(): void
    {
        $f = $this->fixture(); $batch = $this->issue($f); $id = $batch['settlement_batch_id'];
        $result = $this->save($f,$id,'10')['result_ids'][0]; $service = app(CuttingRecordService::class); $token = $this->token($f['user']);
        $partialResponse = $this->withToken($token)->putJson('/api/v1/erp/production/cutting/results/'.$result.'/routes',$this->payload(1)+['routes'=>[
            ['route_type'=>'WAREHOUSE','quantity'=>'6']
        ]])->assertOk();
        $partial = $partialResponse->json('data');
        $this->assertSame('10.00000000',$partial['actual_qty']);
        $this->assertSame('6.00000000',$partial['assigned_qty']);
        $this->assertSame('4.00000000',$partial['unassigned_qty']);
        $this->assertFalse($partial['route_complete']); $this->assertFalse($partial['confirm_allowed']);

        $submittedResponse = $this->withToken($token)->postJson('/api/v1/erp/production/cutting/settlements/'.$id.'/submit',
            $this->payload((int) DB::table('erp_cutting_settlement_batches')->where('id',$id)->value('business_version')))
            ->assertOk()->assertJsonPath('message','加工结果已提交');
        $submitted = $submittedResponse->json('data');
        $this->assertSame('WAIT_ROUTE',$submitted['status']); $this->assertSame('待完善去向',$submitted['status_label']);
        $this->assertSame('4.00000000',$submitted['unassigned_qty']);
        $this->assertFalse($submitted['route_complete']); $this->assertFalse($submitted['confirm_allowed']);
        $this->assertSame('SUBMITTED',DB::table('erp_cutting_results')->where('id',$result)->value('status'));
        $this->domain('batch_not_confirmable',fn () => app(CuttingConfirmationService::class)->confirm(
            $id,$this->confirmation($f,$id,$result,'3000',false),$f['user'],self::PERMISSIONS,true
        ),409);
        $this->withToken($token)->getJson('/api/v1/erp/production/cutting/settlements/'.$id.'/execution')
            ->assertOk()->assertJsonPath('data.source.display_status','待完善去向')
            ->assertJsonPath('data.results.data.0.assigned_qty','6.00000000')
            ->assertJsonPath('data.results.data.0.unassigned_qty','4.00000000')
            ->assertJsonPath('data.results.data.0.route_complete',false)
            ->assertJsonPath('data.results.data.0.confirm_allowed',false);

        $complete = $service->splitRoutes($result,$this->payload(2)+['routes'=>[
            ['route_type'=>'WAREHOUSE','quantity'=>'6'],['route_type'=>'WAREHOUSE','quantity'=>'4']
        ]],$f['user'],self::PERMISSIONS,true);
        $this->assertSame('WAIT_CONFIRM',$complete['status']); $this->assertSame('待用料确认',$complete['status_label']);
        $this->assertSame('10.00000000',$complete['assigned_qty']); $this->assertSame('0.00000000',$complete['unassigned_qty']);
        $this->assertTrue($complete['route_complete']); $this->assertTrue($complete['confirm_allowed']);
        $this->assertSame('WAIT_CONFIRM',DB::table('erp_cutting_settlement_batches')->where('id',$id)->value('status'));
        $this->assertSame(2,DB::table('erp_cutting_result_routes')->where('result_id',$result)->where('status','PLANNED')->count());
    }

    public function test_partial_required_quality_moves_from_wait_route_to_wait_quality_when_completed(): void
    {
        $f = $this->fixture('required'); $batch = $this->issue($f); $id = $batch['settlement_batch_id'];
        $result = $this->save($f,$id,'10')['result_ids'][0]; $service = app(CuttingRecordService::class);
        $service->splitRoutes($result,$this->payload(1)+['routes'=>[['route_type'=>'WAREHOUSE','quantity'=>'6']]],$f['user'],self::PERMISSIONS,true);
        $this->assertSame('WAIT_ROUTE',$this->submitBatch($f,$id)['status']);
        $returned = $service->returnForEdit($id,$this->payload((int) DB::table('erp_cutting_settlement_batches')->where('id',$id)->value('business_version'))+
            ['reason'=>'修正加工结果'], $f['user'],self::PERMISSIONS,true);
        $this->assertSame('PROCESSING',$returned['status']); $this->assertSame('DRAFT',DB::table('erp_cutting_results')->where('id',$result)->value('status'));
        $this->assertSame('WAIT_ROUTE',$this->submitBatch($f,$id)['status']);
        $complete = $service->splitRoutes($result,$this->payload(3)+['routes'=>[['route_type'=>'WAREHOUSE','quantity'=>'10']]],$f['user'],self::PERMISSIONS,true);
        $this->assertSame('WAIT_QUALITY',$complete['status']); $this->assertSame('待质检',$complete['status_label']);
        $this->assertTrue($complete['route_complete']); $this->assertFalse($complete['confirm_allowed']);
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

    public function test_confirmed_plate_remnant_can_be_recut_without_second_inventory_issue_and_keeps_lineage_cost(): void
    {
        $f = $this->fixture();
        $input = app(CuttingInputService::class);
        $records = app(CuttingRecordService::class);
        $confirm = app(CuttingConfirmationService::class);
        $first = $this->issue($f);
        $firstBatchId = $first['settlement_batch_id'];
        $saved = $records->saveResults($firstBatchId, $this->payload(1) + ['results' => [
            ['client_row_id' => 'product-1', 'result_type' => 'product', 'allowed_output_id' => $f['allowed'], 'actual_qty' => '6'],
            ['client_row_id' => 'remnant-1', 'result_type' => 'usable_remnant', 'actual_qty' => '1', 'measurement_status' => 'MEASURED',
                'measurements' => ['shape' => 'RECTANGLE', 'length_mm' => '400', 'width_mm' => '1000', 'thickness_mm' => '2']],
        ]], $f['user'], self::PERMISSIONS, true);
        [$productId, $remnantResultId] = $saved['result_ids'];
        $route = $records->splitRoutes($productId, $this->payload(1) + ['routes' => [
            ['route_type' => 'WAREHOUSE', 'quantity' => '6'],
        ]], $f['user'], self::PERMISSIONS, true)['routes'][0];
        $this->submitBatch($f, $firstBatchId);
        $planId = DB::table('erp_cutting_allowed_outputs')->where('id', $f['allowed'])->value('plan_id');
        $confirm->confirm($firstBatchId, $this->payload((int) DB::table('erp_cutting_settlement_batches')->where('id', $firstBatchId)->value('business_version')) + [
            'costs' => [['result_id' => $productId, 'total_cost' => '1800'], ['result_id' => $remnantResultId, 'total_cost' => '1200']],
            'allocations' => [['route_id' => $route['id'], 'plan_id' => $planId, 'quantity' => '6', 'disposition' => 'PLAN']],
        ], $f['user'], self::PERMISSIONS, true);

        $remnant = DB::table('erp_material_physicals')->where('id', DB::table('erp_cutting_results')->where('id', $remnantResultId)->value('physical_material_id'))->first();
        $this->assertSame('AVAILABLE', $remnant->status);
        $this->assertSame('REMNANT', $remnant->material_form);
        $this->assertSame($f['physicals'][0], (int) $remnant->parent_physical_id);
        $this->assertSame($f['physicals'][0], (int) $remnant->root_physical_id);
        $this->assertSame('1200.0000', $remnant->total_cost);
        $candidate = app(CuttingReadService::class)->inputCandidates($f['order'], ['keyword' => $remnant->physical_no], $f['user'], self::PERMISSIONS, true);
        $this->assertSame($remnant->id, $candidate['data'][0]['id']);
        $this->assertSame('REMNANT_WIP', $candidate['data'][0]['position_type']);

        $transactions = DB::table('erp_inventory_transactions')->count();
        $orderVersion = (int) DB::table('erp_cutting_orders')->where('id', $f['order'])->value('business_version');
        $reserved = $input->reserve($f['order'], $this->payload($orderVersion) + ['physical_material_ids' => [$remnant->id]], $f['user'], self::PERMISSIONS, true);
        $recut = $input->issue($f['order'], $this->payload($reserved['business_version']) + ['physical_material_id' => $remnant->id], $f['user'], self::PERMISSIONS, true);
        $this->assertSame($transactions, DB::table('erp_inventory_transactions')->count());
        $this->assertSame('1200.0000', $recut['original_total_cost']);
        $this->assertNull(DB::table('erp_cutting_settlement_batches')->where('id', $recut['settlement_batch_id'])->value('issue_transaction_id'));
        $this->assertSame('CONSUMED', DB::table('erp_material_holdings')->where('id', $remnant->current_holding_id)->value('status'));
        $movement = DB::table('erp_material_movements')->where('target_holding_id', DB::table('erp_cutting_settlement_batches')->where('id', $recut['settlement_batch_id'])->value('wip_holding_id'))->first();
        $this->assertSame('RECUT_ISSUE', $movement->action);
        $this->assertSame('1200.0000', $movement->total_cost);
        $token = $this->token($f['user']);
        $this->withToken($token)->getJson('/api/v1/erp/production/cutting/material-physicals?keyword='.urlencode($remnant->physical_no))
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('summary.rectangle_remnant', 1)
            ->assertJsonPath('data.0.position_type', 'CUTTING_WIP');
        $this->withToken($token)->getJson('/api/v1/erp/production/cutting/material-physicals/'.$remnant->id)
            ->assertOk()->assertJsonPath('data.physical.id', $remnant->id)->assertJsonPath('data.source.type', 'CUTTING_RESULT')
            ->assertJsonCount(2, 'data.lineage')->assertJsonPath('data.lineage.0.id', $f['physicals'][0])
            ->assertJsonPath('data.lineage.1.parent_physical_id', $f['physicals'][0]);
        $secondSaved = $records->saveResults($recut['settlement_batch_id'], $this->payload(1) + ['results' => [
            ['client_row_id' => 'product-2', 'result_type' => 'product', 'allowed_output_id' => $f['allowed'], 'actual_qty' => '2'],
            ['client_row_id' => 'remnant-2', 'result_type' => 'usable_remnant', 'actual_qty' => '1', 'measurement_status' => 'MEASURED',
                'measurements' => ['shape' => 'RECTANGLE', 'length_mm' => '180', 'width_mm' => '1000', 'thickness_mm' => '2']],
        ]], $f['user'], self::PERMISSIONS, true);
        [$secondProductId, $grandchildResultId] = $secondSaved['result_ids'];
        $secondRoute = $records->splitRoutes($secondProductId, $this->payload(1) + ['routes' => [[
            'route_type' => 'WAREHOUSE', 'quantity' => '2',
        ]]], $f['user'], self::PERMISSIONS, true)['routes'][0];
        $this->submitBatch($f, $recut['settlement_batch_id']);
        $confirm->confirm($recut['settlement_batch_id'], $this->payload((int) DB::table('erp_cutting_settlement_batches')
            ->where('id', $recut['settlement_batch_id'])->value('business_version')) + [
                'costs' => [['result_id' => $secondProductId, 'total_cost' => '600'], ['result_id' => $grandchildResultId, 'total_cost' => '600']],
                'allocations' => [['route_id' => $secondRoute['id'], 'plan_id' => $planId, 'quantity' => '2', 'disposition' => 'PLAN']],
            ], $f['user'], self::PERMISSIONS, true);
        $grandchild = DB::table('erp_material_physicals')->where('id', DB::table('erp_cutting_results')
            ->where('id', $grandchildResultId)->value('physical_material_id'))->first();
        $this->assertSame($remnant->id, (int) $grandchild->parent_physical_id);
        $this->assertSame($f['physicals'][0], (int) $grandchild->root_physical_id);
        $this->assertSame('600.0000', $grandchild->total_cost);
        $this->assertSame($transactions, DB::table('erp_inventory_transactions')->count());
        $this->domain('cutting_correction_child_recut', fn () => app(CuttingCorrectionService::class)->reverse(
            $firstBatchId, $this->payload((int) DB::table('erp_cutting_settlement_batches')->where('id', $firstBatchId)->value('business_version')) + [
                'reason' => '子余料已经再切，父批不得冲销',
            ], $f['user'], self::PERMISSIONS, true), 409);
        $this->assertSame('CONFIRMED', DB::table('erp_cutting_settlement_batches')->where('id', $firstBatchId)->value('status'));
    }

    public function test_uncut_physical_can_return_to_exact_inventory_but_first_cut_can_never_restore_full_plate(): void
    {
        $f = $this->fixture(); $input = app(CuttingInputService::class); $issued = $this->issue($f);
        $payload = $this->payload(1) + ['reason' => '排产取消，材料尚未切割'];
        $returned = $input->returnOriginal($issued['settlement_batch_id'], $payload, $f['user'], self::PERMISSIONS, true);
        $this->assertSame($returned, $input->returnOriginal($issued['settlement_batch_id'], $payload, $f['user'], self::PERMISSIONS, true));
        $this->assertSame('RETURNED', $returned['status']);
        $this->assertSame('AVAILABLE', DB::table('erp_material_physicals')->where('id', $f['physicals'][0])->value('status'));
        $this->assertSame('RETURNED', DB::table('erp_material_holdings')->where('id', DB::table('erp_cutting_settlement_batches')
            ->where('id', $issued['settlement_batch_id'])->value('wip_holding_id'))->value('status'));
        $this->assertSame(0, bccomp('2', (string) $f['balance']->fresh()->quantity_on_hand, 8));
        $this->assertSame('6000.0000', (string) $f['balance']->fresh()->inventory_value);
        $this->assertSame(1, DB::table('erp_inventory_transactions')->where('transaction_type', 'cutting_material_return')
            ->where('source_id', $issued['settlement_batch_id'])->count());
        $this->assertDatabaseHas('erp_material_movements', ['action' => 'RETURN_ORIGINAL', 'total_cost' => '3000.0000']);

        $cut = $this->fixture(); $cutIssued = $this->issue($cut); $batchId = $cutIssued['settlement_batch_id'];
        $input->markFirstCut($batchId, $this->payload(1), $cut['user'], self::PERMISSIONS, true);
        $this->domain('original_return_after_cut', fn () => $input->returnOriginal($batchId, $this->payload(2) + [
            'reason' => '已经切割也不能恢复整板'], $cut['user'], self::PERMISSIONS, true), 409);
        $this->assertSame('ISSUED', DB::table('erp_material_physicals')->where('id', $cut['physicals'][0])->value('status'));
        $this->assertSame(0, DB::table('erp_inventory_transactions')->where('transaction_type', 'cutting_material_return')
            ->where('source_id', $batchId)->count());
    }

    public function test_length_remnant_recut_uses_holding_without_fake_physical_or_second_inventory_issue(): void
    {
        $f = $this->fixture();
        $f['raw']->forceFill(['material_management_mode' => 'quantity', 'cutting_mode' => 'length',
            'standard_stock_length_mm' => '6000', 'is_length_cut_material' => true])->save();
        DB::table('erp_material_lots')->where('id', $f['balance']->material_lot_id)->update(['material_form' => 'STANDARD_LENGTH',
            'cut_length_mm' => '6000', 'updated_at' => now()]);
        $input = app(CuttingInputService::class); $records = app(CuttingRecordService::class); $confirm = app(CuttingConfirmationService::class);
        $first = $input->issue($f['order'], $this->payload(1) + ['inventory_balance_id' => $f['balance']->id, 'input_qty' => '1'],
            $f['user'], self::PERMISSIONS, true);
        $firstBatchId = $first['settlement_batch_id'];
        $saved = $records->saveResults($firstBatchId, $this->payload(1) + ['results' => [
            ['client_row_id' => 'tube-product-1', 'result_type' => 'product', 'allowed_output_id' => $f['allowed'], 'actual_qty' => '6'],
            ['client_row_id' => 'tube-remnant-1', 'result_type' => 'usable_remnant', 'actual_qty' => '1',
                'measurement_status' => 'MEASURED', 'measurements' => ['length_mm' => '2000']],
        ]], $f['user'], self::PERMISSIONS, true);
        [$productId, $remnantResultId] = $saved['result_ids'];
        $route = $records->splitRoutes($productId, $this->payload(1) + ['routes' => [[
            'route_type' => 'WAREHOUSE', 'quantity' => '6',
        ]]], $f['user'], self::PERMISSIONS, true)['routes'][0];
        $this->submitBatch($f, $firstBatchId);
        $planId = DB::table('erp_cutting_allowed_outputs')->where('id', $f['allowed'])->value('plan_id');
        $confirm->confirm($firstBatchId, $this->payload((int) DB::table('erp_cutting_settlement_batches')->where('id', $firstBatchId)->value('business_version')) + [
            'costs' => [['result_id' => $productId, 'total_cost' => '1800'], ['result_id' => $remnantResultId, 'total_cost' => '1200']],
            'allocations' => [['route_id' => $route['id'], 'plan_id' => $planId, 'quantity' => '6', 'disposition' => 'PLAN']],
        ], $f['user'], self::PERMISSIONS, true);
        $this->assertNull(DB::table('erp_cutting_results')->where('id', $remnantResultId)->value('physical_material_id'));
        $remnantHolding = DB::table('erp_material_holdings')->where('position_type', 'REMNANT_WIP')->where('position_id', $remnantResultId)->first();
        $lotNo = DB::table('erp_material_lots')->where('id', $remnantHolding->material_lot_id)->value('lot_no');
        $candidate = app(CuttingReadService::class)->inputCandidates($f['order'], ['input_type' => 'quantity', 'material_form' => 'REMNANT',
            'keyword' => $lotNo], $f['user'], self::PERMISSIONS, true);
        $this->assertSame(1, $candidate['meta']['total']);
        $this->assertSame($remnantHolding->id, $candidate['data'][0]['remnant_holding_id']);
        $this->assertSame('2000.00', $candidate['data'][0]['standard_stock_length_mm']);
        $token = $this->token($f['user']);
        $this->withToken($token)->getJson('/api/v1/erp/production/cutting/orders/'.$f['order'].'/input-candidates?input_type=quantity&material_form=REMNANT&keyword='.urlencode($lotNo))
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.source_type', 'REMNANT_WIP')
            ->assertJsonPath('data.0.remnant_holding_id', $remnantHolding->id);

        $transactions = DB::table('erp_inventory_transactions')->count();
        $orderVersion = (int) DB::table('erp_cutting_orders')->where('id', $f['order'])->value('business_version');
        $second = $input->issue($f['order'], $this->payload($orderVersion) + ['remnant_holding_id' => $remnantHolding->id],
            $f['user'], self::PERMISSIONS, true);
        $this->assertSame('1200.0000', $second['original_total_cost']);
        $this->assertSame($transactions, DB::table('erp_inventory_transactions')->count());
        $secondResultId = $records->saveResults($second['settlement_batch_id'], $this->payload(1) + ['results' => [[
            'client_row_id' => 'tube-product-2', 'result_type' => 'product', 'allowed_output_id' => $f['allowed'], 'actual_qty' => '4',
        ]]], $f['user'], self::PERMISSIONS, true)['result_ids'][0];
        $secondRoute = $records->splitRoutes($secondResultId, $this->payload(1) + ['routes' => [[
            'route_type' => 'WAREHOUSE', 'quantity' => '4',
        ]]], $f['user'], self::PERMISSIONS, true)['routes'][0];
        $this->submitBatch($f, $second['settlement_batch_id']);
        $confirmed = $confirm->confirm($second['settlement_batch_id'], $this->payload((int) DB::table('erp_cutting_settlement_batches')
            ->where('id', $second['settlement_batch_id'])->value('business_version')) + [
                'costs' => [['result_id' => $secondResultId, 'total_cost' => '1200']],
                'allocations' => [['route_id' => $secondRoute['id'], 'plan_id' => $planId, 'quantity' => '4', 'disposition' => 'PLAN']],
            ], $f['user'], self::PERMISSIONS, true);
        $this->assertSame('CONFIRMED', $confirmed['status']);
        $this->assertSame('CONSUMED', DB::table('erp_material_holdings')->where('id', $remnantHolding->id)->value('status'));
        $this->domain('cutting_correction_child_recut', fn () => app(CuttingCorrectionService::class)->reverse(
            $firstBatchId, $this->payload((int) DB::table('erp_cutting_settlement_batches')->where('id', $firstBatchId)->value('business_version')) + [
                'reason' => '定长子余料已再次下料，父批不得冲销',
            ], $f['user'], self::PERMISSIONS, true), 409);
    }

    public function test_untouched_confirmation_reopens_as_controlled_correction_and_can_be_reconfirmed(): void
    {
        $f = $this->fixture(); $records = app(CuttingRecordService::class); $confirm = app(CuttingConfirmationService::class);
        $issued = $this->issue($f); $originalBatchId = $issued['settlement_batch_id'];
        $originalResultId = $this->save($f, $originalBatchId, '10')['result_ids'][0];
        $originalRoute = $this->route($f, $originalResultId, '10')['routes'][0];
        $confirm->confirm($originalBatchId, $this->confirmation($f, $originalBatchId, $originalResultId, '3000'), $f['user'], self::PERMISSIONS, true);
        $transactions = DB::table('erp_inventory_transactions')->count();
        $payload = $this->payload((int) DB::table('erp_cutting_settlement_batches')->where('id', $originalBatchId)->value('business_version')) + [
            'reason' => '原核算去向录入错误，尚未交接或入库',
        ];
        $token = $this->token($f['user']);
        $url = '/api/v1/erp/production/cutting/settlements/'.$originalBatchId.'/reverse-confirmation';
        $correction = $this->withToken($token)->postJson($url, $payload)->assertOk()->assertJsonPath('data.status', 'OPEN')->json('data');
        $this->withToken($token)->postJson($url, $payload)->assertOk()->assertJsonPath('data', $correction);
        $replacementId = $correction['correction_settlement_batch_id'];
        $this->assertSame('REVERSED', DB::table('erp_cutting_settlement_batches')->where('id', $originalBatchId)->value('status'));
        $this->assertSame('REVERSED', DB::table('erp_cutting_results')->where('id', $originalResultId)->value('status'));
        $this->assertSame('CANCELLED', DB::table('erp_cutting_result_routes')->where('id', $originalRoute['id'])->value('status'));
        $this->assertSame('REVERSED', DB::table('erp_cutting_output_allocations')->where('result_id', $originalResultId)->value('status'));
        $replacement = DB::table('erp_cutting_settlement_batches')->where('id', $replacementId)->first();
        $this->assertSame($originalBatchId, (int) $replacement->correction_of_batch_id);
        $this->assertSame('PROCESSING', $replacement->status);
        $correctionWip = DB::table('erp_material_holdings')->where('id', $replacement->wip_holding_id)->first();
        $this->assertSame('CORRECTION_WIP', $correctionWip->position_type);
        $this->assertSame('3000.0000', $correctionWip->total_cost);
        $this->assertSame('CORRECTION', DB::table('erp_material_physicals')->where('id', $f['physicals'][0])->value('status'));
        $this->assertSame($transactions, DB::table('erp_inventory_transactions')->count(), '核算更正不应伪造库存出入库');

        $replacementResult = $records->saveResults($replacementId, $this->payload(1) + ['results' => [[
            'client_row_id' => 'corrected-product', 'result_type' => 'product', 'allowed_output_id' => $f['allowed'], 'actual_qty' => '10',
        ]]], $f['user'], self::PERMISSIONS, true)['result_ids'][0];
        $replacementRoute = $records->splitRoutes($replacementResult, $this->payload(1) + ['routes' => [[
            'route_type' => 'WAREHOUSE', 'quantity' => '10',
        ]]], $f['user'], self::PERMISSIONS, true)['routes'][0];
        $this->submitBatch($f, $replacementId);
        $planId = DB::table('erp_cutting_allowed_outputs')->where('id', $f['allowed'])->value('plan_id');
        $confirmed = $confirm->confirm($replacementId, $this->payload((int) DB::table('erp_cutting_settlement_batches')->where('id', $replacementId)->value('business_version')) + [
            'costs' => [['result_id' => $replacementResult, 'total_cost' => '3000']],
            'allocations' => [['route_id' => $replacementRoute['id'], 'plan_id' => $planId, 'quantity' => '10', 'disposition' => 'PLAN']],
        ], $f['user'], self::PERMISSIONS, true);
        $this->assertSame('CONFIRMED', $confirmed['status']);
        $this->assertSame('CONFIRMED', DB::table('erp_cutting_corrections')->where('id', $correction['correction_id'])->value('status'));
        $this->assertSame('CONSUMED', DB::table('erp_material_physicals')->where('id', $f['physicals'][0])->value('status'));
        $this->assertSame(1, DB::table('erp_cutting_output_allocations')->where('plan_id', $planId)->where('status', 'EFFECTIVE')->count());
        $this->assertSame($transactions, DB::table('erp_inventory_transactions')->count());
    }

    public function test_confirmation_with_formal_warehouse_receipt_cannot_be_directly_reversed(): void
    {
        $f = $this->fixture(); $issued = $this->issue($f); $batchId = $issued['settlement_batch_id'];
        $resultId = $this->save($f, $batchId, '10')['result_ids'][0];
        $route = $this->route($f, $resultId, '10')['routes'][0];
        app(CuttingConfirmationService::class)->confirm($batchId, $this->confirmation($f, $batchId, $resultId, '3000'), $f['user'], self::PERMISSIONS, true);
        app(CuttingWarehouseReceiptService::class)->post($route['id'], $this->payload(2) + [
            'quantity' => '10', 'warehouse_id' => $f['warehouse']->id, 'location_id' => $f['location']->id,
            'batch_no' => 'CUT-CORRECTION-BLOCK-'.Str::ulid(),
        ], $f['user'], self::PERMISSIONS, true);
        $this->domain('cutting_correction_downstream_exists', fn () => app(CuttingCorrectionService::class)->reverse(
            $batchId, $this->payload((int) DB::table('erp_cutting_settlement_batches')->where('id', $batchId)->value('business_version')) + [
                'reason' => '已有正式入库后不得跳过下游冲销',
            ], $f['user'], self::PERMISSIONS, true), 409);
        $this->assertSame('CONFIRMED', DB::table('erp_cutting_settlement_batches')->where('id', $batchId)->value('status'));
        $this->assertSame(0, DB::table('erp_cutting_corrections')->where('original_settlement_batch_id', $batchId)->count());
        $this->assertSame('WAREHOUSED', DB::table('erp_cutting_result_routes')->where('id', $route['id'])->value('status'));
    }

    public function test_available_remnant_disposal_preserves_cost_fact_and_cannot_be_double_posted(): void
    {
        $f = $this->fixture(); $records = app(CuttingRecordService::class); $confirm = app(CuttingConfirmationService::class);
        $issued = $this->issue($f); $batchId = $issued['settlement_batch_id'];
        $saved = $records->saveResults($batchId, $this->payload(1) + ['results' => [
            ['client_row_id' => 'product-for-disposal', 'result_type' => 'product', 'allowed_output_id' => $f['allowed'], 'actual_qty' => '6'],
            ['client_row_id' => 'remnant-for-disposal', 'result_type' => 'usable_remnant', 'actual_qty' => '1', 'measurement_status' => 'MEASURED',
                'measurements' => ['shape' => 'IRREGULAR', 'length_mm' => '350', 'width_mm' => '900', 'thickness_mm' => '2']],
        ]], $f['user'], self::PERMISSIONS, true);
        [$productId, $remnantResultId] = $saved['result_ids'];
        $route = $records->splitRoutes($productId, $this->payload(1) + ['routes' => [[
            'route_type' => 'WAREHOUSE', 'quantity' => '6',
        ]]], $f['user'], self::PERMISSIONS, true)['routes'][0];
        $this->submitBatch($f, $batchId);
        $planId = DB::table('erp_cutting_allowed_outputs')->where('id', $f['allowed'])->value('plan_id');
        $confirm->confirm($batchId, $this->payload((int) DB::table('erp_cutting_settlement_batches')->where('id', $batchId)->value('business_version')) + [
            'costs' => [['result_id' => $productId, 'total_cost' => '2100'], ['result_id' => $remnantResultId, 'total_cost' => '900']],
            'allocations' => [['route_id' => $route['id'], 'plan_id' => $planId, 'quantity' => '6', 'disposition' => 'PLAN']],
        ], $f['user'], self::PERMISSIONS, true);
        $physicalId = (int) DB::table('erp_cutting_results')->where('id', $remnantResultId)->value('physical_material_id');
        $sourceHoldingId = (int) DB::table('erp_material_physicals')->where('id', $physicalId)->value('current_holding_id');
        $transactions = DB::table('erp_inventory_transactions')->count();
        $payload = $this->payload(1) + ['reason' => '尺寸不足，按余料损失正式处置'];
        $url = '/api/v1/erp/production/cutting/material-physicals/'.$physicalId.'/dispose';
        $token = $this->token($f['user']);
        $disposed = $this->withToken($token)->postJson($url, $payload)->assertOk()->assertJsonPath('data.status', 'DISPOSED')->json('data');
        $this->withToken($token)->postJson($url, $payload)->assertOk()->assertJsonPath('data', $disposed);
        $physical = DB::table('erp_material_physicals')->where('id', $physicalId)->first();
        $this->assertSame('DISPOSED', $physical->status);
        $this->assertSame('DISPOSED', DB::table('erp_material_holdings')->where('id', $sourceHoldingId)->value('status'));
        $target = DB::table('erp_material_holdings')->where('id', $physical->current_holding_id)->first();
        $this->assertSame('DISPOSAL', $target->position_type);
        $this->assertSame('900.0000', $target->total_cost);
        $this->assertDatabaseHas('erp_material_physical_disposals', ['id' => $disposed['disposal_id'], 'total_cost' => '900.0000', 'status' => 'POSTED']);
        $this->assertDatabaseHas('erp_material_movements', ['source_holding_id' => $sourceHoldingId,
            'target_holding_id' => $target->id, 'action' => 'DISPOSE', 'total_cost' => '900.0000']);
        $this->assertSame($transactions, DB::table('erp_inventory_transactions')->count());
    }

    public function test_correction_failure_after_effect_reversal_rolls_back_every_fact_and_command(): void
    {
        $f = $this->fixture(); $issued = $this->issue($f); $batchId = $issued['settlement_batch_id'];
        $resultId = $this->save($f, $batchId, '10')['result_ids'][0];
        $route = $this->route($f, $resultId, '10')['routes'][0];
        app(CuttingConfirmationService::class)->confirm($batchId, $this->confirmation($f, $batchId, $resultId, '3000'), $f['user'], self::PERMISSIONS, true);
        $routeHoldingId = (int) DB::table('erp_cutting_result_routes')->where('id', $route['id'])->value('holding_id');
        $payload = $this->payload((int) DB::table('erp_cutting_settlement_batches')->where('id', $batchId)->value('business_version')) + [
            'reason' => '验证更正后段故障必须完整回滚',
        ];
        $injected = false;
        DB::listen(function ($query) use (&$injected): void {
            if (! $injected && str_starts_with(strtolower($query->sql), 'insert into `erp_cutting_corrections`')) {
                $injected = true;
                throw new \RuntimeException('cutting correction injected failure');
            }
        });
        try {
            app(CuttingCorrectionService::class)->reverse($batchId, $payload, $f['user'], self::PERMISSIONS, true);
            $this->fail('Expected injected correction failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('cutting correction injected failure', $e->getMessage());
        }
        $this->assertTrue($injected);
        $this->assertSame('CONFIRMED', DB::table('erp_cutting_settlement_batches')->where('id', $batchId)->value('status'));
        $this->assertSame(0, DB::table('erp_cutting_settlement_batches')->where('correction_of_batch_id', $batchId)->count());
        $this->assertSame('CONFIRMED', DB::table('erp_cutting_results')->where('id', $resultId)->value('status'));
        $this->assertSame('WAIT_WAREHOUSE', DB::table('erp_cutting_result_routes')->where('id', $route['id'])->value('status'));
        $this->assertSame('EFFECTIVE', DB::table('erp_cutting_output_allocations')->where('result_id', $resultId)->value('status'));
        $holding = DB::table('erp_material_holdings')->where('id', $routeHoldingId)->first();
        $this->assertSame('ACTIVE', $holding->status);
        $this->assertSame('10.00000000', $holding->quantity);
        $this->assertSame('3000.0000', $holding->total_cost);
        $this->assertSame('CONSUMED', DB::table('erp_material_physicals')->where('id', $f['physicals'][0])->value('status'));
        $this->assertSame(0, DB::table('erp_cutting_corrections')->where('original_settlement_batch_id', $batchId)->count());
        $this->assertSame(0, DB::table('erp_cutting_commands')->where('client_command_id', $payload['client_command_id'])->count());
        $retried = app(CuttingCorrectionService::class)->reverse($batchId, $payload, $f['user'], self::PERMISSIONS, true);
        $this->assertSame('OPEN', $retried['status']);
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

    public function test_independent_cutting_task_http_lifecycle_uses_shared_labor_without_settling_inputs(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-16 08:00:00'));
        try {
            $f = $this->fixture(); $batch = $this->issue($f); $taskId = (int) $f['created']['cutting_task_id'];
            $token = $this->token($f['user']); $base = '/api/v1/erp/production/cutting/tasks/'.$taskId;
            $this->withToken($token)->getJson('/api/v1/erp/production/cutting/tasks?status=WAIT_CLAIM')->assertOk()
                ->assertJsonPath('data.0.id',$taskId)->assertJsonPath('data.0.display_status','待领取');
            $claimed = $this->withToken($token)->postJson($base.'/claim',$this->payload(1))->assertOk()
                ->assertJsonPath('data.status','READY')->assertJsonPath('data.business_version',2)->json('data');
            $this->assertSame($f['user']->legacy_id,$claimed['assignee_user_legacy_id']);
            $this->withToken($token)->postJson($base.'/start',$this->payload(2))->assertOk()
                ->assertJsonPath('data.status','IN_PROGRESS')->assertJsonCount(1,'data.active_labor_sessions');

            Carbon::setTestNow(Carbon::now()->addMinutes(5));
            $this->withToken($token)->postJson($base.'/pause',$this->payload(3))->assertOk()
                ->assertJsonPath('data.status','PAUSED')->assertJsonPath('data.actual_labor_minutes','5.00');
            $this->withToken($token)->postJson($base.'/resume',$this->payload(4))->assertOk()
                ->assertJsonPath('data.status','IN_PROGRESS');
            Carbon::setTestNow(Carbon::now()->addMinutes(3));
            $this->withToken($token)->postJson($base.'/finish',$this->payload(5))->assertOk()
                ->assertJsonPath('data.status','FINISHED')->assertJsonPath('data.actual_labor_minutes','8.00')
                ->assertJsonPath('data.settlement_statuses.PROCESSING',1);

            $this->withToken($token)->getJson($base)->assertOk()->assertJsonPath('data.task.status','FINISHED')
                ->assertJsonPath('data.inputs.data.0.id',$batch['settlement_batch_id'])
                ->assertJsonPath('data.input_source_locked_per_record',true)->assertJsonCount(2,'data.labor_sessions');
            $this->assertSame('PROCESSING',DB::table('erp_cutting_settlement_batches')->where('id',$batch['settlement_batch_id'])->value('status'));
            $this->assertSame('PUBLISHED',DB::table('erp_cutting_orders')->where('id',$f['order'])->value('status'));
            $this->assertSame(2,DB::table('erp_production_labor_sessions')->where('execution_task_type','CUTTING_TASK')
                ->where('cutting_task_id',$taskId)->whereNull('task_id')->where('status','ENDED')->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_cutting_and_production_share_one_active_employee_switch_guard(): void
    {
        $f = $this->fixture(); $this->issue($f); $taskId = (int) $f['created']['cutting_task_id'];
        $tasks = app(CuttingTaskExecutionService::class);
        $tasks->claim($taskId,$this->payload(1),$f['user'],self::PERMISSIONS,true);
        $started = $tasks->start($taskId,$this->payload(2),$f['user'],self::PERMISSIONS,true);
        $activeId = $started['active_labor_sessions'][0]['id'];
        $target = ProductionQuantityOperation::findOrFail($f['producerOperation']);
        $productionTask = ProductionTask::create(['task_no'=>'PT-SWITCH-'.Str::ulid(),'work_order_id'=>$f['wo']->id,'execution_mode'=>'quantity',
            'routing_operation_id_snapshot'=>$f['stage'],'operation_code_snapshot'=>$target->operation_code_snapshot,
            'operation_name_snapshot'=>$target->operation_name_snapshot,'sequence_no_snapshot'=>10,'status'=>'IN_PROGRESS',
            'assignee_user_legacy_id'=>$f['user']->legacy_id,'claimed_at'=>now(),'business_version'=>1]);
        $labor = app(ProductionLaborSessionService::class);
        $this->domain('labor_switch_confirmation_required',fn () => DB::transaction(fn () => $labor->start(
            $productionTask,$target,'quantity_operation',$f['user']->legacy_id,'owner',1,now(),[]
        )),409);
        DB::transaction(fn () => $labor->start($productionTask,$target,'quantity_operation',$f['user']->legacy_id,'owner',1,now(),[
            'switch_active_labor'=>true,'expected_active_labor_session_id'=>$activeId
        ]));
        $this->assertSame(1,DB::table('erp_production_labor_sessions')->where('employee_legacy_id',$f['user']->legacy_id)->where('status','ACTIVE')->count());
        $this->assertSame('task_switched',DB::table('erp_production_labor_sessions')->where('id',$activeId)->value('end_reason'));
        $this->assertSame('PAUSED',DB::table('erp_cutting_tasks')->where('id',$taskId)->value('status'));
        $this->assertSame('PRODUCTION_TASK',DB::table('erp_production_labor_sessions')->where('employee_legacy_id',$f['user']->legacy_id)
            ->where('status','ACTIVE')->value('execution_task_type'));
    }

    public function test_cutting_collaborator_must_stop_shared_labor_before_owner_finishes(): void
    {
        $f = $this->fixture(); $this->issue($f); $taskId = (int) $f['created']['cutting_task_id'];
        $collaborator = (object) ['legacy_id'=>random_int(100000000,999999999),'username'=>'cut-collab-'.Str::ulid()];
        DB::table('erp_legacy_admin_users')->insert(['legacy_id'=>$collaborator->legacy_id,'username'=>$collaborator->username,
            'status'=>'normal','auth_group_names'=>'[]','created_at'=>now(),'updated_at'=>now()]);
        $this->token($collaborator);
        $service = app(CuttingTaskExecutionService::class);
        $service->claim($taskId,$this->payload(1),$f['user'],self::PERMISSIONS,true);
        $service->start($taskId,$this->payload(2),$f['user'],self::PERMISSIONS,true);
        $added = $service->addCollaborators($taskId,$this->payload(3)+['employee_legacy_ids'=>[$collaborator->legacy_id]],$f['user'],self::PERMISSIONS,true);
        $this->assertSame([$collaborator->legacy_id],$added['added_employee_legacy_ids']);
        $service->startCollaboratorLabor($taskId,$this->payload(4),$collaborator,self::PERMISSIONS,true);
        $this->assertSame(2,DB::table('erp_production_labor_sessions')->where('cutting_task_id',$taskId)->where('status','ACTIVE')->count());
        $this->domain('cutting_collaborator_labor_active',fn () => $service->finish($taskId,$this->payload(5),$f['user'],self::PERMISSIONS,true),409);
        $this->assertSame(2,DB::table('erp_production_labor_sessions')->where('cutting_task_id',$taskId)->where('status','ACTIVE')->count());
        $service->pauseCollaboratorLabor($taskId,$this->payload(5),$collaborator,self::PERMISSIONS,true);
        $finished = $service->finish($taskId,$this->payload(6),$f['user'],self::PERMISSIONS,true);
        $this->assertSame('FINISHED',$finished['status']);
        $this->assertSame(0,DB::table('erp_production_labor_sessions')->where('cutting_task_id',$taskId)->where('status','ACTIVE')->count());
        $this->assertSame('PROCESSING',DB::table('erp_cutting_settlement_batches')->where('cutting_task_id',$taskId)->value('status'));
    }

    public function test_real_cutting_handover_supports_partial_accept_reject_and_formal_kitting(): void
    {
        $f = $this->fixture(); $receiver = $this->employee('cut-receiver-'); $targetTask = $this->consumerTask($f,$receiver);
        $batch = $this->issue($f); $batchId = $batch['settlement_batch_id']; $result = $this->save($f,$batchId,'10')['result_ids'][0];
        $sourceToken = $this->token($f['user']); $receiverToken = $this->token($receiver);
        $this->withToken($sourceToken)->getJson('/api/v1/erp/production/cutting/results/'.$result.'/handover-targets?per_page=10')
            ->assertOk()->assertJsonPath('meta.total',1)->assertJsonPath('data.0.target_material_requirement_id',$f['targetRequirement'])
            ->assertJsonPath('data.0.task_id',$targetTask->id)->assertJsonPath('data.0.eligible_for_dispatch',true)
            ->assertJsonPath('data.0.selectable_qty','10.00000000');
        $split = app(CuttingRecordService::class)->splitRoutes($result,$this->payload(1)+['routes'=>[[
            'route_type'=>'NEXT_OPERATION','quantity'=>'10','target_material_requirement_id'=>$f['targetRequirement']
        ]]],$f['user'],self::PERMISSIONS,true);
        $routeId = $split['routes'][0]['id'];
        app(CuttingConfirmationService::class)->confirm($batchId,$this->confirmation($f,$batchId,$result,'3000'),$f['user'],self::PERMISSIONS,true);
        $inventoryTransactions = DB::table('erp_inventory_transactions')->count();

        $handover = app(CuttingHandoverService::class); $routeVersion = (int) DB::table('erp_cutting_result_routes')->where('id',$routeId)->value('business_version');
        $first = $handover->dispatch($routeId,$this->payload($routeVersion)+['quantity'=>'6'],$f['user'],self::PERMISSIONS,true);
        $this->assertSame('PART_DISPATCHED',$first['route_status']); $this->assertSame('WAIT_HANDOVER',DB::table('erp_production_quantity_operations')->where('id',$f['consumerOperation'])->value('status'));
        $this->withToken($receiverToken)->getJson('/api/v1/erp/production/cutting/handovers/pending')->assertOk()
            ->assertJsonPath('data.0.id',$first['handover_id'])->assertJsonPath('data.0.task_id',$targetTask->id);

        $accepted = $handover->accept($first['handover_id'],$this->payload(1)+['quantity'=>'4'],$receiver,self::PERMISSIONS,true);
        $this->assertSame('PARTIAL',$accepted['status']); $this->assertSame('4.00000000',$accepted['route_received_qty']);
        $this->assertSame('WAIT_HANDOVER',$accepted['target_status']);
        $rejected = $handover->reject($first['handover_id'],$this->payload(2)+['quantity'=>'2','reason'=>'两片边缘变形'],$receiver,self::PERMISSIONS,true);
        $this->assertSame('MIXED',$rejected['status']); $this->assertSame('4.00000000',$rejected['route_handed_over_qty']);
        $this->assertSame('4.00000000',$rejected['route_received_qty']); $this->assertSame('WAIT_MATERIAL',$rejected['target_status']);

        $routeVersion = (int) DB::table('erp_cutting_result_routes')->where('id',$routeId)->value('business_version');
        $second = $handover->dispatch($routeId,$this->payload($routeVersion)+['quantity'=>'6'],$f['user'],self::PERMISSIONS,true);
        $done = $handover->accept($second['handover_id'],$this->payload(1)+['quantity'=>'6'],$receiver,self::PERMISSIONS,true);
        $this->assertSame('RECEIVED',$done['route_status']); $this->assertSame('10.00000000',$done['route_handed_over_qty']);
        $this->assertSame('10.00000000',$done['route_received_qty']);
        $requirement = DB::table('erp_production_target_material_requirements')->where('id',$f['targetRequirement'])->first();
        $this->assertSame('10.00000000',$requirement->satisfied_base_qty); $this->assertSame('SATISFIED',$requirement->status);
        $this->assertSame($inventoryTransactions,DB::table('erp_inventory_transactions')->count(),'真实工序交接不能伪造仓库流水');
        $this->assertSame('10.00000000',(string) DB::table('erp_material_holdings')->where('position_type','PRODUCTION_WIP')
            ->where('position_id',$f['targetRequirement'])->sum('quantity'));
        $this->assertSame('3000.0000',(string) DB::table('erp_material_holdings')->where('position_type','PRODUCTION_WIP')
            ->where('position_id',$f['targetRequirement'])->sum('total_cost'));

        $target = ProductionQuantityOperation::findOrFail($f['consumerOperation']);
        $kitting = app(ProductionKittingService::class)->confirm($targetTask->id,'quantity_operation',$target->id,
            $this->payload((int) $target->business_version),$receiver,self::PERMISSIONS);
        $this->assertSame('IN_PROGRESS',$kitting['target_status']);
        $this->assertSame(1,DB::table('erp_production_kitting_confirmations')->where('task_id',$targetTask->id)->count());
        $sourceFacts = json_decode(DB::table('erp_production_kitting_confirmation_lines')->where('confirmation_id',$kitting['id'])
            ->value('source_facts_snapshot'),true,512,JSON_THROW_ON_ERROR);
        $this->assertCount(2,$sourceFacts['cutting_handovers']);

        $this->withToken($sourceToken)->getJson('/api/v1/erp/production/cutting/settlements/'.$batchId.'/execution')->assertOk()
            ->assertJsonPath('data.results.data.0.designated_qty','10.00000000')
            ->assertJsonPath('data.results.data.0.handed_over_qty','10.00000000')
            ->assertJsonPath('data.results.data.0.received_qty','10.00000000')
            ->assertJsonPath('data.results.data.0.confirm_allowed',false)
            ->assertJsonPath('data.results.data.0.routes.0.display_status','已接收');
    }

    public function test_shared_cutting_task_scope_is_independent_from_every_related_work_order_scope(): void
    {
        $f = $this->fixture();
        $outside = $this->employee('cut-other-department-');
        $f['consumerWo']->update(['responsible_user_legacy_id' => $outside->legacy_id]);
        $taskId = (int) $f['created']['cutting_task_id'];
        $token = $this->token($f['user'], 'self');

        $this->withToken($token)->getJson('/api/v1/erp/production/cutting/tasks?per_page=1')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $taskId);
        $this->withToken($token)->postJson('/api/v1/erp/production/cutting/tasks/'.$taskId.'/claim', $this->payload(1))
            ->assertOk()->assertJsonPath('data.id', $taskId)->assertJsonPath('data.assignee_user_legacy_id', $f['user']->legacy_id);
        $this->withToken($token)->getJson('/api/v1/erp/production/cutting/tasks/'.$taskId.'?per_page=1')
            ->assertOk()->assertJsonPath('data.task.id', $taskId)->assertJsonPath('data.inputs.meta.per_page', 1);
    }

    public function test_cutting_task_execution_migration_refuses_down_when_formal_participant_exists(): void
    {
        $f = $this->fixture();
        $taskId = (int) $f['created']['cutting_task_id'];
        app(CuttingTaskExecutionService::class)->claim(
            $taskId, $this->payload(1), $f['user'], self::PERMISSIONS, true,
        );
        $migration = require database_path('migrations/2026_09_16_220000_add_cutting_task_execution_and_shared_labor.php');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('已有正式下料参与人或劳动事实，不允许通过结构回退删除业务历史。');
        $migration->down();
    }

    public function test_cutting_receiver_uses_target_task_scope_and_current_owner_while_dispatch_snapshot_is_preserved(): void
    {
        $f = $this->fixture();
        $originalReceiver = $this->employee('cut-original-receiver-');
        $targetTask = $this->consumerTask($f, $originalReceiver);
        $batch = $this->issue($f); $result = $this->save($f, $batch['settlement_batch_id'], '10')['result_ids'][0];
        $split = app(CuttingRecordService::class)->splitRoutes($result, $this->payload(1) + ['routes' => [[
            'route_type' => 'NEXT_OPERATION', 'quantity' => '10', 'target_material_requirement_id' => $f['targetRequirement'],
        ]]], $f['user'], self::PERMISSIONS, true);
        app(CuttingConfirmationService::class)->confirm($batch['settlement_batch_id'],
            $this->confirmation($f, $batch['settlement_batch_id'], $result, '3000'), $f['user'], self::PERMISSIONS, true);
        $routeId = $split['routes'][0]['id'];
        $service = app(CuttingHandoverService::class);
        $routeVersion = (int) DB::table('erp_cutting_result_routes')->where('id', $routeId)->value('business_version');
        $dispatch = $service->dispatch($routeId, $this->payload($routeVersion) + ['quantity' => '10'], $f['user'], self::PERMISSIONS, true);

        $originalToken = $this->token($originalReceiver, 'self');
        $this->withToken($originalToken)->getJson('/api/v1/erp/production/cutting/handovers/pending?page=1&per_page=1')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('data.0.id', $dispatch['handover_id']);

        $currentReceiver = $this->employee('cut-current-receiver-');
        $currentToken = $this->token($currentReceiver, 'self');
        $targetTask->update(['assignee_user_legacy_id' => $currentReceiver->legacy_id,
            'business_version' => (int) $targetTask->business_version + 1]);
        ProductionQuantityOperation::whereKey($f['consumerOperation'])->update([
            'responsible_user_legacy_id' => $currentReceiver->legacy_id,
            'business_version' => DB::raw('business_version + 1'),
        ]);

        $this->withToken($originalToken)->getJson('/api/v1/erp/production/cutting/handovers/pending?page=1&per_page=1')
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->withToken($currentToken)->getJson('/api/v1/erp/production/cutting/handovers/pending?page=1&per_page=1')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $dispatch['handover_id']);
        $this->domain('data_scope_denied', fn () => $service->accept($dispatch['handover_id'],
            $this->payload(1) + ['quantity' => '1'], $originalReceiver, self::PERMISSIONS, false), 403);

        $accepted = $service->accept($dispatch['handover_id'], $this->payload(1) + ['quantity' => '10'],
            $currentReceiver, self::PERMISSIONS, false);
        $this->assertTrue($accepted['receiver_changed_after_dispatch']);
        $this->assertSame($originalReceiver->legacy_id, $accepted['expected_receiver_legacy_id']);
        $this->assertSame($currentReceiver->legacy_id, $accepted['handled_by_legacy_id']);
        $this->assertSame($originalReceiver->legacy_id, (int) DB::table('erp_cutting_handovers')
            ->where('id', $dispatch['handover_id'])->value('expected_receiver_legacy_id'));
    }

    public function test_cutting_receipt_recalculates_target_with_other_predecessor_handover_fact(): void
    {
        $f = $this->fixture(); $receiver = $this->employee('cut-readiness-receiver-'); $targetTask = $this->consumerTask($f, $receiver);
        $batch = $this->issue($f); $result = $this->save($f, $batch['settlement_batch_id'], '10')['result_ids'][0];
        $split = app(CuttingRecordService::class)->splitRoutes($result, $this->payload(1) + ['routes' => [[
            'route_type' => 'NEXT_OPERATION', 'quantity' => '10', 'target_material_requirement_id' => $f['targetRequirement'],
        ]]], $f['user'], self::PERMISSIONS, true);
        app(CuttingConfirmationService::class)->confirm($batch['settlement_batch_id'],
            $this->confirmation($f, $batch['settlement_batch_id'], $result, '3000'), $f['user'], self::PERMISSIONS, true);
        DB::table('erp_production_operation_handovers')->insert([
            'handover_no' => 'CUT-OTHER-HO-'.Str::ulid(), 'work_order_id' => $f['consumerWo']->id,
            'source_target_type' => 'quantity_operation', 'source_target_id' => $f['producerOperation'],
            'target_target_type' => 'quantity_operation', 'target_target_id' => $f['consumerOperation'],
            'status' => 'WAIT_RECEIVE', 'handed_over_by_legacy_id' => $f['user']->legacy_id,
            'handed_over_at' => now(), 'expected_receiver_legacy_id' => $receiver->legacy_id,
            'identity_snapshot' => json_encode(['source' => 'other_predecessor'], JSON_THROW_ON_ERROR),
            'business_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $routeId = $split['routes'][0]['id']; $service = app(CuttingHandoverService::class);
        $routeVersion = (int) DB::table('erp_cutting_result_routes')->where('id', $routeId)->value('business_version');
        $dispatch = $service->dispatch($routeId, $this->payload($routeVersion) + ['quantity' => '10'], $f['user'], self::PERMISSIONS, true);
        $accepted = $service->accept($dispatch['handover_id'], $this->payload(1) + ['quantity' => '10'],
            $receiver, self::PERMISSIONS, true);
        $this->assertSame('WAIT_HANDOVER', $accepted['target_status']);
        $this->assertSame('WAIT_HANDOVER', ProductionQuantityOperation::findOrFail($f['consumerOperation'])->status);
        $this->assertSame('WAIT_HANDOVER', $targetTask->fresh()->status);
    }

    public function test_cutting_handover_replay_and_receiver_scope_do_not_duplicate_receipts(): void
    {
        $f = $this->fixture(); $receiver = $this->employee('cut-receiver-'); $this->consumerTask($f,$receiver);
        $batch = $this->issue($f); $id = $batch['settlement_batch_id']; $result = $this->save($f,$id,'10')['result_ids'][0];
        $split = app(CuttingRecordService::class)->splitRoutes($result,$this->payload(1)+['routes'=>[[
            'route_type'=>'NEXT_OPERATION','quantity'=>'10','target_material_requirement_id'=>$f['targetRequirement']
        ]]],$f['user'],self::PERMISSIONS,true); $routeId = $split['routes'][0]['id'];
        app(CuttingConfirmationService::class)->confirm($id,$this->confirmation($f,$id,$result,'3000'),$f['user'],self::PERMISSIONS,true);
        $service = app(CuttingHandoverService::class); $dispatchPayload = $this->payload((int) DB::table('erp_cutting_result_routes')->where('id',$routeId)->value('business_version'))+['quantity'=>'10'];
        $dispatch = $service->dispatch($routeId,$dispatchPayload,$f['user'],self::PERMISSIONS,true);
        $this->assertSame($dispatch,$service->dispatch($routeId,$dispatchPayload,$f['user'],self::PERMISSIONS,true));
        $outsider = $this->employee('cut-outsider-');
        $this->domain('cutting_expected_receiver_required',fn () => $service->accept($dispatch['handover_id'],$this->payload(1)+['quantity'=>'1'],$outsider,self::PERMISSIONS,true),403);
        $acceptPayload = $this->payload(1)+['quantity'=>'4']; $accepted = $service->accept($dispatch['handover_id'],$acceptPayload,$receiver,self::PERMISSIONS,true);
        $this->assertSame($accepted,$service->accept($dispatch['handover_id'],$acceptPayload,$receiver,self::PERMISSIONS,true));
        $this->assertSame(1,DB::table('erp_cutting_handover_decisions')->where('handover_id',$dispatch['handover_id'])->where('action','ACCEPT')->count());
        $this->assertSame('4.00000000',DB::table('erp_production_target_material_requirements')->where('id',$f['targetRequirement'])->value('satisfied_base_qty'));
    }

    public function test_successful_cutting_accept_and_reject_replays_recheck_current_owner_before_returning_history(): void
    {
        foreach (['accept', 'reject'] as $action) {
            $f = $this->fixture(); $receiver = $this->employee('cut-replay-old-');
            $task = $this->consumerTask($f, $receiver);
            $batch = $this->issue($f); $id = $batch['settlement_batch_id'];
            $result = $this->save($f, $id, '10')['result_ids'][0];
            $split = app(CuttingRecordService::class)->splitRoutes($result, $this->payload(1) + ['routes' => [[
                'route_type' => 'NEXT_OPERATION', 'quantity' => '10', 'target_material_requirement_id' => $f['targetRequirement'],
            ]]], $f['user'], self::PERMISSIONS, true);
            app(CuttingConfirmationService::class)->confirm($id, $this->confirmation($f, $id, $result, '3000'), $f['user'], self::PERMISSIONS, true);
            $routeId = $split['routes'][0]['id']; $service = app(CuttingHandoverService::class);
            $dispatch = $service->dispatch($routeId, $this->payload((int) DB::table('erp_cutting_result_routes')->where('id', $routeId)->value('business_version'))
                + ['quantity' => '10'], $f['user'], self::PERMISSIONS, true);
            $payload = $this->payload(1) + ['quantity' => '4'];
            if ($action === 'reject') $payload['reason'] = '边缘损坏';
            $token = $this->token($receiver, 'self');
            $url = '/api/v1/erp/production/cutting/handovers/'.$dispatch['handover_id'].'/'.$action;
            $this->withToken($token)->postJson($url, $payload)->assertOk();
            $before = DB::table('erp_production_target_material_requirements')->where('id', $f['targetRequirement'])->value('satisfied_base_qty');
            $this->assertSame($action === 'accept' ? '4.00000000' : '0.00000000', $before);
            $current = $this->employee('cut-replay-new-');
            $task->update(['assignee_user_legacy_id' => $current->legacy_id, 'business_version' => $task->business_version + 1]);

            // 完全相同的键、内容和操作者必须403，而非从命令账本返回旧success。
            $this->withToken($token)->postJson($url, $payload)->assertForbidden();
            $this->assertSame(1, DB::table('erp_cutting_handover_decisions')->where('handover_id', $dispatch['handover_id'])->count());
            $this->assertSame('4.00000000', DB::table('erp_cutting_handover_decisions')->where('handover_id', $dispatch['handover_id'])->value('quantity'));
            $this->assertSame($before, DB::table('erp_production_target_material_requirements')->where('id', $f['targetRequirement'])->value('satisfied_base_qty'));
        }
    }

    public function test_formal_cutting_warehouse_receipt_is_partial_idempotent_and_distinct_from_receive(): void
    {
        $f = $this->fixture(); $batch = $this->issue($f); $batchId = $batch['settlement_batch_id'];
        $result = $this->save($f,$batchId,'10')['result_ids'][0]; $split = $this->route($f,$result,'10');
        $routeId = $split['routes'][0]['id'];
        app(CuttingConfirmationService::class)->confirm($batchId,$this->confirmation($f,$batchId,$result,'3000'),$f['user'],self::PERMISSIONS,true);
        $service = app(CuttingWarehouseReceiptService::class); $otherWarehouse = Warehouse::create([
            'warehouse_code'=>'CUT-OTHER-'.Str::ulid(),'warehouse_name'=>'其他仓库','status'=>'enabled']);
        $otherLocation = Location::create(['warehouse_id'=>$otherWarehouse->id,'location_code'=>'CUT-OTHER-LOC-'.Str::ulid(),
            'location_name'=>'其他库位','status'=>'enabled']);
        $version = (int) DB::table('erp_cutting_result_routes')->where('id',$routeId)->value('business_version');
        $this->domain('location_invalid',fn () => $service->post($routeId,$this->payload($version)+[
            'quantity'=>'1','warehouse_id'=>$f['warehouse']->id,'location_id'=>$otherLocation->id,'batch_no'=>'CUT-OUT-PARTIAL'
        ],$f['user'],self::PERMISSIONS,true));
        $this->assertSame(0,DB::table('erp_cutting_warehouse_receipts')->where('route_id',$routeId)->count());
        InventoryBalance::create(['item_id'=>$f['output']->id,'warehouse_id'=>$f['warehouse']->id,'location_id'=>$f['location']->id,
            'batch_no'=>'CUT-CONFLICT','unit_id'=>$f['output']->unit_id,'quantity_on_hand'=>1,'quantity_locked'=>0,
            'quantity_available'=>1,'quantity_defective'=>0,'quantity_pending'=>0,'inventory_value'=>0,'average_unit_cost'=>0]);
        $this->domain('warehouse_batch_identity_conflict',fn () => $service->post($routeId,$this->payload($version)+[
            'quantity'=>'1','warehouse_id'=>$f['warehouse']->id,'location_id'=>$f['location']->id,'batch_no'=>'CUT-CONFLICT'
        ],$f['user'],self::PERMISSIONS,true),409);

        $firstPayload = $this->payload($version)+['quantity'=>'4','warehouse_id'=>$f['warehouse']->id,
            'location_id'=>$f['location']->id,'batch_no'=>'CUT-OUT-PARTIAL'];
        $first = $service->post($routeId,$firstPayload,$f['user'],self::PERMISSIONS,true);
        $this->assertSame($first,$service->post($routeId,$firstPayload,$f['user'],self::PERMISSIONS,true));
        $this->assertSame('PART_WAREHOUSED',$first['route_status']); $this->assertSame('1200.0000',$first['posted_cost']);
        $second = $service->post($routeId,$this->payload($first['route_business_version'])+[
            'quantity'=>'6','warehouse_id'=>$f['warehouse']->id,'location_id'=>$f['location']->id,'batch_no'=>'CUT-OUT-PARTIAL'
        ],$f['user'],self::PERMISSIONS,true);
        $this->assertSame('WAREHOUSED',$second['route_status']); $this->assertSame('1800.0000',$second['posted_cost']);
        $this->assertSame(2,DB::table('erp_cutting_warehouse_receipts')->where('route_id',$routeId)->where('status','POSTED')->count());
        $this->assertSame(2,DB::table('erp_inventory_transactions')->where('transaction_type','cutting_output_receipt')
            ->where('source_type','cutting_warehouse_receipt')->count());
        $route = DB::table('erp_cutting_result_routes')->where('id',$routeId)->first();
        $this->assertSame('10.00000000',$route->warehoused_qty); $this->assertSame('3000.0000',$route->warehoused_cost);
        $this->assertSame('0.00000000',$route->handed_over_qty); $this->assertSame('0.00000000',$route->received_qty);
        $balance = InventoryBalance::where('item_id',$f['output']->id)->where('batch_no','CUT-OUT-PARTIAL')->firstOrFail();
        $this->assertSame(0,bccomp('10',(string) $balance->quantity_on_hand,8));
        $this->assertSame(0,bccomp('10',(string) $balance->quantity_locked,8));
        $this->assertSame(0,bccomp('0',(string) $balance->quantity_available,8));
        $this->assertSame(0,bccomp('0',(string) DB::table('erp_production_target_material_requirements')
            ->where('id',$f['targetRequirement'])->value('satisfied_base_qty'),8),'入库锁定不能冒充下一工序已接收');
        $projection = app(CuttingReadService::class)->settlementExecution($batchId,['page'=>1,'per_page'=>20],$f['user'],self::PERMISSIONS,true);
        $this->assertSame('10.00000000',$projection['results']['data'][0]['warehoused_qty']);
        $this->assertSame('0.00000000',$projection['results']['data'][0]['received_qty']);
        $this->assertFalse($projection['results']['data'][0]['confirm_allowed']);
        $this->assertSame('已入库',$projection['results']['data'][0]['routes'][0]['display_status']);
        $this->assertCount(2,$projection['results']['data'][0]['routes'][0]['warehouse_receipts']);

        $flow = $this->fixture(); $flowBatch = $this->issue($flow); $flowBatchId = $flowBatch['settlement_batch_id'];
        $flowResult = $this->save($flow,$flowBatchId,'10')['result_ids'][0];
        $flowSplit = app(CuttingRecordService::class)->splitRoutes($flowResult,$this->payload(1)+['routes'=>[[
            'route_type'=>'NEXT_OPERATION','quantity'=>'10','target_material_requirement_id'=>$flow['targetRequirement']
        ]]],$flow['user'],self::PERMISSIONS,true); $flowRouteId = $flowSplit['routes'][0]['id'];
        app(CuttingConfirmationService::class)->confirm($flowBatchId,$this->confirmation($flow,$flowBatchId,$flowResult,'3000'),$flow['user'],self::PERMISSIONS,true);
        $this->domain('cutting_route_not_warehouse',fn () => $service->post($flowRouteId,$this->payload(2)+[
            'quantity'=>'1','warehouse_id'=>$flow['warehouse']->id,'location_id'=>$flow['location']->id,'batch_no'=>'FLOW-CANNOT-WH'
        ],$flow['user'],self::PERMISSIONS,true),409);
    }

    public function test_public_cutting_surplus_remains_available_while_planned_output_is_locked(): void
    {
        $f = $this->fixture(); $batch = $this->issue($f); $batchId = $batch['settlement_batch_id'];
        $result = $this->save($f,$batchId,'12')['result_ids'][0]; $split = $this->route($f,$result,'12'); $routeId = $split['routes'][0]['id'];
        $payload = $this->confirmation($f,$batchId,$result,'3000');
        $payload['allocations'][0]['quantity'] = '10';
        $payload['allocations'][] = ['route_id'=>$routeId,'quantity'=>'2','disposition'=>'PUBLIC_UNALLOCATED'];
        app(CuttingConfirmationService::class)->confirm($batchId,$payload,$f['user'],self::PERMISSIONS,true);
        $posted = $this->withToken($this->token($f['user']))->postJson('/api/v1/erp/production/cutting/routes/'.$routeId.'/warehouse',$this->payload(2)+[
            'quantity'=>'12','warehouse_id'=>$f['warehouse']->id,'location_id'=>$f['location']->id,'batch_no'=>'CUT-MIXED-SCOPE'
        ])->assertCreated()->assertJsonPath('message','下料产出已正式入库')->json('data');
        $balance = InventoryBalance::findOrFail($posted['inventory_balance_id']);
        $this->assertSame(0,bccomp('12',(string) $balance->quantity_on_hand,8));
        $this->assertSame(0,bccomp('10',(string) $balance->quantity_locked,8));
        $this->assertSame(0,bccomp('2',(string) $balance->quantity_available,8));
        $this->assertSame(2,DB::table('erp_cutting_warehouse_receipt_allocations')->where('receipt_id',$posted['receipt_id'])->count());
        $reservation = DB::table('erp_cutting_inventory_reservations')->whereIn('receipt_allocation_id',
            DB::table('erp_cutting_warehouse_receipt_allocations')->where('receipt_id',$posted['receipt_id'])->pluck('id'))->sole();
        $this->assertSame('PLAN',$reservation->reservation_scope); $this->assertSame('10.00000000',$reservation->reserved_qty);
        $this->assertSame($f['targetRequirement'],(int) $reservation->target_material_requirement_id);
    }

    public function test_builtin_production_roles_receive_explicit_cutting_permissions(): void
    {
        app(RbacBootstrapService::class)->bootstrap(true);
        $matrix = DB::table('erp_rbac_roles as role')->join('erp_rbac_role_permissions as role_permission','role_permission.role_id','=','role.id')
            ->join('erp_rbac_permissions as permission','permission.id','=','role_permission.permission_id')
            ->whereIn('role.code',['production_manager','production_operator','department_principal'])
            ->whereIn('permission.code',['production.cutting.view','production.cutting.record','production.cutting.handover.dispatch',
                'production.cutting.handover.receive','production.cutting.warehouse'])
            ->get(['role.code as role_code','permission.code as permission_code'])->groupBy('role_code');
        $this->assertEqualsCanonicalizing([
            'production.cutting.view','production.cutting.record','production.cutting.handover.dispatch',
            'production.cutting.handover.receive','production.cutting.warehouse',
        ],$matrix['production_manager']->pluck('permission_code')->all());
        $this->assertEqualsCanonicalizing([
            'production.cutting.view','production.cutting.record','production.cutting.handover.dispatch','production.cutting.handover.receive',
        ],$matrix['production_operator']->pluck('permission_code')->all());
        $this->assertEqualsCanonicalizing([
            'production.cutting.view','production.cutting.warehouse',
        ],$matrix['department_principal']->pluck('permission_code')->all());
    }

    public function test_cutting_reserved_inventory_uses_formal_internal_issue_and_current_receiver_without_double_holding(): void
    {
        $f = $this->fixture(); $receiver = $this->employee('库存接收'); $task = $this->consumerTask($f,$receiver);
        $posted = $this->warehouseStock($f,'10'); $id = $posted['reservation_ids'][0];
        $service = app(CuttingInventoryReservationService::class); $token = $this->token($f['user']);
        $payload = $this->payload(1)+['quantity'=>'4'];
        $created = $this->withToken($token)->postJson('/api/v1/erp/production/cutting/inventory-reservations/'.$id.'/issue',$payload)
            ->assertCreated()->assertJsonPath('message','下料库存领用单已建立')->json('data');
        $this->assertSame($created,$service->createIssue($id,$payload,$f['user'],self::PERMISSIONS,true));
        $this->assertSame('1200.0000',$created['total_cost']);
        $this->withToken($token)->getJson('/api/v1/erp/production/cutting/inventory-reservations?per_page=1')
            ->assertOk()->assertJsonPath('per_page',1)->assertJsonPath('data.0.pending_issue_qty','4.00000000')
            ->assertJsonPath('data.0.issuable_qty','6.00000000');
        $balance = InventoryBalance::findOrFail($posted['inventory_balance_id']);
        $this->assertSame(0,bccomp('10',(string) $balance->quantity_on_hand,8));
        $this->assertSame(0,bccomp('10',(string) $balance->quantity_locked,8));
        $this->assertSame('0.00000000',(string) DB::table('erp_production_target_material_requirements')->where('id',$f['targetRequirement'])->value('satisfied_base_qty'));
        $issueId = $created['internal_issue_task_id'];
        $internal = app(ProductionInternalIssueService::class);
        $dispatchPayload = $this->payload(1);
        $dispatched = $internal->dispatch($issueId,$dispatchPayload,$f['user'],self::PERMISSIONS,true);
        $this->assertSame($dispatched,$internal->dispatch($issueId,$dispatchPayload,$f['user'],self::PERMISSIONS,true));
        $this->assertSame(0,bccomp('10',(string) $balance->fresh()->quantity_on_hand,8),'交出只登记正式领用状态，不提前扣库存');
        $this->domain('expected_receiver_required',fn () => $internal->receive($issueId,$this->payload(2),$f['user'],self::PERMISSIONS,true),403);
        $receivePayload = $this->payload(2);
        $received = $internal->receive($issueId,$receivePayload,$receiver,self::PERMISSIONS,true);
        $this->assertSame($received,$internal->receive($issueId,$receivePayload,$receiver,self::PERMISSIONS,true));
        $this->assertSame('RECEIVED',$received['status']);
        $balance = $balance->fresh();
        $this->assertSame(0,bccomp('6',(string) $balance->quantity_on_hand,8));
        $this->assertSame(0,bccomp('6',(string) $balance->quantity_locked,8));
        $this->assertSame(0,bccomp('0',(string) $balance->quantity_available,8));
        $this->assertSame('1800.0000',(string) $balance->inventory_value);
        $line = DB::table('erp_production_internal_issue_lines')->where('issue_task_id',$issueId)->sole();
        $holding = DB::table('erp_material_holdings')->where('id',$line->material_holding_id)->first();
        $this->assertSame('PRODUCTION_WIP',$holding->position_type); $this->assertSame('4.00000000',$holding->quantity);
        $this->assertSame('1200.0000',$holding->total_cost); $this->assertSame($f['targetRequirement'],(int) $holding->position_id);
        $this->assertSame('4.00000000',(string) DB::table('erp_production_target_material_requirements')->where('id',$f['targetRequirement'])->value('satisfied_base_qty'));
        $reservation = DB::table('erp_cutting_inventory_reservations')->where('id',$id)->first();
        $this->assertSame('4.00000000',$reservation->issued_qty); $this->assertSame('1200.0000',$reservation->issued_total_cost);
        $last = $service->createIssue($id,$this->payload((int) $reservation->business_version)+['quantity'=>'6'],$f['user'],self::PERMISSIONS,true);
        $internal->dispatch($last['internal_issue_task_id'],$this->payload(1),$f['user'],self::PERMISSIONS,true);
        $internal->receive($last['internal_issue_task_id'],$this->payload(2),$receiver,self::PERMISSIONS,true);
        $this->assertSame(0,bccomp('0',(string) $balance->fresh()->quantity_on_hand,8));
        $this->assertSame(0,bccomp('0',(string) $balance->fresh()->quantity_locked,8));
        $this->assertSame('0.0000',(string) $balance->fresh()->inventory_value);
        $this->assertSame('CONSUMED',DB::table('erp_cutting_inventory_reservations')->where('id',$id)->value('status'));
        $replacement = $this->employee('改派接收');
        $task->fill(['assignee_user_legacy_id'=>$replacement->legacy_id,'business_version'=>(int) $task->fresh()->business_version+1])->save();
        $this->domain('expected_receiver_required',fn () => $internal->receive($issueId,$receivePayload,$receiver,self::PERMISSIONS,true),403);
        $this->assertSame(2,DB::table('erp_inventory_transactions')->where('transaction_type','production_internal_issue_outbound')
            ->whereIn('source_id',[$issueId,$last['internal_issue_task_id']])->count());
    }

    public function test_cutting_inventory_pending_issue_cancel_and_public_release_preserve_stock_and_audit(): void
    {
        $f = $this->fixture(); $this->consumerTask($f,$this->employee('领用负责人'));
        $posted = $this->warehouseStock($f,'10'); $id = $posted['reservation_ids'][0];
        $service = app(CuttingInventoryReservationService::class);
        $this->domain('permission_denied',fn () => $service->createIssue($id,$this->payload(1)+['quantity'=>'1'],$f['user'],[],true),403);
        $other = $this->fixture();
        $this->domain('cutting_inventory_target_mismatch',fn () => $service->createIssue($id,$this->payload(1)+[
            'quantity'=>'1','target_material_requirement_id'=>$other['targetRequirement']],$f['user'],self::PERMISSIONS,true),403);
        $created = $service->createIssue($id,$this->payload(1)+['quantity'=>'4'],$f['user'],self::PERMISSIONS,true);
        $this->domain('cutting_inventory_release_exceeded',fn () => $service->release($id,$this->payload(2)+[
            'quantity'=>'7','reason'=>'释放未领用库存'],$f['user'],self::PERMISSIONS,true),409);
        $cancelPayload = $this->payload(1);
        $cancelled = $service->cancelIssue($created['internal_issue_task_id'],$cancelPayload,$f['user'],self::PERMISSIONS,true);
        $this->assertSame($cancelled,$service->cancelIssue($created['internal_issue_task_id'],$cancelPayload,$f['user'],self::PERMISSIONS,true));
        $this->assertSame('CANCELLED',$cancelled['status']);
        $releasePayload = $this->payload(3)+['quantity'=>'3','reason'=>'释放不再需要的供给'];
        $released = $service->release($id,$releasePayload,$f['user'],self::PERMISSIONS,true);
        $this->assertSame($released,$service->release($id,$releasePayload,$f['user'],self::PERMISSIONS,true));
        $this->assertTrue($released['public_availability_released']); $this->assertSame('900.0000',$released['released_cost']);
        $balance = InventoryBalance::findOrFail($posted['inventory_balance_id']);
        $this->assertSame(0,bccomp('10',(string) $balance->quantity_on_hand,8));
        $this->assertSame(0,bccomp('7',(string) $balance->quantity_locked,8));
        $this->assertSame(0,bccomp('3',(string) $balance->quantity_available,8));
        $this->assertSame('3000.0000',(string) $balance->inventory_value,'释放改变使用归属，不产生出库或丢失成本');
        $this->assertSame(1,DB::table('erp_cutting_events')->where('aggregate_type','cutting_inventory_reservation')->where('aggregate_id',$id)->where('action','release')->count());
        $this->domain('version_conflict',fn () => $service->release($id,$this->payload(3)+[
            'quantity'=>'1','reason'=>'旧版本不能释放'],$f['user'],self::PERMISSIONS,true),409);
        $this->assertSame('3.00000000',DB::table('erp_cutting_inventory_reservations')->where('id',$id)->value('released_qty'));
    }

    public function test_cutting_reserved_partial_issue_cost_keeps_last_remainder_after_cancellation(): void
    {
        $f = $this->fixture(); $receiver = $this->employee('成本接收'); $this->consumerTask($f,$receiver);
        $posted = $this->warehouseStock($f,'7'); $id = $posted['reservation_ids'][0];
        $service = app(CuttingInventoryReservationService::class); $internal = app(ProductionInternalIssueService::class);
        $first = $service->createIssue($id,$this->payload(1)+['quantity'=>'2'],$f['user'],self::PERMISSIONS,true);
        $this->assertSame('857.1428',$first['total_cost']);
        $cancel = $service->createIssue($id,$this->payload(2)+['quantity'=>'2'],$f['user'],self::PERMISSIONS,true);
        $service->cancelIssue($cancel['internal_issue_task_id'],$this->payload(1),$f['user'],self::PERMISSIONS,true);
        $internal->dispatch($first['internal_issue_task_id'],$this->payload(1),$f['user'],self::PERMISSIONS,true);
        $internal->receive($first['internal_issue_task_id'],$this->payload(2),$receiver,self::PERMISSIONS,true);
        $version = (int) DB::table('erp_cutting_inventory_reservations')->where('id',$id)->value('business_version');
        $last = $service->createIssue($id,$this->payload($version)+['quantity'=>'5'],$f['user'],self::PERMISSIONS,true);
        $this->assertSame('2142.8572',$last['total_cost']);
        $internal->dispatch($last['internal_issue_task_id'],$this->payload(1),$f['user'],self::PERMISSIONS,true);
        $internal->receive($last['internal_issue_task_id'],$this->payload(2),$receiver,self::PERMISSIONS,true);
        $this->assertSame('3000.0000',DB::table('erp_cutting_inventory_reservations')->where('id',$id)->value('issued_total_cost'));
        $balance = InventoryBalance::findOrFail($posted['inventory_balance_id']);
        $this->assertSame('0.0000',(string) $balance->inventory_value);
        $cost = (string) DB::table('erp_material_holdings')->where('position_type','PRODUCTION_WIP')->where('position_id',$f['targetRequirement'])->sum('total_cost');
        $this->assertSame(0,bccomp('3000',$cost,4));
    }

    public function test_restricted_cutting_plan_release_retains_lock_and_configuration_scope(): void
    {
        $f = $this->fixture('none','10','10',true); $this->consumerTask($f,$this->employee('专用接收'));
        $posted = $this->warehouseStock($f,'10'); $id = $posted['reservation_ids'][0];
        $service = app(CuttingInventoryReservationService::class);
        $this->domain('restricted_plan_release_requires_remainder',fn () => $service->release($id,$this->payload(1)+[
            'quantity'=>'3','reason'=>'不得局部放开限制'],$f['user'],self::PERMISSIONS,true),409);
        $released = $service->release($id,$this->payload(1)+['quantity'=>'10','reason'=>'解除原需求归属但保留配置限制'],$f['user'],self::PERMISSIONS,true);
        $this->assertFalse($released['public_availability_released']); $this->assertSame('RESTRICTED_CONFIGURATION',$released['reservation_scope']);
        $balance = InventoryBalance::findOrFail($posted['inventory_balance_id']);
        $this->assertSame(0,bccomp('10',(string) $balance->quantity_locked,8)); $this->assertSame(0,bccomp('0',(string) $balance->quantity_available,8));
        $this->domain('restricted_inventory_release_denied',fn () => $service->release($id,$this->payload(2)+[
            'quantity'=>'10','reason'=>'不能成为公共库存'],$f['user'],self::PERMISSIONS,true),403);
        $configId = (int) DB::table('erp_cutting_inventory_reservations')->where('id',$id)->value('configuration_id');
        DB::table('erp_custom_configuration_scopes')->where('configuration_id',$configId)->where('source_type','work_order')->where('source_id',$f['consumerWo']->id)->delete();
        $this->domain('configuration_scope_denied',fn () => $service->createIssue($id,$this->payload(2)+[
            'quantity'=>'1','target_material_requirement_id'=>$f['targetRequirement']],$f['user'],self::PERMISSIONS,true),403);
        DB::table('erp_custom_configuration_scopes')->insert(['configuration_id'=>$configId,'source_type'=>'work_order','source_id'=>$f['consumerWo']->id,
            'created_at'=>now(),'updated_at'=>now()]);
        $created = $service->createIssue($id,$this->payload(2)+['quantity'=>'10','target_material_requirement_id'=>$f['targetRequirement']],$f['user'],self::PERMISSIONS,true);
        $this->assertSame('WAIT_ISSUE',$created['status']);
        $this->assertSame(1,DB::table('erp_production_internal_issue_lines')->where('cutting_inventory_reservation_id',$id)->count());
    }

    public function test_cutting_inventory_real_http_receive_follows_current_target_owner_not_work_order_owner(): void
    {
        $f = $this->fixture(); $old = $this->employee('原接收人'); $new = $this->employee('新接收人');
        $task = $this->consumerTask($f,$old); $posted = $this->warehouseStock($f,'10');
        $created = app(CuttingInventoryReservationService::class)->createIssue($posted['reservation_ids'][0],$this->payload(1)+[
            'quantity'=>'10'],$f['user'],self::PERMISSIONS,true);
        $issueId = $created['internal_issue_task_id'];
        app(ProductionInternalIssueService::class)->dispatch($issueId,$this->payload(1),$f['user'],self::PERMISSIONS,true);
        $oldToken = $this->token($old,'self'); $newToken = $this->token($new,'self');
        $this->withToken($oldToken)->getJson('/api/v1/erp/production/internal-issues/'.$issueId)->assertOk()->assertJsonPath('data.allowed_actions.receive',true);
        $task->fill(['assignee_user_legacy_id'=>$new->legacy_id,'business_version'=>(int) $task->business_version+1])->save();
        $this->withToken($oldToken)->getJson('/api/v1/erp/production/internal-issues/'.$issueId)->assertNotFound();
        $roles = DB::table('erp_rbac_user_roles')->where('user_legacy_id',$new->legacy_id)->pluck('role_id');
        $costPermission = DB::table('erp_rbac_permissions')->where('code','production.cutting.inventory.view')->value('id');
        DB::table('erp_rbac_role_permissions')->whereIn('role_id',$roles)->where('permission_id',$costPermission)->delete();
        $this->withToken($newToken)->getJson('/api/v1/erp/production/internal-issues/'.$issueId)->assertOk()
            ->assertJsonPath('data.allowed_actions.receive',true)->assertJsonPath('data.expected_receiver_legacy_id',$old->legacy_id)
            ->assertJsonMissingPath('data.lines.0.issue_total_cost');
        $payload = $this->payload(2);
        $this->withToken($newToken)->postJson('/api/v1/erp/production/internal-issues/'.$issueId.'/receive',$payload)->assertOk()->assertJsonPath('data.status','RECEIVED');
        $this->withToken($newToken)->postJson('/api/v1/erp/production/internal-issues/'.$issueId.'/receive',$payload)->assertOk();
        $this->assertSame($new->legacy_id,(int) DB::table('erp_production_internal_issue_tasks')->where('id',$issueId)->value('received_by_legacy_id'));
        $this->assertSame('10.00000000',DB::table('erp_production_target_material_requirements')->where('id',$f['targetRequirement'])->value('satisfied_base_qty'));
    }

    public function test_cutting_inventory_receive_failure_after_stock_insert_rolls_back_unlock_stock_wip_and_command(): void
    {
        $f = $this->fixture(); $receiver = $this->employee('回滚接收'); $this->consumerTask($f,$receiver);
        $posted = $this->warehouseStock($f,'10'); $reservationId = $posted['reservation_ids'][0];
        $created = app(CuttingInventoryReservationService::class)->createIssue($reservationId,$this->payload(1)+['quantity'=>'4'],$f['user'],self::PERMISSIONS,true);
        $issueId = $created['internal_issue_task_id']; $service = app(ProductionInternalIssueService::class);
        $service->dispatch($issueId,$this->payload(1),$f['user'],self::PERMISSIONS,true);
        $injected = false;
        DB::listen(function ($query) use (&$injected): void {
            if (! $injected && str_starts_with(strtolower($query->sql),'insert into `erp_inventory_transaction_items`')) {
                $injected = true;
                throw new \RuntimeException('cutting issue injected failure after actual stock insert');
            }
        });
        $payload = $this->payload(2);
        try {
            $service->receive($issueId,$payload,$receiver,self::PERMISSIONS,true);
            $this->fail('Expected injected failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('cutting issue injected failure after actual stock insert',$e->getMessage());
        }
        $this->assertTrue($injected);
        $balance = InventoryBalance::findOrFail($posted['inventory_balance_id']);
        $this->assertSame(0,bccomp('10',(string) $balance->quantity_on_hand,8));
        $this->assertSame(0,bccomp('10',(string) $balance->quantity_locked,8));
        $this->assertSame('3000.0000',(string) $balance->inventory_value);
        $this->assertSame('0.00000000',DB::table('erp_cutting_inventory_reservations')->where('id',$reservationId)->value('issued_qty'));
        $this->assertSame('ISSUED',DB::table('erp_production_internal_issue_tasks')->where('id',$issueId)->value('status'));
        $this->assertSame(0,DB::table('erp_inventory_transactions')->where('source_type','production_internal_issue')->where('source_id',$issueId)->count());
        $this->assertSame(0,DB::table('erp_production_execution_commands')->where('client_command_id',$payload['client_command_id'])->count());
        $this->assertSame(0,DB::table('erp_material_holdings')->where('position_type','PRODUCTION_WIP')->where('position_id',$f['targetRequirement'])->count());
        $received = $service->receive($issueId,$payload,$receiver,self::PERMISSIONS,true);
        $this->assertSame('RECEIVED',$received['status']);
        $this->assertSame(0,bccomp('6',(string) $balance->fresh()->quantity_on_hand,8));
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

    private function domain(string $code, callable $action, int $status = 422): void
    { try { $action(); $this->fail('Expected '.$code); } catch (WorkOrderDomainException $e) { $this->assertSame($code,$e->errorCode); $this->assertSame($status,$e->status); } }
}
