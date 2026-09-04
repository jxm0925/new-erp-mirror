<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\ApprovalBusinessObject;
use App\Models\Erp\ApprovalBusinessObjectField;
use App\Services\Erp\ApprovalBusinessObjectAccessService;
use App\Services\Erp\ApprovalProviders\DatabaseBusinessObjectProvider;
use App\Services\Erp\ApprovalRegistryApplicationService;
use App\Services\Erp\ApprovalTaskApplicationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ApprovalCenterFinalDefectTest extends TestCase
{
    use DatabaseTransactions;

    public function test_business_object_access_gate_denies_missing_permission_and_missing_scope_resolver(): void
    {
        $user = $this->user('gate_a');
        $access = app(ApprovalBusinessObjectAccessService::class);
        $scoped = $this->object('FORM_QA', 'erp_approval_form_submissions', 'id', 'approval.source.qa');
        $unscoped = $this->object('UNIT_QA', 'erp_units', 'id', 'approval.source.qa');

        $this->assertFalse($access->canBrowse($scoped, $user, [], false));
        $this->assertFalse($access->canBrowse($unscoped, $user, ['approval.source.qa'], false));
        $this->assertTrue($access->canBrowse($unscoped, $user, [], true));
    }

    public function test_business_object_access_gate_applies_self_scope_to_source_rows(): void
    {
        $owner = $this->user('gate_owner');
        $other = $this->user('gate_other');
        $formId = DB::table('erp_approval_form_templates')->insertGetId([
            'form_code' => 'GATE_'.uniqid(), 'form_name' => '权限门禁表单', 'business_module' => 'QA',
            'status' => 'enabled', 'current_version' => 0, 'created_by' => 'QA', 'updated_by' => 'QA',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $formVersionId = DB::table('erp_approval_form_versions')->insertGetId([
            'form_template_id' => $formId, 'version_no' => 1, 'version_status' => 'published',
            'schema_snapshot' => json_encode(['fields' => []]), 'validation_snapshot' => json_encode(['valid' => true]),
            'published_by' => 'QA', 'published_at' => now(), 'updated_by' => 'QA', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([$owner, $other] as $index => $user) DB::table('erp_approval_form_submissions')->insert([
            'submission_no' => 'GATE-'.$user->legacy_id, 'form_template_id' => $formId, 'form_version_id' => $formVersionId,
            'subject' => '权限记录'.$index, 'submission_status' => 'draft', 'form_data' => json_encode([]),
            'submitted_by' => $user->legacy_id, 'submitted_by_name' => $user->nickname,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $object = $this->object('FORM_QA', 'erp_approval_form_submissions', 'id', 'approval.source.qa');
        $query = DB::table('erp_approval_form_submissions')->where('form_template_id', $formId);
        app(ApprovalBusinessObjectAccessService::class)->applyVisibleScope($query, $object, $owner, ['approval.source.qa'], false);
        $this->assertSame(['GATE-'.$owner->legacy_id], $query->pluck('submission_no')->all());
    }

    public function test_database_provider_uses_registered_non_id_integer_primary_key(): void
    {
        $legacyId = ((int) DB::table('erp_departments')->max('legacy_id')) + 1;
        DB::table('erp_departments')->insert([
            'legacy_id' => $legacyId, 'name' => '非ID主键验收部门', 'status' => 'normal', 'sort' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $department = DB::table('erp_departments')->orderBy('legacy_id')->first();
        $this->assertNotNull($department);
        $object = $this->object('DEPARTMENT_QA', 'erp_departments', 'legacy_id', 'department.view');
        $field = new ApprovalBusinessObjectField([
            'field_code' => 'name', 'field_name' => '部门名称', 'field_type' => 'string', 'source_path' => 'name',
            'condition_enabled' => true, 'display_enabled' => true, 'reference_enabled' => false, 'approval_writable' => false,
        ]);
        $object->setRelation('fields', collect([$field]));
        $object->display_fields = ['name'];
        $object->search_fields = ['name'];

        $row = app(DatabaseBusinessObjectProvider::class)->find($object, (int) $department->legacy_id);
        $this->assertSame((int) $department->legacy_id, (int) $row['legacy_id']);
        $this->assertSame($department->name, $row['name']);
    }

    public function test_registry_rejects_non_integer_primary_key_in_v1(): void
    {
        $this->expectException(ValidationException::class);
        app(ApprovalRegistryApplicationService::class)->register([
            'source_table' => 'erp_units', 'adapter_key' => null, 'object_code' => 'UNIT_STRING_PK_QA',
            'object_name' => '单位字符串主键验收', 'business_module' => 'QA', 'primary_key' => 'unit_code',
            'route_pattern' => null, 'view_permission_code' => null,
            'fields' => [[
                'field_code' => 'unit_code', 'field_name' => '单位编码', 'field_type' => 'string', 'selected' => true,
                'condition_enabled' => true, 'display_enabled' => true, 'search_enabled' => true,
                'reference_enabled' => false, 'approval_writable' => false, 'sort' => 0,
            ]],
            'event' => ['event_code' => 'unit_event', 'event_name' => '单位事件', 'manual_start_allowed' => false, 'event_trigger_allowed' => true],
        ], 'QA');
    }

    public function test_initiated_scope_returns_only_current_users_applications_with_pagination(): void
    {
        $owner = $this->user('initiated_owner');
        $other = $this->user('initiated_other');
        foreach ([$owner, $other] as $index => $initiator) {
            DB::table('erp_approval_tasks')->insert([
                'task_no' => 'INITIATED-QA-'.$initiator->legacy_id,
                'business_type' => 'INITIATED_QA',
                'business_id' => $index + 1,
                'business_no' => 'INITIATED-BIZ-'.($index + 1),
                'subject' => '我的申请分页验收',
                'risk_level' => 'low',
                'task_status' => 'PENDING',
                'current_node_order' => null,
                'initiator_id' => $initiator->legacy_id,
                'initiator_name' => $initiator->nickname,
                'business_snapshot' => json_encode(['id' => $index + 1]),
                'flow_snapshot' => json_encode(['flow_name' => '我的申请验收']),
                'submitted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $page = app(ApprovalTaskApplicationService::class)->paginate([
            'scope' => 'initiated',
            'page' => 1,
            'per_page' => 10,
        ], $owner, ['approval.task.view'], false);

        $this->assertSame(1, $page->total());
        $this->assertSame('INITIATED-QA-'.$owner->legacy_id, $page->items()[0]->task_no);
        $this->assertSame(1, app(ApprovalTaskApplicationService::class)->summary($owner, ['approval.task.view'], false)['initiated']);
    }

    private function user(string $prefix): object
    {
        $id = ((int) DB::table('erp_legacy_admin_users')->max('legacy_id')) + 1;
        $username = $prefix.'_'.uniqid();
        DB::table('erp_legacy_admin_users')->insert([
            'legacy_id' => $id, 'username' => $username, 'nickname' => $username,
            'password_hash' => password_hash('123456', PASSWORD_BCRYPT), 'status' => 'normal',
            'is_sales' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return (object) ['legacy_id' => $id, 'username' => $username, 'nickname' => $username, 'auth_group_names' => '[]'];
    }

    private function object(string $code, string $table, string $primaryKey, ?string $permission): ApprovalBusinessObject
    {
        return new ApprovalBusinessObject([
            'object_code' => $code, 'source_type' => 'database', 'source_table' => $table,
            'primary_key' => $primaryKey, 'view_permission_code' => $permission, 'enabled' => true,
        ]);
    }
}
