<?php

namespace Tests\Feature\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\Item;
use App\Models\Erp\ProductionDemand;
use App\Models\Erp\ProductionRouting;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesOrderLine;
use App\Models\Erp\Unit;
use App\Services\Erp\DocumentNumberService;
use App\Services\Erp\ProductionMasterDataService;
use App\Services\Erp\ReleaseGateApplicationService;
use App\Services\Erp\WorkOrderApplicationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class Phase6A1CloseoutTest extends TestCase
{
    use DatabaseTransactions;

    private const WO_PERMS = ['production.work_order.view', 'production.work_order.create', 'production.work_order.edit', 'production.work_order.gate.view'];
    private const MASTER_PERMS = ['production.routing.view', 'production.routing.create', 'production.routing.edit', 'production.routing.activate', 'production.routing.default'];

    public function test_cases_01_to_04_sales_route_is_automatic_frozen_and_required_for_release(): void
    {
        [$user, $item] = $this->base();
        $route = $this->route($item, true, false);
        $demand = $this->demand($user, $item, 'WITH-ROUTE');
        $service = app(WorkOrderApplicationService::class);
        $workOrder = $service->createDraft($this->salesPayload($demand), $user, self::WO_PERMS, true);
        $this->assertSame($route->id, (int) $workOrder->production_routing_id); // CASE 01
        $this->assertSame(1, (int) $workOrder->routing_version_snapshot);
        $this->assertSame($route->routing_no, $workOrder->routing_snapshot['routing_no']); // CASE 02
        $snapshot = $workOrder->routing_snapshot;
        DB::table('erp_production_routings')->where('id', $route->id)->update(['routing_name' => '主数据改名']);
        $this->assertSame($snapshot, $workOrder->fresh()->routing_snapshot); // CASE 04

        $missingItem = $this->item($item->unit_id, 'NO-ROUTE');
        $missingDemand = $this->demand($user, $missingItem, 'NO-ROUTE');
        $missing = $service->createDraft($this->salesPayload($missingDemand), $user, self::WO_PERMS, true);
        $missing->status = WorkOrderApplicationService::WAIT_RELEASE;
        $missing->responsible_user_legacy_id = $user->legacy_id;
        $missing->production_location_name = '一车间';
        $missing->save();
        $gate = app(ReleaseGateApplicationService::class)->evaluate($missing->id, $user, self::WO_PERMS, true);
        $this->assertContains('routing_snapshot_missing', collect($gate['blockers'])->pluck('reason_code')->all()); // CASE 03
    }

    public function test_cases_05_to_11_stock_target_source_rules_and_immutable_sales_fields(): void
    {
        [$user, $item] = $this->base();
        $route = $this->route($item, true, true);
        $nodes = $route->operations;
        $payload = $this->stockPayload($user, $item, $route, $nodes->last()->id);
        $service = app(WorkOrderApplicationService::class);
        $workOrder = $service->createDraft($payload, $user, self::WO_PERMS, true);
        $this->assertSame($nodes->last()->id, (int) $workOrder->target_routing_operation_id); // CASE 05, 07
        $this->assertSame($nodes->last()->operation_id, (int) $workOrder->target_operation_id);
        $this->assertStringStartsWith('SPB', $workOrder->source_no_snapshot);

        $other = $this->route($item, false, false);
        $this->expectDomain('target_routing_operation_invalid', fn () => $service->createDraft($this->stockPayload($user, $item, $route, $other->operations->first()->id), $user, self::WO_PERMS, true)); // CASE 06
        $this->expectDomain('source_module_unavailable', fn () => $service->createDraft(['client_command_id' => $this->id(), 'source_type' => 'production_plan', 'target_qty' => 1], $user, self::WO_PERMS, true)); // CASE 08
        $this->expectDomain('source_module_unavailable', fn () => $service->createDraft(['client_command_id' => $this->id(), 'source_type' => 'trial', 'target_qty' => 1], $user, self::WO_PERMS, true)); // CASE 09, 10

        $demand = $this->demand($user, $item, 'IMMUTABLE');
        $sales = $service->createDraft($this->salesPayload($demand), $user, self::WO_PERMS, true);
        $this->expectDomain('immutable_field', fn () => $service->updateDraft($sales->id, ['client_command_id' => $this->id(), 'expected_version' => 1, 'production_routing_id' => $other->id], $user, self::WO_PERMS, true)); // CASE 11
    }

    public function test_cases_12_to_15_route_versions_default_and_retirement(): void
    {
        [$user, $item] = $this->base();
        $route = $this->route($item, true, false);
        $master = app(ProductionMasterDataService::class);
        $copy = $master->copyRouting($route->id, ['client_command_id' => $this->id()], $user, self::MASTER_PERMS, true);
        $this->assertSame($route->routing_no, $copy->routing_no); // CASE 12
        $this->assertSame(2, $copy->version); // CASE 13
        $copy = $master->activateRouting($copy->id, ['client_command_id' => $this->id(), 'expected_version' => 1], $user, self::MASTER_PERMS, true);
        $copy = $master->setDefaultRouting($copy->id, ['client_command_id' => $this->id(), 'expected_version' => 2], $user, self::MASTER_PERMS, true);
        $this->assertTrue($copy->is_default);
        $this->assertFalse((bool) $route->fresh()->is_default); // CASE 14
        $retired = $master->retireRouting($route->id, ['client_command_id' => $this->id(), 'expected_version' => $route->fresh()->business_version], $user, self::MASTER_PERMS, true);
        $this->assertSame('retired', $retired->status);
        $this->assertDatabaseHas('erp_production_routings', ['id' => $route->id, 'routing_no' => $route->routing_no]); // CASE 15
    }

    public function test_case_16_same_command_returns_one_formal_record(): void
    {
        [$user, $item] = $this->base();
        $master = app(ProductionMasterDataService::class);
        $route = $this->route($item, false, false);
        $command = ['client_command_id' => $this->id()];
        $first = $master->copyRouting($route->id, $command, $user, self::MASTER_PERMS, true);
        $second = $master->copyRouting($route->id, $command, $user, self::MASTER_PERMS, true);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DB::table('erp_production_master_commands')->where('client_command_id', $command['client_command_id'])->count()); // CASE 16
    }

    private function base(): array
    {
        $legacyId = random_int(910000, 990000);
        DB::table('erp_legacy_admin_users')->insert(['legacy_id' => $legacyId, 'username' => 'p6a1-'.$legacyId, 'nickname' => '6A.1验收员', 'status' => 'normal', 'auth_group_names' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([['work_order','生产工单','WO'],['stock_prebuild','备货来源号','SPB']] as [$type,$name,$prefix]) DB::table('erp_document_number_rules')->updateOrInsert(['document_type'=>$type], ['name'=>$name,'prefix'=>$prefix,'date_format'=>'Ymd','sequence_length'=>5,'reset_cycle'=>'daily','enabled'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $unit = Unit::create(['unit_code'=>$this->id(),'unit_name'=>'件','unit_type'=>'count','decimal_places'=>0,'is_base'=>true,'status'=>'enabled']);
        return [DB::table('erp_legacy_admin_users')->where('legacy_id',$legacyId)->first(), $this->item($unit->id, 'FG')];
    }

    private function item(int $unitId, string $name): Item { return Item::create(['item_code'=>$this->id(),'item_name'=>'6A.1-'.$name,'unit_id'=>$unitId,'status'=>'enabled','is_production_item'=>true,'is_stock_item'=>true]); }

    private function route(Item $item, bool $default, bool $duplicate): ProductionRouting
    {
        $op = DB::table('erp_production_operations')->insertGetId(['operation_no'=>$this->id(),'operation_name'=>'组装','status'=>'enabled','sort'=>10,'business_version'=>1,'created_at'=>now(),'updated_at'=>now()]);
        $id = DB::table('erp_production_routings')->insertGetId(['routing_no'=>$this->id(),'routing_name'=>'标准路线','output_item_id'=>$item->id,'version'=>1,'status'=>'active','is_default'=>$default,'default_scope_key'=>$default?$item->id:null,'business_version'=>1,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('erp_production_routing_operations')->insert(['routing_id'=>$id,'operation_id'=>$op,'sequence'=>10,'is_key_operation'=>false,'created_at'=>now(),'updated_at'=>now()]);
        if ($duplicate) DB::table('erp_production_routing_operations')->insert(['routing_id'=>$id,'operation_id'=>$op,'sequence'=>30,'is_key_operation'=>true,'created_at'=>now(),'updated_at'=>now()]);
        return ProductionRouting::with(['outputItem','operations.operation'])->findOrFail($id);
    }

    private function demand(object $user, Item $item, string $suffix): ProductionDemand
    {
        $order = SalesOrder::create(['sales_order_no'=>$this->id(),'customer_name'=>'验收客户','order_status'=>'confirmed','confirm_status'=>'confirmed','production_confirm_status'=>'confirmed','sales_user_legacy_id'=>$user->legacy_id,'created_by_legacy_id'=>$user->legacy_id,'total_amount'=>0,'final_receivable_amount'=>0]);
        $line = SalesOrderLine::create(['sales_order_id'=>$order->id,'line_no'=>1,'line_uuid'=>$this->id(),'line_type'=>'physical','item_id'=>$item->id,'item_name'=>$item->item_name,'order_qty'=>5,'unit_id'=>$item->unit_id,'unit_name_snapshot'=>'件','item_base_unit_id'=>$item->unit_id,'item_base_required_qty'=>5,'unit_price'=>0,'amount'=>0]);
        return ProductionDemand::create(['requirement_no'=>'P6A1-'.$suffix.'-'.$this->id(),'sales_order_id'=>$order->id,'sales_order_line_id'=>$line->id,'item_id'=>$item->id,'production_qty'=>5,'base_unit_id'=>$item->unit_id,'base_unit_name_snapshot'=>'件','allocated_qty'=>0,'consumed_qty'=>0,'remaining_qty'=>5,'closed_qty'=>0,'requirement_status'=>'ready','bom_match_status'=>'matched','is_active'=>true,'requirement_version'=>1,'business_version'=>1,'is_ready_for_work_order'=>false]);
    }

    private function salesPayload(ProductionDemand $demand): array { return ['client_command_id'=>$this->id(),'source_type'=>'sales_order','production_demand_id'=>$demand->id,'expected_demand_version'=>$demand->business_version,'target_qty'=>1]; }
    private function stockPayload(object $user, Item $item, ProductionRouting $route, int $nodeId): array { $session=(string)Str::uuid(); $reservation=app(DocumentNumberService::class)->reserve('work_order',$session,$user->legacy_id,'/production/work-orders/create'); return ['client_command_id'=>$this->id(),'source_type'=>'stock_prebuild','creation_session_id'=>$session,'reservation_token'=>$reservation->reservation_token,'output_item_id'=>$item->id,'production_routing_id'=>$route->id,'target_routing_operation_id'=>$nodeId,'target_qty'=>1]; }
    private function expectDomain(string $code, callable $callback): void { try { $callback(); $this->fail("应拒绝：{$code}"); } catch (WorkOrderDomainException $e) { $this->assertSame($code, $e->errorCode); } }
    private function id(): string { return 'P6A1-'.str_replace('.', '', uniqid('', true)); }
}
