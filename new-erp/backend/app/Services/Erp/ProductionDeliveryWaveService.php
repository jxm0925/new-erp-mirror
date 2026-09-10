<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\MaterialDelivery;
use App\Models\Erp\MaterialDeliveryTask;
use App\Models\Erp\MaterialDeliveryTaskAssignment;
use App\Models\Erp\MaterialDeliveryTaskEvent;
use App\Models\Erp\MaterialDeliveryTaskLine;
use App\Models\Erp\MaterialDeliveryWave;
use App\Models\Erp\ProductionPreparationOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Keeps the PD receipt document independent from the per-person DT execution task. */
final class ProductionDeliveryWaveService
{
    public function __construct(private readonly ProductionDataScopeResolver $scopeResolver) {}

    public function create(array $payload, object $user, array $permissions, bool $superAdmin = false): MaterialDeliveryWave
    {
        $this->permission($permissions, 'production.material_delivery.create');
        $command = trim((string) ($payload['client_command_id'] ?? ''));
        if ($command === '') $this->fail('client_command_required', 'client_command_id 不能为空。');
        $hashPayload = $payload; unset($hashPayload['client_command_id']);
        $hash = hash('sha256', json_encode($hashPayload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        return DB::transaction(function () use ($payload, $user, $permissions, $superAdmin, $command, $hash) {
            $existing = MaterialDeliveryWave::query()->where('client_command_id', $command)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->request_hash !== $hash) $this->fail('idempotency_hash_conflict', '该请求标识已用于不同的配送波次。', 409);
                return $existing->fresh($this->relations());
            }
            $prep = ProductionPreparationOrder::query()->with('lines')->lockForUpdate()->find((int) ($payload['production_preparation_order_id'] ?? 0));
            if (! $prep) $this->fail('preparation_order_not_found', '订单备料单不存在。', 404);
            $tasks = collect($payload['tasks'] ?? []);
            if ($tasks->isEmpty()) $this->fail('delivery_tasks_required', '配送波次至少需要一个配送执行任务。');
            $wave = MaterialDeliveryWave::create([
                'wave_no' => 'TMP-'.bin2hex(random_bytes(12)), 'production_preparation_order_id' => $prep->id,
                'production_master_order_id' => $prep->production_master_order_id, 'status' => 'WAIT_EXECUTION',
                'client_command_id' => $command, 'request_hash' => $hash, 'business_version' => 1,
                'organization_code' => $prep->organization_code, 'created_by_legacy_id' => $this->userId($user), 'updated_by_legacy_id' => $this->userId($user),
            ]);
            $wave->update(['wave_no' => 'DW'.now()->format('Ymd').str_pad((string) $wave->id, 6, '0', STR_PAD_LEFT)]);
            foreach ($tasks as $taskPayload) $this->createTask($wave, $prep, (array) $taskPayload, $user, $permissions, $superAdmin);
            return $wave->fresh($this->relations());
        }, 5);
    }

    public function poolClaim(int $taskId, array $payload, object $user, array $permissions, bool $superAdmin = false): MaterialDeliveryTask
    {
        $this->permission($permissions, 'production.material_delivery.dispatch');
        return DB::transaction(function () use ($taskId, $payload, $user, $permissions, $superAdmin) {
            $task = MaterialDeliveryTask::query()->with('lines.delivery.workOrder')->lockForUpdate()->find($taskId);
            if (! $task) $this->fail('delivery_task_not_found', '配送执行任务不存在。', 404);
            $this->visible($task, $user, $permissions, $superAdmin); $this->version($task, $payload);
            if ($task->assignment_mode !== 'pool_claim' || $task->status !== 'WAIT_CLAIM') $this->fail('pool_claim_not_allowed', '当前配送任务不在区域池待接单状态。');
            if ($task->assignments()->whereNull('released_at')->lockForUpdate()->exists()) $this->fail('delivery_task_already_claimed', '配送执行任务已被接单。', 409);
            MaterialDeliveryTaskAssignment::create(['delivery_task_id' => $task->id, 'assignee_legacy_id' => $this->userId($user), 'assignment_type' => 'pool_claim', 'assigned_by_legacy_id' => $this->userId($user), 'claimed_at' => now()]);
            $this->transition($task, 'CLAIMED', 'pool_claim', $user, $payload['remark'] ?? null);
            return $task->fresh($this->taskRelations());
        }, 5);
    }

