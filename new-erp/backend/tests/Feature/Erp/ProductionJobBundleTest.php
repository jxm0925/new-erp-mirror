<?php

namespace Tests\Feature\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{Bom, BomItem, Item, ProductionJobBundle, ProductionLaborSession, ProductionOperation, ProductionQuantityOperation, ProductionRouting, ProductionRoutingOperation, ProductionTask, ProductionUnitOperation, Unit, WorkOrder};
use App\Services\Erp\{ProductionExecutionActionService, ProductionJobBundleApplicationService, ProductionJobBundleQueryService, ProductionKittingService, ProductionMasterDataService, ProductionReportService, ProductionTaskAssignmentService, ProductionTaskQueryService, RbacBootstrapService, ReleaseGateApplicationService, WorkOrderApplicationService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductionJobBundleTest extends TestCase
{
    use DatabaseTransactions;

    private const PERMISSIONS = ['production.task.view', 'production.task.claim', 'production.task.start', 'production.task.pause',
        'production.task.resume', 'production.task.complete', 'production.report.create', 'production.work_order.view',
        'production.work_order.edit', 'production.work_order.create', 'production.work_order.submit', 'production.work_order.publish',
        'production.work_order.gate.view', 'production.kitting.confirm', 'production.kitting.view'];
    private object $manager;
    private object $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        app(RbacBootstrapService::class)->bootstrap();
        $this->manager = $this->actor('all', self::PERMISSIONS);
        $this->worker = $this->actor('self', array_values(array_diff(self::PERMISSIONS, ['production.work_order.edit'])));
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_one_device_component_and_two_stock_units_publish_real_tasks_and_share_one_clock(): void
    {
        $f = $this->fixture('unit', 1, 2);
        $this->assertCount(3, $f['tasks']);
        $this->assertSame(3, DB::table('erp_production_units')->whereIn('work_order_id', $f['orders']->pluck('id'))->count());
        $this->assertTrue($f['tasks']->every(fn ($t) => $t->targets()->count() === 1));
        $bundle = $this->create($f['tasks']);
        $bundle = $this->action('claim', $bundle);
        foreach ($bundle['lines'] as $line) {
            $this->assertSame('unit_operation', $line['target']['type']);
            $this->assertSame('套', $line['unit']['base_unit_name']);
            $this->confirmKitting($line);
        }
        $this->assertSame(0, ProductionLaborSession::whereIn('task_id', $f['tasks']->pluck('id'))->count());
        $bundle = $this->action('start', $bundle);
        $this->assertCount(3, $bundle['lines']);
        $this->assertSame(1, ProductionLaborSession::where('job_bundle_id', $bundle['id'])->where('status', 'ACTIVE')->count());
        $this->assertSame(0, ProductionLaborSession::whereIn('task_id', $f['tasks']->pluck('id'))->count());
        $this->travel(12)->minutes();
        foreach ($bundle['lines'] as $line) {
            $bundle = app(ProductionJobBundleApplicationService::class)->complete($bundle['id'], $line['id'],
                $this->command($bundle) + ['expected_target_version' => $line['target']['business_version']], $this->worker, $this->workerPermissions());
        }
        $this->assertSame('PAUSED', $bundle['status']);
        $this->assertSame(0, ProductionLaborSession::where('job_bundle_id', $bundle['id'])->where('status', 'ACTIVE')->count());
        $this->assertSame(3, DB::table('erp_production_output_records')->whereIn('work_order_id', $f['orders']->pluck('id'))->count());
        $this->assertSame(12.0, (float) $bundle['actual_labor_minutes']);
        $this->assertSame(12.0, (float) collect($bundle['lines'])->sum('allocated_labor_minutes'));
        $this->assertSame(12.0, (float) ProductionLaborSession::where('job_bundle_id', $bundle['id'])->sum('actual_labor_minutes'));
        $this->travel(5)->minutes();
        $bundle = $this->action('finish', $bundle);
        $this->assertSame('COMPLETED', $bundle['status']);
        $this->assertSame(12.0, (float) $bundle['actual_labor_minutes']);
        $this->assertSame(0, ProductionTask::whereIn('id', $f['tasks']->pluck('id'))->whereNotNull('active_job_bundle_id')->count());
        $this->assertSame(0, DB::table('erp_production_job_bundle_lines')->where('job_bundle_id', $bundle['id'])->whereNotNull('active_task_id')->count());
        $this->assertSame(0, DB::table('erp_production_reports')->whereIn('work_order_id', $f['orders']->pluck('id'))->count());
        $this->travelBack();
    }

    public function test_reports_remain_per_work_order_and_original_quality_and_destination_are_retained(): void
    {
        $f = $this->fixture('quantity', 2, 4, true);
        $bundle = $this->create($f['tasks']);
        $bundle = $this->action('claim', $bundle);
        foreach ($bundle['lines'] as $line) $this->confirmKitting($line);
        $bundle = $this->action('start', $bundle);
        $this->travel(10)->minutes();
        $bundle = $this->action('pause', $bundle);
        $this->assertSame(10.0, (float) $bundle['actual_labor_minutes']);
        $this->assertEqualsWithDelta(10.0, collect($bundle['lines'])->sum('allocated_labor_minutes'), 0.0001);
        $this->assertNotSame($bundle['lines'][0]['allocated_labor_minutes'], $bundle['lines'][1]['allocated_labor_minutes']);
        $bundle = $this->action('resume', $bundle);
        foreach ($bundle['lines'] as $line) {
            $bundle = app(ProductionJobBundleApplicationService::class)->report($bundle['id'], $line['id'],
                $this->command($bundle) + ['expected_target_version' => $line['target']['business_version'],
                    'qualified_base_qty' => $line['target']['remaining_base_qty'], 'unqualified_base_qty' => 0, 'scrapped_base_qty' => 0],
                $this->worker, $this->workerPermissions());
        }
        $this->assertSame(1, ProductionLaborSession::where('job_bundle_id', $bundle['id'])->where('status', 'ACTIVE')->count());
        foreach ($bundle['lines'] as $line) {
            $bundle = app(ProductionJobBundleApplicationService::class)->complete($bundle['id'], $line['id'],
                $this->command($bundle) + ['expected_target_version' => $line['target']['business_version']], $this->worker, $this->workerPermissions());
        }
        $this->assertSame(['WAIT_QUALITY', 'WAIT_QUALITY'], collect($bundle['lines'])->pluck('output_record.status')->all());
        $this->assertSame(2, DB::table('erp_production_reports')->whereIn('work_order_id', $f['orders']->pluck('id'))->count());
        $outputs = DB::table('erp_production_output_records')->whereIn('work_order_id', $f['orders']->pluck('id'))->get()->keyBy('work_order_id');
        $this->assertSame('flow_only', $outputs[$f['orders'][0]->id]->output_mode_snapshot);
        $this->assertSame('warehouse_required', $outputs[$f['orders'][1]->id]->output_mode_snapshot);
        $this->assertSame(2.0, (float) $outputs[$f['orders'][0]->id]->output_base_qty);
        $this->assertSame(4.0, (float) $outputs[$f['orders'][1]->id]->output_base_qty);
        $this->assertNotSame($outputs[$f['orders'][0]->id]->output_item_id, $outputs[$f['orders'][1]->id]->output_item_id);
        $this->assertSame(0, DB::table('erp_inventory_transactions')->where('source_type', 'job_bundle')->count());
        $this->travelBack();
    }

    public function test_create_replay_conflicting_commands_versions_and_active_membership_are_protected(): void
    {
        $f = $this->fixture(); $payload = $this->createPayload($f['tasks']);
        $service = app(ProductionJobBundleApplicationService::class);
        $first = $service->create($payload, $this->manager, self::PERMISSIONS);
        $this->assertSame($first, $service->create($payload, $this->manager, self::PERMISSIONS));
        $this->expectDomain('command_conflict', fn () => $service->create(array_replace($payload, ['title' => '不同内容']), $this->manager, self::PERMISSIONS));
        $new = $this->createPayload($f['tasks']->map(fn ($t) => $t->fresh()));
        $this->expectDomain('bundle_task_not_available', fn () => $service->create($new, $this->manager, self::PERMISSIONS));
        $this->expectDomain('version_conflict', fn () => $service->claim($first['id'], ['client_command_id' => $this->code(), 'expected_version' => 99], $this->worker, $this->workerPermissions()));
        $this->assertSame(1, ProductionJobBundle::where('id', $first['id'])->count());
        $this->assertSame(2, DB::table('erp_production_job_bundle_lines')->where('job_bundle_id', $first['id'])->count());
    }

    public function test_optional_output_requires_an_explicit_destination_and_preserves_continuation_permission(): void
    {
        foreach ([false, true] as $allowContinue) {
            $f = $this->fixture('unit', 1, 1, false, 'warehouse_optional', $allowContinue);
            $bundle = $this->action('claim', $this->create($f['tasks']));
            foreach ($bundle['lines'] as $line) $this->confirmKitting($line);
            $bundle = $this->action('start', $bundle);
            $line = collect($bundle['lines'])->first(fn ($row) => $row['work_order']['id'] === $f['orders'][0]->id);
            $this->assertSame('warehouse_optional', $line['target']['output_mode']);
            $this->assertSame($allowContinue, $line['target']['allow_continue_without_warehouse']);
            $complete = fn (array $extra) => app(ProductionJobBundleApplicationService::class)->complete($bundle['id'], $line['id'],
                $this->command($bundle) + ['expected_target_version' => $line['target']['business_version']] + $extra,
                $this->worker, $this->workerPermissions());
            $this->expectDomain('output_disposition_required', fn () => $complete([]));
            if (! $allowContinue) $this->expectDomain('output_direct_handover_not_allowed', fn () => $complete(['disposition' => 'direct_handover']));
            $this->assertSame(0, DB::table('erp_production_output_records')->where('work_order_id', $f['orders'][0]->id)->count());
            $this->assertSame(1, ProductionLaborSession::where('job_bundle_id', $bundle['id'])->where('status', 'ACTIVE')->count());
            $this->assertSame(0, DB::table('erp_production_job_bundle_labor_allocations')->whereIn('job_bundle_line_id', collect($bundle['lines'])->pluck('id'))->count());
            $choice = $allowContinue ? 'direct_handover' : 'warehouse';
            $bundle = $complete(['disposition' => $choice]);
            $this->assertSame($choice, DB::table('erp_production_output_records')->where('work_order_id', $f['orders'][0]->id)->value('disposition'));
            $last = collect($bundle['lines'])->first(fn ($row) => $row['work_order']['id'] === $f['orders'][1]->id);
            $bundle = app(ProductionJobBundleApplicationService::class)->complete($bundle['id'], $last['id'],
                $this->command($bundle) + ['expected_target_version' => $last['target']['business_version']], $this->worker, $this->workerPermissions());
            $this->assertSame('COMPLETED', $this->action('finish', $bundle)['status']);
        }
    }

    public function test_same_name_does_not_override_different_operation_or_machine_parameters_and_zero_weight(): void
    {
        $f = $this->fixture(); $task = $f['tasks']->last(); $target = $task->productionQuantityOperation;
        $different = ProductionOperation::create(['operation_no' => $this->code(), 'operation_name' => '共用焊接', 'status' => 'enabled']);
        $originalId = $target->operation_id_snapshot;
        $snapshot = $task->workOrder->routing_snapshot;
        $snapshot['operations'][0]['operation_id'] = $different->id;
        $task->workOrder->update(['routing_snapshot' => $snapshot]);
        $target->update(['operation_id_snapshot' => $different->id]);
        $this->expectDomain('bundle_tasks_incompatible', fn () => $this->create($f['tasks']));
        $snapshot['operations'][0]['operation_id'] = $originalId;
        $snapshot['operations'][0]['parameters'] = ['workstation' => '另一工位', 'equipment_rule' => '另一设备'];
        $task->workOrder->update(['routing_snapshot' => $snapshot]);
        $target->update(['operation_id_snapshot' => $originalId]);
        $this->expectDomain('bundle_tasks_incompatible', fn () => $this->create($f['tasks']));
        $snapshot['operations'][0]['parameters'] = [];
        $task->workOrder->update(['routing_snapshot' => $snapshot]);
        $target->update(['standard_minutes_snapshot' => 0]);
        $this->expectDomain('bundle_standard_weight_required', fn () => $this->create($f['tasks']));
    }

    public function test_bundled_original_interfaces_cannot_start_report_complete_or_claim_separately(): void
    {
        $f = $this->fixture(); $bundle = $this->create($f['tasks']);
        $task = $f['tasks']->first()->fresh(); $target = $task->productionQuantityOperation;
        $this->expectDomain('task_in_job_bundle', fn () => app(ProductionTaskAssignmentService::class)->claim($task->id,
            ['client_command_id' => $this->code(), 'expected_version' => $task->business_version], $this->worker, $this->workerPermissions()));
        $bundle = $this->action('claim', $bundle);
        foreach ($bundle['lines'] as $line) $this->confirmKitting($line);
        $target->refresh();
        $this->expectDomain('task_in_job_bundle', fn () => app(ProductionExecutionActionService::class)->start($task->id, 'quantity_operation', $target->id,
            ['client_command_id' => $this->code(), 'expected_version' => $target->business_version], $this->worker, $this->workerPermissions()));
        $bundle = $this->action('start', $bundle); $target->refresh();
        $this->expectDomain('task_in_job_bundle', fn () => app(ProductionReportService::class)->report($task->id, 'quantity_operation', $target->id,
            ['client_command_id' => $this->code(), 'expected_version' => $target->business_version, 'qualified_base_qty' => 1], $this->worker, $this->workerPermissions()));
        $this->expectDomain('task_in_job_bundle', fn () => app(ProductionExecutionActionService::class)->complete($task->id, 'quantity_operation', $target->id,
            ['client_command_id' => $this->code(), 'expected_version' => $target->business_version], $this->worker, $this->workerPermissions()));
        $view = app(ProductionTaskQueryService::class)->show($task->id, $this->worker, $this->workerPermissions(), false)->toArray();
        $this->assertSame($bundle['id'], $view['active_job_bundle']['id']);
        $this->assertFalse($view['target_details'][0]['allowed_actions']['complete']);
        $this->assertSame(0, DB::table('erp_production_reports')->where('task_id', $task->id)->count());
    }

    public function test_material_ownership_and_stale_ready_sources_block_without_starting_any_target(): void
    {
        $f = $this->fixture(); $bundle = $this->create($f['tasks']); $bundle = $this->action('claim', $bundle);
        foreach ($bundle['lines'] as $line) $this->confirmKitting($line);
        $line = $bundle['lines'][0];
        $issue = DB::table('erp_production_internal_issue_tasks')->insertGetId(['issue_no' => $this->code(), 'work_order_id' => $line['work_order']['id'],
            'target_task_id' => $line['task']['id'], 'target_type' => $line['target']['type'], 'target_id' => $line['target']['id'],
            'source_type' => 'inventory_continuation', 'status' => 'ISSUED', 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->expectDomain('bundle_line_not_ready', fn () => $this->action('start', $bundle));
        $this->assertSame(0, ProductionLaborSession::where('job_bundle_id', $bundle['id'])->count());
        $this->assertSame(0, ProductionQuantityOperation::whereIn('work_order_id', $f['orders']->pluck('id'))->whereNotNull('started_at')->count());
        DB::table('erp_production_internal_issue_tasks')->where('id', $issue)->update(['status' => 'RECEIVED']);
        $requirement = DB::table('erp_production_target_material_requirements')->where('target_type', $line['target']['type'])->where('target_id', $line['target']['id'])->first();
        DB::table('erp_production_target_material_requirements')->where('id', $requirement->id)->update(['work_order_id' => $f['orders'][1]->id]);
        $this->expectDomain('bundle_material_ownership_invalid', fn () => $this->action('start', $bundle));
    }

    public function test_worker_pool_scope_and_foreign_line_quantity_owner_permissions_are_enforced(): void
    {
        $f = $this->fixture(); $bundle = $this->create($f['tasks']);
        $query = app(ProductionJobBundleQueryService::class);
        $pool = $query->paginate(['view' => 'all', 'status' => 'WAIT_CLAIM', 'work_order_id' => $f['orders'][0]->id], $this->worker, $this->workerPermissions(), false);
        $this->assertSame(1, $pool['total']);
        $other = $this->actor('self', ['production.task.view']);
        $bundle = $this->action('claim', $bundle);
        $this->expectDomain('job_bundle_not_found', fn () => $query->show($bundle['id'], $other, ['production.task.view'], false));
        foreach ($bundle['lines'] as $line) $this->confirmKitting($line);
        $bundle = $this->action('start', $bundle); $line = $bundle['lines'][0];
        $service = app(ProductionJobBundleApplicationService::class);
        $this->expectDomain('bundle_line_not_executable', fn () => $service->report($bundle['id'], 999999999,
            $this->command($bundle) + ['expected_target_version' => 1], $this->worker, $this->workerPermissions()));
        $this->expectDomain('report_quantity_exceeds_remaining', fn () => $service->report($bundle['id'], $line['id'],
            $this->command($bundle) + ['expected_target_version' => $line['target']['business_version'], 'qualified_base_qty' => 999], $this->worker, $this->workerPermissions()));
        $this->expectDomain('permission_denied', fn () => $service->pause($bundle['id'], $this->command($bundle), $this->worker, ['production.task.view']));
    }

    public function test_start_rechecks_same_item_configuration_for_wip_and_actual_input_and_rolls_back_every_target(): void
    {
        foreach (['wip', 'input'] as $sourceType) {
            $f = $this->fixture();
            $configurations = [];
            foreach ([100, 200] as $length) $configurations[] = DB::table('erp_custom_configurations')->insertGetId([
                'item_id' => $f['raw']->id, 'configuration_no' => $this->code(), 'version_no' => 1,
                'dimensions' => json_encode(['length_mm' => $length, 'width_mm' => 50, 'thickness_mm' => 2]),
                'drawing_reference' => '同一物料的独立规格版本 '.$length, 'scope_mode' => 'PUBLIC', 'status' => 'PUBLISHED',
                'business_version' => 1, 'created_by_legacy_id' => $this->manager->legacy_id,
                'created_at' => now(), 'updated_at' => now()]);
            DB::table('erp_work_order_material_requirements')->whereIn('work_order_id', $f['orders']->pluck('id'))
                ->update(['configuration_id' => $configurations[0]]);
            $bundle = $this->action('claim', $this->create($f['tasks']));
            foreach ($bundle['lines'] as $line) $this->confirmKitting($line);
            $line = $bundle['lines'][0];
            $requirement = DB::table('erp_production_target_material_requirements')->where('target_type', $line['target']['type'])
                ->where('target_id', $line['target']['id'])->sole();
            // Represent a stale received source after arranging the bundle.
            // Both sources deliberately retain the correct Item and quantity;
            // only their exact specification/configuration version differs.
            $lot = DB::table('erp_material_lots')->insertGetId(['lot_no' => $this->code(), 'item_id' => $f['raw']->id,
                'configuration_id' => $configurations[1], 'material_form' => 'PRODUCT', 'source_type' => 'test_received_source',
                'source_id' => $requirement->id, 'created_at' => now(), 'updated_at' => now()]);
            $holding = DB::table('erp_material_holdings')->insertGetId(['material_lot_id' => $lot,
                'position_type' => $sourceType === 'wip' ? 'PRODUCTION_WIP' : 'PRODUCTION_RECEIVED', 'position_id' => $requirement->id,
                'quantity' => $requirement->required_base_qty, 'total_cost' => '10.0000', 'status' => 'ACTIVE', 'business_version' => 1,
                'created_at' => now(), 'updated_at' => now()]);
            if ($sourceType === 'input') {
                $inputHolding = DB::table('erp_material_holdings')->insertGetId(['material_lot_id' => $lot,
                    'position_type' => 'PRODUCTION_INPUT', 'position_id' => 0, 'quantity' => $requirement->required_base_qty,
                    'total_cost' => '10.0000', 'status' => 'ACTIVE', 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
                $bridge = DB::table('erp_production_input_holdings')->insertGetId(['target_type' => $line['target']['type'],
                    'target_id' => $line['target']['id'], 'target_material_requirement_id' => $requirement->id,
                    'source_output_record_id' => null, 'source_holding_id' => $holding, 'input_holding_id' => $inputHolding,
                    'quantity' => $requirement->required_base_qty, 'total_cost' => '10.0000', 'status' => 'ACTIVE',
                    'created_at' => now(), 'updated_at' => now()]);
                DB::table('erp_material_holdings')->where('id', $inputHolding)->update(['position_id' => $bridge]);
            }
            $this->expectDomain('bundle_material_configuration_invalid', fn () => $this->action('start', $bundle));
            $this->assertSame(0, ProductionLaborSession::where('job_bundle_id', $bundle['id'])->count());
            $this->assertSame(0, ProductionQuantityOperation::whereIn('work_order_id', $f['orders']->pluck('id'))->whereNotNull('started_at')->count());
            $this->assertSame('CLAIMED', ProductionJobBundle::findOrFail($bundle['id'])->status);
            $this->assertSame(0, DB::table('erp_production_execution_commands')->where('command_type', 'start_job_bundle')
                ->where('aggregate_id', $bundle['id'])->count());
            DB::table('erp_material_lots')->where('id', $lot)->update(['configuration_id' => $configurations[0]]);
            $started = $this->action('start', $bundle);
            $this->assertSame('IN_PROGRESS', $started['status']);
            $snapshot = collect($started['lines'])->firstWhere('id', $line['id'])['started_material_snapshot'][0];
            $this->assertSame($configurations[0], (int) $snapshot['required_configuration_id']);
            $sources = $sourceType === 'wip' ? $snapshot['source_facts']['requirement_holdings'] : $snapshot['source_facts']['production_inputs'];
            $this->assertSame($configurations[0], (int) $sources[0]['actual_configuration_id']);
            // End this controlled clock before the next independently published fixture.
            $this->action('pause', $started);
        }
    }

    public function test_cancel_releases_only_bundle_membership_and_old_single_task_execution_still_works(): void
    {
        $f = $this->fixture(); $bundle = $this->create($f['tasks']);
        $bundle = app(ProductionJobBundleApplicationService::class)->cancel($bundle['id'], $this->command($bundle), $this->manager, self::PERMISSIONS);
        $this->assertSame('CANCELLED', $bundle['status']);
        $task = $f['tasks']->first()->fresh();
        $this->assertNull($task->active_job_bundle_id);
        app(ProductionTaskAssignmentService::class)->claim($task->id, ['client_command_id' => $this->code(), 'expected_version' => $task->business_version], $this->worker, $this->workerPermissions());
        $target = $task->productionQuantityOperation->fresh();
        DB::table('erp_production_target_material_requirements')->where('target_type', 'quantity_operation')->where('target_id', $target->id)->update(['satisfied_base_qty' => 2]);
        $result = app(ProductionKittingService::class)->confirm($task->id, 'quantity_operation', $target->id,
            ['client_command_id' => $this->code(), 'expected_version' => $target->business_version], $this->worker, $this->workerPermissions());
        $this->assertSame('IN_PROGRESS', $result['target_status']);
        $this->assertSame(1, ProductionLaborSession::where('task_id', $task->id)->where('status', 'ACTIVE')->count());
    }

    private function fixture(string $mode = 'quantity', int $firstQty = 2, int $secondQty = 4, bool $differentItem = false,
        string $outputMode = 'flow_only', bool $allowContinue = true): array
    {
        $unit = Unit::create(['unit_code' => $this->code(), 'unit_name' => '套', 'unit_type' => 'quantity', 'decimal_places' => 0, 'is_base' => true, 'status' => 'enabled']);
        $item = Item::create(['item_code' => $this->code(), 'item_name' => '设备架子', 'spec' => '真实规格来自物料档案', 'item_type' => 'semi_finished',
            'unit_id' => $unit->id, 'is_stock_item' => true, 'is_production_item' => true, 'production_execution_mode' => $mode, 'status' => 'enabled']);
        $otherItem = $differentItem ? Item::create(['item_code' => $this->code(), 'item_name' => '独立电箱', 'item_type' => 'semi_finished',
            'unit_id' => $unit->id, 'is_stock_item' => true, 'is_production_item' => true, 'production_execution_mode' => $mode, 'status' => 'enabled']) : $item;
        $raw = Item::create(['item_code' => $this->code(), 'item_name' => '实际BOM原料', 'item_type' => 'raw_material', 'unit_id' => $unit->id, 'is_stock_item' => true, 'status' => 'enabled']);
        $operation = ProductionOperation::create(['operation_no' => $this->code(), 'operation_name' => '共用焊接', 'status' => 'enabled']);
        $routes = [];
        foreach (collect([$item, $otherItem])->unique('id') as $output) {
            $bom = Bom::create(['bom_no' => $this->code(), 'bom_name' => '独立部件BOM', 'output_item_id' => $output->id,
                'bom_type' => 'standard', 'version' => 'V1', 'is_default' => true, 'status' => 'active', 'audit_status' => 'approved', 'effective_date' => now()->subDay()->toDateString()]);
            BomItem::create(['bom_id' => $bom->id, 'line_no' => 10, 'component_item_id' => $raw->id,
                'component_item_code' => $raw->item_code, 'component_item_name' => $raw->item_name, 'qty' => 1, 'unit_id' => $unit->id, 'loss_rate' => 0, 'fixed_qty' => 0]);
            $route = ProductionRouting::create(['routing_no' => $this->code(), 'routing_name' => '独立焊接路线', 'output_item_id' => $output->id,
                'version' => 1, 'status' => 'active', 'is_default' => true, 'default_scope_key' => $output->id.':0:0']);
            $node = ProductionRoutingOperation::create(['routing_id' => $route->id, 'operation_id' => $operation->id, 'sequence' => 10,
                'output_item_id' => $output->id, 'output_mode' => $outputMode, 'quality_mode' => $differentItem ? 'required' : 'none',
                'work_mode' => 'manual', 'allow_continue_without_warehouse' => $allowContinue, 'unit_standard_minutes' => 5, 'setup_standard_minutes' => 0, 'parameters' => []]);
            DB::table('erp_routing_operation_material_supply_rules')->insert(['routing_operation_id' => $node->id, 'component_item_id' => $raw->id,
                'target_routing_operation_id' => $node->id, 'required_qty_ratio' => 1, 'supply_mode' => 'dedicated_delivery', 'requires_delivery' => true,
                'participates_in_kitting' => true, 'allow_partial_delivery' => false, 'delivery_location_type' => 'operation_station', 'business_version' => 1,
                'created_at' => now(), 'updated_at' => now()]);
            $routes[$output->id] = [$route, $node];
        }
        $orders = collect();
        foreach ([[$item, $firstQty, 'production_plan'], [$otherItem, $secondQty, 'stock_prebuild']] as [$output, $qty, $source]) {
            [$route, $node] = $routes[$output->id];
            $wo = WorkOrder::create(['work_order_no' => $this->code(), 'source_type' => $source, 'output_item_id' => $output->id,
                'source_title_snapshot' => $source === 'production_plan' ? '设备部件计划' : '部件公共备货', 'production_routing_id' => $route->id,
                'routing_version_snapshot' => 1, 'routing_snapshot' => app(ProductionMasterDataService::class)->snapshot($route),
                'target_routing_operation_id' => $node->id, 'target_operation_id' => $operation->id,
                'target_qty' => $qty, 'target_base_qty' => $qty, 'target_unit_id' => $unit->id, 'base_unit_id' => $unit->id,
                'target_unit_name_snapshot' => $unit->unit_name, 'base_unit_name_snapshot' => $unit->unit_name,
                'stocking_purpose' => $source === 'stock_prebuild' ? 'common_inventory' : null,
                'configured_output_mode_snapshot' => $source === 'stock_prebuild' ? $outputMode : null,
                'effective_output_mode_snapshot' => $source === 'stock_prebuild' ? 'warehouse_required' : null,
                'effective_output_item_id_snapshot' => $source === 'stock_prebuild' ? $output->id : null,
                'status' => 'DRAFT', 'responsible_user_legacy_id' => $this->manager->legacy_id, 'business_version' => 1]);
            $app = app(WorkOrderApplicationService::class);
            $waiting = $app->submit($wo->id, ['client_command_id' => $this->code(), 'expected_version' => 1], $this->manager, self::PERMISSIONS);
            $gate = app(ReleaseGateApplicationService::class)->evaluate($waiting->id, $this->manager, self::PERMISSIONS);
            $this->assertTrue($gate['allowed'], json_encode($gate['blockers'], JSON_UNESCAPED_UNICODE));
            $orders->push($app->publish($waiting->id, ['client_command_id' => $this->code(), 'expected_version' => $waiting->business_version], $this->manager, self::PERMISSIONS));
        }
        $tasks = ProductionTask::whereIn('work_order_id', $orders->pluck('id'))->orderBy('id')->get();
        return compact('orders', 'tasks', 'item', 'otherItem', 'raw');
    }

    private function confirmKitting(array $line): void
    {
        $requirements = DB::table('erp_production_target_material_requirements')->where('target_type', $line['target']['type'])->where('target_id', $line['target']['id'])->get();
        // Material receiving has its own regression suite. Seed the exact frozen receipt projection per target; never share it across details.
        foreach ($requirements as $r) DB::table('erp_production_target_material_requirements')->where('id', $r->id)->update(['satisfied_base_qty' => $r->required_base_qty]);
        $target = ($line['target']['type'] === 'quantity_operation' ? ProductionQuantityOperation::class : ProductionUnitOperation::class)::findOrFail($line['target']['id']);
        $result = app(ProductionKittingService::class)->confirm($line['task']['id'], $line['target']['type'], $line['target']['id'],
            ['client_command_id' => $this->code(), 'expected_version' => $target->business_version], $this->worker, $this->workerPermissions());
        $this->assertSame('READY', $result['target_status']);
        $this->assertNull($result['started_at']);
    }
    private function create($tasks): array { return app(ProductionJobBundleApplicationService::class)->create($this->createPayload($tasks), $this->manager, self::PERMISSIONS); }
    private function createPayload($tasks): array { return ['client_command_id' => $this->code(), 'tasks' => $tasks->map(fn ($t) => ['task_id' => $t->id, 'expected_task_version' => $t->business_version])->values()->all()]; }
    private function action(string $name, array $bundle): array { return app(ProductionJobBundleApplicationService::class)->{$name}($bundle['id'], $this->command($bundle), $this->worker, $this->workerPermissions()); }
    private function command(array $bundle): array { return ['client_command_id' => $this->code(), 'expected_version' => $bundle['business_version']]; }
    private function actor(string $scope, array $permissions): object
    {
        $id = random_int(7200000, 7299999);
        DB::table('erp_legacy_admin_users')->insert(['legacy_id' => $id, 'username' => $this->code(), 'nickname' => '共同加工测试员工', 'status' => 'normal',
            'auth_group_names' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        $role = DB::table('erp_rbac_roles')->insertGetId(['code' => $this->code(), 'name' => '共同加工测试角色', 'data_scope' => $scope, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach (DB::table('erp_rbac_permissions')->whereIn('code', $permissions)->pluck('id') as $permission) DB::table('erp_rbac_role_permissions')->insert(['role_id' => $role, 'permission_id' => $permission]);
        DB::table('erp_rbac_user_roles')->insert(['user_legacy_id' => $id, 'role_id' => $role]);
        return DB::table('erp_legacy_admin_users')->where('legacy_id', $id)->first();
    }
    private function workerPermissions(): array { return array_values(array_diff(self::PERMISSIONS, ['production.work_order.edit'])); }
    private function code(): string { return 'PJB-'.strtoupper(Str::random(16)); }
    private function expectDomain(string $code, callable $action): void
    {
        try { $action(); $this->fail('Expected '.$code); } catch (WorkOrderDomainException $e) { $this->assertSame($code, $e->errorCode, $e->getMessage()); }
    }
}
