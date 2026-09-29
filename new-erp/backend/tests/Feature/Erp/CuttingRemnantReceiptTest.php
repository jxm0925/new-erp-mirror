<?php

namespace Tests\Feature\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Services\Erp\{CuttingConfirmationService, CuttingInputService, CuttingReadService, CuttingRecordService, CuttingRemnantReceiptService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CuttingRemnantReceiptTest extends TestCase
{
    use DatabaseTransactions;
    use \Tests\Support\CuttingTestFixtures;

    private function remnants(string $mode = 'sheet'): array
    {
        $f = $this->fixture(rawMode: $mode);
        DB::table('erp_units')->where('id', $f['raw']->unit_id)->update(['unit_type' => 'quantity']);
        $batch = $mode === 'sheet' ? $this->issue($f) : app(CuttingInputService::class)->issue($f['order'], $this->payload(1) +
            ['inventory_balance_id' => $f['balance']->id, 'input_qty' => '1'], $f['user'], self::PERMISSIONS, true);
        $batchId = $batch['settlement_batch_id'];
        $measurements = $mode === 'sheet' ? ['shape' => 'RECTANGLE', 'length_mm' => '400', 'width_mm' => '1000', 'thickness_mm' => '2'] : ['length_mm' => '400'];
        $saved = app(CuttingRecordService::class)->saveResults($batchId, $this->payload(1) + ['results' => [
            ['client_row_id' => 'product', 'result_type' => 'product', 'allowed_output_id' => $f['allowed'], 'actual_qty' => '6'],
            ['client_row_id' => 'remnant-a', 'result_type' => 'usable_remnant', 'actual_qty' => '1', 'measurement_status' => 'MEASURED', 'measurements' => $measurements],
            ['client_row_id' => 'remnant-b', 'result_type' => 'usable_remnant', 'actual_qty' => '1', 'measurement_status' => 'MEASURED', 'measurements' => $measurements],
        ]], $f['user'], self::PERMISSIONS, true);
        [$product, $one, $two] = $saved['result_ids'];
        $route = $this->route($f, $product, '6')['routes'][0]['id'];
        $this->submitBatch($f, $batchId);
        app(CuttingConfirmationService::class)->confirm($batchId, $this->payload((int) DB::table('erp_cutting_settlement_batches')->where('id', $batchId)->value('business_version')) + [
            'costs' => [['result_id' => $product, 'total_cost' => '1800'], ['result_id' => $one, 'total_cost' => '700.1234'], ['result_id' => $two, 'total_cost' => '499.8766']],
            'allocations' => [['route_id' => $route, 'plan_id' => DB::table('erp_cutting_allowed_outputs')->where('id', $f['allowed'])->value('plan_id'), 'quantity' => '6', 'disposition' => 'PLAN']],
        ], $f['user'], self::PERMISSIONS, true);
        $rows = app(CuttingRemnantReceiptService::class)->paginate($f['order'], ['status' => 'PENDING'], $f['user'], self::PERMISSIONS, true)['data'];
        $payload = ['client_command_id' => (string) Str::uuid(), 'warehouse_id' => $f['warehouse']->id, 'location_id' => $f['location']->id, 'remark' => '余料实收入库',
            'lines' => array_map(fn ($r) => ['result_id' => $r['id'], 'expected_version' => $r['business_version'], 'holding_version' => $r['holding_version'], 'physical_version' => $r['physical_version']], $rows)];
        return $f + compact('payload', 'rows', 'batchId');
    }