    public function dispatcherAssign(int $taskId, array $payload, object $user, array $permissions, bool $superAdmin = false): MaterialDeliveryTask
    {
        $this->permission($permissions, 'production.material_delivery.dispatch');
        return DB::transaction(function () use ($taskId, $payload, $user, $permissions, $superAdmin) {
            $task = MaterialDeliveryTask::query()->with('lines.delivery.workOrder')->lockForUpdate()->find($taskId);
            if (! $task) $this->fail('delivery_task_not_found', '配送执行任务不存在。', 404);
            $this->visible($task, $user, $permissions, $superAdmin); $this->version($task, $payload);
            if ($task->assignment_mode !== 'dispatcher_assign' || $task->status !== 'WAIT_CLAIM') $this->fail('dispatcher_assign_not_allowed', '当前配送任务不在待调度派单状态。');
            $assignee = (int) ($payload['delivery_user_legacy_id'] ?? 0); $this->assertActiveUser($assignee);
            MaterialDeliveryTaskAssignment::create(['delivery_task_id' => $task->id, 'assignee_legacy_id' => $assignee, 'assignment_type' => 'dispatcher_assign', 'assigned_by_legacy_id' => $this->userId($user)]);
            $this->transition($task, 'CLAIMED', 'dispatcher_assign', $user, $payload['remark'] ?? null);
            return $task->fresh($this->taskRelations());
        }, 5);
    }

    public function transitionTask(int $taskId, array $payload, object $user, array $permissions, bool $superAdmin = false): MaterialDeliveryTask
    {
        $this->permission($permissions, 'production.material_delivery.dispatch');
        return DB::transaction(function () use ($taskId, $payload, $user, $permissions, $superAdmin) {
            $task = MaterialDeliveryTask::query()->with('lines.delivery.workOrder')->lockForUpdate()->find($taskId);
            if (! $task) $this->fail('delivery_task_not_found', '配送执行任务不存在。', 404);
            $this->visible($task, $user, $permissions, $superAdmin); $this->version($task, $payload);
            $to = strtoupper((string) ($payload['status'] ?? ''));
            $allowed = ['CLAIMED' => ['PICKED_UP', 'CANCELLED'], 'PICKED_UP' => ['DELIVERING', 'EXCEPTION', 'CANCELLED'], 'DELIVERING' => ['PARTIAL_DONE', 'DONE', 'EXCEPTION'], 'PARTIAL_DONE' => ['DELIVERING', 'DONE', 'EXCEPTION']];
            if (! in_array($to, $allowed[$task->status] ?? [], true)) $this->fail('delivery_task_transition_invalid', '配送执行任务当前状态不允许该操作。');
            $this->assertAssigneeOrDispatcher($task, $user, $permissions, $superAdmin);
            // DONE only says the courier finished this allocation; PD and MaterialReceipt are not changed here.
            $this->transition($task, $to, 'transition', $user, $payload['remark'] ?? null);
            return $task->fresh($this->taskRelations());
        }, 5);
    }

