<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\CuttingTask;
use App\Models\Erp\WorkOrder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class CuttingCommandService
{
    public function __construct(private readonly ProductionDataScopeResolver $scope) {}

    public function run(string $type, int $aggregateId, array $payload, object $user, callable $action): array
    {
        $fields = match ($type) {
            'publish_cutting_order' => ['plans'], 'save_cutting_results' => ['results'], 'split_cutting_result' => ['routes'],
            'inspect_cutting_result' => ['result','reason'], 'register_material_physical' => ['source_transaction_item_id','dimensions'],
            'reserve_cutting_physical' => ['physical_material_ids'], 'release_cutting_physical' => ['physical_material_id'],
            'issue_cutting_material' => ['physical_material_id','inventory_balance_id','input_qty'],
            'confirm_cutting_batch' => ['costs','allocations'],
            'return_cutting_for_edit' => ['reason'],
            'start_cutting_task','resume_cutting_task','start_cutting_collaborator_labor' => ['switch_active_labor','expected_active_labor_session_id'],
            'add_cutting_task_collaborators' => ['employee_legacy_ids'],
            'dispatch_cutting_route','accept_cutting_handover' => ['quantity'],
            'reject_cutting_handover' => ['quantity','reason'],
            'warehouse_cutting_route' => ['quantity','warehouse_id','location_id','batch_no'],
            'claim_cutting_task','pause_cutting_task','finish_cutting_task','leave_cutting_task_collaboration',
            'pause_cutting_collaborator_labor','submit_cutting_results','mark_cutting_first_cut' => [], default => null,
        };
        if ($fields !== null && array_diff(array_keys($payload), array_merge(['client_command_id','expected_version'], $fields)))
            $this->fail('command_fields_invalid', '操作包含不允许的字段。');
        $id = $payload['client_command_id'] ?? null;
        if (! is_string($id) || strlen($id) < 1 || strlen($id) > 120) $this->fail('command_id_invalid', '请求标识不能为空且不能超过120字节。');
        $hash = hash('sha256', json_encode($this->canonical([$type, $aggregateId, $this->actor($user), $payload]), JSON_THROW_ON_ERROR));
        $recover = function (object $row) use ($type, $hash, $user): array {
            if ($row->command_type !== $type || $row->request_hash !== $hash || (int) $row->actor_legacy_id !== $this->actor($user))
                $this->fail('idempotency_hash_conflict', '该请求标识已用于不同操作。', 409);
            if ($row->status !== 'SUCCEEDED') $this->fail('command_processing', '相同请求正在处理，请稍后重试。', 409);
            return $this->canonical(json_decode($row->response, true, 512, JSON_THROW_ON_ERROR));
        };
        try {
            return DB::transaction(function () use ($id, $type, $hash, $user, $action, $recover): array {
                $existing = DB::table('erp_cutting_commands')->where('client_command_id', $id)->lockForUpdate()->first();
                if ($existing) return $recover($existing);
                $commandId = DB::table('erp_cutting_commands')->insertGetId(['client_command_id' => $id, 'command_type' => $type,
                    'actor_legacy_id' => $this->actor($user), 'request_hash' => $hash, 'status' => 'PROCESSING', 'created_at' => now(), 'updated_at' => now()]);
                $response = $this->canonical($action());
                DB::table('erp_cutting_commands')->where('id', $commandId)->update(['status' => 'SUCCEEDED',
                    'response' => json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
                return $response;
            }, 5);
        } catch (QueryException $e) {
            if ((string) $e->getCode() === '23000') {
                $existing = DB::table('erp_cutting_commands')->where('client_command_id', $id)->first();
                if ($existing) return $recover($existing);
                $this->fail('persistence_conflict', '用料或记录已被其他操作占用，请刷新。', 409);
            }
            throw $e;
        }
    }

    public function permission(array $permissions, string $code): void
    {
        if (! in_array($code, $permissions, true)) $this->fail('permission_denied', '没有执行该下料操作的权限。', 403, ['permission' => $code]);
    }

    public function workOrder(int $id, object $user, array $permissions, bool $super, string $permission, bool $lock = false): WorkOrder
    {
        $q = WorkOrder::query()->whereKey($id); if ($lock) $q->lockForUpdate();
        $wo = $q->first(); if (! $wo) $this->fail('work_order_missing', '正式来源工单不存在。', 404);
        $this->assertWorkOrder($wo, $user, $permissions, $super, $permission); return $wo;
    }

    public function cuttingTask(int $id, object $user, array $permissions, bool $super, string $permission, bool $lock = false): CuttingTask
    {
        $this->permission($permissions, $permission);
        $query = CuttingTask::query()->whereKey($id);
        $this->scope->applyCuttingTaskScope(
            $query,
            $this->scope->resolve($user, $permission, $permissions, $super),
            $this->actor($user),
        );
        if ($lock) $query->lockForUpdate();
        $task = $query->first();
        if ($task) return $task;
        if (CuttingTask::query()->whereKey($id)->exists()) {
            $this->fail('data_scope_denied', '该下料任务不在当前下料责任范围内。', 403);
        }
        $this->fail('cutting_task_missing', '下料任务不存在。', 404);
    }

    public function assertWorkOrder(WorkOrder $wo, object $user, array $permissions, bool $super, string $permission): void
    {
        $this->permission($permissions, $permission);
        if (! $this->scope->workOrderVisible($wo, $this->scope->resolve($user, $permission, $permissions, $super)))
            $this->fail('data_scope_denied', '该工单不在当前生产数据范围内。', 403);
    }

    public function order(int $id, object $user, array $permissions, bool $super, string $permission, bool $lock = false): object
    {
        $this->permission($permissions, $permission);
        $q = DB::table('erp_cutting_orders')->where('id', $id); if ($lock) $q->lockForUpdate();
        $row = $q->first(); if (! $row) $this->fail('cutting_order_missing', '下料单不存在。', 404);
        $ids = DB::table('erp_cutting_plan_allocations')->where('cutting_order_id', $id)->pluck('work_order_id');
        $targetWos = DB::table('erp_cutting_plan_allocations as p')->join('erp_production_target_material_requirements as r','r.id','=','p.target_material_requirement_id')
            ->where('p.cutting_order_id',$id)->pluck('r.work_order_id');
        $ids = $ids->merge($targetWos)->unique()->sort();
        if ($ids->isEmpty()) $this->fail('source_missing', '下料单没有正式来源。', 409);
        foreach ($ids as $wo) $this->workOrder((int) $wo, $user, $permissions, $super, $permission);
        return $row;
    }

    public function batch(int $id, object $user, array $permissions, bool $super, string $permission): object
    {
        $row = DB::table('erp_cutting_settlement_batches')->where('id', $id)->first();
        if (! $row) $this->fail('batch_missing', '用料批次不存在。', 404);
        $this->order($row->cutting_order_id, $user, $permissions, $super, $permission, true);
        return DB::table('erp_cutting_settlement_batches')->where('id', $id)->lockForUpdate()->first();
    }

    public function assertBatchVisible(int $id, object $user, array $permissions, bool $super, string $permission): object
    {
        $row = DB::table('erp_cutting_settlement_batches')->where('id', $id)->first();
        if (! $row) $this->fail('batch_missing', '用料批次不存在。', 404);
        $this->order($row->cutting_order_id, $user, $permissions, $super, $permission); return $row;
    }

    public function assertResultVisible(int $id, object $user, array $permissions, bool $super, string $permission): object
    {
        $row = DB::table('erp_cutting_results')->where('id', $id)->first();
        if (! $row) $this->fail('result_missing', '产出结果不存在。', 404);
        $this->assertBatchVisible($row->settlement_batch_id, $user, $permissions, $super, $permission); return $row;
    }

    public function version(object $row, array $payload): void
    {
        if (! isset($payload['expected_version']) || filter_var($payload['expected_version'], FILTER_VALIDATE_INT) === false)
            $this->fail('version_required', '请提供记录版本。');
        if ((int) $payload['expected_version'] !== (int) $row->business_version)
            $this->fail('version_conflict', '记录已变化，请刷新后重试。', 409, ['current_version' => (int) $row->business_version]);
    }

    public function event(string $type, int $id, string $action, object $user, mixed $before, mixed $after): void
    {
        DB::table('erp_cutting_events')->insert(['aggregate_type' => $type, 'aggregate_id' => $id, 'action' => $action,
            'operator_legacy_id' => $this->actor($user), 'before_snapshot' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'after_snapshot' => json_encode($after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'occurred_at' => now()]);
    }

    public function actor(object $user): int
    {
        $id = (int) ($user->legacy_id ?? $user->id ?? 0); if ($id <= 0) $this->fail('unauthenticated', '请先登录ERP。', 401); return $id;
    }

    public function fail(string $code, string $message, int $status = 422, array $details = []): never
    { throw new WorkOrderDomainException($code, $message, $status, $details); }

    private function canonical(array $data): array
    {
        foreach ($data as $key => $value) if (is_array($value)) $data[$key] = $this->canonical($value);
        if (! array_is_list($data)) ksort($data); return $data;
    }
}
