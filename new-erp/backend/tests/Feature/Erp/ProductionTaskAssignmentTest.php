<?php

namespace Tests\Feature\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{Item, ProductionQuantityOperation, ProductionTask, ProductionTaskAssignment, ProductionTaskTarget, Unit, WorkOrder};
use App\Services\Erp\{ProductionTaskAssignmentService, ProductionTaskCollaborationService, ProductionTaskEfficiencyService, ProductionTaskQueryService, ProductionTargetReadinessService, RbacBootstrapService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductionTaskAssignmentTest extends TestCase
{
    use DatabaseTransactions;

    private const WORKER_PERMISSIONS = ['production.task.view', 'production.task.claim', 'production.task.collaborate'];
    private const MANAGER_PERMISSIONS = ['production.task.view', 'production.task.claim', 'production.task.collaborate', 'production.assignment.auto'];
    private array $context;

    protected function setUp(): void
    {
        parent::setUp();
        app(RbacBootstrapService::class)->bootstrap(true);
        $suffix = Str::upper(Str::random(8));
        $unit = Unit::create(['unit_code' => 'AS-U-'.$suffix, 'unit_name' => '件', 'unit_type' => 'quantity',
            'decimal_places' => 0, 'is_base' => true, 'status' => 'enabled']);
        $item = Item::create(['item_code' => 'AS-I-'.$suffix, 'item_name' => '派单验证成品', 'item_type' => 'finished_good',
            'unit_id' => $unit->id, 'status' => 'enabled']);
        $operation = DB::table('erp_production_operations')->insertGetId(['operation_no' => 'AS-OP-'.$suffix,
            'operation_name' => '装配', 'status' => 'enabled', 'sort' => 1, 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $routing = DB::table('erp_production_routings')->insertGetId(['routing_no' => 'AS-R-'.$suffix, 'routing_name' => '派单验证路线',
            'output_item_id' => $item->id, 'version' => 1, 'status' => 'draft', 'is_default' => false, 'business_version' => 1,
            'created_at' => now(), 'updated_at' => now()]);
        $node = DB::table('erp_production_routing_operations')->insertGetId(['routing_id' => $routing, 'operation_id' => $operation,
            'sequence' => 1, 'is_key_operation' => false, 'created_at' => now(), 'updated_at' => now()]);
        $this->context = compact('unit', 'item', 'operation', 'routing', 'node');
        $this->context['manager'] = $this->user('派单管理员', 'production_manager');
        $this->context['fast'] = $this->user('独立快工', 'production_operator');
        $this->context['slow'] = $this->user('独立稳工', 'production_operator');
        $this->context['helper'] = $this->user('短时协作者', 'production_operator');
    }

    public function test_ranking_requires_comparable_solo_qualified_history_and_excludes_assistance(): void
    {
        [$task] = $this->task();
        $this->sample($this->context['fast'], 8);
        $this->sample($this->context['slow'], 12);
        [$assisted, $target] = $this->sample($this->context['slow'], .5);
        $this->labor($assisted, $target, $this->context['helper']->legacy_id, .1, 'collaborator');
        $this->sample($this->context['helper'], .2, ['planned_base_qty' => 20, 'completed_base_qty' => 20]);
        [$wrongRoute] = $this->sample($this->context['helper'], .3);
        $wrongRoute->workOrder->update(['routing_version_snapshot' => 2]);
        [$scrapped, $scrapTarget] = $this->sample($this->context['helper'], .4);
        $scrapTarget->update(['scrapped_base_qty' => 1]);
        $rows = app(ProductionTaskEfficiencyService::class)->candidates($task)->all();
        $this->assertSame([$this->context['fast']->legacy_id, $this->context['slow']->legacy_id], array_column($rows, 'employee_legacy_id'));
        $this->assertSame(8.0, $rows[0]['fastest_qualified_minutes']);
        $this->assertSame(12.0, $rows[1]['fastest_qualified_minutes']);
        $this->assertSame(1, $rows[1]['qualified_sample_count']);
    }

    public function test_quality_required_samples_need_full_pass_and_have_no_failed_or_rework_history(): void
    {
        [$task, $target] = $this->task(['quality_mode_snapshot' => 'required']);
        [$good, $goodTarget, $output] = $this->sample($this->context['fast'], 8, ['quality_mode_snapshot' => 'required']);
        $this->inspection($output, 'passed');
        [$bad, $badTarget, $badOutput] = $this->sample($this->context['helper'], .1, ['quality_mode_snapshot' => 'required']);
        $this->inspection($badOutput, 'passed');
        $this->inspection($badOutput, 'failed');
        [$uninspected] = $this->sample($this->context['slow'], .2, ['quality_mode_snapshot' => 'required']);
        [$reworked, $reworkedTarget, $reworkedOutput] = $this->sample($this->context['slow'], .3, ['quality_mode_snapshot' => 'required']);
        $this->inspection($reworkedOutput, 'passed');
        DB::table('erp_production_execution_events')->insert(['aggregate_type' => 'quantity_operation', 'aggregate_id' => $reworkedTarget->id,
            'action' => 'rework', 'before_status' => 'REWORK', 'after_status' => 'IN_PROGRESS', 'before_version' => 1, 'after_version' => 2,
            'fact_snapshot' => '{}', 'operator_legacy_id' => $this->context['slow']->legacy_id, 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $rows = app(ProductionTaskEfficiencyService::class)->candidates($task);
        $this->assertSame([$this->context['fast']->legacy_id], $rows->pluck('employee_legacy_id')->all());
    }

    public function test_outlier_fast_time_is_excluded_from_qualified_ranking_when_history_supports_detection(): void
    {
        [$task] = $this->task();
        foreach ([.01, 10, 11, 12, 13] as $minutes) $this->sample($this->context['fast'], $minutes);
        $this->sample($this->context['slow'], 9);
        $rows = app(ProductionTaskEfficiencyService::class)->candidates($task)->all();
        $this->assertSame($this->context['slow']->legacy_id, $rows[0]['employee_legacy_id']);
        $this->assertSame(10.0, $rows[1]['fastest_qualified_minutes']);
        $this->assertSame(4, $rows[1]['qualified_sample_count']);
        $this->assertSame(1, $rows[1]['excluded_outlier_count']);
    }

    public function test_offer_reserves_task_without_owner_or_labor_and_accept_reuses_formal_claim(): void
    {
        [$task, $target] = $this->task();
        $this->sample($this->context['fast'], 8);
        $service = app(ProductionTaskAssignmentService::class);
        $offered = $service->autoAssign($task->id, $this->command(1), $this->context['manager'], self::MANAGER_PERMISSIONS);
        $offer = ProductionTaskAssignment::findOrFail($offered['assignment']['id']);
        $this->assertSame('WAIT_ACCEPT', $task->fresh()->status);
        $this->assertNull($task->fresh()->assignee_user_legacy_id);
        $this->assertNull($task->fresh()->claimed_at);
        $this->assertSame('WAIT_CLAIM', $target->fresh()->status);
        $this->assertSame(0, $task->collaborators()->count());
        $this->assertSame(0, $task->laborSessions()->count());
        $mine = app(ProductionTaskQueryService::class)->paginate(['view' => 'owned'], $this->context['fast'], self::WORKER_PERMISSIONS, false);
        $this->assertContains($task->id, $mine->getCollection()->pluck('id')->all());
        app(ProductionTargetReadinessService::class)->refresh('quantity_operation', $target, $task->fresh());
        $this->assertSame('WAIT_ACCEPT', $task->fresh()->status);
        $payload = $this->command(1) + ['expected_task_version' => 2];
        $accepted = $service->accept($offer->id, $payload, $this->context['fast'], self::WORKER_PERMISSIONS);
        $this->assertEquals($accepted, $service->accept($offer->id, $payload, $this->context['fast'], self::WORKER_PERMISSIONS));
        $this->assertSame('CLAIMED', $accepted['status']);
        $this->assertSame('READY', $target->fresh()->status);
        $this->assertSame($this->context['fast']->legacy_id, (int) $task->fresh()->assignee_user_legacy_id);
        $this->assertSame(1, $task->collaborators()->where('role', 'owner')->count());
        $this->assertSame(0, $task->laborSessions()->count());
        $this->assertNull($offer->fresh()->active_task_id);
        $this->assertSame('ACCEPTED', $offer->fresh()->status);
    }

    public function test_rejection_persists_identity_time_reason_and_next_offer_skips_rejecter(): void
    {
        [$task] = $this->task();
        $this->sample($this->context['fast'], 8); $this->sample($this->context['slow'], 12);
        $service = app(ProductionTaskAssignmentService::class);
        $offered = $service->autoAssign($task->id, $this->command(1), $this->context['manager'], self::MANAGER_PERMISSIONS);
        $id = $offered['assignment']['id'];
        $payload = $this->command(1) + ['expected_task_version' => 2, 'reason' => '本班已有其他任务'];
        $first = $service->reject($id, $payload, $this->context['fast'], self::WORKER_PERMISSIONS);
        $this->assertEquals($first, $service->reject($id, $payload, $this->context['fast'], self::WORKER_PERMISSIONS));
        $record = ProductionTaskAssignment::findOrFail($id);
        $this->assertSame('REJECTED', $record->status);
        $this->assertSame($this->context['fast']->legacy_id, (int) $record->decided_by_legacy_id);
        $this->assertNotNull($record->decided_at);
        $this->assertSame('本班已有其他任务', $record->rejection_reason);
        $this->assertSame('WAIT_CLAIM', $task->fresh()->status);
        $next = $service->autoAssign($task->id, $this->command(3), $this->context['manager'], self::MANAGER_PERMISSIONS);
        $this->assertSame($this->context['slow']->legacy_id, $next['assignment']['offered_to_legacy_id']);
        $this->assertSame(1, $task->assignments()->where('status', 'PENDING')->count());
        $this->assertSame(2, $task->assignments()->count());
    }

    public function test_no_history_falls_back_to_manual_and_frozen_switch_controls_automatic_offer(): void
    {
        [$task] = $this->task();
        $service = app(ProductionTaskAssignmentService::class);
        $result = $service->autoAssign($task->id, $this->command(1), $this->context['manager'], self::MANAGER_PERMISSIONS);
        $this->assertSame('no_comparable_history', $result['reason_code']);
        $this->assertSame('WAIT_CLAIM', $task->fresh()->status);
        $this->sample($this->context['fast'], 8);
        $this->assertNull($service->tryOfferReadyTask($task));
        $task->update(['auto_assignment_enabled_snapshot' => true]);
        $this->assertSame('WAIT_ACCEPT', $service->tryOfferReadyTask($task)['status']);
        $this->assertNull($service->tryOfferReadyTask($task->fresh()));
        $this->assertSame(1, $task->assignments()->count());
    }

    public function test_wrong_recipient_stale_versions_and_accept_reject_conflict_do_not_change_facts(): void
    {
        [$task] = $this->task(); $this->sample($this->context['fast'], 8);
        $service = app(ProductionTaskAssignmentService::class);
        $id = $service->autoAssign($task->id, $this->command(1), $this->context['manager'], self::MANAGER_PERMISSIONS)['assignment']['id'];
        $this->domain('assignment_recipient_required', fn () => $service->accept($id, $this->command(1) + ['expected_task_version' => 2], $this->context['slow'], self::WORKER_PERMISSIONS));
        $this->domain('version_conflict', fn () => $service->accept($id, $this->command(1) + ['expected_task_version' => 1], $this->context['fast'], self::WORKER_PERMISSIONS));
        $service->accept($id, $this->command(1) + ['expected_task_version' => 2], $this->context['fast'], self::WORKER_PERMISSIONS);
        $this->domain('version_conflict', fn () => $service->reject($id, $this->command(1) + ['expected_task_version' => 2], $this->context['fast'], self::WORKER_PERMISSIONS));
        $this->assertSame('ACCEPTED', ProductionTaskAssignment::find($id)->status);
        $this->assertSame(1, $task->collaborators()->where('role', 'owner')->count());
    }

    public function test_disabled_account_cannot_accept_and_cancellation_preserves_offer_history(): void
    {
        [$task] = $this->task(); $this->sample($this->context['fast'], 8);
        $service = app(ProductionTaskAssignmentService::class);
        $id = $service->autoAssign($task->id, $this->command(1), $this->context['manager'], self::MANAGER_PERMISSIONS)['assignment']['id'];
        DB::table('erp_legacy_admin_users')->where('legacy_id', $this->context['fast']->legacy_id)->update(['status' => 'hidden']);
        $this->domain('assignee_unavailable', fn () => $service->accept($id, $this->command(1) + ['expected_task_version' => 2], $this->context['fast'], self::WORKER_PERMISSIONS));
        $this->assertSame('PENDING', ProductionTaskAssignment::find($id)->status);
        $task->workOrder->update(['status' => 'CANCELLED']);
        $service->cancelPendingForWorkOrder($task->work_order_id, $this->context['manager']);
        $this->assertSame('CANCELLED', ProductionTaskAssignment::find($id)->status);
        $this->assertNull(ProductionTaskAssignment::find($id)->active_task_id);
        $this->assertSame(1, $task->assignments()->count());
    }

    public function test_command_keys_bind_actor_and_task_and_never_replay_another_claim(): void
    {
        [$first] = $this->task(); [$second] = $this->task();
        $service = app(ProductionTaskAssignmentService::class);
        $payload = $this->command(1);
        $accepted = $service->claim($first->id, $payload, $this->context['fast'], self::WORKER_PERMISSIONS);
        $this->assertEquals($accepted, $service->claim($first->id, $payload, $this->context['fast'], self::WORKER_PERMISSIONS));
        $this->domain('command_conflict', fn () => $service->claim($second->id, $payload, $this->context['fast'], self::WORKER_PERMISSIONS));
        $this->domain('command_conflict', fn () => $service->claim($second->id, $payload, $this->context['slow'], self::WORKER_PERMISSIONS));
        $this->assertSame('WAIT_CLAIM', $second->fresh()->status);
        $this->assertSame(0, $second->collaborators()->count());
    }

    public function test_collaboration_requires_owner_selection_and_server_search_pagination(): void
    {
        [$task] = $this->task();
        app(ProductionTaskAssignmentService::class)->claim($task->id, $this->command(1), $this->context['fast'], self::WORKER_PERMISSIONS);
        $collaboration = app(ProductionTaskCollaborationService::class);
        $this->domain('collaboration_owner_required', fn () => $collaboration->join($task->id, $this->command(2), $this->context['slow'], self::WORKER_PERMISSIONS));
        $this->assertSame(0, $task->collaborators()->where('role', 'collaborator')->count());
        $page = $collaboration->candidates($task->id, ['keyword' => '独立稳工', 'per_page' => 1], $this->context['fast'], self::WORKER_PERMISSIONS);
        $this->assertSame(1, $page->total()); $this->assertSame($this->context['slow']->legacy_id, $page->items()[0]['user_id']);
        $payload = $this->command(2) + ['employee_legacy_ids' => [$this->context['slow']->legacy_id]];
        $added = $collaboration->add($task->id, $payload, $this->context['fast'], self::WORKER_PERMISSIONS);
        $this->assertEquals($added, $collaboration->add($task->id, $payload, $this->context['fast'], self::WORKER_PERMISSIONS));
        $this->assertSame(1, $task->collaborators()->where('role', 'collaborator')->count());
        $task->update(['status' => 'COMPLETED']);
        $this->domain('task_not_collaboratable', fn () => $collaboration->add($task->id, $this->command(3) + ['employee_legacy_ids' => [$this->context['helper']->legacy_id]], $this->context['fast'], self::WORKER_PERMISSIONS));
    }

    private function user(string $name, string $role): object
    {
        $id = random_int(2100000, 2199999);
        DB::table('erp_legacy_admin_users')->insert(['legacy_id' => $id, 'username' => 'as-'.$id, 'nickname' => $name,
            'status' => 'normal', 'department_names' => '[]', 'auth_group_names' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('erp_rbac_user_roles')->insert(['user_legacy_id' => $id, 'role_id' => DB::table('erp_rbac_roles')->where('code', $role)->value('id')]);
        return (object) ['legacy_id' => $id, 'nickname' => $name];
    }

    private function task(array $targetOverrides = []): array
    {
        $c = $this->context; $suffix = Str::upper(Str::random(10));
        $workOrder = WorkOrder::create(['work_order_no' => 'AS-WO-'.$suffix, 'source_type' => 'stock_prebuild', 'output_item_id' => $c['item']->id,
            'target_qty' => 10, 'target_base_qty' => 10, 'target_unit_id' => $c['unit']->id, 'base_unit_id' => $c['unit']->id,
            'production_routing_id' => $c['routing'], 'routing_version_snapshot' => 1, 'status' => 'RELEASED',
            'collaboration_enabled' => true, 'business_version' => 1]);
        $task = ProductionTask::create(['task_no' => 'AS-T-'.$suffix, 'work_order_id' => $workOrder->id, 'execution_mode' => 'quantity',
            'routing_operation_id_snapshot' => $c['node'], 'operation_code_snapshot' => 'OP', 'operation_name_snapshot' => '装配',
            'sequence_no_snapshot' => 1, 'status' => 'WAIT_CLAIM', 'business_version' => 1]);
        $target = ProductionQuantityOperation::create(array_merge(['work_order_id' => $workOrder->id, 'routing_operation_id_snapshot' => $c['node'],
            'operation_id_snapshot' => $c['operation'], 'operation_code_snapshot' => 'OP', 'operation_name_snapshot' => '装配',
            'sequence_no_snapshot' => 1, 'status' => 'WAIT_CLAIM', 'planned_base_qty' => 10, 'remaining_base_qty' => 10, 'work_mode_snapshot' => 'manual',
            'output_mode_snapshot' => 'flow_only', 'quality_mode_snapshot' => 'none', 'business_version' => 1], $targetOverrides));
        ProductionTaskTarget::create(['task_id' => $task->id, 'target_type' => 'quantity_operation', 'target_id' => $target->id, 'status_snapshot' => $target->status]);
        return [$task, $target];
    }

    private function sample(object $worker, float $minutes, array $overrides = []): array
    {
        [$task, $target] = $this->task(array_merge(['status' => 'COMPLETED', 'completed_base_qty' => 10, 'remaining_base_qty' => 0,
            'responsible_user_legacy_id' => $worker->legacy_id, 'completed_at' => now()], $overrides));
        $task->update(['status' => 'COMPLETED', 'assignee_user_legacy_id' => $worker->legacy_id]);
        $this->labor($task, $target, $worker->legacy_id, $minutes);
        $output = DB::table('erp_production_output_records')->insertGetId(['output_no' => 'AS-O-'.Str::random(14), 'work_order_id' => $task->work_order_id,
            'source_target_type' => 'quantity_operation', 'source_target_id' => $target->id, 'output_item_id' => $this->context['item']->id,
            'output_base_qty' => $target->completed_base_qty, 'output_mode_snapshot' => $target->output_mode_snapshot,
            'quality_mode_snapshot' => $target->quality_mode_snapshot, 'status' => 'HANDED_OVER', 'created_by_legacy_id' => $worker->legacy_id,
            'produced_at' => now(), 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        return [$task, $target, $output];
    }

    private function labor(ProductionTask $task, ProductionQuantityOperation $target, int $employee, float $minutes, string $role = 'owner'): void
    {
        DB::table('erp_production_labor_sessions')->insert(['task_id' => $task->id, 'target_type' => 'quantity_operation', 'target_id' => $target->id,
            'employee_legacy_id' => $employee, 'role' => $role, 'status' => 'ENDED', 'started_at' => now()->subMinutes(30), 'ended_at' => now(),
            'actual_labor_minutes' => $minutes, 'responsibility_weight_snapshot' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function inspection(int $output, string $result): void
    {
        DB::table('erp_production_quality_inspections')->insert(['inspection_no' => 'AS-Q-'.Str::random(14), 'output_record_id' => $output,
            'status' => 'COMPLETED', 'result' => $result, 'inspected_base_qty' => 10, 'qualified_base_qty' => $result === 'passed' ? 10 : 0,
            'unqualified_base_qty' => $result === 'passed' ? 0 : 10, 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function command(int $version): array
    { return ['client_command_id' => 'assignment-test-'.Str::uuid(), 'expected_version' => $version]; }

    private function domain(string $code, callable $action): void
    {
        try { $action(); $this->fail('Expected domain error '.$code); }
        catch (WorkOrderDomainException $error) { $this->assertSame($code, $error->errorCode); }
    }
}