    public function paginate(array $filters, object $user, array $permissions, bool $superAdmin = false): LengthAwarePaginator
    {
        $this->permission($permissions, 'production.material_delivery.view');
        $scope = $this->scopeResolver->resolve($user, 'production.material_delivery.view', $permissions, $superAdmin);
        $query = MaterialDeliveryWave::query()->with($this->relations())->orderByDesc('id');
        $query->whereHas('deliveryTasks.lines.delivery.workOrder', fn (Builder $orders) => $this->scopeResolver->applyWorkOrderScope($orders, $scope));
        if (! empty($filters['status'])) $query->where('status', $filters['status']);
        if (! empty($filters['production_preparation_order_id'])) $query->where('production_preparation_order_id', (int) $filters['production_preparation_order_id']);
        return $query->paginate(min(50, max(1, (int) ($filters['per_page'] ?? 20))), ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)));
    }

    private function createTask(MaterialDeliveryWave $wave, ProductionPreparationOrder $prep, array $payload, object $user, array $permissions, bool $superAdmin): void
    {
        $mode = (string) ($payload['assignment_mode'] ?? '');
        if (! in_array($mode, ['pool_claim', 'dispatcher_assign'], true)) $this->fail('assignment_mode_invalid', '本期仅支持区域池接单或调度派单。');
        if ($mode === 'pool_claim' && trim((string) ($payload['zone_pool_code'] ?? '')) === '') $this->fail('zone_pool_required', '区域池接单任务必须指定区域池。');
        $lines = collect($payload['lines'] ?? []); if ($lines->isEmpty()) $this->fail('delivery_task_lines_required', '配送执行任务至少需要一条 PD 明细。');
        $lineIds = $lines->pluck('material_delivery_line_id')->map(fn ($id) => (int) $id)->all();
        if (count($lineIds) !== count(array_unique($lineIds)) || in_array(0, $lineIds, true)) $this->fail('delivery_task_lines_invalid', '配送执行任务明细不可为空或重复。');
        $pdLines = DB::table('erp_material_delivery_lines as line')->join('erp_material_deliveries as pd', 'pd.id', '=', 'line.delivery_id')
            ->join('erp_work_orders as wo', 'wo.id', '=', 'pd.work_order_id')->whereIn('line.id', $lineIds)->orderBy('line.id')->lockForUpdate()
            ->get(['line.*', 'pd.work_order_id', 'wo.production_master_order_id']);
        if ($pdLines->count() !== count($lineIds)) $this->fail('delivery_line_not_found', '部分生产配送明细不存在。', 404);
        foreach ($pdLines as $pdLine) {
            if ((int) $pdLine->production_master_order_id !== (int) $prep->production_master_order_id) $this->fail('delivery_line_scope_invalid', '配送执行任务只能分配当前 PB 所属 MWO 的 PD 明细。');
            $delivery = MaterialDelivery::query()->with('workOrder')->find($pdLine->delivery_id); $this->visibleDelivery($delivery, $user, $permissions, $superAdmin);
            $row = $lines->firstWhere('material_delivery_line_id', $pdLine->id); $allocation = (float) ($row['allocated_qty'] ?? 0);
            if ($allocation <= 0) $this->fail('allocated_qty_invalid', '配送执行数量必须大于零。');
            $already = (float) MaterialDeliveryTaskLine::query()->where('material_delivery_line_id', $pdLine->id)->whereHas('task', fn (Builder $tasks) => $tasks->where('status', '<>', 'CANCELLED'))->sum('allocated_qty');
            if ($allocation > (float) $pdLine->delivery_qty - $already + 0.00000001) $this->fail('delivery_allocation_exceeded', '配送执行任务分配合计不能超过生产配送明细剩余数量。', 409);
            $serials = array_values(array_unique(array_map('intval', (array) (($row['serial_snapshot'] ?? [])['inventory_serial_ids'] ?? []))));
            $pdSerialSnapshot = is_string($pdLine->serial_snapshot ?? null)
                ? json_decode($pdLine->serial_snapshot, true) : ($pdLine->serial_snapshot ?? []);
            $availableSerials = array_values(array_map('intval', (array) (($pdSerialSnapshot ?? [])['inventory_serial_ids'] ?? [])));
            if ($serials && array_diff($serials, $availableSerials)) $this->fail('delivery_task_serial_invalid', '配送任务序列号必须来自对应的生产配送明细。');
            $allocatedSerials = MaterialDeliveryTaskLine::query()->where('material_delivery_line_id', $pdLine->id)
                ->whereHas('task', fn (Builder $tasks) => $tasks->where('status', '<>', 'CANCELLED'))->get()
                ->flatMap(fn (MaterialDeliveryTaskLine $line) => (array) (($line->serial_snapshot ?? [])['inventory_serial_ids'] ?? []))->map(fn ($id) => (int) $id)->all();
            if (array_intersect($serials, $allocatedSerials)) $this->fail('delivery_task_serial_already_allocated', '同一序列号不能分配给多个有效配送执行任务。', 409);
            if ($availableSerials && (count($serials) !== (int) $allocation || abs($allocation - (int) $allocation) > 0.00000001)) $this->fail('delivery_task_serial_quantity_mismatch', '序列化物料必须逐件绑定配送任务。');
        }
        $task = MaterialDeliveryTask::create(['task_no' => 'TMP-'.bin2hex(random_bytes(12)), 'delivery_wave_id' => $wave->id, 'assignment_mode' => $mode,
            'zone_pool_code' => $payload['zone_pool_code'] ?? null, 'warehouse_code' => $payload['warehouse_code'] ?? null, 'production_zone_code' => $payload['production_zone_code'] ?? null, 'work_center_code' => $payload['work_center_code'] ?? null, 'location_code' => $payload['location_code'] ?? null,
            'priority' => min(100, max(1, (int) ($payload['priority'] ?? 50))), 'planned_start_at' => $payload['planned_start_at'] ?? null, 'required_finish_at' => $payload['required_finish_at'] ?? null,
            'status' => 'WAIT_CLAIM', 'business_version' => 1, 'organization_code' => $prep->organization_code, 'created_by_legacy_id' => $this->userId($user), 'updated_by_legacy_id' => $this->userId($user)]);
        $task->update(['task_no' => 'DT'.now()->format('Ymd').str_pad((string) $task->id, 6, '0', STR_PAD_LEFT)]);
        foreach ($pdLines as $pdLine) { $row = $lines->firstWhere('material_delivery_line_id', $pdLine->id); MaterialDeliveryTaskLine::create(['delivery_task_id' => $task->id, 'material_delivery_id' => $pdLine->delivery_id, 'material_delivery_line_id' => $pdLine->id, 'allocated_qty' => $row['allocated_qty'], 'serial_snapshot' => $row['serial_snapshot'] ?? null, 'handling_unit_id' => $row['handling_unit_id'] ?? null, 'status' => 'WAIT_EXECUTION', 'business_version' => 1]); }
        if ($mode === 'dispatcher_assign') { $assignee = (int) ($payload['delivery_user_legacy_id'] ?? 0); $this->assertActiveUser($assignee); MaterialDeliveryTaskAssignment::create(['delivery_task_id' => $task->id, 'assignee_legacy_id' => $assignee, 'assignment_type' => 'dispatcher_assign', 'assigned_by_legacy_id' => $this->userId($user)]); $this->transition($task, 'CLAIMED', 'dispatcher_assign', $user); } else $this->event($task, 'create', null, 'WAIT_CLAIM', 0, 1, $user);
    }

    private function relations(): array { return ['preparationOrder', 'masterOrder', 'deliveryTasks.lines.delivery', 'deliveryTasks.assignments']; }
    private function taskRelations(): array { return ['wave', 'lines.delivery', 'lines.deliveryLine', 'assignments']; }
    private function visible(MaterialDeliveryTask $task, object $user, array $permissions, bool $superAdmin): void { foreach ($task->lines as $line) $this->visibleDelivery($line->delivery, $user, $permissions, $superAdmin); }
    private function visibleDelivery(?MaterialDelivery $delivery, object $user, array $permissions, bool $superAdmin): void { if (! $delivery || ! $delivery->workOrder) $this->fail('delivery_scope_invalid', '配送单缺少生产工单。'); $scope = $this->scopeResolver->resolve($user, 'production.material_delivery.view', $permissions, $superAdmin); if (! $this->scopeResolver->workOrderVisible($delivery->workOrder, $scope)) $this->fail('data_scope_denied', '配送执行任务不在当前数据范围内。', 403); }
    private function assertAssigneeOrDispatcher(MaterialDeliveryTask $task, object $user, array $permissions, bool $superAdmin): void { if ($superAdmin) return; if (! $task->assignments()->whereNull('released_at')->where('assignee_legacy_id', $this->userId($user))->exists()) $this->fail('delivery_task_assignee_required', '只有当前配送负责人可以更新执行状态。', 403); }
    private function transition(MaterialDeliveryTask $task, string $to, string $action, object $user, ?string $remark = null): void { $from = $task->status; $before = (int) $task->business_version; $task->update(['status' => $to, 'business_version' => $before + 1, 'updated_by_legacy_id' => $this->userId($user)]); $this->event($task, $action, $from, $to, $before, $before + 1, $user, $remark); }
    private function event(MaterialDeliveryTask $task, string $action, ?string $from, string $to, int $before, int $after, object $user, ?string $remark = null): void { MaterialDeliveryTaskEvent::create(['delivery_task_id' => $task->id, 'action' => $action, 'from_status' => $from, 'to_status' => $to, 'before_version' => $before, 'after_version' => $after, 'snapshot' => ['task_no' => $task->task_no], 'operator_legacy_id' => $this->userId($user), 'remark' => $remark]); }
    private function assertActiveUser(int $id): void { if ($id <= 0 || ! DB::table('erp_legacy_admin_users')->where('legacy_id', $id)->whereIn('status', ['normal', 'active'])->exists()) $this->fail('delivery_user_invalid', '配送人不存在或已停用。'); }
    private function version(MaterialDeliveryTask $task, array $payload): void { if ((int) ($payload['expected_version'] ?? 0) !== (int) $task->business_version) $this->fail('version_conflict', '配送执行任务版本已变化，请刷新后重试。', 409); }
    private function permission(array $permissions, string $required): void { if (! in_array($required, $permissions, true)) $this->fail('permission_denied', '当前用户没有执行配送操作的权限。', 403); }
    private function userId(object $user): int { return (int) ($user->legacy_id ?? $user->id ?? 0); }
    private function fail(string $code, string $message, int $status = 422): never { throw new WorkOrderDomainException($code, $message, $status); }
}
