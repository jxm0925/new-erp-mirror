<?php

namespace Tests\Support;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{Bom, BomItem, InventoryBalance, Item, Location, ProductionQuantityOperation, ProductionTask, PurchaseReceipt, PurchaseReceiptItem, Supplier, Unit, Warehouse, WorkOrder, WorkOrderMaterialRequirement};
use App\Services\Erp\{CuttingConfirmationService, CuttingDecimal, CuttingHandoverService, CuttingInputService, CuttingInventoryReservationService, CuttingReadService, CuttingRecordService, CuttingTaskExecutionService, CuttingWarehouseReceiptService, InventoryService, ProductionInternalIssueService, ProductionKittingService, ProductionLaborSessionService, PurchaseReceiptPostingRepairApplicationService, RbacBootstrapService};
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Shared real-service cutting fixtures; each caller owns its transaction or committed cleanup. */
trait CuttingTestFixtures
{
    private const PERMISSIONS = ['production.cutting.plan','production.cutting.issue','production.cutting.record',
        'production.cutting.view','production.cutting.material_manage','production.cutting.confirm','production.output.quality',
        'production.cutting.task.claim','production.cutting.task.start','production.cutting.task.pause',
        'production.cutting.task.resume','production.cutting.task.finish','production.cutting.task.collaborate',
        'production.cutting.handover.view','production.cutting.handover.dispatch',
        'production.cutting.handover.receive','production.cutting.handover.reject',
        'production.cutting.warehouse',
        'production.cutting.inventory.view','production.cutting.inventory.issue','production.cutting.inventory.release',
        'production.cutting.close','production.cutting.cancel',
        'production.output.issue','production.output.receive','production.task.view',
        'production.kitting.view','production.kitting.confirm'];

    private function submitBatch(array $f, int $id): array
    { return app(CuttingRecordService::class)->submit($id,$this->payload((int) DB::table('erp_cutting_settlement_batches')->where('id',$id)->value('business_version')),$f['user'],self::PERMISSIONS,true); }

    private function confirmation(array $f, int $id, int $result, string $cost, bool $submit = true): array
    {
        if ($submit) $this->submitBatch($f,$id);
        $plan = DB::table('erp_cutting_allowed_outputs')->where('id',$f['allowed'])->value('plan_id');
        return $this->payload((int) DB::table('erp_cutting_settlement_batches')->where('id',$id)->value('business_version'))+['costs'=>[['result_id'=>$result,'total_cost'=>$cost]],
            'allocations'=>DB::table('erp_cutting_result_routes')->where('result_id',$result)->orderBy('id')->get()->map(fn ($r) => ['route_id'=>$r->id,'plan_id'=>$plan,'quantity'=>$r->quantity,'disposition'=>'PLAN'])->all()];
    }

    private function warehouseStock(array $f, string $quantity, string $cost = '3000'): array
    {
        $batch = $this->issue($f); $batchId = $batch['settlement_batch_id'];
        $result = $this->save($f,$batchId,$quantity)['result_ids'][0];
        $routeId = $this->route($f,$result,$quantity)['routes'][0]['id'];
        app(CuttingConfirmationService::class)->confirm($batchId,$this->confirmation($f,$batchId,$result,$cost),$f['user'],self::PERMISSIONS,true);
        return app(CuttingWarehouseReceiptService::class)->post($routeId,$this->payload(2)+[
            'quantity'=>$quantity,'warehouse_id'=>$f['warehouse']->id,'location_id'=>$f['location']->id,'batch_no'=>'CUT-ISSUE-'.Str::ulid()],$f['user'],self::PERMISSIONS,true);
    }

    private function employee(string $prefix): object
    {
        $user = (object) ['legacy_id'=>random_int(100000000,999999999),'username'=>$prefix.Str::ulid()];
        DB::table('erp_legacy_admin_users')->insert(['legacy_id'=>$user->legacy_id,'username'=>$user->username,
            'status'=>'normal','auth_group_names'=>'[]','created_at'=>now(),'updated_at'=>now()]);
        return $user;
    }