    public function test_closed_order_receives_two_remnants_once_with_exact_cost_and_identity(): void
    {
        $f = $this->remnants(); $s = app(CuttingRemnantReceiptService::class);
        DB::table('erp_cutting_orders')->where('id', $f['order'])->update(['status' => 'CLOSED']);
        $before = DB::table('erp_inventory_transactions')->count();
        $response = $s->post($f['order'], $f['payload'], $f['user'], self::PERMISSIONS, true);
        $this->assertSame('1200.0000', $response['posted_cost']); $this->assertSame(2, $response['piece_count']);
        $this->assertSame($response, $s->post($f['order'], $f['payload'], $f['user'], self::PERMISSIONS, true));
        $this->assertSame($before + 1, DB::table('erp_inventory_transactions')->count());
        $detail = $s->show($f['order'], $response['receipt_id'], $f['user'], self::PERMISSIONS, true);
        $this->assertCount(2, $detail['lines']); $this->assertSame('余料实收入库', $detail['remark']);
        foreach ($detail['lines'] as $line) {
            $physical = DB::table('erp_material_physicals')->where('id', $line['physical_material_id'])->first();
            $balance = DB::table('erp_inventory_balances')->where('id', $line['inventory_balance_id'])->first();
            $this->assertSame($line['batch_no'], $physical->physical_no);
            $this->assertSame((int) $line['warehouse_holding_id'], (int) $physical->current_holding_id);
            $this->assertSame(0, bccomp('1', $balance->quantity_on_hand, 8));
            $this->assertSame($line['posted_cost'], $balance->inventory_value);
            $this->assertSame($line['posted_cost'], $physical->total_cost);
            $this->assertSame('CONSUMED', DB::table('erp_material_holdings')->where('id', $line['source_holding_id'])->value('status'));
        }
        $this->assertSame('CLOSED', DB::table('erp_cutting_orders')->where('id', $f['order'])->value('status'));
        $this->assertSame(0, $s->paginate($f['order'], ['status' => 'PENDING'], $f['user'], self::PERMISSIONS, true)['meta']['total']);
        $posted = $s->paginate($f['order'], ['status' => 'POSTED', 'per_page' => 1, 'page' => 2], $f['user'], self::PERMISSIONS, true);
        $this->assertCount(1, $posted['data']); $this->assertSame(2, $posted['meta']['total']);
        $this->assertFalse($posted['data'][0]['receivable']);
        $this->assertSame('499.8766', $posted['data'][0]['total_cost']);
        $this->assertEquals($response, $s->commandResult($f['order'], $f['payload']['client_command_id'], $f['user'], self::PERMISSIONS, true)['result']);
        $this->assertSame('NOT_FOUND', $s->commandResult($f['order'], (string) Str::uuid(), $f['user'], self::PERMISSIONS, true)['status']);
        $again = $f['payload']; $again['client_command_id'] = (string) Str::uuid();
        $this->assertRejected(fn () => $s->post($f['order'], $again, $f['user'], self::PERMISSIONS, true), 409);
    }

    public function test_warehouse_inbox_groups_pending_pieces_and_counts_posted_receipt_once(): void
    {
        $f = $this->remnants();
        $workspace = app(\App\Services\Erp\WarehouseWorkspaceService::class);
        $pending = $workspace->paginate(['kind' => 'remnant', 'keyword' => $f['raw']->item_code], $f['user'], self::PERMISSIONS, true);
        $row = collect($pending['data'])->firstWhere('id', $f['order']);
        $this->assertNotNull($row); $this->assertEquals(2, $row['quantity']);
        $before = $workspace->summary($f['user'], self::PERMISSIONS, true)['today_inbound'];
        $result = app(CuttingRemnantReceiptService::class)->post($f['order'], $f['payload'], $f['user'], self::PERMISSIONS, true);
        $this->assertSame($before + 1, $workspace->summary($f['user'], self::PERMISSIONS, true)['today_inbound']);
        $this->assertSame(0, $workspace->paginate(['kind' => 'remnant', 'keyword' => $f['raw']->item_code], $f['user'], self::PERMISSIONS, true)['meta']['total']);
        $posted = $workspace->paginate(['kind' => 'remnant', 'status_group' => 'completed', 'keyword' => $result['receipt_no']], $f['user'], self::PERMISSIONS, true);
        $this->assertSame(1, $posted['meta']['total']); $this->assertSame((int) $f['order'], $posted['data'][0]['parent_id']);
        DB::table('erp_cutting_remnant_receipts')->where('id', $result['receipt_id'])->update(['posted_at' => now()->subDay(), 'updated_at' => now()]);
        $this->assertSame($before, $workspace->summary($f['user'], self::PERMISSIONS, true)['today_inbound']);
    }

    public function test_mixed_stale_selection_rolls_back_entire_receipt(): void
    {
        $f = $this->remnants(); $before = DB::table('erp_inventory_transactions')->count();
        DB::table('erp_material_holdings')->where('id', $f['rows'][1]['source_holding_id'])->increment('business_version');
        $this->assertRejected(fn () => app(CuttingRemnantReceiptService::class)->post($f['order'], $f['payload'], $f['user'], self::PERMISSIONS, true), 409);
        $this->assertSame($before, DB::table('erp_inventory_transactions')->count());
        $this->assertSame(0, DB::table('erp_cutting_remnant_receipts')->where('cutting_order_id', $f['order'])->count());
        $this->assertSame('ACTIVE', DB::table('erp_material_holdings')->where('id', $f['rows'][0]['source_holding_id'])->value('status'));
    }

