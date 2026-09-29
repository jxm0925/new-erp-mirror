<?php

namespace Tests\Feature\Erp;

use App\Services\Erp\{ProductionMaterialExecutionService, ProductionPickingWorkspaceService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WarehousePhysicalPickingTest extends TestCase
{
    use DatabaseTransactions;
    use \Tests\Support\CuttingTestFixtures;

    public function test_explicit_physical_selection_picks_that_piece_and_rejects_a_foreign_piece_atomically(): void
    {
        $f = $this->fixture(publish: false);
        $other = $this->fixture(publish: false);
        $permissions = ['production.material_picking.view', 'production.material_picking.create', 'production.material_picking.assign', 'production.material_picking.pick'];
        $service = app(ProductionMaterialExecutionService::class);
        $demand = DB::table('erp_production_target_material_requirements')->where('material_requirement_id', $f['inputRequirement']->id)->value('id');
        $task = $service->createPickingTask(['client_command_id' => (string) Str::uuid(), 'expected_version' => 1, 'work_order_id' => $f['wo']->id,
            'warehouse_id' => $f['warehouse']->id, 'lines' => [['target_material_requirement_id' => $demand,
                'inventory_balance_id' => $f['balance']->id, 'planned_pick_qty' => 1]]], $f['user'], $permissions, true);
        $task = $service->assignPickingTask($task->id, ['client_command_id' => (string) Str::uuid(), 'expected_version' => $task->business_version,
            'assigned_picker_legacy_id' => $f['user']->legacy_id], $f['user'], $permissions, true);
        $task = $service->startPickingTask($task->id, ['client_command_id' => (string) Str::uuid(), 'expected_version' => $task->business_version], $f['user'], $permissions, true);
        $filters = ['picking_task_id' => $task->id, 'picking_task_line_id' => $task->lines->first()->id, 'per_page' => 1];
        $page = app(ProductionPickingWorkspaceService::class)->physicals($filters, $f['user'], $permissions, true);
        $this->assertSame(2, $page->total()); $this->assertCount(1, $page->items());
        $this->assertSame($f['physicals'][0], (int) $page->items()[0]->id);
        $this->assertIsArray($page->items()[0]->dimensions);
        $payload = ['client_command_id' => (string) Str::uuid(), 'expected_version' => $task->business_version,
            'lines' => [['picking_task_line_id' => $task->lines->first()->id, 'actual_pick_qty' => 1, 'physical_material_ids' => [$other['physicals'][0]]]]];
        try { $service->confirmPickingTask($task->id, $payload, $f['user'], $permissions, true); $this->fail('Foreign stock must not be substituted.'); }
        catch (ValidationException $error) { $this->assertArrayHasKey('physical_material_id', $error->errors()); }
        $this->assertSame('PICKING', $task->fresh()->status);
        $this->assertEquals(2, $f['balance']->fresh()->quantity_on_hand);
        $payload['client_command_id'] = (string) Str::uuid();
        $payload['lines'][0]['physical_material_ids'] = [$f['physicals'][1]];
        $picked = $service->confirmPickingTask($task->id, $payload, $f['user'], $permissions, true);
        $this->assertSame([$f['physicals'][1]], $picked->lines->first()->serial_snapshot['physical_material_ids']);
        $this->assertSame('AVAILABLE', DB::table('erp_material_physicals')->where('id', $f['physicals'][0])->value('status'));
        $this->assertNotSame('AVAILABLE', DB::table('erp_material_physicals')->where('id', $f['physicals'][1])->value('status'));
        $this->assertEquals(1, $f['balance']->fresh()->quantity_on_hand);
        $this->assertSame($picked->id, $service->confirmPickingTask($task->id, $payload, $f['user'], $permissions, true)->id);
        $this->assertEquals(1, $f['balance']->fresh()->quantity_on_hand);
    }
}
