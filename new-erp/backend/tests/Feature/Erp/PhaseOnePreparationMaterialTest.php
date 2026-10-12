<?php

namespace Tests\Feature\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{Bom, BomItem, InventoryBalance, Item, Location, MaterialPickingTask, MaterialPickingTaskLine, PurchaseRequest, PurchaseRequestItem, Unit, Warehouse, WorkOrder, WorkOrderMaterialRequirement, WorkOrderPreparationMaterial};
use App\Services\Erp\{PurchaseRequestLifecycleApplicationService, PurchaseWorkflowApplicationService, ReleaseGateApplicationService, WorkOrderOutputPlanService, WorkOrderPreparationMaterialService, WorkOrderTechnicalService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Database integration coverage; do not run on the development/business database. */
class PhaseOnePreparationMaterialTest extends TestCase
{
    use DatabaseTransactions;

    public function test_dynamic_occurrences_survive_reload_and_bind_after_real_output_freeze(): void
    {
        $f = $this->fixture();
        $f['wo']->update(['technical_version' => 1, 'technical_snapshot' => ['configuration_snapshot' => ['cut_requirements' => [
            ['component_item_id' => $f['item']->id, 'cut_length_mm' => 1000, 'piece_qty' => 2, 'remark' => null],
            ['component_item_id' => $f['item']->id, 'cut_length_mm' => 1500, 'piece_qty' => 3, 'remark' => null],
        ]]]]);
        $beforeTargets = DB::table('erp_production_target_material_requirements')->count();
        $first = $this->prepare($f);
        $repeat = $this->prepare($f);
        $this->assertCount(2, $first['rows']);
        $this->assertSame(array_column($first['rows'], 'id'), array_column($repeat['rows'], 'id'));
        $this->assertSame(['1000.00000000', '1500.00000000'], array_column($repeat['rows'], 'cut_length_mm_snapshot'));
        $this->assertSame($beforeTargets, DB::table('erp_production_target_material_requirements')->count());
        $this->assertSame(1, DB::table('erp_work_order_material_preparation_versions')->where('preparation_id', WorkOrderPreparationMaterial::findOrFail($first['rows'][0]['id'])->preparation_id)->count());
        $this->freezeAndBind($f);
        $rows = WorkOrderPreparationMaterial::where('work_order_id', $f['wo']->id)->get();
        $this->assertCount(2, $rows->pluck('material_requirement_id')->unique());
        $this->assertTrue($rows->every(fn ($row) => $row->status === 'PUBLISHED' && $row->material_requirement_id));
        $this->assertSame($beforeTargets, DB::table('erp_production_target_material_requirements')->count());
    }

    public function test_real_route_change_invalidates_preparation_and_outstanding_procurement_blocks_resync(): void
    {
        $f = $this->fixture(); $prepared = $this->prepare($f);
        $this->procurement($f, $prepared['rows'][0]['id'], '5');
        $f['wo']->update(['routing_snapshot' => ['version' => 2, 'operations' => []]]);
        $projection = $this->service()->projection($f['wo']->fresh());
        $this->assertFalse($projection['ready']);
        $this->assertSame('preparation_stale', $projection['issues'][0]['code']);
        $this->assertFalse($projection['rows'][0]['can_procure']);
        try { $this->prepare($f); $this->fail('Expected an outstanding procurement conflict.'); }
        catch (WorkOrderDomainException $e) { $this->assertSame('preparation_procurement_conflict', $e->errorCode); }
    }

    public function test_existing_serialization_hash_with_live_procurement_needs_no_new_version(): void
    {
        $f = $this->fixture(); $prepared = $this->prepare($f);
        $this->procurement($f, $prepared['rows'][0]['id'], '5');
        $header = DB::table('erp_work_order_material_preparations')->where('work_order_id', $f['wo']->id)->first();
        $snapshot = array_reverse(json_decode($header->input_snapshot, true), true);
        DB::table('erp_work_order_material_preparations')->where('id', $header->id)->update([
            'input_snapshot' => json_encode($snapshot), 'input_hash' => hash('sha256', json_encode($snapshot)),
        ]);
        $this->assertSame($prepared['version'], $this->prepare($f)['version']);
        $this->freezeAndBind($f);
        $formal = WorkOrderMaterialRequirement::where('work_order_id', $f['wo']->id)->firstOrFail();
        $this->assertSame('5.00000000', $this->service()->pendingForFormal($formal->id));
    }

    public function test_waiting_and_started_picks_are_excluded_from_preparation_stock_projection(): void
    {
        $f = $this->fixture(); $this->prepare($f);
        $warehouse = Warehouse::create(['warehouse_code' => $this->code(), 'warehouse_name' => '准备测试仓', 'management_scope' => 'factory', 'status' => 'enabled']);
        $location = Location::create(['location_code' => $this->code(), 'location_name' => '准备测试库位', 'warehouse_id' => $warehouse->id, 'status' => 'enabled']);
        $balance = InventoryBalance::create(['item_id' => $f['item']->id, 'unit_id' => $f['unit']->id, 'warehouse_id' => $warehouse->id,
            'location_id' => $location->id, 'batch_no' => $this->code(), 'quantity_on_hand' => 20, 'quantity_available' => 20,
            'quantity_locked' => 0, 'quantity_defective' => 0, 'quantity_pending' => 0]);
        // An independent published order owns these pending picks; the prepared order
        // must not borrow their balances merely because no inventory posting occurred yet.
        $other = $f['wo']->replicate(); $other->work_order_no = $this->code(); $other->status = 'RELEASED'; $other->save();
        $material = $this->materialRows($f)[0]; $material['work_order_id'] = $other->id;
        $requirement = WorkOrderMaterialRequirement::create($material);
        $task = MaterialPickingTask::create(['task_no' => $this->code(), 'work_order_id' => $other->id, 'warehouse_id' => $warehouse->id, 'status' => 'WAIT_PICK', 'business_version' => 1]);
        MaterialPickingTaskLine::create(['task_id' => $task->id, 'material_requirement_id' => $requirement->id, 'component_item_id' => $f['item']->id,
            'required_qty_snapshot' => 20, 'planned_pick_qty' => 12, 'actual_pick_qty' => 0, 'unit_id' => $f['unit']->id,
            'inventory_balance_id' => $balance->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id,
            'batch_no' => $balance->batch_no, 'status' => 'WAIT_PICK', 'business_version' => 1]);
        foreach (['WAIT_PICK', 'PICKING'] as $state) {
            $task->update(['status' => $state]);
            $row = $this->service()->projection($f['wo']->fresh())['rows'][0];
            $this->assertSame('8.00000000', $row['available_stock_qty']);
            $this->assertSame('12.00000000', $row['procureable_qty']);
        }
        $task->update(['status' => 'CANCELLED']);
        $this->assertSame('0.00000000', $this->service()->projection($f['wo']->fresh())['rows'][0]['procureable_qty']);
        $this->assertSame('20.0000', (string) $balance->fresh()->quantity_available);
    }

    public function test_deleted_cancelled_and_closed_requests_follow_existing_lifecycle_quantity_rules(): void
    {
        $f = $this->fixture(); $rowId = $this->prepare($f)['rows'][0]['id'];
        [$deleted] = $this->procurement($f, $rowId, '5');
        app(PurchaseRequestLifecycleApplicationService::class)->softDelete($deleted->id, '准备测试');
        $this->assertSame('0.00000000', $this->service()->pendingForPreparation($rowId));
        [$cancelled] = $this->procurement($f, $rowId, '5', 'draft');
        app(PurchaseWorkflowApplicationService::class)->cancelRequest($cancelled->id, '准备测试');
        $this->assertSame('0.00000000', $this->service()->pendingForPreparation($rowId));
        [$closed, $line] = $this->procurement($f, $rowId, '10', 'partially_planned');
        $closed->update(['planned_qty' => 3]); $line->update(['converted_qty' => 3]);
        app(PurchaseWorkflowApplicationService::class)->closeRequest($closed->id, '准备测试');
        $this->assertSame('3.00000000', $this->service()->pendingForPreparation($rowId));
        $this->assertSame('17.00000000', $this->service()->projection($f['wo']->fresh())['rows'][0]['procureable_qty']);
    }

    public function test_stock_prebuild_child_without_sales_demand_can_prepare_but_cancelled_root_cannot(): void
    {
        $f = $this->fixture();
        $root = $f['wo']->replicate(); $root->work_order_no = $this->code(); $root->save();
        $f['wo']->update(['assembly_root_work_order_id' => $root->id, 'assembly_parent_work_order_id' => $root->id]);
        $this->assertTrue($this->prepare($f)['ready']);
        $root->update(['status' => 'CANCELLED']);
        $this->assertFalse($this->service()->projection($f['wo']->fresh())['ready']);
    }

    public function test_formal_balance_tracks_purchase_receipt_issue_and_a_second_incoming_request_once(): void
    {
        $f = $this->fixture(); $preparedId = $this->prepare($f)['rows'][0]['id'];
        [$request, $requestLine] = $this->procurement($f, $preparedId, '10');
        $this->freezeAndBind($f);
        $formal = WorkOrderMaterialRequirement::where('work_order_id', $f['wo']->id)->firstOrFail();
        $targetId = DB::table('erp_production_quantity_operations')->insertGetId(['work_order_id' => $f['wo']->id,
            'operation_code_snapshot' => $this->code(), 'operation_name_snapshot' => '准备测试工序', 'sequence_no_snapshot' => 10,
            'planned_base_qty' => 20, 'remaining_base_qty' => 20, 'status' => 'WAIT_MATERIAL', 'created_at' => now(), 'updated_at' => now()]);
        $supplyId = DB::table('erp_work_order_material_supply_rules')->insertGetId(['work_order_id' => $f['wo']->id,
            'material_requirement_id' => $formal->id, 'component_item_id' => $f['item']->id,
            'target_operation_code_snapshot' => $this->code(), 'target_operation_name_snapshot' => '准备测试工序',
            'required_base_qty_snapshot' => 20, 'supply_mode_snapshot' => 'dedicated_delivery', 'requires_delivery_snapshot' => true,
            'participates_in_kitting_snapshot' => true, 'allow_partial_delivery_snapshot' => true,
            'delivery_location_type_snapshot' => 'operation_station', 'rule_snapshot' => '{"required_qty_ratio":1}', 'created_at' => now(), 'updated_at' => now()]);
        $demandId = DB::table('erp_production_target_material_requirements')->insertGetId(['work_order_id' => $f['wo']->id,
            'target_type' => 'quantity_operation', 'target_id' => $targetId, 'material_requirement_id' => $formal->id,
            'material_supply_rule_snapshot_id' => $supplyId, 'component_item_id' => $f['item']->id,
            'required_base_qty' => 20, 'created_at' => now(), 'updated_at' => now()]);
        $source = (object) ['material_requirement_id' => $formal->id];
        $this->assertSame('10.00000000', $this->service()->formalProcurementBalance($source)['procureable_qty']);

        // These are persisted quantity facts for the query integration test, not a
        // replacement for the separate purchase/warehouse command workflow tests.
        $supplierId = DB::table('erp_suppliers')->insertGetId(['supplier_code' => $this->code(), 'supplier_name' => '准备测试供应商', 'status' => 'enabled']);
        $orderId = DB::table('erp_purchase_orders')->insertGetId(['purchase_order_no' => $this->code(), 'supplier_id' => $supplierId, 'management_scope' => 'factory']);
        $orderItemId = DB::table('erp_purchase_order_items')->insertGetId(['order_id' => $orderId, 'request_id' => $request->id,
            'request_item_id' => $requestLine->id, 'item_id' => $f['item']->id, 'order_qty' => 10]);
        $receiptId = DB::table('erp_purchase_receipts')->insertGetId(['receipt_no' => $this->code(), 'order_id' => $orderId,
            'supplier_id' => $supplierId, 'receipt_status' => 'confirmed', 'stock_post_status' => 'posted']);
        $receiptItemId = DB::table('erp_purchase_receipt_items')->insertGetId(['receipt_id' => $receiptId, 'order_item_id' => $orderItemId,
            'item_id' => $f['item']->id, 'receipt_qty' => 10, 'qualified_qty' => 10]);
        $warehouse = Warehouse::create(['warehouse_code' => $this->code(), 'warehouse_name' => '正式需求测试仓', 'management_scope' => 'factory', 'status' => 'enabled']);
        $location = Location::create(['location_code' => $this->code(), 'location_name' => '正式需求测试位', 'warehouse_id' => $warehouse->id, 'status' => 'enabled']);
        $transactionNo = $this->code(); $batchNo = $this->code();
        $postingId = DB::table('erp_inventory_transactions')->insertGetId(['transaction_no' => $transactionNo,
            'transaction_type' => 'purchase_receipt_posting', 'source_type' => 'purchase_receipt', 'source_id' => $receiptId,
            'posting_status' => 'posted', 'warehouse_id' => $warehouse->id, 'location_id' => $location->id]);
        DB::table('erp_inventory_transaction_items')->insert(['transaction_id' => $postingId, 'transaction_no' => $transactionNo,
            'item_id' => $f['item']->id, 'item_code' => $f['item']->item_code, 'item_name' => $f['item']->item_name,
            'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'batch_no' => $batchNo, 'unit_id' => $f['unit']->id,
            'change_qty' => 10, 'balance_after_qty' => 10, 'source_type' => 'purchase_receipt', 'source_id' => $receiptId, 'source_item_id' => $receiptItemId]);
        $balance = InventoryBalance::create(['item_id' => $f['item']->id, 'unit_id' => $f['unit']->id, 'warehouse_id' => $warehouse->id,
            'location_id' => $location->id, 'batch_no' => $batchNo, 'quantity_on_hand' => 10, 'quantity_available' => 10,
            'quantity_locked' => 0, 'quantity_defective' => 0, 'quantity_pending' => 0]);
        $afterReceipt = $this->service()->formalProcurementBalance($source);
        $this->assertSame('10.00000000', $afterReceipt['received_procurement_qty']);
        $this->assertSame('0.00000000', $afterReceipt['pending_procurement_qty']);
        $this->assertSame('10.00000000', $afterReceipt['procureable_qty']);

        $balance->update(['quantity_on_hand' => 0, 'quantity_available' => 0]);
        DB::table('erp_production_target_material_requirements')->where('id', $demandId)->update(['satisfied_base_qty' => 10]);
        $this->assertSame('10.00000000', $this->service()->formalProcurementBalance($source)['procureable_qty']);
        [$incoming] = $this->procurement($f, $preparedId, '10');
        DB::table('erp_material_procurement_sources')->where('request_id', $incoming->id)->update([
            'preparation_material_requirement_id' => null, 'preparation_version' => null, 'target_material_requirement_id' => $demandId,
        ]);
        $withIncoming = $this->service()->formalProcurementBalance($source);
        $this->assertSame('10.00000000', $withIncoming['pending_procurement_qty']);
        $this->assertSame('0.00000000', $withIncoming['procureable_qty']);
        app(PurchaseRequestLifecycleApplicationService::class)->softDelete($incoming->id, '准备测试');
        $this->assertSame('10.00000000', $this->service()->formalProcurementBalance($source)['procureable_qty']);
    }

    private function fixture(): array
    {
        $unit = Unit::create(['unit_code' => $this->code(), 'unit_name' => '件', 'unit_type' => 'quantity', 'decimal_places' => 4, 'is_base' => true, 'status' => 'enabled']);
        $output = Item::create(['item_code' => $this->code(), 'item_name' => '准备测试成品', 'item_type' => 'finished_good',
            'management_scope' => 'factory', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_production_item' => true, 'production_execution_mode' => 'quantity', 'status' => 'enabled']);
        $item = Item::create(['item_code' => $this->code(), 'item_name' => '准备测试原料', 'item_type' => 'raw_material',
            'management_scope' => 'factory', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_purchase_item' => true, 'manufacturing_strategy' => 'purchase', 'status' => 'enabled']);
        $bom = Bom::create(['bom_no' => $this->code(), 'bom_name' => '准备测试BOM', 'output_item_id' => $output->id,
            'bom_type' => 'standard', 'version' => 'V1', 'is_default' => true, 'status' => 'active', 'audit_status' => 'approved']);
        BomItem::create(['bom_id' => $bom->id, 'line_no' => 10, 'component_item_id' => $item->id, 'component_item_code' => $item->item_code,
            'component_item_name' => $item->item_name, 'qty' => 1, 'unit_id' => $unit->id, 'loss_rate' => 0, 'fixed_qty' => 0]);
        $wo = WorkOrder::create(['work_order_no' => $this->code(), 'source_type' => 'stock_prebuild', 'output_item_id' => $output->id,
            'target_qty' => 20, 'target_base_qty' => 20, 'base_unit_id' => $unit->id, 'target_unit_id' => $unit->id,
            'bom_id' => $bom->id, 'status' => 'WAIT_RELEASE', 'business_version' => 1, 'routing_snapshot' => ['version' => 1, 'operations' => []]]);
        $user = (object) ['legacy_id' => 987654321, 'username' => 'preparation-test'];
        return compact('unit', 'item', 'output', 'bom', 'wo', 'user');
    }

    private function procurement(array $f, int $rowId, string $qty, string $status = 'confirmed'): array
    {
        $request = PurchaseRequest::create(['request_no' => $this->code(), 'item_id' => $f['item']->id, 'request_qty' => $qty,
            'planned_qty' => 0, 'request_status' => $status, 'management_scope' => 'factory', 'source_type' => 'production_material']);
        $line = PurchaseRequestItem::create(['request_id' => $request->id, 'item_id' => $f['item']->id, 'unit_id' => $f['unit']->id,
            'request_qty' => $qty, 'converted_qty' => 0, 'remaining_qty' => $qty]);
        DB::table('erp_material_procurement_sources')->insert(['request_id' => $request->id, 'request_item_id' => $line->id,
            'component_item_id' => $f['item']->id, 'work_order_id' => $f['wo']->id, 'preparation_material_requirement_id' => $rowId,
            'preparation_version' => 1, 'requested_base_qty' => $qty, 'created_by_legacy_id' => $f['user']->legacy_id, 'created_at' => now(), 'updated_at' => now()]);
        return [$request, $line];
    }

    private function materialRows(array $f): array
    {
        $wo = $f['wo']->fresh();
        return app(ReleaseGateApplicationService::class)->buildMaterialRows($wo,
            $f['bom']->fresh(['items.componentItem.unit', 'items.unit']), app(WorkOrderTechnicalService::class)->effectiveConfiguration($wo));
    }

    private function freezeAndBind(array $f): void
    {
        DB::transaction(function () use ($f): void {
            $wo = WorkOrder::whereKey($f['wo']->id)->lockForUpdate()->firstOrFail();
            // Valid Gate plan is fixture input; this test exercises the real freeze method
            // and material binding, without weakening or invoking the release Gate itself.
            app(WorkOrderOutputPlanService::class)->freezeLocked($wo, ['work_order_id' => $wo->id,
                'work_order_version' => $wo->business_version, 'matched' => true, 'execution_supported' => true], $f['user'], 2);
            DB::table('erp_work_order_material_requirements')->insert($this->materialRows($f));
            $wo->status = 'RELEASED'; $wo->released_at = now(); $wo->business_version = 2; $wo->save();
            $this->service()->bindPublishedLocked($wo);
        });
    }

    private function prepare(array $f): array { return DB::transaction(fn () => $this->service()->synchronizeLocked($f['wo']->fresh(), $f['user'])); }
    private function service(): WorkOrderPreparationMaterialService { return app(WorkOrderPreparationMaterialService::class); }
    private function code(): string { return 'P1-'.Str::upper(Str::random(20)); }
}