    public function test_permission_scope_foreign_row_locator_and_unknown_unit_are_rejected(): void
    {
        $f = $this->remnants(); $s = app(CuttingRemnantReceiptService::class);
        $this->assertRejected(fn () => $s->post($f['order'], $f['payload'], $f['user'], [], true), 403);
        $this->assertRejected(fn () => $s->post($f['order'], $f['payload'], $this->employee('outsider'), self::PERMISSIONS, false), 403);
        $bad = $f['payload']; $bad['lines'][0]['result_id'] = PHP_INT_MAX;
        $this->assertRejected(fn () => $s->post($f['order'], $bad, $f['user'], self::PERMISSIONS, true), 409);
        $bad = $f['payload']; $bad['location_id'] = PHP_INT_MAX;
        $this->assertRejected(fn () => $s->post($f['order'], $bad, $f['user'], self::PERMISSIONS, true), 422);
        DB::table('erp_units')->where('id', $f['raw']->unit_id)->update(['unit_type' => 'weight']);
        $this->assertRejected(fn () => $s->post($f['order'], $f['payload'], $f['user'], self::PERMISSIONS, true), 422);
        $this->assertFalse($s->paginate($f['order'], [], $f['user'], self::PERMISSIONS, true)['data'][0]['receivable']);
    }

    public function test_http_pagination_filters_detail_and_recovery_follow_same_authorization(): void
    {
        $f = $this->remnants(); $token = $this->token($f['user']); $base = '/api/v1/erp/production/cutting/orders/'.$f['order'];
        $this->withToken($token)->getJson($base.'/remnants?status=PENDING&per_page=1&page=2')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data');
        $this->withToken($token)->getJson($base.'/remnants?status=bogus')->assertUnprocessable();
        $post = $this->withToken($token)->postJson($base.'/remnant-receipts', $f['payload'])->assertCreated()->json('data');
        $this->withToken($token)->getJson($base.'/remnant-receipts/'.$post['receipt_id'])->assertOk()->assertJsonCount(2, 'data.lines');
        $this->withToken($token)->getJson($base.'/remnant-receipt-command?client_command_id='.$f['payload']['client_command_id'])->assertOk()->assertJsonPath('data.status', 'SUCCEEDED');
        $this->withToken($this->token($this->employee('deny'), 'none'))->getJson($base.'/remnants')->assertForbidden();
    }

    public function test_warehoused_sheet_recut_posts_outbound_instead_of_workshop_only_movement(): void
    {
        $f = $this->remnants(); $s = app(CuttingRemnantReceiptService::class);
        $response = $s->post($f['order'], $f['payload'], $f['user'], self::PERMISSIONS, true);
        $line = $s->show($f['order'], $response['receipt_id'], $f['user'], self::PERMISSIONS, true)['lines'][0];
        $input = app(CuttingInputService::class);
        $version = (int) DB::table('erp_cutting_orders')->where('id', $f['order'])->value('business_version');
        $reserved = $input->reserve($f['order'], $this->payload($version) + ['physical_material_ids' => [$line['physical_material_id']]], $f['user'], self::PERMISSIONS, true);
        $issue = $input->issue($f['order'], $this->payload($reserved['business_version']) + ['physical_material_id' => $line['physical_material_id']], $f['user'], self::PERMISSIONS, true);
        $this->assertSame($line['posted_cost'], $issue['original_total_cost']);
        $this->assertNotNull(DB::table('erp_cutting_settlement_batches')->where('id', $issue['settlement_batch_id'])->value('issue_transaction_id'));
        $this->assertSame(0, bccomp('0', DB::table('erp_inventory_balances')->where('id', $line['inventory_balance_id'])->value('quantity_on_hand'), 8));
    }

    public function test_length_remnant_keeps_actual_length_when_received_and_reissued(): void
    {
        $f = $this->remnants('length'); $s = app(CuttingRemnantReceiptService::class);
        $response = $s->post($f['order'], $f['payload'], $f['user'], self::PERMISSIONS, true);
        $line = $s->show($f['order'], $response['receipt_id'], $f['user'], self::PERMISSIONS, true)['lines'][0];
        $candidate = app(CuttingReadService::class)->inputCandidates($f['order'], ['input_type' => 'quantity', 'keyword' => $line['batch_no']], $f['user'], self::PERMISSIONS, true)['data'][0];
        $this->assertEquals(400, $candidate['standard_stock_length_mm']);
        $version = (int) DB::table('erp_cutting_orders')->where('id', $f['order'])->value('business_version');
        $issue = app(CuttingInputService::class)->issue($f['order'], $this->payload($version) + ['inventory_balance_id' => $line['inventory_balance_id'], 'input_qty' => '1'], $f['user'], self::PERMISSIONS, true);
        $batch = DB::table('erp_cutting_settlement_batches')->where('id', $issue['settlement_batch_id'])->first();
        $this->assertEquals(400, $batch->standard_stock_length_mm); $this->assertNotNull($batch->issue_transaction_id);
        $this->assertSame($line['posted_cost'], $issue['original_total_cost']);
    }

    private function assertRejected(callable $call, int $status): void
    {
        try { $call(); $this->fail('Expected domain rejection'); }
        catch (WorkOrderDomainException $e) { $this->assertSame($status, $e->status); }
    }
}
