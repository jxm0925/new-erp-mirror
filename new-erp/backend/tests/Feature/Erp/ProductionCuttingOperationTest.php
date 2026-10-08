<?php

namespace Tests\Feature\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{ProductionQuantityOperation, ProductionTask};
use App\Services\Erp\{ProductionCuttingOperationService, ProductionMaterialExecutionService, ProductionExecutionActionService, CuttingTaskExecutionService, CuttingInputService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\CuttingTestFixtures;
use Tests\TestCase;

final class ProductionCuttingOperationTest extends TestCase
{
    use DatabaseTransactions, CuttingTestFixtures;

    private function permissions(): array
    {
        return array_merge(self::PERMISSIONS, ['production.task.start','production.task.pause','production.task.resume','production.task.complete','production.report.create',
            'production.material_picking.create','production.material_picking.assign','production.material_picking.pick',
            'production.material_delivery.create','production.material_delivery.dispatch','production.material_delivery.confirm','production.material_receipt.confirm']);
    }

    private function receivedOperation(string $mode = 'sheet', bool $custom = false, bool $multiple = false): array
    {
        $this->travelTo(now()->startOfSecond());
        $f = $this->fixture(publish:false, restricted:$custom, rawMode:$mode); $user = $f['user']; $p = $this->permissions();
        $f['inputRequirement']->update(['cutting_requirement_snapshot'=>['length_mm'=>'500','width_mm'=>$mode === 'sheet' ? '200' : null,'thickness_mm'=>$mode === 'sheet' ? '2' : null,'piece_qty'=>1,'allow_rotation'=>false]]);
        if ($custom) $f['wo']->update(['output_configuration_id'=>$f['planPayload']['configuration_id']]);
        $target = ProductionQuantityOperation::findOrFail($f['producerOperation']);
        $target->update(['responsible_user_legacy_id'=>$user->legacy_id,'claimed_at'=>now(),'kitting_required'=>false]);
        $task = ProductionTask::create(['task_no'=>'OPCUT-'.$this->payload(1)['client_command_id'],'work_order_id'=>$f['wo']->id,
            'execution_mode'=>'quantity','routing_operation_id_snapshot'=>$f['stage'],'operation_code_snapshot'=>'CUT','operation_name_snapshot'=>'下料',
            'sequence_no_snapshot'=>10,'status'=>'WAIT_MATERIAL','assignee_user_legacy_id'=>$user->legacy_id,'assignment_mode'=>'manual_claim','claimed_at'=>now(),'business_version'=>1]);
        DB::table('erp_production_task_targets')->insert(['task_id'=>$task->id,'target_type'=>'quantity_operation','target_id'=>$target->id,
            'status_snapshot'=>'WAIT_MATERIAL','created_at'=>now(),'updated_at'=>now()]);
        $requirement = DB::table('erp_production_target_material_requirements')->where('target_type','quantity_operation')->where('target_id',$target->id)->first();
        $pickLines = [['target_material_requirement_id'=>$requirement->id,'inventory_balance_id'=>$f['balance']->id,'planned_pick_qty'=>'2']];
        if ($multiple) {
            $f['inputRequirement']->update(['required_qty'=>1,'base_required_qty'=>1,'remaining_qty'=>1,'per_output_qty'=>'0.1']);
            DB::table('erp_production_target_material_requirements')->where('id',$requirement->id)->update(['required_base_qty'=>1]);
            DB::table('erp_work_order_material_supply_rules')->where('id',$requirement->material_supply_rule_snapshot_id)->update(['required_base_qty_snapshot'=>1]);
            $second = $f['inputRequirement']->replicate(); $second->line_no = 2;
            $second->cutting_requirement_snapshot = ['length_mm'=>'300','width_mm'=>'200','thickness_mm'=>'2','piece_qty'=>1,'allow_rotation'=>false]; $second->save();
            $supply = $this->supply($f['wo'],$second,$f['stage'],$f['raw']->id,'1');
            $secondTarget = $this->targetRequirement($f['wo'],$second,$supply,$target->id,$f['raw']->id,'1');
            $pickLines[0]['planned_pick_qty'] = '1';
            $pickLines[] = ['target_material_requirement_id'=>$secondTarget,'inventory_balance_id'=>$f['balance']->id,'planned_pick_qty'=>'1'];
        }
        $service = app(ProductionMaterialExecutionService::class);
        $pick = $service->createPickingTask($this->payload(1)+['work_order_id'=>$f['wo']->id,'warehouse_id'=>$f['warehouse']->id,
            'lines'=>$pickLines],$user,$p,true);
        $pick = $service->assignPickingTask($pick->id,$this->payload($pick->business_version)+['assigned_picker_legacy_id'=>$user->legacy_id],$user,$p,true);
        $pick = $service->startPickingTask($pick->id,$this->payload($pick->business_version),$user,$p,true);
        $pick = $service->confirmPickingTask($pick->id,$this->payload($pick->business_version)+['lines'=>$pick->lines->map(fn ($line) => ['picking_task_line_id'=>$line->id,'actual_pick_qty'=>$multiple ? '1' : '2'])->all()],$user,$p,true);
        $service->receiveOnsite($pick->id,$this->payload($pick->business_version)+[
            'lines'=>$pick->lines->map(fn ($line) => ['picking_task_line_id'=>$line->id,'accepted_qty'=>$multiple ? '1' : '2',
                'physical_material_ids'=>DB::table('erp_material_physicals as physical')->join('erp_material_holdings as holding','holding.id','=','physical.current_holding_id')
                    ->join('erp_inventory_transaction_items as posted','posted.id','=','holding.position_id')
                    ->where('holding.position_type','PRODUCTION_TRANSIT')->where('posted.source_item_id',$line->id)->pluck('physical.id')->all()])->all()],$user,$p,true);
        $target->refresh();
        app(ProductionExecutionActionService::class)->start($task->id,'quantity_operation',$target->id,$this->payload($target->business_version),$user,$p);
        return [$f,$task,$target->fresh(),$p];
    }

    public function test_operation_uses_received_material_once_freezes_output_and_reuses_single_labor_clock(): void
    {
        [$f,$task,$target,$p] = $this->receivedOperation(); $user = $f['user']; $service = app(ProductionCuttingOperationService::class);
        $transactionCount = DB::table('erp_inventory_transactions')->count();
        $eligibility = app(\App\Services\Erp\StockPrebuildEligibilityService::class);
        $this->assertTrue($eligibility->items()->whereKey($f['output']->id)->exists());
        $f['raw']->update(['is_production_item'=>true]);
        $this->assertFalse($eligibility->items()->whereKey($f['raw']->id)->exists());
        $prepare = $this->payload($target->business_version);
        $created = $service->prepare($task->id,'quantity_operation',$target->id,$prepare,$user,$p,true);
        $this->assertSame($created,$service->prepare($task->id,'quantity_operation',$target->id,$prepare,$user,$p,true));
        $page = $service->materials($task->id,'quantity_operation',$target->id,['per_page'=>1],$user,$p,true);
        $this->assertSame(2,$page['meta']['total']); $this->assertCount(1,$page['data']);
        $input = DB::table('erp_production_input_holdings')->where('target_type','quantity_operation')->where('target_id',$target->id)->first();
        foreach ($f['physicals'] as $physical) {
            $batch = $service->useMaterial($task->id,'quantity_operation',$target->id,$this->payload($target->business_version)+[
                'production_input_holding_id'=>$input->id,'physical_material_id'=>$physical],$user,$p,true);
            $service->save($task->id,'quantity_operation',$target->id,$batch['settlement_batch_id'],$this->payload(1)+[
                'actual_qty'=>'5','other_results'=>[['client_row_id'=>'remnant','result_type'=>'usable_remnant','actual_qty'=>'1','measurement_status'=>'MEASURED',
                    'measurements'=>['shape'=>'RECTANGLE','length_mm'=>'1000','width_mm'=>'1000','thickness_mm'=>'2']]]],$user,$p,true);
        }
        // Editing current BOM after release cannot change this operation's recorded dimensions.
        DB::table('erp_bom_items')->where('id',$f['inputRequirement']->bom_item_id)->update(['cut_length_mm'=>999]);
        $this->travel(15)->minutes();
        $finish = $this->payload($target->business_version);
        $result = $service->finish($task->id,'quantity_operation',$target->id,$finish,$user,$p,true);
        $this->assertEquals($result,$service->finish($task->id,'quantity_operation',$target->id,$finish,$user,$p,true));
        $this->assertSame($transactionCount,DB::table('erp_inventory_transactions')->count());
        $this->assertEquals(0,$f['balance']->fresh()->quantity_on_hand);
        $output = DB::table('erp_production_output_records')->where('source_target_type','quantity_operation')->where('source_target_id',$target->id)->first();
        $this->assertEquals('10.00000000',$output->output_base_qty);
        $this->assertEquals('2000.0000',$output->material_total_cost);
        $this->assertEquals('6000.0000',DB::table('erp_production_material_consumptions')->where('output_record_id',$output->id)->sum('total_cost'));
        $this->assertEquals(15,$target->fresh()->actual_labor_minutes);
        $link = DB::table('erp_production_cutting_operations')->where('id',$created['operation_id'])->first();
        $this->assertSame(0,DB::table('erp_production_labor_sessions')->where('cutting_task_id',$link->cutting_task_id)->count());
        $this->assertSame(1,DB::table('erp_production_labor_sessions')->where('task_id',$task->id)->count());
        $this->assertSame(0,DB::table('erp_cutting_result_routes as route')->join('erp_cutting_results as result','result.id','=','route.result_id')
            ->join('erp_cutting_settlement_batches as batch','batch.id','=','result.settlement_batch_id')->where('batch.cutting_order_id',$link->cutting_order_id)
            ->where('route.status','WAIT_WAREHOUSE')->count());
        $view = $service->view($task->id,'quantity_operation',$target->id,[],$user,$p,true);
        $this->assertTrue($view['target']['cutting_required']);
        $this->assertSame('COMPLETED',$view['target']['status']);
    }

    public function test_ordinary_completion_and_standalone_cutting_clock_cannot_bypass_operation_results(): void
    {
        [$f,$task,$target,$p] = $this->receivedOperation();
        $this->denied('cutting_results_required',fn () => app(ProductionExecutionActionService::class)->complete($task->id,'quantity_operation',$target->id,
            $this->payload($target->business_version)+['completed_base_qty'=>'10'],$f['user'],$p));
        $created = app(ProductionCuttingOperationService::class)->prepare($task->id,'quantity_operation',$target->id,$this->payload($target->business_version),$f['user'],$p,true);
        $link = DB::table('erp_production_cutting_operations')->where('id',$created['operation_id'])->first();
        $this->denied('production_operation_clock_required',fn () => app(CuttingTaskExecutionService::class)->start($link->cutting_task_id,$this->payload(1),$f['user'],$p,true));
        $this->denied('operation_received_material_required',fn () => app(CuttingInputService::class)->issue($link->cutting_order_id,$this->payload(1)+['physical_material_id'=>$f['physicals'][0]],$f['user'],$p,true));
        $f['raw']->update(['cutting_mode'=>null,'is_length_cut_material'=>false]);
        $this->assertTrue(app(ProductionCuttingOperationService::class)->required('quantity_operation',$target->id));
        $this->assertSame(0,DB::table('erp_production_output_records')->where('work_order_id',$f['wo']->id)->count());
    }

    private function denied(string $code, callable $action): void
    {
        try { $action(); $this->fail('Expected '.$code); }
        catch (WorkOrderDomainException $e) { $this->assertSame($code,$e->errorCode); }
    }

    public function test_unused_physical_returns_and_quality_release_keep_original_identity_and_amounts(): void
    {
        [$f,$task,$target,$p] = $this->receivedOperation();
        $p = array_merge($p, ['production.material_return.create','production.material_return.receive','production.material_return.quality']);
        $service = app(\App\Services\Erp\ProductionMaterialReturnService::class);
        foreach (['normal_return','quality_return'] as $index => $kind) {
            $created = $service->create($this->payload($task->fresh()->business_version)+[
                'task_id'=>$task->id,'target_type'=>'quantity_operation','target_id'=>$target->id,'return_type'=>$kind,'reason'=>'未加工整板退回',
                'lines'=>[['material_requirement_id'=>$f['inputRequirement']->id,'warehouse_id'=>$f['warehouse']->id,
                    'location_id'=>$f['location']->id,'batch_no'=>$f['balance']->batch_no,'return_base_qty'=>1]]],$f['user'],$p);
            $command = $this->payload(1);
            $received = $service->receive($created['id'],$command,$f['user'],$p);
            $this->assertEquals($received,$service->receive($created['id'],$command,$f['user'],$p));
            $this->assertDatabaseHas('erp_material_physicals',['id'=>$f['physicals'][$index],'status'=>$index ? 'QUARANTINED' : 'AVAILABLE','total_cost'=>'3000.0000']);
            $this->assertEquals($index + 1,$f['balance']->fresh()->quantity_on_hand);
            $this->assertEquals(1,$f['balance']->fresh()->quantity_available);
            if ($index) {
                $inspected = $service->quality($created['id'],$this->payload($received['business_version'])+['passed'=>true,'reason'=>'检验合格'],$f['user'],$p);
                $this->assertSame('COMPLETED',$inspected['status']);
                $this->assertDatabaseHas('erp_material_physicals',['id'=>$f['physicals'][$index],'status'=>'AVAILABLE']);
                $this->assertEquals(2,$f['balance']->fresh()->quantity_available);
            }
        }
        $this->assertEquals('6000.0000',$f['balance']->fresh()->inventory_value);
        $this->assertEquals(0,DB::table('erp_material_holdings')->where('position_type','PRODUCTION_INPUT')
            ->whereIn('id',DB::table('erp_production_input_holdings')->where('target_id',$target->id)->where('target_type','quantity_operation')->select('input_holding_id'))->sum('total_cost'));
    }

    public function test_one_failed_material_batch_can_record_zero_output_without_losing_its_cost(): void
    {
        [$f,$task,$target,$p] = $this->receivedOperation(); $service = app(ProductionCuttingOperationService::class);
        $service->prepare($task->id,'quantity_operation',$target->id,$this->payload($target->business_version),$f['user'],$p,true);
        $input = DB::table('erp_production_input_holdings')->where('target_type','quantity_operation')->where('target_id',$target->id)->first();
        foreach ($f['physicals'] as $index => $physical) {
            $batch = $service->useMaterial($task->id,'quantity_operation',$target->id,$this->payload($target->business_version)+[
                'production_input_holding_id'=>$input->id,'physical_material_id'=>$physical],$f['user'],$p,true);
            $service->save($task->id,'quantity_operation',$target->id,$batch['settlement_batch_id'],$this->payload(1)+[
                'actual_qty'=>$index ? '10' : '0','other_results'=>$index ? [] : [[
                    'client_row_id'=>'loss','result_type'=>'process_loss','actual_qty'=>'10','measurement_status'=>'MEASURED','measurements'=>['weight_kg'=>'10']]]],$f['user'],$p,true);
        }
        $service->finish($task->id,'quantity_operation',$target->id,$this->payload($target->business_version),$f['user'],$p,true);
        $output = DB::table('erp_production_output_records')->where('source_target_type','quantity_operation')->where('source_target_id',$target->id)->first();
        $this->assertEquals(10,$output->output_base_qty);
        $this->assertEquals('3000.0000',$output->material_total_cost);
        $this->assertEquals('3000.0000',$output->material_loss_cost);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('materialModes')]
    public function test_standard_and_custom_materials_keep_frozen_dimensions_and_exact_cost(string $mode, bool $custom): void
    {
        [$f,$task,$target,$p] = $this->receivedOperation($mode,$custom); $service = app(ProductionCuttingOperationService::class);
        $created = $service->prepare($task->id,'quantity_operation',$target->id,$this->payload($target->business_version),$f['user'],$p,true);
        $input = DB::table('erp_production_input_holdings')->where('target_type','quantity_operation')->where('target_id',$target->id)->first();
        $batches = $mode === 'sheet' ? array_map(fn ($id) => ['physical_material_id'=>$id],$f['physicals']) : [['quantity'=>'2']];
        foreach ($batches as $source) {
            $batch = $service->useMaterial($task->id,'quantity_operation',$target->id,$this->payload($target->business_version)+[
                'production_input_holding_id'=>$input->id]+$source,$f['user'],$p,true);
            $service->save($task->id,'quantity_operation',$target->id,$batch['settlement_batch_id'],$this->payload(1)+[
                'actual_qty'=>$mode === 'sheet' ? '5' : '10','other_results'=>array_map(fn ($n) => ['client_row_id'=>'remnant-'.$n,'result_type'=>'usable_remnant','actual_qty'=>'1','measurement_status'=>'MEASURED',
                    'measurements'=>$mode === 'sheet' ? ['shape'=>'RECTANGLE','length_mm'=>'1000','width_mm'=>'1000','thickness_mm'=>'2'] : ['length_mm'=>'3500']],$mode === 'sheet' ? [1] : [1,2])],$f['user'],$p,true);
        }
        $service->finish($task->id,'quantity_operation',$target->id,$this->payload($target->business_version),$f['user'],$p,true);
        $output = DB::table('erp_production_output_records')->where('source_target_type','quantity_operation')->where('source_target_id',$target->id)->first();
        $this->assertEquals(10,$output->output_base_qty);
        $this->assertEquals($mode === 'sheet' ? '2000.0000' : '2500.0000',$output->material_total_cost);
        $this->assertEquals(0,$f['balance']->fresh()->quantity_on_hand);
        $this->assertDatabaseHas('erp_material_lots',['id'=>$output->material_lot_id,'configuration_id'=>$custom ? $f['planPayload']['configuration_id'] : null]);
        $saved = DB::table('erp_cutting_results as result')->join('erp_cutting_settlement_batches as batch','batch.id','=','result.settlement_batch_id')
            ->where('batch.cutting_order_id',$created['cutting_order_id'])->where('result.result_type','product')->first(['result.*']);
        $this->assertEquals(500,$saved->cut_length_mm);
        $this->assertEquals($custom ? $f['planPayload']['configuration_id'] : null,$saved->configuration_id);
    }

    public static function materialModes(): array
    {
        return ['standard bar'=>['length',false], 'custom bar'=>['length',true], 'custom sheet'=>['sheet',true]];
    }

    public function test_two_material_requirements_contribute_cost_without_doubling_finished_quantity(): void
    {
        [$f,$task,$target,$p] = $this->receivedOperation(multiple:true); $service = app(ProductionCuttingOperationService::class);
        $service->prepare($task->id,'quantity_operation',$target->id,$this->payload($target->business_version),$f['user'],$p,true);
        $inputs = DB::table('erp_production_input_holdings')->where('target_type','quantity_operation')->where('target_id',$target->id)->orderBy('id')->get();
        $this->assertCount(2,$inputs);
        foreach ($inputs as $index => $input) {
            $physical = DB::table('erp_material_physicals')->where('current_holding_id',$input->input_holding_id)->value('id');
            $batch = $service->useMaterial($task->id,'quantity_operation',$target->id,$this->payload($target->business_version)+[
                'production_input_holding_id'=>$input->id,'physical_material_id'=>$physical],$f['user'],$p,true);
            $service->save($task->id,'quantity_operation',$target->id,$batch['settlement_batch_id'],$this->payload(1)+['actual_qty'=>'10'],$f['user'],$p,true);
            if ($index === 0) {
                $this->denied('cutting_output_quantity_mismatch',fn () => $service->finish($task->id,'quantity_operation',$target->id,$this->payload($target->business_version),$f['user'],$p,true));
                $this->assertDatabaseHas('erp_cutting_settlement_batches',['id'=>$batch['settlement_batch_id'],'status'=>'PROCESSING']);
            }
        }
        $service->finish($task->id,'quantity_operation',$target->id,$this->payload($target->business_version),$f['user'],$p,true);
        $output = DB::table('erp_production_output_records')->where('source_target_type','quantity_operation')->where('source_target_id',$target->id)->first();
        $this->assertEquals(10,$output->output_base_qty);
        $this->assertEquals('6000.0000',$output->material_total_cost);
        $this->assertDatabaseHas('erp_material_holdings',['id'=>$output->material_holding_id,'quantity'=>10,'total_cost'=>6000]);
        $this->assertEquals(2,DB::table('erp_production_material_consumptions')->where('output_record_id',$output->id)->count());
    }
}
