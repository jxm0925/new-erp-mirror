<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\{Item, ProductionTask, Unit, WorkOrder};
use App\Services\Erp\RbacBootstrapService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductionTaskCollaborationInviteTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_adds_real_production_users_and_task_detail_projects_their_identity(): void
    {
        app(RbacBootstrapService::class)->bootstrap(true);
        $ownerId = random_int(930001, 939999);
        $candidateId = random_int(940001, 949999);
        $nonCollaboratorId = random_int(950001, 959999);
        foreach ([[$ownerId, '负责人', '装配一组', 'production_operator'],
            [$candidateId, '真实协同员', '装配二组', 'production_operator'],
            [$nonCollaboratorId, '无协同权限人员', '质检组', 'department_principal']] as [$id, $name, $department, $role]) {
            DB::table('erp_legacy_admin_users')->insert([
                'legacy_id' => $id, 'username' => 'collab-'.$id, 'nickname' => $name,
                'department_names' => json_encode([$department], JSON_UNESCAPED_UNICODE),
                'status' => 'normal', 'auth_group_names' => '[]', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('erp_rbac_user_roles')->insert([
                'user_legacy_id' => $id,
                'role_id' => DB::table('erp_rbac_roles')->where('code', $role)->value('id'),
            ]);
        }

        $suffix = Str::upper(Str::random(8));
        $unit = Unit::create(['unit_code' => 'CI-U-'.$suffix, 'unit_name' => '件', 'unit_type' => 'quantity',
            'decimal_places' => 0, 'is_base' => true, 'status' => 'enabled']);
        $item = Item::create(['item_code' => 'CI-I-'.$suffix, 'item_name' => '协同测试成品',
            'item_type' => 'finished_good', 'unit_id' => $unit->id, 'status' => 'enabled']);
        $workOrder = WorkOrder::create(['work_order_no' => 'CI-WO-'.$suffix, 'source_type' => 'stock_prebuild',
            'output_item_id' => $item->id, 'target_qty' => 1, 'target_base_qty' => 1,
            'target_unit_id' => $unit->id, 'base_unit_id' => $unit->id, 'status' => 'IN_PROGRESS',
            'responsible_user_legacy_id' => $ownerId, 'collaboration_enabled' => true, 'business_version' => 1]);
        $task = ProductionTask::create(['task_no' => 'CI-T-'.$suffix, 'work_order_id' => $workOrder->id,
            'execution_mode' => 'quantity', 'operation_code_snapshot' => 'OP', 'operation_name_snapshot' => '装配',
            'sequence_no_snapshot' => 1, 'status' => 'READY', 'assignee_user_legacy_id' => $ownerId,
            'claimed_at' => now(), 'business_version' => 1]);

        $ownerToken = $this->token($ownerId);
        $candidateToken = $this->token($candidateId);
        $directory = $this->withToken($ownerToken)->getJson('/api/v1/erp/user-directory/users?scope=production&capability=collaborate&per_page=100')
            ->assertOk()->json('data');
        $this->assertContains($candidateId, array_column($directory, 'user_id'));
        $this->assertNotContains($nonCollaboratorId, array_column($directory, 'user_id'));
        $addPayload = [
            'client_command_id' => 'collab-add-'.Str::uuid(), 'expected_version' => 1,
            'employee_legacy_ids' => [$candidateId],
        ];
        $this->withToken($ownerToken)->postJson('/api/v1/erp/production/tasks/'.$task->id.'/collaborators', $addPayload)->assertOk()
            ->assertJsonPath('data.added_employee_legacy_ids.0', $candidateId)
            ->assertJsonPath('data.task_business_version', 2);
        $this->withToken($ownerToken)->postJson('/api/v1/erp/production/tasks/'.$task->id.'/collaborators', $addPayload)
            ->assertOk()
            ->assertJsonPath('data.added_employee_legacy_ids.0', $candidateId)
            ->assertJsonPath('data.task_business_version', 2);

        $this->assertDatabaseHas('erp_production_task_collaborators', [
            'task_id' => $task->id, 'employee_legacy_id' => $candidateId, 'role' => 'collaborator', 'left_at' => null,
        ]);
        $this->withToken($ownerToken)->getJson('/api/v1/erp/production/tasks/'.$task->id)
            ->assertOk()
            ->assertJsonPath('data.assignee_user.display_name', '负责人')
            ->assertJsonPath('data.assignee_user.department_name', '装配一组')
            ->assertJsonPath('data.collaborators.0.employee.display_name', '真实协同员')
            ->assertJsonPath('data.collaborators.0.employee.department_name', '装配二组');

        $this->withToken($ownerToken)->postJson('/api/v1/erp/production/tasks/'.$task->id.'/collaborators', [
            'client_command_id' => 'collab-invalid-'.Str::uuid(), 'expected_version' => 2,
            'employee_legacy_ids' => [$nonCollaboratorId],
        ])->assertUnprocessable()->assertJsonPath('error_code', 'collaborator_invalid');
        $this->assertSame(1, DB::table('erp_production_task_collaborators')->where('task_id', $task->id)->whereNull('left_at')->count());

        $this->withToken($candidateToken)->postJson('/api/v1/erp/production/tasks/'.$task->id.'/collaborators', [
            'client_command_id' => 'collab-forbidden-'.Str::uuid(), 'expected_version' => 2,
            'employee_legacy_ids' => [$ownerId],
        ])->assertForbidden()->assertJsonPath('error_code', 'task_owner_required');
    }

    private function token(int $userId): string
    {
        $token = 'collab-token-'.Str::random(32);
        DB::table('erp_auth_tokens')->insert(['user_legacy_id' => $userId, 'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        return $token;
    }
}
