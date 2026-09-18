<?php

namespace Tests\Feature\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{InventoryBalance, Item, ProductionQuantityOperation, SalesOrder, SalesOrderFulfillment, SalesOrderLine};
use App\Services\Erp\{CuttingInventoryReservationService, ProductionExecutionActionService, ProductionInternalIssueService,
    ProductionKittingService, ProductionOutputService, WorkOrderCompletionService, InventoryReservationService, SalesShipmentApplicationService};
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
            'production.completion.review', 'production.output.warehouse', 'production.output.cost.allocate', 'production.output.cost.view']);
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
