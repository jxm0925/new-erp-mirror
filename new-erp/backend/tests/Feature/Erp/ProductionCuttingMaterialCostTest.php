<?php

namespace Tests\Feature\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{BomItem, InventoryBalance, Item, ProductionQuantityOperation, SalesOrder, SalesOrderFulfillment, SalesOrderLine, Unit, WorkOrderMaterialRequirement};
use App\Services\Erp\{CuttingInventoryReservationService, ProductionExecutionActionService, ProductionInternalIssueService,
    ProductionKittingService, ProductionMaterialExecutionService, ProductionOutputService, WorkOrderCompletionService,
    InventoryReservationService, SalesShipmentApplicationService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CuttingTestFixtures;
use Tests\TestCase;

class ProductionCuttingMaterialCostTest extends TestCase
{
    use DatabaseTransactions, CuttingTestFixtures;

    private function productionPermissions(): array
    {
        return array_merge(self::PERMISSIONS, ['production.task.complete', 'production.completion.create',
            'production.completion.review', 'production.output.warehouse', 'production.output.cost.allocate', 'production.output.cost.view',
            'production.material_picking.view', 'production.material_picking.create', 'production.material_picking.assign',
            'production.material_picking.pick', 'production.material_delivery.view', 'production.material_delivery.create',
            'production.material_delivery.dispatch', 'production.material_delivery.confirm',
            'production.material_receipt.view', 'production.material_receipt.confirm']);
    }

    private function received(): array
    {
        $this->travelTo(now()->startOfSecond());
        $f = $this->fixture(); $receiver = $this->employee('cut-cost-');
        $task = $this->consumerTask($f, $receiver);
        Item::whereKey($f['consumerWo']->output_item_id)->update(['is_stock_item' => true]);
        $stock = $this->warehouseStock($f, '10');
        $issue = app(CuttingInventoryReservationService::class)->createIssue($stock['reservation_ids'][0],
            $this->payload(1) + ['quantity' => '10'], $f['user'], self::PERMISSIONS, true);
        $internal = app(ProductionInternalIssueService::class);
        $internal->dispatch($issue['internal_issue_task_id'], $this->payload(1), $f['user'], self::PERMISSIONS, true);
        $internal->receive($issue['internal_issue_task_id'], $this->payload(2), $receiver, self::PERMISSIONS, true);
        $target = ProductionQuantityOperation::findOrFail($f['consumerOperation']);
        app(ProductionKittingService::class)->confirm($task->id, 'quantity_operation', $target->id,
            $this->payload($target->business_version), $receiver, self::PERMISSIONS);
        return [$f, $receiver, $task];
    }

    private function receivePurchasedComponents(array $f, object $receiver): array
    {
        $specs = [
            ['name' => 'PLC', 'cost' => '600.0000'],
            ['name' => '开关电源', 'cost' => '200.0000'],
            ['name' => '继电器', 'cost' => '100.0000'],
            ['name' => '线材端子', 'cost' => '300.0000'],
        ];
        $unit = Unit::findOrFail($f['consumerRequirement']->unit_id);
        $rows = []; $requirementIds = [];
        foreach ($specs as $offset => $spec) {
            $suffix = (string) Str::ulid();
            $item = Item::create([
                'item_code' => 'ASM-COMP-'.$suffix, 'item_name' => $spec['name'], 'item_type' => 'raw_material',
                'unit_id' => $unit->id, 'is_purchase_item' => true, 'is_stock_item' => true, 'status' => 'enabled',
            ]);
            $bomItem = BomItem::create([
                'bom_id' => $f['consumerWo']->bom_id, 'line_no' => $offset + 2, 'component_item_id' => $item->id,
                'component_item_code' => $item->item_code, 'component_item_name' => $item->item_name,
                'qty' => '0.1', 'unit_id' => $unit->id, 'loss_rate' => 0, 'fixed_qty' => 0, 'replaceable' => false,
            ]);
            $requirement = WorkOrderMaterialRequirement::create([
                'work_order_id' => $f['consumerWo']->id, 'line_no' => $offset + 2, 'bom_id' => $f['consumerWo']->bom_id,
                'bom_item_id' => $bomItem->id, 'component_item_id' => $item->id,
                'component_item_code_snapshot' => $item->item_code, 'component_item_name_snapshot' => $item->item_name,
                'unit_id' => $unit->id, 'unit_name_snapshot' => $unit->unit_name, 'per_output_qty' => '0.1',
                'loss_rate' => 0, 'fixed_qty' => 0, 'required_qty' => 1, 'base_unit_id' => $unit->id,
                'base_unit_name_snapshot' => $unit->unit_name, 'base_required_qty' => 1, 'issued_qty' => 0,
                'returned_qty' => 0, 'remaining_qty' => 1, 'status' => 'OPEN', 'business_version' => 1,
            ]);
            $supply = $this->supply($f['consumerWo'], $requirement, $f['consumerStage'], $item->id, '1');
            $targetRequirement = $this->targetRequirement($f['consumerWo'], $requirement, $supply,
                $f['consumerOperation'], $item->id, '1');
            $balance = InventoryBalance::create([
                'item_id' => $item->id, 'warehouse_id' => $f['warehouse']->id, 'location_id' => $f['location']->id,
                'batch_no' => 'ASM-COMP-'.$suffix, 'unit_id' => $unit->id, 'quantity_on_hand' => 5,
                'quantity_available' => 5, 'quantity_locked' => 0, 'quantity_defective' => 0, 'quantity_pending' => 0,
                'average_unit_cost' => $spec['cost'], 'inventory_value' => bcmul($spec['cost'], '5', 4),
            ]);
            $rows[] = ['target_material_requirement_id' => $targetRequirement,
                'inventory_balance_id' => $balance->id, 'planned_pick_qty' => '1'];
            $requirementIds[] = $targetRequirement;
        }
        $service = app(ProductionMaterialExecutionService::class); $permissions = $this->productionPermissions();
        $pick = $service->createPickingTask([
            'client_command_id' => (string) Str::uuid(), 'work_order_id' => $f['consumerWo']->id,
            'expected_version' => $f['consumerWo']->fresh()->business_version, 'warehouse_id' => $f['warehouse']->id,
            'lines' => $rows,
        ], $f['user'], $permissions, true);
        $pick = $service->assignPickingTask($pick->id, [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => $pick->business_version,
            'assigned_picker_legacy_id' => $f['user']->legacy_id,
        ], $f['user'], $permissions, true);
        $pick = $service->startPickingTask($pick->id, [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => $pick->business_version,
        ], $f['user'], $permissions, true);
        $pick = $service->confirmPickingTask($pick->id, [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => $pick->business_version,
            'lines' => $pick->lines->map(fn ($line) => ['picking_task_line_id' => $line->id, 'actual_pick_qty' => '1'])->all(),
        ], $f['user'], $permissions, true);
        $delivery = $service->createDelivery([
            'client_command_id' => (string) Str::uuid(), 'picking_task_id' => $pick->id,
            'expected_version' => $pick->business_version,
            'lines' => $pick->lines->map(fn ($line) => ['picking_task_line_id' => $line->id, 'delivery_qty' => '1'])->all(),
        ], $f['user'], $permissions, true);
        $delivery = $service->dispatchDelivery($delivery->id, [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => $delivery->business_version,
            'delivery_user_legacy_id' => $f['user']->legacy_id,
        ], $f['user'], $permissions, true);
        $delivery = $service->deliverDelivery($delivery->id, [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => $delivery->business_version,
        ], $f['user'], $permissions, true);
        $service->receiveDelivery($delivery->id, [
            'client_command_id' => (string) Str::uuid(), 'expected_version' => $delivery->business_version,
            'lines' => $delivery->lines->map(fn ($line) => [
                'delivery_line_id' => $line->id, 'accepted_qty' => '1', 'rejected_qty' => '0',
            ])->all(),
        ], $receiver, $permissions, true);
        return compact('pick', 'requirementIds', 'specs');
    }

    private function complete(array $f, object $receiver, object $task, bool $loss = false): array
    {
        $target = ProductionQuantityOperation::findOrFail($f['consumerOperation']);
        $payload = $this->payload($target->business_version) + ['completed_base_qty' => $loss ? '9' : '10',
            'scrapped_base_qty' => $loss ? '1' : '0', 'defect_reason' => $loss ? '实际加工报废' : null, 'disposition' => 'warehouse'];
        if ($loss) $payload['material_cost_allocation'] = ['output_total_cost' => '2400.0001', 'loss_total_cost' => '599.9999'];
        $service = app(ProductionExecutionActionService::class);
        $result = $service->complete($task->id, 'quantity_operation', $target->id, $payload, $receiver, $this->productionPermissions());
        $this->assertEquals($result, $service->complete($task->id, 'quantity_operation', $target->id, $payload, $receiver, $this->productionPermissions()));
        return $result;
    }

    private function approve(array $f, int $outputId): void
    {
        $service = app(WorkOrderCompletionService::class);
        $completion = $service->submit($f['consumerWo']->id, $this->payload($f['consumerWo']->fresh()->business_version)
            + ['output_record_ids' => [$outputId]], $f['user'], $this->productionPermissions(), true);
        $service->review($completion['completion_id'], $this->payload(1) + ['decision' => 'approve'], $f['user'], $this->productionPermissions(), true);
    }

    public function test_real_cutting_issue_kitting_completion_partial_receipts_and_sales_preserve_exact_material_amount(): void
    {
        [$f, $receiver, $task] = $this->received();
        $result = $this->complete($f, $receiver, $task, true);
        $id = $result['output_record_id'];
        $output = DB::table('erp_production_output_records')->find($id);
        $this->assertSame('2400.0001', $output->material_total_cost);
        $this->assertSame('599.9999', $output->material_loss_cost);
        $this->assertSame(1, DB::table('erp_production_material_consumptions')->where('output_record_id', $id)->count());
        $this->assertSame(0, DB::table('erp_material_holdings')->where('position_type', 'PRODUCTION_WIP')->where('position_id', $f['targetRequirement'])->where('status', 'ACTIVE')->count());
        $this->assertSame('10.00000000', DB::table('erp_production_target_material_requirements')->where('id', $f['targetRequirement'])->value('consumed_base_qty'));
        $this->approve($f, $id);
        $batch = 'CUT-FINAL-'.Str::ulid(); $costs = [];
        foreach (['3', '3', '3'] as $quantity) {
            $current = DB::table('erp_production_output_records')->find($id);
            $payload = $this->payload($current->business_version) + ['warehouse_id' => $f['warehouse']->id,
                'location_id' => $f['location']->id, 'batch_no' => $batch, 'posted_base_qty' => $quantity, 'unit_cost' => '999999'];
            $service = app(ProductionOutputService::class);
            $posting = $service->warehouse($id, $payload, $f['user'], $this->productionPermissions());
            $this->assertEquals($posting, $service->warehouse($id, $payload, $f['user'], $this->productionPermissions()));
            $costs[] = DB::table('erp_inventory_transaction_items')->where('transaction_id', $posting['inventory_transaction_id'])->value('cost_amount');
        }
        $this->assertSame(['800.0000', '800.0000', '800.0001'], $costs);
        $balance = InventoryBalance::where('item_id', $output->output_item_id)->where('batch_no', $batch)->firstOrFail();
        $this->assertSame(0, bccomp((string) $balance->inventory_value, '2400.0001', 4));
        $this->assertSame((int) $output->material_lot_id, (int) $balance->material_lot_id);
        $this->assertSame('0.0000', DB::table('erp_material_holdings')->where('id', $output->material_holding_id)->value('total_cost'));
        $sales = SalesOrder::create(['sales_order_no' => 'CUT-SALE-'.Str::ulid(), 'customer_name' => '真实成本链客户',
            'order_status' => 'confirmed', 'confirm_status' => 'confirmed', 'shipment_status' => 'not_shipped', 'total_amount' => 0,
            'final_receivable_amount' => 0, 'funding_policy_snapshot' => ['policy_type' => 'full_prepay', 'policy_name' => '全额预付']]);
        $item = Item::findOrFail($output->output_item_id);
        $line = SalesOrderLine::create(['sales_order_id' => $sales->id, 'line_no' => 1, 'item_id' => $item->id, 'item_name' => $item->item_name,
            'line_type' => 'physical', 'order_qty' => 9, 'unit_price' => 0, 'amount' => 0, 'fulfillment_factor_snapshot' => 1,
            'item_base_unit_id' => $item->unit_id, 'item_base_required_qty' => 9, 'fulfillment_type' => 'inventory',
            'item_snapshot' => ['item_id' => $item->id, 'item_code' => $item->item_code]]);
        $fulfillment = SalesOrderFulfillment::create(['sales_order_id' => $sales->id, 'sales_order_line_id' => $line->id,
            'fulfillment_type' => 'inventory', 'fulfillment_qty' => 9, 'sales_qty' => 9, 'fulfillment_factor_snapshot' => 1,
            'item_base_qty' => 9, 'base_unit_id' => $item->unit_id, 'item_id' => $item->id, 'warehouse_id' => $balance->warehouse_id,
            'location_id' => $balance->location_id, 'batch_no' => $balance->batch_no, 'inventory_balance_id' => $balance->id,
            'reservation_status' => 'pending', 'demand_status' => 'confirmed']);
        app(InventoryReservationService::class)->reserveForSalesOrder($sales);
        $shipmentService = app(SalesShipmentApplicationService::class); $saleCosts = [];
        foreach ([3, 3, 3] as $quantity) {
            $shipment = $shipmentService->create($sales->id, ['lines' => [['sales_order_fulfillment_id' => $fulfillment->id, 'base_qty' => $quantity]]], '成本链');
            $shipment = $shipmentService->confirm($shipment, '成本链');
            $shipment = $shipmentService->postOutbound($shipment, '成本链');
            $saleCosts[] = (string) $shipment->actual_cost_amount;
        }
        $this->assertSame(['800.0000', '800.0000', '800.0001'], $saleCosts);
        $this->assertSame(0, bccomp((string) $balance->fresh()->inventory_value, '0', 4));
        $this->assertSame(0, bccomp((string) $balance->fresh()->quantity_on_hand, '0', 8));
        $this->assertSame('2400.0001', (string) $sales->fresh()->actual_sales_cost_amount);
    }

    public function test_self_made_enclosure_and_purchased_components_use_real_posted_amounts_in_one_output_cost(): void
    {
        $this->travelTo(now()->startOfSecond());
        $f = $this->fixture('none', '10', '10', false, true, '1800'); $receiver = $this->employee('mixed-cost-');
        $f['consumerWo']->update(['production_location_name' => '智能控制电箱总装工位']);
        $task = $this->consumerTask($f, $receiver);
        Item::whereKey($f['consumerWo']->output_item_id)->update(['is_stock_item' => true]);
        $stock = $this->warehouseStock($f, '10', '1800.0000');
        $issue = app(CuttingInventoryReservationService::class)->createIssue($stock['reservation_ids'][0],
            $this->payload(1) + ['quantity' => '10'], $f['user'], self::PERMISSIONS, true);
        $internal = app(ProductionInternalIssueService::class);
        $internal->dispatch($issue['internal_issue_task_id'], $this->payload(1), $f['user'], self::PERMISSIONS, true);
        $internal->receive($issue['internal_issue_task_id'], $this->payload(2), $receiver, self::PERMISSIONS, true);
        $purchased = $this->receivePurchasedComponents($f, $receiver);

        $bridges = DB::table('erp_production_input_holdings')->whereIn('target_material_requirement_id', $purchased['requirementIds'])
            ->orderBy('total_cost')->get();
        $this->assertSame(['100.0000', '200.0000', '300.0000', '600.0000'], $bridges->pluck('total_cost')->all());
        $this->assertSame('1200.0000', $bridges->reduce(fn ($sum, $row) => bcadd($sum, (string) $row->total_cost, 4), '0.0000'));
        $this->assertTrue($bridges->every(fn ($row) => $row->inventory_transaction_item_id && $row->material_receipt_line_id));
        $postedCosts = DB::table('erp_inventory_transaction_items')->where('transaction_id', $purchased['pick']->inventory_transaction_id)
            ->orderBy('cost_amount')->pluck('cost_amount')->all();
        $this->assertSame(['-600.0000', '-300.0000', '-200.0000', '-100.0000'], $postedCosts);

        $target = ProductionQuantityOperation::findOrFail($f['consumerOperation']);
        app(ProductionKittingService::class)->confirm($task->id, 'quantity_operation', $target->id,
            $this->payload($target->business_version), $receiver, $this->productionPermissions());
        $result = $this->complete($f, $receiver, $task);
        $output = DB::table('erp_production_output_records')->find($result['output_record_id']);
        $this->assertSame('3000.0000', $output->material_total_cost);
        $this->assertSame(5, DB::table('erp_production_material_consumptions')->where('output_record_id', $output->id)->count());
        $this->assertSame('1800.0000', DB::table('erp_production_material_consumptions')->where('output_record_id', $output->id)
            ->where('target_material_requirement_id', $f['targetRequirement'])->value('total_cost'));
        $this->assertSame('1200.0000', DB::table('erp_production_material_consumptions')->where('output_record_id', $output->id)
            ->whereIn('target_material_requirement_id', $purchased['requirementIds'])->sum('total_cost'));
    }

    public function test_loss_requires_explicit_conserved_cost_allocation_and_rolls_back_completion(): void
    {
        [$f, $receiver, $task] = $this->received();
        $target = ProductionQuantityOperation::findOrFail($f['consumerOperation']);
        try {
            app(ProductionExecutionActionService::class)->complete($task->id, 'quantity_operation', $target->id,
                $this->payload($target->business_version) + ['completed_base_qty' => '9', 'scrapped_base_qty' => '1', 'defect_reason' => '报废'],
                $receiver, $this->productionPermissions());
            $this->fail('未分配损失成本不应完工。');
        } catch (WorkOrderDomainException $e) { $this->assertSame('production_material_cost_allocation_required', $e->errorCode); }
        $this->assertSame('IN_PROGRESS', $target->fresh()->status);
        $this->assertSame(0, DB::table('erp_production_output_records')->where('work_order_id', $f['consumerWo']->id)->count());
        $this->assertSame('3000.0000', DB::table('erp_material_holdings')->where('position_type', 'PRODUCTION_WIP')->where('position_id', $f['targetRequirement'])->value('total_cost'));
        $this->complete($f, $receiver, $task, true);
    }

    public function test_real_http_worker_cannot_allocate_loss_amount_and_output_read_hides_costs_without_explicit_permission(): void
    {
        [$f, $receiver, $task] = $this->received();
        $token = $this->token($receiver);
        $role = DB::table('erp_rbac_user_roles')->where('user_legacy_id', $receiver->legacy_id)->value('role_id');
        $permission = DB::table('erp_rbac_permissions')->where('code', 'production.task.complete')->value('id');
        if (! $permission) $permission = DB::table('erp_rbac_permissions')->insertGetId(['code' => 'production.task.complete', 'name' => '完工', 'type' => 'button', 'enabled' => true, 'sort' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('erp_rbac_role_permissions')->insert(['role_id' => $role, 'permission_id' => $permission]);
        $target = ProductionQuantityOperation::findOrFail($f['consumerOperation']);
        $payload = $this->payload($target->business_version) + ['completed_base_qty' => '9', 'scrapped_base_qty' => '1', 'defect_reason' => '报废',
            'material_cost_allocation' => ['output_total_cost' => '2400', 'loss_total_cost' => '600']];
        $this->withToken($token)->postJson('/api/v1/erp/production/tasks/'.$task->id.'/targets/quantity_operation/'.$target->id.'/complete', $payload)->assertForbidden();
        $this->assertSame('IN_PROGRESS', $target->fresh()->status);
        $id = $this->complete($f, $receiver, $task)['output_record_id'];
        $this->withToken($token)->getJson('/api/v1/erp/production/outputs/'.$id)->assertOk()
            ->assertJsonMissingPath('data.material_total_cost')->assertJsonMissingPath('data.material_loss_cost')->assertJsonMissingPath('data.material_holding_id');
        $view = DB::table('erp_rbac_permissions')->where('code', 'production.output.cost.view')->value('id');
        if (! $view) $view = DB::table('erp_rbac_permissions')->insertGetId(['code' => 'production.output.cost.view', 'name' => '查看材料成本', 'type' => 'button', 'enabled' => true, 'sort' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('erp_rbac_role_permissions')->insert(['role_id' => $role, 'permission_id' => $view]);
        $this->withToken($token)->getJson('/api/v1/erp/production/outputs/'.$id)->assertOk()->assertJsonPath('data.material_total_cost', '3000.0000')->assertJsonMissingPath('data.material_holding_id');
    }

    public function test_receipt_failure_after_real_inventory_insert_restores_output_wip_amount_and_same_command_can_retry(): void
    {
        [$f, $receiver, $task] = $this->received();
        $id = $this->complete($f, $receiver, $task)['output_record_id'];
        $this->approve($f, $id);
        $output = DB::table('erp_production_output_records')->find($id);
        $payload = $this->payload($output->business_version) + ['warehouse_id' => $f['warehouse']->id, 'location_id' => $f['location']->id,
            'batch_no' => 'CUT-COST-ROLLBACK-'.Str::ulid(), 'posted_base_qty' => '4'];
        $injected = false;
        DB::listen(function ($query) use (&$injected, $output): void {
            if (! $injected && str_starts_with($query->sql, 'insert into `erp_inventory_transaction_items`')
                && in_array((int) $output->output_item_id, $query->bindings, true)) {
                $injected = true; throw new \RuntimeException('injected final material receipt failure');
            }
        });
        try {
            app(ProductionOutputService::class)->warehouse($id, $payload, $f['user'], $this->productionPermissions());
            $this->fail('注入故障应回滚。');
        } catch (\RuntimeException $e) { $this->assertSame('injected final material receipt failure', $e->getMessage()); }
        $this->assertTrue($injected);
        $holding = DB::table('erp_material_holdings')->find($output->material_holding_id);
        $this->assertSame('10.00000000', $holding->quantity); $this->assertSame('3000.0000', $holding->total_cost);
        $this->assertSame(0, InventoryBalance::where('batch_no', $payload['batch_no'])->count());
        $this->assertSame(0, DB::table('erp_production_execution_commands')->where('client_command_id', $payload['client_command_id'])->count());
        $posting = app(ProductionOutputService::class)->warehouse($id, $payload, $f['user'], $this->productionPermissions());
        $this->assertSame('1200.0000', DB::table('erp_inventory_transaction_items')->where('transaction_id', $posting['inventory_transaction_id'])->value('cost_amount'));
        $this->assertSame('1800.0000', DB::table('erp_material_holdings')->where('id', $holding->id)->value('total_cost'));
    }
}
