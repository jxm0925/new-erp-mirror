<?php

namespace Tests\Feature\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{Item, Unit};
use App\Services\Erp\{CuttingConfirmationService, CuttingHandoverService, CuttingOrderLifecycleService,
    CuttingReadService, CuttingRecordService, CuttingTaskExecutionService, CuttingWarehouseReceiptService, CuttingWorkerOrderService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CuttingWorkerFlowTest extends TestCase
{
    use DatabaseTransactions;
    use \Tests\Support\CuttingTestFixtures;

    public function test_automatic_length_cost_preserves_the_last_decimal_tail(): void
    {
        $batch = (object) ['original_total_cost'=>'1.0000','physical_material_id'=>null,'standard_stock_length_mm'=>'3','input_qty'=>'1'];
        $rows = collect([1,2,3])->map(fn ($id) => (object) ['id'=>$id,'result_type'=>'product','configuration_id'=>null,'actual_qty'=>'1',
            'measurements'=>null,'measurement_status'=>'NOT_RECORDED','cut_length_mm'=>'1','piece_qty'=>'1']);
        $result = app(\App\Services\Erp\CuttingAutomaticCostService::class)->allocate($batch,$rows);
        $this->assertSame('LENGTH_MM',$result['basis']);
        $this->assertSame(['0.3333','0.3333','0.3334'],array_values($result['costs']));
    }

    public function test_sheet_area_allocation_assigns_unaccounted_area_to_weighed_loss(): void
    {
        $f = $this->fixture(publish:false);
        $physical = DB::table('erp_material_physicals')->find($f['physicals'][0]);
        $size = json_decode($physical->dimensions,true,512,JSON_THROW_ON_ERROR);
        $sourceArea = bcmul((string) $size['length_mm'],(string) $size['width_mm'],8);
        $halfLength = bcdiv((string) $size['length_mm'],'2',8);
        $batch = (object) ['original_total_cost'=>'3000.0000','physical_material_id'=>$physical->id,
            'standard_stock_length_mm'=>null,'input_qty'=>'1'];
        $rows = collect([
            (object) ['id'=>901,'result_type'=>'product','configuration_id'=>null,'actual_qty'=>'1','measurements'=>json_encode([
                'length_mm'=>$halfLength,'width_mm'=>(string) $size['width_mm']]),'measurement_status'=>'MEASURED','cut_length_mm'=>null,'piece_qty'=>null],
            (object) ['id'=>902,'result_type'=>'process_loss','configuration_id'=>null,'actual_qty'=>'2','measurements'=>json_encode([
                'weight_kg'=>'2']),'measurement_status'=>'MEASURED','cut_length_mm'=>null,'piece_qty'=>null],
        ]);
        $result = app(\App\Services\Erp\CuttingAutomaticCostService::class)->allocate($batch,$rows);
        $this->assertSame('SHEET_AREA_MM2',$result['basis']);
        $this->assertSame($sourceArea,bcadd($result['weights'][901],$result['weights'][902],8));
        $this->assertSame(['1500.0000','1500.0000'],array_values($result['costs']));
    }

    public function test_worker_automatic_cost_uses_area_not_piece_count_and_freezes_exact_amounts(): void
    {
        $f = $this->fixture(publish:false);
        $created = app(CuttingWorkerOrderService::class)->create($this->payload(0)+['inputs'=>[['physical_material_id'=>$f['physicals'][0]]]],$f['user'],self::PERMISSIONS,true);
        $batchId = $created['batches'][0]['settlement_batch_id'];
        $records = app(CuttingRecordService::class);
        $saved = $records->saveResults($batchId,$this->payload(1)+['results'=>[
            ['client_row_id'=>'small','result_type'=>'product','item_id'=>$f['output']->id,'actual_qty'=>'1','measurement_status'=>'MEASURED','measurements'=>['length_mm'=>'100','width_mm'=>'100']],
            ['client_row_id'=>'large','result_type'=>'product','item_id'=>$f['output']->id,'actual_qty'=>'1','measurement_status'=>'MEASURED','measurements'=>['length_mm'=>'200','width_mm'=>'100']],
        ]],$f['user'],self::PERMISSIONS,true);
        foreach ($saved['result_ids'] as $id) $records->splitRoutes($id,$this->payload(1)+['routes'=>[['route_type'=>'WAREHOUSE','quantity'=>'1']]],$f['user'],self::PERMISSIONS,true);
        $submitted = $this->submitBatch($f,$batchId);
        $token = $this->token($f['user'],'self');
        $role = DB::table('erp_rbac_user_roles')->where('user_legacy_id',$f['user']->legacy_id)->value('role_id');
        DB::table('erp_rbac_role_permissions')->where('role_id',$role)->where('permission_id',DB::table('erp_rbac_permissions')->where('code','production.cutting.confirm')->value('id'))->delete();
        $command = $this->payload($submitted['business_version'])+['cost_method'=>'MATERIAL_SHARE_V1'];
        $one = $this->withToken($token)->postJson('/api/v1/erp/production/cutting/settlements/'.$batchId.'/confirm',$command)
            ->assertOk()->assertJsonPath('data.status','CONFIRMED')->assertJsonMissingPath('data.confirmed_total_cost')->json('data');
        $this->withToken($token)->postJson('/api/v1/erp/production/cutting/settlements/'.$batchId.'/confirm',$command)->assertOk()->assertJsonPath('data',$one);
        $costs = DB::table('erp_cutting_results')->whereIn('id',$saved['result_ids'])->orderBy('id')->pluck('total_cost')->all();
        $this->assertSame(['1000.0000','2000.0000'],$costs);
        $this->assertSame(1,DB::table('erp_cutting_allowed_outputs')->where('cutting_order_id',$created['cutting_order_id'])->count());
        $this->assertSame(0,DB::table('erp_inventory_balances')->where('item_id',$f['output']->id)->count());
    }

    public function test_missing_cost_basis_does_not_freeze_worker_report_or_invent_zero_cost(): void
    {
        $f = $this->fixture(publish:false);
        $created = app(CuttingWorkerOrderService::class)->create($this->payload(0)+['inputs'=>[['physical_material_id'=>$f['physicals'][0]]]],$f['user'],self::PERMISSIONS,true);
        $batchId = $created['batches'][0]['settlement_batch_id'];
        $saved = app(CuttingRecordService::class)->saveResults($batchId,$this->payload(1)+['results'=>[
            ['client_row_id'=>'a','result_type'=>'product','item_id'=>$f['output']->id,'actual_qty'=>'1'],
            ['client_row_id'=>'b','result_type'=>'product','item_id'=>$f['output']->id,'actual_qty'=>'1'],
        ]],$f['user'],self::PERMISSIONS,true);
        $this->withToken($this->token($f['user']))->postJson('/api/v1/erp/production/cutting/settlements/'.$batchId.'/submit',$this->payload($saved['business_version']))
            ->assertUnprocessable()->assertJsonPath('error_code','automatic_cost_basis_missing');
        $this->assertDatabaseHas('erp_cutting_settlement_batches',['id'=>$batchId,'status'=>'PROCESSING','business_version'=>$saved['business_version']]);
        $this->assertSame(0,DB::table('erp_cutting_results')->whereIn('id',$saved['result_ids'])->whereNotNull('total_cost')->count());
    }

    public function test_creation_rejects_output_first_and_invalid_material_rolls_back_everything(): void
    {
        $f = $this->fixture(publish:false); $token = $this->token($f['user'],'self');
        $before = $this->counts();
        $orders = DB::table('erp_cutting_orders')->count();
        $transactions = DB::table('erp_inventory_transactions')->count();
        $this->withToken($token)->postJson('/api/v1/erp/production/cutting/orders',$this->payload(0)+[
            'outputs'=>[['item_id'=>$f['output']->id,'planned_qty'=>'10']]])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/erp/production/cutting/orders',$this->payload(0)+[
            'inputs'=>[['physical_material_id'=>$f['physicals'][0]],['physical_material_id'=>PHP_INT_MAX]]])->assertConflict();
        $this->assertSame($orders,DB::table('erp_cutting_orders')->count());
        $this->assertSame($transactions,DB::table('erp_inventory_transactions')->count());
        $this->assertSame($before,$this->counts());
        $this->assertDatabaseHas('erp_material_physicals',['id'=>$f['physicals'][0],'status'=>'AVAILABLE']);
        $this->withToken($token)->getJson('/api/v1/erp/production/cutting/worker-inputs?keyword='.$f['raw']->item_code.'&per_page=1')
            ->assertOk()->assertJsonPath('meta.total',2)->assertJsonPath('meta.per_page',1)
            ->assertJsonMissingPath('data.0.total_cost');
    }

    public function test_length_material_is_chosen_first_with_real_root_quantity_and_no_outputs(): void
    {
        $f = $this->fixture(publish:false);
        $raw = Item::create(['item_code'=>'WF-LENGTH-'.Str::ulid(),'item_name'=>'方管','item_type'=>'raw_material','unit_id'=>$f['raw']->unit_id,
            'is_stock_item'=>true,'material_management_mode'=>'quantity','cutting_mode'=>'length','standard_stock_length_mm'=>'6000','status'=>'enabled']);
        $balance = \App\Models\Erp\InventoryBalance::create(['item_id'=>$raw->id,'warehouse_id'=>$f['warehouse']->id,'location_id'=>$f['location']->id,
            'batch_no'=>'WF-'.Str::ulid(),'quantity_on_hand'=>'5','quantity_locked'=>'0','quantity_available'=>'5','inventory_value'=>'500']);
        $service = app(CuttingWorkerOrderService::class);
        $payload = $this->payload(0)+['inputs'=>[['inventory_balance_id'=>$balance->id,'input_qty'=>'2']]];
        $created = $service->create($payload,$f['user'],self::PERMISSIONS,true);
        $this->assertSame($created,$service->create($payload,$f['user'],self::PERMISSIONS,true));
        $this->assertSame(0,bccomp('3',(string) $balance->fresh()->quantity_available,8));
        $this->assertDatabaseHas('erp_cutting_settlement_batches',['id'=>$created['batches'][0]['settlement_batch_id'],'input_item_id'=>$raw->id,'input_qty'=>'2']);
        $this->assertSame(0,DB::table('erp_cutting_allowed_outputs')->where('cutting_order_id',$created['cutting_order_id'])->count());
        $this->withToken($this->token($f['user']))->postJson('/api/v1/erp/production/cutting/orders',$this->payload(0)+['inputs'=>[
            ['inventory_balance_id'=>$balance->id,'input_qty'=>'0.5']]])->assertUnprocessable()->assertJsonPath('error_code','root_quantity_invalid');
    }

    public function test_worker_creates_without_work_order_plan_publish_claim_or_labor_and_retries_once(): void
    {
        $f = $this->fixture(publish:false); $user = $f['user'];
        $counts = $this->counts();
        $token = $this->token($user,'self');
        // This employee cannot publish old-style plans, but can register his own cutting work.
        $role = DB::table('erp_rbac_user_roles')->where('user_legacy_id',$user->legacy_id)->value('role_id');
        $permission = DB::table('erp_rbac_permissions')->where('code','production.cutting.plan')->value('id');
        DB::table('erp_rbac_role_permissions')->where('role_id',$role)->where('permission_id',$permission)->delete();
        $payload = $this->payload(0)+['inputs'=>[['physical_material_id'=>$f['physicals'][0]]]];
        $one = $this->withToken($token)->postJson('/api/v1/erp/production/cutting/orders',$payload)->assertCreated()->json('data');
        $two = $this->withToken($token)->postJson('/api/v1/erp/production/cutting/orders',$payload)->assertCreated()->json('data');
        $this->assertSame($one,$two);
        $this->assertSame($counts,$this->counts());
        $order = DB::table('erp_cutting_orders')->find($one['cutting_order_id']);
        $this->assertSame('WORKER',$order->purpose); $this->assertNull($order->published_at);
        $this->assertDatabaseHas('erp_cutting_tasks',['id'=>$one['cutting_task_id'],'status'=>'READY','assignee_user_legacy_id'=>$user->legacy_id,'started_at'=>null]);
        $this->assertSame(1,DB::table('erp_cutting_task_participants')->where('cutting_task_id',$one['cutting_task_id'])->count());
        $this->assertSame(0,DB::table('erp_cutting_plan_allocations')->where('cutting_order_id',$order->id)->count());
        $this->assertSame(0,DB::table('erp_cutting_allowed_outputs')->where('cutting_order_id',$order->id)->count());
        $this->assertCount(1,$one['batches']);
        $this->assertSame(1,DB::table('erp_cutting_settlement_batches')->where('cutting_order_id',$order->id)->count());
        $this->assertDatabaseHas('erp_material_physicals',['id'=>$f['physicals'][0],'status'=>'ISSUED']);
        $this->withToken($token)->getJson('/api/v1/erp/production/cutting/orders/'.$order->id.'/execution')->assertOk()->assertJsonPath('data.order.purpose','WORKER');
        $this->withToken($token)->getJson('/api/v1/erp/production/cutting/orders?keyword='.$order->cutting_order_no)->assertOk()->assertJsonPath('meta.total',1);
        $other = $this->employee('外部'); $otherToken = $this->token($other,'self');
        $this->withToken($otherToken)->getJson('/api/v1/erp/production/cutting/orders/'.$order->id.'/execution')->assertForbidden();
        $this->withToken($otherToken)->getJson('/api/v1/erp/production/cutting/orders?keyword='.$order->cutting_order_no)->assertOk()->assertJsonPath('meta.total',0);
    }

    public function test_worker_output_splits_to_real_work_order_and_stock_after_cutting_without_producer_plan(): void
    {
        $f = $this->fixture(publish:false);
        $receiver = $this->employee('接收'); $targetTask = $this->consumerTask($f,$receiver);
        $created = app(CuttingWorkerOrderService::class)->create($this->payload(0)+['inputs'=>[['physical_material_id'=>$f['physicals'][0]]]],$f['user'],self::PERMISSIONS,true);
        $f['order'] = $created['cutting_order_id'];
        $candidates = app(CuttingReadService::class)->inputCandidates($f['order'],['per_page'=>100],$f['user'],self::PERMISSIONS,true);
        $this->assertContains($f['physicals'][1],array_column($candidates['data'],'id'));
        $this->assertNotContains($f['physicals'][0],array_column($candidates['data'],'id'));
        $batchId = $created['batches'][0]['settlement_batch_id'];
        app(CuttingTaskExecutionService::class)->start($created['cutting_task_id'],$this->payload(1),$f['user'],self::PERMISSIONS,true);
        $result = app(CuttingRecordService::class)->saveResults($batchId,$this->payload(1)+['results'=>[
            ['client_row_id'=>'first-and-only-product-selection','result_type'=>'product','item_id'=>$f['output']->id,
                'actual_qty'=>'10','measurement_status'=>'NOT_RECORDED']]],$f['user'],self::PERMISSIONS,true)['result_ids'][0];
        $targets = app(CuttingReadService::class)->handoverTargets($result,[],$f['user'],self::PERMISSIONS,true);
        $this->assertContains($f['targetRequirement'],array_column($targets['data'],'target_material_requirement_id'));
        $routes = app(CuttingRecordService::class)->splitRoutes($result,$this->payload(1)+['routes'=>[
            ['route_type'=>'NEXT_OPERATION','quantity'=>'6','target_material_requirement_id'=>$f['targetRequirement']],
            ['route_type'=>'WAREHOUSE','quantity'=>'4'],
        ]],$f['user'],self::PERMISSIONS,true)['routes'];
        $this->submitBatch($f,$batchId);
        $this->assertSame(0,DB::table('erp_inventory_balances')->where('item_id',$f['output']->id)->count());
        $this->assertSame('0.00000000',DB::table('erp_production_target_material_requirements')->where('id',$f['targetRequirement'])->value('satisfied_base_qty'));
        $allocations = array_map(fn ($route) => ['route_id'=>$route['id'],'quantity'=>$route['quantity'],
            'disposition'=>$route['route_type']==='NEXT_OPERATION'?'WORK_ORDER':'PUBLIC_UNALLOCATED'],$routes);
        app(CuttingConfirmationService::class)->confirm($batchId,$this->payload((int) DB::table('erp_cutting_settlement_batches')->where('id',$batchId)->value('business_version'))+
            ['costs'=>[['result_id'=>$result,'total_cost'=>'3000']],'allocations'=>$allocations],$f['user'],self::PERMISSIONS,true);
        $dispatch = app(CuttingHandoverService::class)->dispatch($routes[0]['id'],$this->payload(2)+['quantity'=>'6'],$f['user'],self::PERMISSIONS,true);
        app(CuttingHandoverService::class)->accept($dispatch['handover_id'],$this->payload(1)+['quantity'=>'6'],$receiver,self::PERMISSIONS,true);
        $receipt = app(CuttingWarehouseReceiptService::class)->post($routes[1]['id'],$this->payload(2)+['quantity'=>'4','warehouse_id'=>$f['warehouse']->id,
            'location_id'=>$f['location']->id,'batch_no'=>'WORKER-'.Str::ulid()],$f['user'],self::PERMISSIONS,true);
        $this->assertSame('6.00000000',DB::table('erp_production_target_material_requirements')->where('id',$f['targetRequirement'])->value('satisfied_base_qty'));
        $this->assertSame('4.0000',(string) DB::table('erp_inventory_balances')->where('item_id',$f['output']->id)->sum('quantity_available'));
        $this->assertSame('1800.0000',DB::table('erp_cutting_handovers')->where('id',$dispatch['handover_id'])->value('accepted_cost'));
        $this->assertSame('1200.0000',$receipt['posted_cost']);
        $this->assertSame(0,DB::table('erp_cutting_plan_allocations')->where('cutting_order_id',$f['order'])->count());
        app(CuttingTaskExecutionService::class)->finish($created['cutting_task_id'],$this->payload(2),$f['user'],self::PERMISSIONS,true);
        $closed = app(CuttingOrderLifecycleService::class)->close($f['order'],$this->payload((int) DB::table('erp_cutting_orders')->where('id',$f['order'])->value('business_version'))+['reason'=>'本次下料已完成'],$f['user'],self::PERMISSIONS,true);
        $this->assertSame('CLOSED',$closed['status']);
    }

    private function counts(): array
    {
        return array_map(fn ($table) => DB::table($table)->count(),['erp_work_orders','erp_cutting_demands','erp_cutting_plan_allocations',
            'erp_cutting_results','erp_production_labor_sessions']);
    }
}