    private function consumerTask(array $f, object $receiver): ProductionTask
    {
        $target = ProductionQuantityOperation::findOrFail($f['consumerOperation']);
        $target->fill(['status'=>'WAIT_MATERIAL','responsible_user_legacy_id'=>$receiver->legacy_id,'claimed_at'=>now(),
            'kitting_required'=>true,'business_version'=>(int) $target->business_version+1])->save();
        $task = ProductionTask::create(['task_no'=>'CUT-TARGET-'.Str::ulid(),'work_order_id'=>$f['consumerWo']->id,
            'execution_mode'=>'quantity','routing_operation_id_snapshot'=>$f['consumerStage'],
            'operation_code_snapshot'=>$target->operation_code_snapshot,'operation_name_snapshot'=>$target->operation_name_snapshot,
            'sequence_no_snapshot'=>$target->sequence_no_snapshot,'status'=>'WAIT_MATERIAL',
            'assignee_user_legacy_id'=>$receiver->legacy_id,'assignment_mode'=>'manual_claim','claimed_at'=>now(),'business_version'=>1]);
        DB::table('erp_production_task_targets')->insert(['task_id'=>$task->id,'target_type'=>'quantity_operation',
            'target_id'=>$target->id,'status_snapshot'=>'WAIT_MATERIAL','created_at'=>now(),'updated_at'=>now()]);
        return $task;
    }

    private function fixture(string $quality = 'none', string $required = '10', string $planned = '10', bool $restricted = false,
        bool $publish = true, string $rawUnitCost = '3000'): array
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
            'receipt_status'=>'confirmed','confirm_status'=>'confirmed','stock_post_status'=>'pending','total_receipt_qty'=>2,'total_qualified_qty'=>2,
            'total_amount'=>bcmul($rawUnitCost,'2',4)]);
        $line = PurchaseReceiptItem::create(['receipt_id'=>$receipt->id,'item_id'=>$raw->id,'purchase_unit_id'=>$unit->id,'purchase_unit_name_snapshot'=>'件',
            'conversion_factor_snapshot'=>1,'base_unit_id'=>$unit->id,'base_unit_name_snapshot'=>'件','receipt_qty'=>2,'qualified_qty'=>2,'unqualified_qty'=>0,
            'standard_base_qty'=>2,'actual_base_qty'=>2,'qualified_base_qty'=>2,'unqualified_base_qty'=>0,'is_stock_item_snapshot'=>true,
            'quality_fact_origin'=>'current','original_received_qty'=>2,'original_qualified_qty'=>2,'original_unqualified_qty'=>0,
            'original_received_base_qty'=>2,'original_qualified_base_qty'=>2,'original_unqualified_base_qty'=>0,'final_stockable_base_qty'=>2,
            'physical_received_base_qty'=>2,'contract_fulfilled_base_qty'=>2,'unit_price'=>$rawUnitCost,'receipt_cost'=>bcmul($rawUnitCost,'2',4),
            'batch_no'=>'CUT-BAT-'.$s,'inventory_posting_status'=>'pending']);
        app(PurchaseReceiptPostingRepairApplicationService::class)->repair($receipt->id, [['receipt_item_id'=>$line->id,'allocations'=>[
            ['warehouse_id'=>$warehouse->id,'location_id'=>$location->id,'base_qty'=>2,'serial_nos'=>[],
                'physical_entries'=>[
                    ['dimensions'=>['length_mm'=>'2440','width_mm'=>'1220','thickness_mm'=>'2']],
                    ['dimensions'=>['length_mm'=>'2440','width_mm'=>'1220','thickness_mm'=>'2']],
                ]]]]],'下料专项');
        $tx = app(InventoryService::class)->postPurchaseReceipt($receipt->id); $txLine = $tx->items->first();
        $balance = InventoryBalance::where('item_id',$raw->id)->where('batch_no','CUT-BAT-'.$s)->firstOrFail();
        $physicals = DB::table('erp_material_physicals')->where('source_transaction_item_id', $txLine->id)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
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
        if ($restricted) {
            $output->update(['is_custom_item'=>true]);
            $configId = DB::table('erp_custom_configurations')->insertGetId(['item_id'=>$output->id,'configuration_no'=>'CUT-CFG-'.$s,
                'version_no'=>1,'dimensions'=>json_encode(['length_mm'=>'100','width_mm'=>'80']),
                'drawing_reference'=>'CUT-DRAWING-V1','scope_mode'=>'RESTRICTED','status'=>'PUBLISHED','business_version'=>1,
                'created_by_legacy_id'=>$user->legacy_id,'published_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
            foreach ([$wo->id,$consumerWo->id] as $scopeId) DB::table('erp_custom_configuration_scopes')->insert([
                'configuration_id'=>$configId,'source_type'=>'work_order','source_id'=>$scopeId,'created_at'=>now(),'updated_at'=>now()]);
            $planPayload['configuration_id'] = $configId;
        }
        if (! $publish) {
            return compact('user','raw','output','physicals','balance','wo','stage','warehouse','location','targetRequirement','inputRequirement','planPayload','consumerWo','consumerStage','consumerOperation','consumerRequirement','producerOperation');
        }
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
}
