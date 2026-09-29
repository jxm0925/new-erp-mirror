<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\{PurchaseReceiptItem, SalesOrder, SalesOrderLine, SalesReturn, SalesReturnItem, SalesShipment};
use App\Services\Erp\PurchaseReceiptPostingRepairApplicationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WarehouseMobileHttpTest extends TestCase
{
    use DatabaseTransactions;
    use \Tests\Support\CuttingTestFixtures;

    private const INVENTORY = '/api/v1/erp/inventory/';
    private const PRODUCTION = '/api/v1/erp/production/';

    public function test_summary_list_upper_date_only_document_pages_and_locator_contract(): void
    {
        $f = $this->fixture(); [$receipt, $lines] = $this->pendingReceipt($f);
        $token = $this->httpToken($f['user'], ['inventory.post.view', 'inventory.post.repair', 'inventory.post.execute']);
        $this->withToken($token)->getJson(self::INVENTORY.'warehouse-workspace/summary')->assertOk()
            ->assertJsonStructure(['data' => ['today_inbound', 'today_outbound', 'types', 'todos']]);
        $this->withToken($token)->getJson(self::INVENTORY.'warehouse-workspace?'.http_build_query([
            'kind' => 'purchase_receipt', 'date_to' => now()->toDateString(), 'keyword' => $receipt->receipt_no,
            'page' => 1, 'per_page' => 1,
        ]))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('data.0.id', $receipt->id);
        $this->withToken($token)->getJson(self::INVENTORY.'warehouse-workspace?date_from=2026-09-26&date_to=2026-09-25')
            ->assertUnprocessable();
        $this->withToken($token)->getJson(self::INVENTORY.'warehouse-workspace/documents/purchase_receipt/'.$receipt->id.'?per_page=1&page=2')
            ->assertOk()->assertJsonPath('data.lines.total', 2)->assertJsonPath('data.lines.current_page', 2)
            ->assertJsonPath('data.lines.last_page', 2)->assertJsonCount(1, 'data.lines.data')
            ->assertJsonPath('data.lines.data.0.id', $lines[1]->id);
        $this->withToken($token)->getJson(self::INVENTORY.'warehouse-workspace/locators?'.http_build_query([
            'mode' => 'location', 'warehouse_id' => $f['warehouse']->id, 'keyword' => $f['location']->location_code, 'per_page' => 1,
        ]))->assertOk()->assertJsonPath('total', 1)->assertJsonPath('per_page', 1)
            ->assertJsonPath('data.0.id', $f['location']->id)->assertJsonPath('data.0.warehouse_id', $f['warehouse']->id);
    }

    public function test_http_purchase_command_success_replay_and_result_do_not_duplicate_posting(): void
    {
        $f = $this->fixture(); [$receipt, $lines] = $this->pendingReceipt($f);
        $allocation = array_map(fn ($line) => ['receipt_item_id' => $line->id, 'allocations' => [[
            'warehouse_id' => $f['warehouse']->id, 'location_id' => $f['location']->id, 'base_qty' => 2,
            'physical_entries' => [['dimensions' => ['length_mm' => 600, 'width_mm' => 400, 'thickness_mm' => 2]],
                ['dimensions' => ['length_mm' => 700, 'width_mm' => 450, 'thickness_mm' => 2]]],
        ]]], $lines);
        app(PurchaseReceiptPostingRepairApplicationService::class)->repair($receipt->id, $allocation, 'HTTP仓库测试');
        $token = $this->httpToken($f['user'], ['inventory.post.view', 'inventory.post.execute']);
        $command = ['action' => 'purchase.post', 'aggregate_id' => $receipt->id, 'client_command_id' => (string) Str::uuid(), 'payload' => []];
        $first = $this->withToken($token)->postJson(self::INVENTORY.'warehouse-commands', $command)->assertOk()
            ->assertJsonPath('data.action', 'purchase.post')->assertJsonPath('data.aggregate_id', $receipt->id)->json('data');
        $this->withToken($token)->postJson(self::INVENTORY.'warehouse-commands', $command)->assertOk()->assertJsonPath('data.result.id', $first['result']['id']);
        $this->withToken($token)->getJson(self::INVENTORY.'warehouse-commands/result?client_command_id='.$command['client_command_id'])
            ->assertOk()->assertJsonPath('data.status', 'SUCCEEDED')->assertJsonPath('data.response.result.id', $first['result']['id']);
        $this->assertSame(1, DB::table('erp_inventory_transactions')->where('source_type', 'purchase_receipt')->where('source_id', $receipt->id)->count());
        $this->assertSame('posted', $receipt->fresh()->stock_post_status);
    }

    public function test_http_permission_denial_and_foreign_sales_scope_do_not_expose_documents(): void
    {
        $f = $this->fixture();
        $denied = $this->httpToken($this->employee('http-denied-'), []);
        $this->withToken($denied)->getJson(self::INVENTORY.'warehouse-workspace/summary')->assertForbidden();
        $this->withToken($denied)->getJson(self::INVENTORY.'warehouse-workspace')->assertForbidden();
        $this->withToken($denied)->postJson(self::INVENTORY.'warehouse-commands', [
            'action' => 'purchase.post', 'aggregate_id' => 1, 'client_command_id' => (string) Str::uuid(), 'payload' => [],
        ])->assertForbidden();
        $order = SalesOrder::create(['sales_order_no' => 'HTTP-SO-'.Str::ulid(), 'customer_name' => '不可跨范围查看',
            'sales_user_legacy_id' => $f['user']->legacy_id, 'created_by_legacy_id' => $f['user']->legacy_id,
            'order_status' => 'confirmed', 'confirm_status' => 'confirmed']);
        $shipment = SalesShipment::create(['shipment_no' => 'HTTP-SHP-'.Str::ulid(), 'sales_order_id' => $order->id, 'shipment_status' => 'pending_outbound']);
        $outsider = $this->httpToken($this->employee('http-outsider-'), ['sales_order.shipment.view'], 'self');
        $this->withToken($outsider)->getJson(self::INVENTORY.'warehouse-workspace?'.http_build_query(['kind' => 'sales_shipment', 'keyword' => $shipment->shipment_no]))
            ->assertOk()->assertJsonPath('meta.total', 0)->assertJsonCount(0, 'data');
        $this->withToken($outsider)->getJson(self::INVENTORY.'warehouse-workspace/documents/sales_shipment/'.$shipment->id)
            ->assertNotFound()->assertJsonMissing(['shipment_no' => $shipment->shipment_no]);
    }

    public function test_http_domain_rejection_returns_recoverable_validation_status_instead_of_server_error(): void
    {
        $f = $this->fixture(); [$receipt] = $this->pendingReceipt($f);
        $token = $this->httpToken($f['user'], ['inventory.post.repair']);
        $id = (string) Str::uuid();
        $this->withToken($token)->postJson(self::INVENTORY.'warehouse-commands', [
            'action' => 'purchase.allocate', 'aggregate_id' => $receipt->id, 'client_command_id' => $id, 'payload' => [],
        ])->assertUnprocessable()->assertJsonPath('error_code', 'validation_error');
        $this->withToken($token)->getJson(self::INVENTORY.'warehouse-commands/result?client_command_id='.$id)
            ->assertOk()->assertJsonPath('data.status', 'FAILED')->assertJsonPath('data.response.status', 422);
        $this->assertSame(0, DB::table('erp_inventory_transactions')->where('source_type', 'purchase_receipt')->where('source_id', $receipt->id)->count());
    }

    public function test_http_picking_and_delivery_detail_line_page_meta_matches_returned_slice(): void
    {
        $f = $this->fixture(); [$pick, $delivery, $pickLines, $deliveryLines] = $this->materialReadFixture($f);
        $token = $this->httpToken($f['user'], ['production.material_picking.view', 'production.material_delivery.view']);
        foreach ([['material-picking-tasks', $pick, $pickLines], ['material-deliveries', $delivery, $deliveryLines]] as [$route, $id, $lines]) {
            $this->withToken($token)->getJson(self::PRODUCTION.$route.'/'.$id.'?line_page=2&line_per_page=1')
                ->assertOk()->assertJsonPath('data.line_meta.total', 2)->assertJsonPath('data.line_meta.current_page', 2)
                ->assertJsonPath('data.line_meta.per_page', 1)->assertJsonPath('data.line_meta.last_page', 2)
                ->assertJsonCount(1, 'data.lines')->assertJsonPath('data.lines.0.id', $lines[1]);
            $this->withToken($token)->getJson(self::PRODUCTION.$route.'/'.$id.'?line_page=0&line_per_page=1')->assertUnprocessable();
        }
    }

    public function test_sales_return_serial_candidates_require_both_permissions_and_current_order_scope(): void
    {
        [$f, $order, $return, $line] = $this->returnReadFixture();
        $url = self::INVENTORY.'warehouse-workspace/sales-return-serials?'.http_build_query([
            'return_id' => $return->id, 'return_item_id' => $line->id,
        ]);
        foreach ([[], ['sales_return.view'], ['sales_return.receive']] as $permissions) {
            $token = $this->httpToken($this->employee('serial-http-denied-'), $permissions);
            $this->withToken($token)->getJson($url)->assertForbidden()->assertJsonMissing(['return_no' => $return->return_no]);
        }
        $permissions = ['sales_return.view', 'sales_return.receive'];
        $outsider = $this->employee('serial-http-outsider-');
        $this->withToken($this->httpToken($outsider, $permissions, 'self'))->getJson($url)->assertNotFound();
        $ownerToken = $this->httpToken($f['user'], $permissions, 'self');
        $this->withToken($ownerToken)->getJson($url)->assertOk()->assertJsonPath('total', 0)->assertJsonCount(0, 'data');
        $order->update(['sales_user_legacy_id' => $outsider->legacy_id]);
        $this->withToken($ownerToken)->getJson($url)->assertNotFound();
    }

    public function test_sales_return_serial_candidate_line_ownership_and_empty_pagination_contract(): void
    {
        [$f, , $return, $line] = $this->returnReadFixture();
        $token = $this->httpToken($f['user'], ['sales_return.view', 'sales_return.receive'], 'self');
        $url = self::INVENTORY.'warehouse-workspace/sales-return-serials';
        $parameters = ['return_id' => $return->id, 'return_item_id' => $line->id, 'page' => 2, 'per_page' => 1];
        $this->withToken($token)->getJson($url.'?'.http_build_query($parameters))->assertOk()
            ->assertJsonPath('total', 0)->assertJsonPath('current_page', 2)->assertJsonPath('per_page', 1)
            ->assertJsonPath('last_page', 1)->assertJsonCount(0, 'data');
        $foreignReturn = $return->replicate(); $foreignReturn->return_no = 'HTTP-SR-'.Str::ulid(); $foreignReturn->save();
        $foreignLine = $line->replicate(); $foreignLine->sales_return_id = $foreignReturn->id; $foreignLine->save();
        $this->withToken($token)->getJson($url.'?'.http_build_query(array_replace($parameters, ['return_item_id' => $foreignLine->id])))
            ->assertNotFound();
        $this->withToken($token)->getJson($url.'?'.http_build_query($parameters + ['ids' => [2147483646, 2147483647]]))
            ->assertOk()->assertJsonPath('total', 0)->assertJsonCount(0, 'data');
        // A JSON GET preserves an explicitly empty array; query-string encoding drops empty arrays.
        $this->withToken($token)->json('GET', $url, $parameters + ['ids' => []])->assertOk()
            ->assertJsonPath('total', 0)->assertJsonPath('current_page', 2)->assertJsonCount(0, 'data');
    }

    public function test_sales_return_serial_candidate_ids_and_pagination_are_validated_before_querying(): void
    {
        [$f, , $return, $line] = $this->returnReadFixture();
        $token = $this->httpToken($f['user'], ['sales_return.view', 'sales_return.receive'], 'self');
        $url = self::INVENTORY.'warehouse-workspace/sales-return-serials';
        $parameters = ['return_id' => $return->id, 'return_item_id' => $line->id];
        foreach (['1', [0], [-1], ['invalid'], [1, 1], range(1, 1001)] as $ids) {
            $this->withToken($token)->json('GET', $url, $parameters + ['ids' => $ids])->assertUnprocessable();
        }
        foreach ([['return_id' => 0], ['return_item_id' => 0], ['page' => 0], ['per_page' => 101]] as $invalid) {
            $this->withToken($token)->getJson($url.'?'.http_build_query(array_replace($parameters, $invalid)))->assertUnprocessable();
        }
        $this->withToken($token)->getJson($url)->assertUnprocessable();
    }

    private function returnReadFixture(): array
    {
        $f = $this->fixture();
        $order = SalesOrder::create(['sales_order_no' => 'HTTP-SR-SO-'.Str::ulid(), 'customer_name' => '销售退货候选权限客户',
            'sales_user_legacy_id' => $f['user']->legacy_id, 'created_by_legacy_id' => $f['user']->legacy_id,
            'order_status' => 'confirmed', 'confirm_status' => 'confirmed', 'shipment_status' => 'shipped']);
        $orderLine = SalesOrderLine::create(['sales_order_id' => $order->id, 'line_no' => 1, 'item_id' => $f['output']->id,
            'item_name' => $f['output']->item_name, 'line_type' => 'physical', 'order_qty' => 2, 'shipped_qty' => 2,
            'fulfillment_factor_snapshot' => 1, 'item_base_unit_id' => $f['output']->unit_id, 'item_base_required_qty' => 2,
            'item_snapshot' => ['id' => $f['output']->id], 'fulfillment_type' => 'inventory']);
        $return = SalesReturn::create(['return_no' => 'HTTP-SR-'.Str::ulid(), 'sales_order_id' => $order->id,
            'return_date' => now()->toDateString(), 'return_status' => 'pending_receipt', 'return_reason' => '候选读取契约']);
        $line = SalesReturnItem::create(['sales_return_id' => $return->id, 'sales_order_line_id' => $orderLine->id,
            'item_id' => $f['output']->id, 'base_unit_id' => $f['output']->unit_id, 'requested_sales_qty' => 2,
            'requested_base_qty' => 2, 'fulfillment_snapshot' => ['item_id' => $f['output']->id]]);
        return [$f, $order, $return, $line];
    }

    private function pendingReceipt(array $f): array
    {
        $source = PurchaseReceiptItem::where('item_id', $f['raw']->id)->firstOrFail();
        $receipt = $source->receipt->replicate();
        $receipt->fill(['receipt_no' => 'HTTP-PRC-'.Str::ulid(), 'stock_post_status' => 'pending', 'total_receipt_qty' => 4,
            'total_qualified_qty' => 4, 'total_amount' => 12000]); $receipt->save();
        $lines = [];
        for ($i = 0; $i < 2; $i++) {
            $line = $source->replicate(); $line->fill(['receipt_id' => $receipt->id, 'warehouse_id' => null, 'location_id' => null,
                'inventory_posting_status' => 'pending', 'inventory_posting_log_id' => null, 'batch_no' => 'HTTP-BATCH-'.Str::ulid()]);
            $line->save(); $lines[] = $line;
        }
        return [$receipt, $lines];
    }

    private function materialReadFixture(array $f): array
    {
        $common = ['created_at' => now(), 'updated_at' => now()];
        $pick = DB::table('erp_material_picking_tasks')->insertGetId($common + ['task_no' => 'HTTP-PICK-'.Str::ulid(),
            'work_order_id' => $f['wo']->id, 'warehouse_id' => $f['warehouse']->id,
            'production_location_name_snapshot' => 'HTTP装配区', 'status' => 'WAIT_PICK', 'business_version' => 1]);
        $delivery = DB::table('erp_material_deliveries')->insertGetId($common + ['delivery_no' => 'HTTP-DEL-'.Str::ulid(),
            'work_order_id' => $f['wo']->id, 'picking_task_id' => $pick, 'from_warehouse_id' => $f['warehouse']->id,
            'to_production_location_snapshot' => 'HTTP装配区', 'status' => 'READY', 'business_version' => 1,
            'production_target_type' => 'quantity_operation', 'production_target_id' => $f['producerOperation'],
            'expected_receiver_legacy_id' => $f['user']->legacy_id]);
        $pickLines = []; $deliveryLines = [];
        for ($i = 0; $i < 2; $i++) {
            $line = DB::table('erp_material_picking_task_lines')->insertGetId($common + ['task_id' => $pick,
                'material_requirement_id' => $f['inputRequirement']->id, 'component_item_id' => $f['raw']->id,
                'required_qty_snapshot' => 2, 'planned_pick_qty' => 1, 'inventory_balance_id' => $f['balance']->id,
                'warehouse_id' => $f['warehouse']->id, 'location_id' => $f['location']->id,
                'batch_no' => $f['balance']->batch_no, 'unit_id' => $f['raw']->unit_id]);
            $pickLines[] = $line;
            $deliveryLines[] = DB::table('erp_material_delivery_lines')->insertGetId($common + ['delivery_id' => $delivery,
                'material_requirement_id' => $f['inputRequirement']->id, 'picking_task_line_id' => $line,
                'component_item_id' => $f['raw']->id, 'delivery_qty' => 1, 'unit_id' => $f['raw']->unit_id,
                'batch_no' => $f['balance']->batch_no]);
        }
        return [$pick, $delivery, $pickLines, $deliveryLines];
    }

    private function httpToken(object $user, array $permissions, string $scope = 'all'): string
    {
        $role = DB::table('erp_rbac_roles')->insertGetId(['code' => 'warehouse-http-'.Str::uuid(), 'name' => '仓库HTTP测试',
            'data_scope' => $scope, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($permissions as $code) {
            $permission = DB::table('erp_rbac_permissions')->where('code', $code)->value('id');
            if (! $permission) $permission = DB::table('erp_rbac_permissions')->insertGetId(['code' => $code, 'name' => $code,
                'type' => 'button', 'enabled' => true, 'sort' => 1, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('erp_rbac_role_permissions')->insert(['role_id' => $role, 'permission_id' => $permission]);
        }
        DB::table('erp_rbac_user_roles')->insert(['user_legacy_id' => $user->legacy_id, 'role_id' => $role]);
        $token = Str::random(64);
        DB::table('erp_auth_tokens')->insert(['user_legacy_id' => $user->legacy_id, 'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        return $token;
    }
}
