<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\ApprovalTask;
use App\Models\Erp\ApprovalBusinessAction;
use App\Models\Erp\ApprovalTaskNode;
use App\Services\Erp\ApprovalTaskApplicationService;
use App\Services\Erp\ApprovalFlowApplicationService;
use App\Services\Erp\ApprovalBusinessObjectRegistry;
use App\Services\Erp\ApprovalTriggerEngine;
use App\Services\Erp\Contracts\ApprovalBusinessActionHandler;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GenericApprovalEngineTest extends TestCase
{
    use DatabaseTransactions;

    private object $initiator;
    private object $secondary;
    private int $formId;
    private int $formVersionId;

    protected function setUp(): void
    {
        parent::setUp();
        $suffix = strtoupper(substr(uniqid(), -8));
        $legacyId = ((int) DB::table('erp_legacy_admin_users')->max('legacy_id')) + 1;
        DB::table('erp_legacy_admin_users')->insert([
            'legacy_id' => $legacyId, 'username' => 'qa_approval_'.strtolower($suffix),
            'nickname' => '通用审批验收员', 'password_hash' => password_hash('123456', PASSWORD_BCRYPT),
            'status' => 'normal', 'is_sales' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $roleId = DB::table('erp_rbac_roles')->where('code', 'admin')->value('id');
        DB::table('erp_rbac_user_roles')->insertOrIgnore(['user_legacy_id' => $legacyId, 'role_id' => $roleId]);
        $this->initiator = (object) ['legacy_id' => $legacyId, 'username' => 'qa_approval_'.strtolower($suffix), 'nickname' => '通用审批验收员'];
        $secondaryId = $legacyId + 1;
        DB::table('erp_legacy_admin_users')->insert([
            'legacy_id' => $secondaryId, 'username' => 'qa_approval_2_'.strtolower($suffix),
            'nickname' => '通用审批复核员', 'password_hash' => password_hash('123456', PASSWORD_BCRYPT),
            'status' => 'normal', 'is_sales' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('erp_rbac_user_roles')->insertOrIgnore(['user_legacy_id' => $secondaryId, 'role_id' => $roleId]);
        $this->secondary = (object) ['legacy_id' => $secondaryId, 'username' => 'qa_approval_2_'.strtolower($suffix), 'nickname' => '通用审批复核员'];

        $this->formId = DB::table('erp_approval_form_templates')->insertGetId([
            'form_code' => 'QA_GENERIC_'.$suffix, 'form_name' => '通用审批验收表单', 'business_module' => '验收',
            'status' => 'enabled', 'current_version' => 1, 'created_by' => 'QA', 'updated_by' => 'QA',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $schema = ['fields' => [
            ['key' => 'amount', 'label' => '金额', 'type' => 'number', 'required' => true],
            ['key' => 'discount_rate', 'label' => '折扣率', 'type' => 'number', 'required' => true],
            ['key' => 'risk_level', 'label' => '风险', 'type' => 'select', 'required' => true, 'options' => [['label' => '高', 'value' => 'HIGH'], ['label' => '低', 'value' => 'LOW']]],
            ['key' => 'is_over_budget', 'label' => '超预算', 'type' => 'select', 'required' => true, 'options' => [['label' => '是', 'value' => true], ['label' => '否', 'value' => false]]],
            ['key' => 'owner_user_id', 'label' => '负责人', 'type' => 'user', 'required' => true],
        ]];
        $this->formVersionId = DB::table('erp_approval_form_versions')->insertGetId([
            'form_template_id' => $this->formId, 'version_no' => 1, 'version_status' => 'published',
            'schema_snapshot' => json_encode($schema, JSON_UNESCAPED_UNICODE),
            'validation_snapshot' => json_encode(['valid' => true, 'errors' => []]),
            'published_by' => 'QA', 'published_at' => now(), 'updated_by' => 'QA', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_priority_nested_conditions_node_skip_server_snapshot_and_idempotency(): void
    {
        $submissionId = $this->submission(['amount' => '60000.25', 'discount_rate' => '0.15', 'risk_level' => 'LOW', 'is_over_budget' => true, 'owner_user_id' => $this->initiator->legacy_id]);
        $this->flow('QA_HIGH_FALSE', 200, 'EVENT_TRIGGER', ['field' => 'amount', 'operator' => '>=', 'value' => '999999', 'type' => 'decimal']);
        $selectedFlow = $this->flow('QA_NESTED_TRUE', 100, 'EVENT_TRIGGER', [
            'logic' => 'AND', 'children' => [
                ['field' => 'amount', 'operator' => '>=', 'value' => '50000', 'type' => 'decimal'],
                ['logic' => 'OR', 'children' => [
                    ['field' => 'risk_level', 'operator' => '=', 'value' => 'HIGH', 'type' => 'enum'],
                    ['field' => 'is_over_budget', 'operator' => '=', 'value' => true, 'type' => 'boolean'],
                ]],
            ],
        ], secondNodeCondition: ['field' => 'risk_level', 'operator' => '=', 'value' => 'HIGH', 'type' => 'enum']);

        $result = app(ApprovalTriggerEngine::class)->dispatch('CUSTOM_FORM_SUBMISSION', $submissionId, 'submit_form', $this->initiator, [
            'condition_context' => ['amount' => 0, 'risk_level' => 'HIGH'],
            'business_snapshot' => ['forged' => true],
        ], 'EVENT_TRIGGER');

        $this->assertSame('STARTED', $result['result']);
        $task = $result['task']->fresh(['nodes', 'flowVersion']);
        $this->assertSame($selectedFlow, (int) $task->flow_template_id);
        $this->assertSame('60000.25', (string) data_get($task->business_snapshot, 'form_data.amount'));
        $this->assertNull(data_get($task->business_snapshot, 'forged'));
        $this->assertSame('PENDING', $task->nodes->first()->node_status);
        $this->assertSame('SKIPPED', $task->nodes->last()->node_status);
        $this->assertTrue($task->nodes->first()->assignees()->where('user_id', $this->initiator->legacy_id)->exists());

        $again = app(ApprovalTriggerEngine::class)->dispatch('CUSTOM_FORM_SUBMISSION', $submissionId, 'submit_form', $this->initiator, [], 'EVENT_TRIGGER');
        $this->assertSame($task->id, $again['task']->id);
        $this->assertSame(1, ApprovalTask::query()->where('business_object_code', 'CUSTOM_FORM_SUBMISSION')->where('business_id', $submissionId)->count());
    }

    public function test_registry_exposes_only_explicitly_whitelisted_business_fields(): void
    {
        $registry = app(ApprovalBusinessObjectRegistry::class);
        $purchase = $registry->serialize($registry->find('PURCHASE_ORDER'));
        $fields = collect($purchase['fields'])->pluck('value')->all();

        $this->assertContains('total_amount', $fields);
        $this->assertContains('supplier_id', $fields);
        $this->assertNotContains('legacy_system', $fields);
        $this->assertNotContains('sync_batch_no', $fields);
        $this->assertSame(['remark'], collect($purchase['fields'])->where('approval_writable', true)->pluck('value')->values()->all());
    }

    public function test_manual_and_event_modes_are_not_mixed_and_false_condition_bypasses(): void
    {
        $manualSubmission = $this->submission(['amount' => 10, 'discount_rate' => 0, 'risk_level' => 'LOW', 'is_over_budget' => false, 'owner_user_id' => $this->initiator->legacy_id]);
        $manualFlow = $this->flow('QA_MANUAL_ONLY', 100, 'MANUAL_START', []);

        $eventResult = app(ApprovalTriggerEngine::class)->dispatch('CUSTOM_FORM_SUBMISSION', $manualSubmission, 'submit_form', $this->initiator, [], 'EVENT_TRIGGER');
        $this->assertSame('BYPASS', $eventResult['result']);
        $manualResult = app(ApprovalTriggerEngine::class)->dispatch('CUSTOM_FORM_SUBMISSION', $manualSubmission, 'submit_form', $this->initiator, [], 'MANUAL_START', $manualFlow);
        $this->assertSame('STARTED', $manualResult['result']);

        $falseSubmission = $this->submission(['amount' => 5, 'discount_rate' => 0, 'risk_level' => 'LOW', 'is_over_budget' => false, 'owner_user_id' => $this->initiator->legacy_id]);
        $this->flow('QA_FALSE_ONLY', 300, 'EVENT_TRIGGER', ['field' => 'amount', 'operator' => '>', 'value' => 100, 'type' => 'decimal']);
        $false = app(ApprovalTriggerEngine::class)->dispatch('CUSTOM_FORM_SUBMISSION', $falseSubmission, 'submit_form', $this->initiator, [], 'EVENT_TRIGGER');
        $this->assertSame('BYPASS', $false['result']);
        $this->assertDatabaseMissing('erp_approval_tasks', ['business_id' => $falseSubmission, 'business_object_code' => 'CUSTOM_FORM_SUBMISSION']);
    }

    public function test_field_assignee_and_published_version_snapshot_complete_through_same_runtime(): void
    {
        $submissionId = $this->submission(['amount' => 120, 'discount_rate' => 0.2, 'risk_level' => 'HIGH', 'is_over_budget' => false, 'owner_user_id' => $this->initiator->legacy_id]);
        $flowId = $this->flow('QA_FIELD_OWNER', 500, 'EVENT_TRIGGER', ['field' => 'discount_rate', 'operator' => '>', 'value' => '0.1', 'type' => 'decimal'], assignee: ['type' => 'field_user', 'field' => 'owner_user_id', 'value' => 'owner_user_id']);
        $result = app(ApprovalTriggerEngine::class)->dispatch('CUSTOM_FORM_SUBMISSION', $submissionId, 'submit_form', $this->initiator, [], 'EVENT_TRIGGER');
        $task = $result['task']->fresh(['nodes.assignees']);
        $this->assertSame($this->initiator->legacy_id, (int) $task->nodes->first()->assignees->first()->user_id);

        $originalSnapshot = data_get($task->flow_snapshot, 'version_no');
        DB::table('erp_approval_flow_versions')->insert([
            'flow_template_id' => $flowId, 'version_no' => 2, 'version_status' => 'published',
            'definition_snapshot' => json_encode(['schema_version' => 2, 'nodes' => []]),
            'validation_snapshot' => json_encode(['valid' => true]), 'published_by' => 'QA', 'published_at' => now(),
            'updated_by' => 'QA', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('erp_approval_flow_templates')->where('id', $flowId)->update(['current_version' => 2]);
        $this->assertSame($originalSnapshot, data_get($task->fresh()->flow_snapshot, 'version_no'));

        $approved = app(ApprovalTaskApplicationService::class)->decide($task->id, 'approve', '验收通过', $this->initiator, ['approval.task.decide'], true);
        $this->assertSame('APPROVED', $approved->task_status);
        $this->assertSame('approved', DB::table('erp_approval_form_submissions')->where('id', $submissionId)->value('submission_status'));
        $this->assertDatabaseHas('erp_approval_action_executions', ['approval_task_id' => $task->id, 'action_code' => 'approval.complete', 'execution_status' => 'SUCCEEDED']);
    }

    public function test_all_sign_transfer_and_return_previous_use_runtime_assignee_snapshots(): void
    {
        $allSubmission = $this->submission(['amount' => 120, 'discount_rate' => 0, 'risk_level' => 'LOW', 'is_over_budget' => false, 'owner_user_id' => $this->initiator->legacy_id]);
        $allFlow = $this->flow('QA_ALL_SIGN', 700, 'EVENT_TRIGGER', []);
        $definition = $this->definition($allFlow);
        $definition['nodes'][0]['approver_rule'] = ['type' => 'specified_users', 'value' => [$this->initiator->legacy_id, $this->secondary->legacy_id]];
        $definition['nodes'][0]['processing_strategy'] = 'parallel';
        $definition['nodes'][0]['completion_strategy'] = 'ALL';
        $this->replaceDefinition($allFlow, $definition);
        $task = app(ApprovalTriggerEngine::class)->dispatch('CUSTOM_FORM_SUBMISSION', $allSubmission, 'submit_form', $this->initiator, [], 'EVENT_TRIGGER')['task'];
        $waiting = app(ApprovalTaskApplicationService::class)->decide($task->id, 'approve', '第一位通过', $this->initiator, ['approval.task.decide'], false);
        $this->assertSame('PENDING', $waiting->task_status);
        $this->assertSame(1, $waiting->nodes->first()->decisions()->where('decision', 'APPROVE')->count());
        $approved = app(ApprovalTaskApplicationService::class)->decide($task->id, 'approve', '第二位通过', $this->secondary, ['approval.task.decide'], false);
        $this->assertSame('APPROVED', $approved->task_status);

        $transferSubmission = $this->submission(['amount' => 20, 'discount_rate' => 0, 'risk_level' => 'LOW', 'is_over_budget' => false, 'owner_user_id' => $this->initiator->legacy_id]);
        $transferFlow = $this->flow('QA_TRANSFER', 800, 'EVENT_TRIGGER', [], assignee: ['type' => 'field_user', 'field' => 'owner_user_id', 'value' => 'owner_user_id']);
        $transferTask = app(ApprovalTriggerEngine::class)->dispatch('CUSTOM_FORM_SUBMISSION', $transferSubmission, 'submit_form', $this->initiator, [], 'EVENT_TRIGGER')['task'];
        try {
            app(ApprovalTaskApplicationService::class)->transfer($transferTask->id, $this->secondary->legacy_id, '管理员未指定来源', $this->initiator, ['approval.task.decide'], true);
            $this->fail('管理员转交不得在未指定真实来源 assignee 时增加会签分母。');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('source_assignee_id', $exception->errors());
        }
        $denominatorBefore = $transferTask->nodes()->first()->assignees()->where('status', '!=', 'TRANSFERRED')->count();
        app(ApprovalTaskApplicationService::class)->transfer($transferTask->id, $this->secondary->legacy_id, '转交复核', $this->initiator, ['approval.task.decide'], false);
        $this->assertDatabaseHas('erp_approval_node_assignees', ['approval_task_id' => $transferTask->id, 'user_id' => $this->initiator->legacy_id, 'status' => 'TRANSFERRED']);
        $this->assertDatabaseHas('erp_approval_node_assignees', ['approval_task_id' => $transferTask->id, 'user_id' => $this->secondary->legacy_id, 'status' => 'PENDING']);
        $this->assertSame($denominatorBefore, $transferTask->nodes()->first()->assignees()->where('status', '!=', 'TRANSFERRED')->count());

        $returnSubmission = $this->submission(['amount' => 30, 'discount_rate' => 0, 'risk_level' => 'HIGH', 'is_over_budget' => false, 'owner_user_id' => $this->initiator->legacy_id]);
        $returnFlow = $this->flow('QA_RETURN_PREVIOUS', 900, 'EVENT_TRIGGER', [], secondNodeCondition: ['field' => 'risk_level', 'operator' => '=', 'value' => 'HIGH', 'type' => 'enum'], assignee: ['type' => 'field_user', 'field' => 'owner_user_id', 'value' => 'owner_user_id']);
        $returnDefinition = $this->definition($returnFlow);
        $returnDefinition['nodes'][1]['reject_strategy'] = 'RETURN_PREVIOUS';
        $this->replaceDefinition($returnFlow, $returnDefinition);
        $returnTask = app(ApprovalTriggerEngine::class)->dispatch('CUSTOM_FORM_SUBMISSION', $returnSubmission, 'submit_form', $this->initiator, [], 'EVENT_TRIGGER')['task'];
        app(ApprovalTaskApplicationService::class)->decide($returnTask->id, 'approve', '首节点通过', $this->initiator, ['approval.task.decide'], true);
        $returned = app(ApprovalTaskApplicationService::class)->decide($returnTask->id, 'reject', '退回重新处理', $this->initiator, ['approval.task.decide'], true);
        $this->assertSame('PENDING', $returned->nodes->first()->node_status);
        $this->assertSame('WAITING', $returned->nodes->last()->node_status);
        $this->assertSame(2, (int) $returned->nodes->first()->current_round);
        $this->assertDatabaseHas('erp_approval_node_decisions', [
            'approval_task_id' => $returnTask->id, 'approval_task_node_id' => $returned->nodes->first()->id,
            'round_no' => 1, 'decision' => 'APPROVE', 'comment' => '首节点通过',
        ]);
        $this->assertDatabaseHas('erp_approval_task_logs', ['approval_task_id' => $returnTask->id, 'action' => 'returned_previous']);
        $secondRound = app(ApprovalTaskApplicationService::class)->decide($returnTask->id, 'approve', '第二轮重新通过', $this->initiator, ['approval.task.decide'], true);
        $this->assertSame('PENDING', $secondRound->task_status);
        $this->assertSame(2, DB::table('erp_approval_node_decisions')->where('approval_task_node_id', $returned->nodes->first()->id)->count());
        $this->assertDatabaseHas('erp_approval_node_decisions', [
            'approval_task_node_id' => $returned->nodes->first()->id, 'round_no' => 2,
            'decision' => 'APPROVE', 'comment' => '第二轮重新通过',
        ]);
    }

    public function test_batch_decision_reports_explicit_partial_success(): void
    {
        $flowId = $this->flow('QA_BATCH', 980, 'EVENT_TRIGGER', [], assignee: ['type' => 'field_user', 'field' => 'owner_user_id', 'value' => 'owner_user_id']);
        $firstId = $this->submission(['amount' => 1, 'discount_rate' => 0, 'risk_level' => 'LOW', 'is_over_budget' => false, 'owner_user_id' => $this->initiator->legacy_id]);
        $secondId = $this->submission(['amount' => 2, 'discount_rate' => 0, 'risk_level' => 'LOW', 'is_over_budget' => false, 'owner_user_id' => $this->initiator->legacy_id]);
        $first = app(ApprovalTriggerEngine::class)->dispatch('CUSTOM_FORM_SUBMISSION', $firstId, 'submit_form', $this->initiator, [], 'EVENT_TRIGGER', $flowId)['task'];
        $second = app(ApprovalTriggerEngine::class)->dispatch('CUSTOM_FORM_SUBMISSION', $secondId, 'submit_form', $this->initiator, [], 'EVENT_TRIGGER', $flowId)['task'];
        app(ApprovalTaskApplicationService::class)->decide($first->id, 'approve', '先完成一条', $this->initiator, ['approval.task.decide'], true);

        $result = app(ApprovalTaskApplicationService::class)->batchDecide([$first->id, $second->id], '批量审核', $this->initiator, ['approval.task.decide'], true);
        $this->assertSame(1, $result['success_count']);
        $this->assertSame(1, $result['failed_count']);
        $this->assertTrue($result['partial_success']);
        $this->assertTrue(collect($result['results'])->firstWhere('task_id', $second->id)['success']);
        $this->assertFalse(collect($result['results'])->firstWhere('task_id', $first->id)['success']);
    }

    public function test_sales_order_change_publish_rejects_duplicate_semantic_approval_type(): void
    {
        $flowId = $this->flow('QA_DUPLICATE_TYPE', 990, 'EVENT_TRIGGER', [], ['field' => 'risk_level', 'operator' => '=', 'value' => 'HIGH', 'type' => 'enum']);
        $definition = $this->definition($flowId);
        $definition['business_object_code'] = 'SALES_ORDER_CHANGE';
        $definition['nodes'][0]['approval_type'] = 'business';
        $definition['nodes'][1]['approval_type'] = 'business';
        $validation = app(ApprovalFlowApplicationService::class)->validateDefinition($definition);

        $this->assertFalse($validation['valid']);
        $this->assertTrue(collect($validation['errors'])->contains(fn ($error) => str_contains($error, '审核类型不得重复')));
    }

    public function test_ratio_sign_cc_and_action_nodes_run_without_business_specific_branches(): void
    {
        $submissionId = $this->submission([
            'amount' => 66,
            'discount_rate' => 0.05,
            'risk_level' => 'LOW',
            'is_over_budget' => false,
            'owner_user_id' => $this->initiator->legacy_id,
        ]);
        $flowId = $this->flow('QA_RATIO_CC_ACTION', 950, 'EVENT_TRIGGER', []);
        $definition = $this->definition($flowId);
        $definition['nodes'][0]['approver_rule'] = [
            'type' => 'specified_users',
            'value' => [$this->initiator->legacy_id, $this->secondary->legacy_id],
        ];
        $definition['nodes'][0]['processing_strategy'] = 'parallel';
        $definition['nodes'][0]['completion_strategy'] = 'RATIO';
        $definition['nodes'][0]['required_approver_ratio'] = 0.5;
        $definition['nodes'][] = [
            'key' => 'cc', 'name' => '验收抄送', 'node_type' => 'CC',
            'approver_rule' => ['type' => 'role', 'value' => 'admin'],
            'entry_conditions' => ['logic' => 'AND', 'children' => []],
        ];
        $definition['nodes'][] = [
            'key' => 'action', 'name' => '验收动作', 'node_type' => 'ACTION',
            'action_code' => 'approval.complete', 'action_config' => [],
            'entry_conditions' => ['logic' => 'AND', 'children' => []],
        ];
        $this->replaceDefinition($flowId, $definition);

        $task = app(ApprovalTriggerEngine::class)
            ->dispatch('CUSTOM_FORM_SUBMISSION', $submissionId, 'submit_form', $this->initiator, [], 'EVENT_TRIGGER')['task'];
        $approved = app(ApprovalTaskApplicationService::class)
            ->decide($task->id, 'approve', '比例会签通过', $this->initiator, ['approval.task.decide'], false);

        $this->assertSame('APPROVED', $approved->task_status);
        $statuses = $approved->nodes()->orderBy('node_order')->pluck('node_status')->all();
        $this->assertSame(['APPROVED', 'APPROVED', 'APPROVED'], $statuses);
        $this->assertDatabaseHas('erp_approval_notifications', [
            'approval_task_id' => $task->id,
            'notification_type' => 'CC',
        ]);
        $this->assertDatabaseHas('erp_approval_action_executions', [
            'approval_task_id' => $task->id,
            'action_code' => 'approval.complete',
            'execution_status' => 'SUCCEEDED',
        ]);
    }

    public function test_failed_completion_action_does_not_complete_task_and_can_be_retried(): void
    {
        FlakyApprovalAction::$shouldFail = true;
        DB::table('erp_approval_business_actions')->insert([
            'business_object_id' => null, 'action_code' => 'qa.flaky.complete', 'action_name' => '验收失败恢复动作',
            'result_event' => 'approved', 'handler_class' => FlakyApprovalAction::class, 'enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $submissionId = $this->submission(['amount' => 80, 'discount_rate' => 0, 'risk_level' => 'LOW', 'is_over_budget' => false, 'owner_user_id' => $this->initiator->legacy_id]);
        $flowId = $this->flow('QA_ACTION_RECOVERY', 1000, 'EVENT_TRIGGER', [], assignee: ['type' => 'field_user', 'field' => 'owner_user_id', 'value' => 'owner_user_id']);
        $definition = $this->definition($flowId);
        $definition['completion_actions'][0]['action_key'] = 'qa.flaky.complete';
        $this->replaceDefinition($flowId, $definition);
        $task = app(ApprovalTriggerEngine::class)->dispatch('CUSTOM_FORM_SUBMISSION', $submissionId, 'submit_form', $this->initiator, [], 'EVENT_TRIGGER')['task'];

        try {
            app(ApprovalTaskApplicationService::class)->decide($task->id, 'approve', '首次执行失败', $this->initiator, ['approval.task.decide'], true);
            $this->fail('失败业务动作不得伪装成审批完成。');
        } catch (\Illuminate\Validation\ValidationException) {
            $failed = $task->fresh();
            $this->assertSame('PENDING', $failed->task_status);
            $this->assertSame('FAILED', $failed->action_status);
            $this->assertDatabaseHas('erp_approval_action_executions', ['approval_task_id' => $task->id, 'action_code' => 'qa.flaky.complete', 'execution_status' => 'FAILED']);
        }
        FlakyApprovalAction::$shouldFail = false;
        $approved = app(ApprovalTaskApplicationService::class)->decide($task->id, 'approve', '修复后重试', $this->initiator, ['approval.task.decide'], true);
        $this->assertSame('APPROVED', $approved->task_status);
        $this->assertSame('SUCCEEDED', $approved->action_status);
    }

    private function submission(array $data): int
    {
        return DB::table('erp_approval_form_submissions')->insertGetId([
            'submission_no' => 'QA-AF-'.strtoupper(substr(uniqid(), -10)), 'form_template_id' => $this->formId,
            'form_version_id' => $this->formVersionId, 'subject' => '通用审批验收申请', 'submission_status' => 'submitted',
            'form_data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'submitted_by' => $this->initiator->legacy_id,
            'submitted_by_name' => $this->initiator->nickname, 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function flow(string $code, int $priority, string $triggerMode, array $startConditions, ?array $secondNodeCondition = null, ?array $assignee = null): int
    {
        $assignee ??= ['type' => 'role', 'value' => 'admin'];
        $nodes = [[
            'key' => 'approval', 'name' => '通用审核', 'node_type' => 'APPROVAL', 'approval_type' => 'business',
            'permission_code' => 'approval.task.decide', 'processing_strategy' => 'sequential', 'completion_strategy' => 'ANY',
            'approver_rule' => $assignee, 'entry_conditions' => ['logic' => 'AND', 'children' => []],
            'allow_reject' => true, 'allow_transfer' => true, 'comment_required' => true, 'reject_strategy' => 'TERMINATE',
        ]];
        if ($secondNodeCondition !== null) $nodes[] = [
            'key' => 'conditional', 'name' => '条件复核', 'node_type' => 'APPROVAL', 'approval_type' => 'business',
            'permission_code' => 'approval.task.decide', 'processing_strategy' => 'sequential', 'completion_strategy' => 'ANY',
            'approver_rule' => $assignee, 'entry_conditions' => $secondNodeCondition,
            'allow_reject' => true, 'allow_transfer' => true, 'comment_required' => true, 'reject_strategy' => 'TERMINATE',
        ];
        $definition = [
            'schema_version' => 2, 'source_mode' => 'custom_form', 'form_template_id' => $this->formId,
            'business_object_code' => 'CUSTOM_FORM_SUBMISSION', 'event_code' => 'submit_form',
            'trigger_mode' => $triggerMode, 'execution_mode' => 'BEFORE_ACTION', 'priority' => $priority,
            'match_strategy' => 'FIRST_MATCH', 'start_conditions' => $startConditions, 'applicable_scope' => ['type' => 'all', 'department_ids' => []],
            'allow_self_approval' => true, 'nodes' => $nodes,
            'completion_actions' => [
                ['event' => 'approved', 'action_key' => 'approval.complete', 'config' => []],
                ['event' => 'rejected', 'action_key' => 'approval.reject', 'config' => []],
            ],
            'notifications' => ['websocket' => true, 'in_app' => true, 'email' => false],
        ];
        $flowId = DB::table('erp_approval_flow_templates')->insertGetId([
            'flow_code' => $code.'_'.strtoupper(substr(uniqid(), -6)), 'flow_name' => $code,
            'business_module' => '验收', 'business_type' => 'CUSTOM_FORM_SUBMISSION', 'business_object_code' => 'CUSTOM_FORM_SUBMISSION',
            'event_code' => 'submit_form', 'trigger_mode' => $triggerMode, 'execution_mode' => 'BEFORE_ACTION',
            'priority' => $priority, 'match_strategy' => 'FIRST_MATCH', 'business_scene' => '通用运行时验收',
            'applicable_scope' => 'all', 'status' => 'enabled', 'current_version' => 1, 'created_by' => 'QA', 'updated_by' => 'QA',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('erp_approval_flow_versions')->insert([
            'flow_template_id' => $flowId, 'version_no' => 1, 'version_status' => 'published',
            'definition_snapshot' => json_encode($definition, JSON_UNESCAPED_UNICODE),
            'validation_snapshot' => json_encode(['valid' => true, 'errors' => [], 'warnings' => []]),
            'published_by' => 'QA', 'published_at' => now(), 'updated_by' => 'QA', 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $flowId;
    }

    private function definition(int $flowId): array
    {
        return json_decode((string) DB::table('erp_approval_flow_versions')->where('flow_template_id', $flowId)->where('version_no', 1)->value('definition_snapshot'), true);
    }

    private function replaceDefinition(int $flowId, array $definition): void
    {
        DB::table('erp_approval_flow_versions')->where('flow_template_id', $flowId)->where('version_no', 1)
            ->update(['definition_snapshot' => json_encode($definition, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
    }
}

class FlakyApprovalAction implements ApprovalBusinessActionHandler
{
    public static bool $shouldFail = true;

    public function execute(ApprovalBusinessAction $action, ApprovalTask $task, ?ApprovalTaskNode $node, array $config, string $operator, ?string $decision = null, ?string $comment = null): array
    {
        if (self::$shouldFail) throw new \RuntimeException('验收模拟业务动作失败');
        return ['status' => 'approved', 'candidate_status' => 'APPROVED', 'operated_by' => $operator];
    }
}
