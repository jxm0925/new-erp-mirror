<?php

namespace Tests\Feature\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\ProductionDemand;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesOrderLine;
use App\Services\Erp\WorkOrderApplicationService;
use App\Services\Erp\RbacBootstrapService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkOrderDomainCoreTest extends TestCase
{
    use DatabaseTransactions;

    private const PERMISSIONS = [
        'production.demand.view', 'production.work_order.view', 'production.work_order.create',
        'production.work_order.edit', 'production.work_order.submit', 'production.work_order.cancel',
    ];

    public function test_01_create_split_idempotency_quantity_and_status_lifecycle(): void
    {
        [$user, $demand] = $this->fixture();
        $service = app(WorkOrderApplicationService::class);

        $create = ['client_command_id' => 'wo-create-1', 'production_demand_id' => $demand->id, 'expected_demand_version' => 1, 'target_qty' => 4, 'planned_date' => '2026-09-01', 'production_batch' => 'B-01'];
        $workOrder = $service->createDraft($create, $user, self::PERMISSIONS);
        $this->assertDatabaseHas('erp_work_order_command_ledgers', ['client_command_id' => 'wo-create-1', 'status' => 'succeeded']);
        $same = $service->createDraft($create, $user, self::PERMISSIONS);
        $this->assertSame($workOrder->id, $same->id);
        $this->assertSame(1, DB::table('erp_work_orders')->where('production_demand_id', $demand->id)->count());

        try {
            $service->createDraft([...$create, 'target_qty' => 5], $user, self::PERMISSIONS);
            $this->fail('A changed request hash must not reuse a client command.');
        } catch (WorkOrderDomainException $exception) {
            $this->assertSame('idempotency_hash_conflict', $exception->errorCode);
            $this->assertSame(409, $exception->status);
        }

        try {
            $service->createDraft(['client_command_id' => 'wo-create-2', 'production_demand_id' => $demand->id, 'expected_demand_version' => 2, 'target_qty' => 7], $user, self::PERMISSIONS);
            $this->fail('The second split must be rejected when it exceeds available demand.');
        } catch (WorkOrderDomainException $exception) {
            $this->assertSame('quantity_conflict', $exception->errorCode);
            $this->assertSame(409, $exception->status);
        }

        $updated = $service->updateDraft($workOrder->id, ['client_command_id' => 'wo-edit-1', 'expected_version' => 1, 'target_qty' => 5], $user, self::PERMISSIONS);
        $this->assertSame(2, (int) $updated->business_version);
        $this->assertSame(5.0, (float) $demand->fresh()->allocated_qty);
        $this->assertSame(0.0, (float) $demand->fresh()->consumed_qty);

        try {
            $service->updateDraft($workOrder->id, ['client_command_id' => 'wo-edit-stale', 'expected_version' => 1, 'target_qty' => 3], $user, self::PERMISSIONS);
            $this->fail('A stale expected version must be rejected.');
        } catch (WorkOrderDomainException $exception) {
            $this->assertSame('version_conflict', $exception->errorCode);
            $this->assertSame(409, $exception->status);
        }

        $submitted = $service->submit($workOrder->id, ['client_command_id' => 'wo-submit-1', 'expected_version' => 2], $user, self::PERMISSIONS);
        $this->assertSame(WorkOrderApplicationService::WAIT_RELEASE, $submitted->status);
        $returned = $service->returnToDraft($workOrder->id, ['client_command_id' => 'wo-return-1', 'expected_version' => 3], $user, self::PERMISSIONS);
        $this->assertSame(WorkOrderApplicationService::DRAFT, $returned->status);
        $cancelled = $service->cancel($workOrder->id, ['client_command_id' => 'wo-cancel-1', 'expected_version' => 4, 'reason' => '需求调整'], $user, self::PERMISSIONS);
        $this->assertSame(WorkOrderApplicationService::CANCELLED, $cancelled->status);
        $this->assertSame(0.0, (float) $demand->fresh()->allocated_qty);
        $this->assertSame(0.0, (float) $demand->fresh()->consumed_qty);
        $this->assertSame(10.0, (float) $demand->fresh()->remaining_qty);
        $this->assertSame(5, DB::table('erp_work_order_status_logs')->where('work_order_id', $workOrder->id)->count());
    }

    public function test_02_permission_and_data_scope_are_enforced(): void
    {
        [$owner, $demand] = $this->fixture(101);
        $other = DB::table('erp_legacy_admin_users')->where('legacy_id', 202)->first();
        $service = app(WorkOrderApplicationService::class);

        try {
            $service->paginateDemands([], $owner, []);
            $this->fail('Missing permission must be rejected.');
        } catch (WorkOrderDomainException $exception) {
            $this->assertSame('permission_denied', $exception->errorCode);
        }

        try {
            $service->showDemand($demand->id, $other, ['production.demand.view']);
            $this->fail('A user outside the self data scope must not see the demand.');
        } catch (WorkOrderDomainException $exception) {
            $this->assertSame('data_scope_denied', $exception->errorCode);
        }
    }

    public function test_03_number_and_command_ledger_are_unique_and_failed_commands_are_recorded(): void
    {
        [$user, $demand] = $this->fixture(303);
        $service = app(WorkOrderApplicationService::class);
        $first = $service->createDraft(['client_command_id' => 'wo-number-1', 'production_demand_id' => $demand->id, 'expected_demand_version' => 1, 'target_qty' => 2], $user, self::PERMISSIONS);
        $second = $service->createDraft(['client_command_id' => 'wo-number-2', 'production_demand_id' => $demand->id, 'expected_demand_version' => 2, 'target_qty' => 3], $user, self::PERMISSIONS);
        $this->assertNotSame($first->work_order_no, $second->work_order_no);
        $this->assertDatabaseHas('erp_work_order_command_ledgers', ['client_command_id' => 'wo-number-1', 'status' => 'succeeded', 'result_id' => $first->id]);
        $this->assertDatabaseHas('erp_work_order_command_ledgers', ['client_command_id' => 'wo-number-2', 'status' => 'succeeded', 'result_id' => $second->id]);
        try {
            $service->createDraft(['client_command_id' => 'wo-number-failed', 'production_demand_id' => $demand->id, 'expected_demand_version' => 3, 'target_qty' => 6], $user, self::PERMISSIONS);
            $this->fail('An over-allocation command must fail.');
        } catch (WorkOrderDomainException $exception) {
            $this->assertSame('quantity_conflict', $exception->errorCode);
        }
        $this->assertDatabaseHas('erp_work_order_command_ledgers', ['client_command_id' => 'wo-number-failed', 'status' => 'failed']);
    }

    public function test_04_consumption_is_execution_fact_and_cancel_only_releases_allocation(): void
    {
        [$user, $demand] = $this->fixture(404, 3);
        $service = app(WorkOrderApplicationService::class);
        $workOrder = $service->createDraft([
            'client_command_id' => 'wo-consumed-1', 'production_demand_id' => $demand->id, 'expected_demand_version' => 1, 'target_qty' => 7,
        ], $user, self::PERMISSIONS);
        $fresh = $demand->fresh();
        $this->assertSame(3.0, (float) $fresh->consumed_qty);
        $this->assertSame(7.0, (float) $fresh->allocated_qty);
        $this->assertSame(0.0, (float) $fresh->remaining_qty);

        $service->cancel($workOrder->id, [
            'client_command_id' => 'wo-consumed-cancel', 'expected_version' => 1,
        ], $user, self::PERMISSIONS);
        $fresh = $demand->fresh();
        $this->assertSame(3.0, (float) $fresh->consumed_qty);
        $this->assertSame(0.0, (float) $fresh->allocated_qty);
        $this->assertSame(7.0, (float) $fresh->remaining_qty);
    }

    public function test_05_responsible_user_and_organization_are_server_scoped(): void
    {
        [$user, $demand] = $this->fixture(505);
        $service = app(WorkOrderApplicationService::class);
        try {
            $service->createDraft([
                'client_command_id' => 'wo-scope-1', 'production_demand_id' => $demand->id, 'expected_demand_version' => 1, 'target_qty' => 1,
                'responsible_user_legacy_id' => 202, 'organization_code' => 'CLIENT-CONTROLLED',
            ], $user, self::PERMISSIONS);
            $this->fail('A self-scoped user must not assign an out-of-scope responsible user.');
        } catch (WorkOrderDomainException $exception) {
            $this->assertSame('validation_error', $exception->errorCode);
        }
        $workOrder = $service->createDraft([
            'client_command_id' => 'wo-scope-2', 'production_demand_id' => $demand->id, 'expected_demand_version' => 1, 'target_qty' => 1,
            'organization_code' => 'CLIENT-CONTROLLED',
        ], $user, self::PERMISSIONS);
        $this->assertNull($workOrder->organization_code);
    }

    public function test_06_production_roles_have_a_real_permission_matrix(): void
    {
        app(RbacBootstrapService::class)->bootstrap(true);
        $productionPermissionIds = DB::table('erp_rbac_permissions')
            ->whereIn('code', [
                'production.demand.view', 'production.work_order.view', 'production.work_order.create',
                'production.work_order.edit', 'production.work_order.submit', 'production.work_order.cancel',
            ])->pluck('id');
        $managerId = DB::table('erp_rbac_roles')->where('code', 'production_manager')->value('id');
        $operatorId = DB::table('erp_rbac_roles')->where('code', 'production_operator')->value('id');
        $this->assertSame(6, DB::table('erp_rbac_role_permissions')->where('role_id', $managerId)->whereIn('permission_id', $productionPermissionIds)->count());
        $this->assertSame(2, DB::table('erp_rbac_role_permissions')->where('role_id', $operatorId)->whereIn('permission_id', $productionPermissionIds)->count());
        $principalId = DB::table('erp_rbac_roles')->where('code', 'department_principal')->value('id');
        $this->assertSame(2, DB::table('erp_rbac_role_permissions')->where('role_id', $principalId)->whereIn('permission_id', $productionPermissionIds)->count());
    }

    public function test_07_stale_processing_is_recovered_or_left_in_explicit_unknown_state(): void
    {
        [$user, $demand] = $this->fixture(707);
        $service = app(WorkOrderApplicationService::class);
        $payload = ['client_command_id' => 'wo-recovery-committed', 'production_demand_id' => $demand->id, 'expected_demand_version' => 1, 'target_qty' => 1];
        $workOrder = $service->createDraft($payload, $user, self::PERMISSIONS);
        DB::table('erp_work_order_command_ledgers')->where('client_command_id', $payload['client_command_id'])->update([
            'status' => 'processing', 'processing_finished_at' => null, 'processing_started_at' => now()->subMinutes(10),
        ]);

        $recovered = $service->createDraft($payload, $user, self::PERMISSIONS);
        $this->assertSame($workOrder->id, $recovered->id);
        $this->assertDatabaseHas('erp_work_order_command_ledgers', [
            'client_command_id' => $payload['client_command_id'], 'status' => 'succeeded', 'result_id' => $workOrder->id,
        ]);

        $unknownPayload = ['client_command_id' => 'wo-recovery-unknown', 'production_demand_id' => $demand->id, 'expected_demand_version' => 2, 'target_qty' => 1];
        $identity = ['aggregate_type' => 'work_order', 'aggregate_id' => null, 'command_type' => 'create_draft', 'payload' => $unknownPayload];
        unset($identity['payload']['client_command_id']);
        ksort($identity['payload']);
        DB::table('erp_work_order_command_ledgers')->insert([
            'client_command_id' => $unknownPayload['client_command_id'], 'command_type' => 'create_draft', 'aggregate_type' => 'work_order',
            'aggregate_id' => null, 'request_hash' => hash('sha256', json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'status' => 'processing', 'processing_started_at' => now()->subMinutes(10), 'created_at' => now(), 'updated_at' => now(),
        ]);
        try {
            $service->createDraft($unknownPayload, $user, self::PERMISSIONS);
            $this->fail('Unknown command outcomes must require explicit recovery confirmation.');
        } catch (WorkOrderDomainException $exception) {
            $this->assertSame('command_recovery_required', $exception->errorCode);
            $this->assertSame(409, $exception->status);
        }
        $this->assertDatabaseHas('erp_work_order_command_ledgers', [
            'client_command_id' => $unknownPayload['client_command_id'], 'status' => 'recovery_required',
        ]);
        try {
            $service->createDraft($unknownPayload, $user, self::PERMISSIONS);
            $this->fail('An unresolved command must remain blocked on repeated requests.');
        } catch (WorkOrderDomainException $exception) {
            $this->assertSame('command_recovery_required', $exception->errorCode);
            $this->assertSame(409, $exception->status);
        }
    }

    public function test_08_two_mysql_connections_preserve_ledger_identity_race_result(): void
    {
        $connection = config('database.connections.mysql');
        config(['database.connections.wo02_race_a' => $connection, 'database.connections.wo02_race_b' => $connection]);
        DB::purge('wo02_race_a');
        DB::purge('wo02_race_b');
        $first = DB::connection('wo02_race_a');
        $second = DB::connection('wo02_race_b');
        $this->assertNotSame(spl_object_id($first->getPdo()), spl_object_id($second->getPdo()));
        $lockName = 'wo02-ledger-race-'.uniqid();
        $this->assertSame(1, (int) $first->selectOne('SELECT GET_LOCK(?, 1) AS locked', [$lockName])->locked);
        $this->assertSame(0, (int) $second->selectOne('SELECT GET_LOCK(?, 0) AS locked', [$lockName])->locked);
        $id = 'wo-two-connection-race-'.uniqid();
        $row = [
            'client_command_id' => $id, 'command_type' => 'create_draft', 'aggregate_type' => 'work_order',
            'request_hash' => hash('sha256', $id), 'status' => 'processing', 'created_at' => now(), 'updated_at' => now(),
        ];
        $first->table('erp_work_order_command_ledgers')->insert($row);
        try {
            $second->table('erp_work_order_command_ledgers')->insert($row);
            $this->fail('A second MySQL connection must lose the unique command identity race.');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertSame('23000', (string) $exception->getCode());
        } finally {
            $second->table('erp_work_order_command_ledgers')->where('client_command_id', $id)->delete();
            $first->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
        }
    }

    public function test_09_real_token_controller_scope_and_structured_conflict_are_enforced(): void
    {
        [$user, $demand] = $this->fixture(909);
        app(RbacBootstrapService::class)->bootstrap(true);
        $token = 'wo-api-token-'.uniqid();
        DB::table('erp_auth_tokens')->insert([
            'user_legacy_id' => $user->legacy_id, 'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->withToken($token)->postJson('/api/v1/erp/production/work-orders', [
            'client_command_id' => 'wo-api-1', 'production_demand_id' => $demand->id, 'expected_demand_version' => 1, 'target_qty' => 1,
            'organization_code' => 'CLIENT-CANNOT-CHOOSE',
        ]);
        $response->assertCreated()->assertJsonStructure(['data' => ['id', 'production_demand_id']]);
        $this->assertNull($response->json('data.organization_code'));

        $crossIdentity = $this->withToken($token)->putJson('/api/v1/erp/production/work-orders/'.$response->json('data.id'), [
            'client_command_id' => 'wo-api-1', 'target_qty' => 1, 'expected_version' => 1,
        ]);
        $crossIdentity->assertStatus(409)->assertJsonPath('error_code', 'idempotency_hash_conflict')
            ->assertJsonStructure(['errors', 'details']);

        $response = $this->withToken($token)->postJson('/api/v1/erp/production/work-orders', [
            'client_command_id' => 'wo-api-1', 'production_demand_id' => $demand->id, 'expected_demand_version' => 1, 'target_qty' => 2,
        ]);
        $response->assertStatus(409)->assertJsonPath('error_code', 'idempotency_hash_conflict')->assertJsonStructure(['errors', 'details']);

        $this->flushHeaders();
        $response = $this->postJson('/api/v1/erp/production/work-orders', [
            'client_command_id' => 'wo-api-no-token', 'production_demand_id' => $demand->id, 'expected_demand_version' => 2, 'target_qty' => 1,
        ]);
        $response->assertUnauthorized()->assertJsonPath('error_code', 'unauthenticated')->assertJsonStructure(['errors', 'details']);
    }

    public function test_10_independent_mysql_service_processes_compete_and_recover_after_injected_crash(): void
    {
        $setup = $this->runServiceProbe(['setup']);
        $this->assertTrue($setup['ok'] ?? false, json_encode($setup));
        $ownerId = (int) $setup['owner_id'];
        $orderId = (int) $setup['order_id'];
        $demandId = (int) $setup['demand_id'];
        $raceCommand = 'wo-probe-race-'.$ownerId;
        $crashCommand = 'wo-probe-crash-'.$ownerId;
        try {
            $first = $this->startServiceProbe(['concurrent', $demandId, $ownerId, $raceCommand]);
            $second = $this->startServiceProbe(['concurrent', $demandId, $ownerId, $raceCommand]);
            $firstResult = $this->finishServiceProbe($first);
            $secondResult = $this->finishServiceProbe($second);
            $raceResults = [$firstResult, $secondResult];
            $this->assertSame(1, count(array_filter($raceResults, fn (array $result): bool => ($result['ok'] ?? false) === true)), json_encode($raceResults));
            $this->assertSame(1, count(array_filter($raceResults, fn (array $result): bool => ($result['error_code'] ?? null) === 'command_processing' && ($result['status'] ?? null) === 409)), json_encode($raceResults));

            $crash = $this->runServiceProbe(['crash', $demandId, $ownerId, $crashCommand]);
            $this->assertFalse($crash['ok'] ?? true, json_encode($crash));
            $this->assertSame('RuntimeException', $crash['error_code'] ?? null, json_encode($crash));
            $recovered = $this->runServiceProbe(['recover', $demandId, $ownerId, $crashCommand]);
            $this->assertTrue($recovered['ok'] ?? false, json_encode($recovered));
            $this->assertDatabaseHas('erp_work_order_command_ledgers', [
                'client_command_id' => $crashCommand, 'status' => 'succeeded', 'result_id' => $recovered['work_order_id'],
            ]);
        } finally {
            $this->runServiceProbe(['cleanup', $orderId, $demandId, $ownerId]);
        }
    }

    private function runServiceProbe(array $arguments): array
    {
        $process = $this->startServiceProbe($arguments);
        return $this->finishServiceProbe($process);
    }

    private function startServiceProbe(array $arguments): array
    {
        $script = base_path('tests/Support/work_order_service_probe.php');
        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($script);
        foreach ($arguments as $argument) $command .= ' '.escapeshellarg((string) $argument);
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
        if (! is_resource($process)) $this->fail('Could not start the independent MySQL service probe.');
        return [$process, $pipes];
    }

    private function finishServiceProbe(array $handle): array
    {
        [$process, $pipes] = $handle;
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $result = json_decode(trim($stdout), true);
        if (! is_array($result)) $this->fail('Service probe did not return JSON: '.trim($stderr).' '.trim($stdout));
        return $result;
    }

    private function fixture(int $ownerId = 101, float $consumed = 0): array
    {
        foreach ([$ownerId, 202] as $legacyId) {
            DB::table('erp_legacy_admin_users')->insert([
                'legacy_id' => $legacyId, 'username' => 'qa-'.$legacyId, 'nickname' => 'QA '.$legacyId,
                'status' => 'normal', 'auth_group_names' => '[]', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $user = DB::table('erp_legacy_admin_users')->where('legacy_id', $ownerId)->first();
        app(RbacBootstrapService::class)->bootstrap(true);
        DB::table('erp_rbac_roles')->updateOrInsert(
            ['code' => 'work_order_creator_test'],
            ['name' => '工单创建权限测试角色', 'data_scope' => 'all', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
        );
        $creatorRoleId = DB::table('erp_rbac_roles')->where('code', 'work_order_creator_test')->value('id');
        $permissionIds = DB::table('erp_rbac_permissions')->whereIn('code', self::PERMISSIONS)->pluck('id');
        foreach ($permissionIds as $permissionId) {
            DB::table('erp_rbac_role_permissions')->insertOrIgnore(['role_id' => $creatorRoleId, 'permission_id' => $permissionId]);
        }
        DB::table('erp_rbac_user_roles')->insertOrIgnore(['user_legacy_id' => $ownerId, 'role_id' => $creatorRoleId]);
        $order = SalesOrder::create([
            'sales_order_no' => 'QA-WO-SO-'.$ownerId, 'customer_name' => 'QA客户', 'order_status' => 'confirmed',
            'confirm_status' => 'confirmed', 'production_confirm_status' => 'confirmed', 'sales_user_legacy_id' => $ownerId,
            'created_by_legacy_id' => $ownerId, 'total_amount' => 0, 'final_receivable_amount' => 0,
        ]);
        $line = SalesOrderLine::create([
            'sales_order_id' => $order->id, 'line_no' => 1, 'line_uuid' => 'QA-WO-LINE-'.$ownerId,
            'line_type' => 'physical', 'order_qty' => 10, 'unit_price' => 0, 'amount' => 0,
        ]);
        $demand = ProductionDemand::create([
            'requirement_no' => 'QA-WO-DEMAND-'.$ownerId, 'sales_order_id' => $order->id, 'sales_order_line_id' => $line->id,
            'production_qty' => 10, 'allocated_qty' => 0, 'consumed_qty' => $consumed, 'remaining_qty' => 10 - $consumed, 'closed_qty' => 0,
            'requirement_status' => 'ready', 'bom_match_status' => 'matched', 'is_active' => true, 'requirement_version' => 1,
            'business_version' => 1, 'is_ready_for_work_order' => false,
        ]);
        return [$user, $demand];
    }
}
